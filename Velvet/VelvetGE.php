<?php

namespace Surface\Drawing\Velvet;

use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\Effects\FilterChain;
use Surface\Contracts\Drawing\Effects\Glass;
use Surface\Contracts\Fonts\TrueTypeFont;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\PagedFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Framebuffers\RingFramebuffer;
use Surface\Contracts\Framebuffers\Spans;
use Surface\Contracts\Rasterize\Edges;
use Surface\Contracts\Rasterize\RasterizeDriver;
use Surface\Contracts\Rasterize\Rasterizer;
use Surface\Drawing\GlassGroups;
use Surface\Drawing\RenderingEngine;
use Surface\Framebuffers\PixelMapper;
use Surface\Framebuffers\PixelMapperMode;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\GradientSpread;
use Throwable;

/**
 * The software rendering engine. It draws on the CPU into the framebuffer it
 * is given: Rasterize turns each shape into coverage spans and the framebuffer
 * paints them. The framebuffer's kind decides what a frame is:
 *
 *   full, dirty, ePaper  the frame's commands are drawn over what is there
 *   ring                 repair(), draw, present(): the frame lands on the front
 *   paged                the commands are drawn into the current page; setPage()
 *                        then replay() draws the same frame into the next
 *
 * Draining is never the engine's: flush, damage, epochs and pages belong to
 * whoever holds the framebuffer.
 */
class VelvetGE extends RenderingEngine
{
    protected Edges $edges;

    protected PixelMapper $mapper;

    /** @var array<int, array<int, array<int, array<int, Rasterizer>>>> [x][y][width][height]: one per clip met in the frame being drawn. */
    private array $rasterizers = [];

    /** Where each shape command holds its clip; these are drawn in batches, one scan call a run of one clip. */
    private const array SHAPES = ['path' => 4, 'polyline' => 5, 'ellipse' => 6, 'ring' => 7, 'roundrect' => 7, 'roundring' => 8, 'shadow' => 8];

    /** @var list<Framebuffer> The RGBA8 frames layers are drawn in, lent in turn within one frame and kept for the next. */
    private array $scratches = [];

    /** How many of $scratches are lent just now. */
    private int $lent = 0;

    /** @var array<string, Framebuffer> The glass pyramid's RGBA8 levels, by role and size: kept from frame to frame. */
    private array $levels = [];

    /** @var array<int, array{key: string, built: array{region: Region, samples: array<int, Framebuffer>, sharp: Framebuffer}, levels: list<string>}> The last frame's backdrop pyramids by their place in the frame, kept while nothing under them is redrawn. */
    private array $pyramids = [];

    /** How many backdrops this frame's top-level run has used. */
    private int $backdrops = 0;

    /** @var array<string, true> The level frames this frame uses, kept or built. */
    private array $used = [];

    /** @var list<string> The level frames the pyramid being built took. */
    private array $made = [];

    /** The coverage bytes the glyph cache holds at most (each entry counts 64 more); the least recently used goes first. */
    public const int GLYPH_CACHE_BYTES = 8 * 1024 * 1024;

    /** @var array<string, array{string, int}|null> face|pixelSize|glyph|style|edges => [coverage, width], null for a glyph with no coverage */
    private array $glyphMasks = [];

    /** What $glyphMasks holds, by GLYPH_CACHE_BYTES's count. */
    private int $glyphBytes = 0;

    /**
     * @param  Edges|null  $edges  Null picks by format: anti-aliased where the format can blend (RGB, grey), hard where it cannot (mono, palette, planar).
     * @param  bool  $directFill  Shapes painted straight into the framebuffer's span sink where the drivers can (ext-rasterize
     *                            into ext-fb); false sends every shape's spans through paintSpans(). The pixels are the same.
     */
    public function __construct(
        protected Framebuffer $framebuffer,
        protected RasterizeDriver $rasterize,
        ?Edges $edges = null,
        protected bool $directFill = true,
    ) {
        $this->mapper = PixelMapper::for($framebuffer->hostFormat());
        $this->edges = $edges ?? (in_array($this->mapper->mode(), [PixelMapperMode::RGB, PixelMapperMode::GREY], true) ? Edges::ANTIALIASED : Edges::HARD);
    }

    public function name(): string
    {
        return 'velvet';
    }

    public function framebuffer(): Framebuffer
    {
        return $this->framebuffer;
    }

    public function edges(): Edges
    {
        return $this->edges;
    }

    protected function execute(array $commands): void
    {
        $target = $this->framebuffer;
        // A back a scroll already repaired holds the last frame: age() 1.
        if ($target instanceof RingFramebuffer && $target->age() !== 1) {
            $target->repair();
        }

        // A paged framebuffer holds one page: only its rows are worth rasterizing.
        $rows = $target instanceof PagedFramebuffer ? $target->pageRegion($target->page()) : $this->surface();
        $this->rasterizers = [];
        $this->lent = 0;

        $this->backdrops = 0;
        $this->used = [];

        try {
            $this->run($commands, $target, $rows, $this->mapper);
            // backdrops this frame did not draw, and the level frames only they used, go: a pane that moves would leave a set behind every frame
            $this->pyramids = array_slice($this->pyramids, 0, $this->backdrops, true);
            $this->levels = array_intersect_key($this->levels, $this->used);
        } catch (Throwable $e) {
            // a layer that failed part way leaves its frames unclean
            $this->scratches = [];
            throw $e;
        }
    }

    /** @param list<array<int, mixed>> $commands */
    private function run(array $commands, Framebuffer $target, Region $rows, PixelMapper $mapper): void
    {
        // a run of shape commands under one clip waits in $batch and is drawn by one fillBatch()
        [$batch, $batchClip, $from, $cut] = [[], null, null, null];
        // the glass backdrops of this run: panes whose read areas nothing was drawn into share one, captured at the group's first pane
        $groups = GlassGroups::of($commands, $this->surface(), $this->commandBox(...));
        $groupOf = [];
        foreach ($groups as $g => $group) {
            foreach ($group['members'] as $member) {
                $groupOf[$member] = $g;
            }
        }
        $built = [];
        foreach ($commands as $index => $command) {
            $at = self::SHAPES[$command[0]] ?? null;
            if ($at !== null) {
                // commands recorded under one clip share its Region: cut it to the rows once
                if ($command[$at] !== $from) {
                    [$from, $cut] = [$command[$at], $command[$at]->intersect($rows)];
                }
                if (is_null($cut)) {
                    continue;
                }
                if ($batch !== [] && ($cut->x !== $batchClip->x || $cut->y !== $batchClip->y || $cut->width !== $batchClip->width || $cut->height !== $batchClip->height)) {
                    $this->rasterizer($batchClip)->fillBatch($batch, $target, $this->directFill);
                    $batch = [];
                }
                $batch[] = $command;
                $batchClip = $cut;

                continue;
            }
            if ($batch !== []) {
                $this->rasterizer($batchClip)->fillBatch($batch, $target, $this->directFill);
                $batch = [];
            }

            if ($command[0] === 'layer') {
                $this->layDown($command, $target, $rows);

                continue;
            }
            if ($command[0] === 'glass') {
                $g = $groupOf[$index];
                $panes = $built[$g] ??= $this->backdrop($groups[$g], $target);
                $clip = $command[6]->intersect($rows);
                if ($command[5] !== null) {
                    // the shadow, after the backdrop was read and before the pane: the pane covers its own rounded box
                    $shadow = $command[5];
                    $shadow[8] = $shadow[8]->intersect($rows);
                    if ($shadow[8] !== null) {
                        $this->rasterizer($shadow[8])->fillBatch([$shadow], $target, $this->directFill);
                    }
                }
                if (! is_null($clip)) {
                    $target->paintGlass($command[2], $panes['samples'][Glass::levels($command[4])], 2, $command[3][0] < 1.0 ? $panes['sharp'] : null, $panes['region']->x, $panes['region']->y, $command[3], $clip);
                }

                continue;
            }
            if ($command[0] === 'clear') {
                $word = $mapper->fromRgba8($command[1] >> 24, ($command[1] >> 16) & 0xFF, ($command[1] >> 8) & 0xFF, $command[1] & 0xFF);
                $area = isset($command[2]) ? $command[2]->intersect($rows) : null;
                match (true) {
                    ! isset($command[2]) => $target->fill($word),
                    ! is_null($area) => $target->setSegment($area->x, $area->y, $area->width, $area->height, $word),
                    default => null,
                };

                continue;
            }
            if ($command[0] === 'image') {
                [, $source, $placement, $opacity, $filter, $style, $clip] = $command;
                $coverage = null;
                if (isset($style['round'])) {
                    // rounded corners: the image paints through the coverage of the rounded box, as roundRect() would fill it
                    $area = $clip->intersect($rows);
                    if (is_null($area)) {
                        continue;
                    }
                    [$x0, $y0, $x1, $y1, $radii] = $style['round'];
                    $coverage = $this->rasterizer($area)->fillRoundRect($x0, $y0, $x1 - $x0, $y1 - $y0, $radii);
                    if ($coverage === '') {
                        continue;
                    }
                }
                $target->paintImage($source, $placement, $opacity, $filter, $clip, $style['tint'] ?? 0xFFFFFFFF, $style['window'] ?? null, $coverage);

                continue;
            }

            $clip = $command[array_key_last($command)]->intersect($rows);
            if (is_null($clip)) {
                continue;
            }
            if ($command[0] === 'spans') {
                $target->paintSpans($command[1], $command[2]);

                continue;
            }
            if ($command[0] === 'glyphs') {
                $masks = $this->glyphMasks($command[1], $command[2], $command[3]);
                if ($masks !== []) {
                    $target->paintMasks($masks, $command[4], $clip);
                }

                continue;
            }
            if ($command[0] === 'gradpath') {
                $spans = $this->rasterizer($clip)->fillPath($command[1], $command[2]);
                if ($spans !== '') {
                    [$g, $m] = [$command[3], $command[3]['inverse']];
                    $target->paintGradient($spans, $g['ramp'], $g['kind'], $g['spread'], [$m->a, $m->b, $m->c, $m->d, $m->e, $m->f]);
                }

                continue;
            }

            throw new DrawingException("Velvet does not know the command '{$command[0]}'.");
        }
        if ($batch !== []) {
            $this->rasterizer($batchClip)->fillBatch($batch, $target, $this->directFill);
        }
        $this->rasterizers = [];

        if ($target instanceof RingFramebuffer) {
            $target->present();
        }
    }

    /** The rasterizer of a clip already cut to the rows, made once a frame. */
    private function rasterizer(Region $clip): Rasterizer
    {
        return $this->rasterizers[$clip->x][$clip->y][$clip->width][$clip->height] ??= $this->rasterize->rasterizer($clip, $this->edges);
    }

    /**
     * Every glyph's mask at its place, for one paintMasks() call; the framebuffer cuts them to the clip.
     * shortcut: translucent text blends twice where two glyph boxes overlap; upgrade by merging the masks when that shows.
     *
     * @param  list<array{string, int, int, int, int}>  $glyphs
     * @return list<array{string, int, int, int}>
     */
    private function glyphMasks(TrueTypeFont $font, float $pixelSize, array $glyphs): array
    {
        $masks = [];
        foreach ($glyphs as [$face, $glyph, $left, $top, $style]) {
            $key = "{$face}|{$pixelSize}|{$glyph}|{$style}|{$this->edges->name}";
            if (array_key_exists($key, $this->glyphMasks)) {
                // a hit moves the glyph to the end: the front is the least recently used
                $mask = $this->glyphMasks[$key];
                unset($this->glyphMasks[$key]);
                $this->glyphMasks[$key] = $mask;
            } else {
                $mask = $this->glyphMask($font, $face, $pixelSize, $glyph, $style, $key);
            }
            if ($mask !== null) {
                $masks[] = [$mask[0], $mask[1], $left, $top];
            }
        }

        return $masks;
    }

    /**
     * A glyph's coverage in its final form, made once and kept: hard edges keep coverage of 128 and up, at 255.
     *
     * @return array{string, int}|null [coverage, width]; null when it covers nothing
     */
    private function glyphMask(TrueTypeFont $font, string $face, float $pixelSize, int $glyph, int $style, string $key): ?array
    {
        $mask = $font->mask($face, $pixelSize, $glyph, $style);
        $coverage = $mask->width > 0 && $mask->height > 0 ? substr($mask->coverage, 0, $mask->width * $mask->height) : '';
        if ($this->edges === Edges::HARD) {
            static $hard = null;
            $hard ??= [implode('', array_map('chr', range(0, 255))), str_repeat("\0", 128).str_repeat("\xFF", 128)];
            $coverage = strtr($coverage, $hard[0], $hard[1]);
        }
        $kept = $coverage === '' || strspn($coverage, "\0") === strlen($coverage) ? null : [$coverage, $mask->width];

        $size = strlen($kept[0] ?? '') + 64;
        while ($this->glyphMasks !== [] && $this->glyphBytes + $size > self::GLYPH_CACHE_BYTES) {
            $first = array_key_first($this->glyphMasks);
            $this->glyphBytes -= strlen($this->glyphMasks[$first][0] ?? '') + 64;
            unset($this->glyphMasks[$first]);
        }
        $this->glyphMasks[$key] = $kept;
        $this->glyphBytes += $size;

        return $kept;
    }

    /**
     * The spans in a gradient: a temporary RGBA image of the gradient's colour at every pixel the spans cover,
     * painted through those spans as their coverage, so the edge is the path's.
     *
     * @param  array{kind: string, inverse: Affine, spread: GradientSpread, ramp: string}  $gradient
     */

    /**
     * A layer: its backdrop read, filtered and laid back inside the rounded box; its children drawn over nothing in a frame of
     * their own, filtered, straightened and laid down at the layer's opacity through the same box. The pixel work is the
     * framebuffer's (filter, paintImage); this only orders it.
     *
     * @param  array<int, mixed>  $layer
     */
    private function layDown(array $layer, Framebuffer $target, Region $rows): void
    {
        [, $full, $geometry, $children, $alpha, $filter, $backdrop, $clip] = $layer;
        if ($target instanceof PagedFramebuffer) {
            throw DrawingException::badLayer('a framebuffer that holds one page of the surface cannot draw layers.');
        }
        $paint = $clip->intersect($rows);
        if (is_null($paint)) {
            return;
        }
        [$x0, $y0, $x1, $y1, $radii] = $geometry;
        $coverage = $this->rasterizer($paint)->fillRoundRect($x0, $y0, $x1 - $x0, $y1 - $y0, $radii);
        if ($coverage === '') {
            return;
        }
        $surface = $this->surface();
        $identity = Affine::identity();

        if (! is_null($backdrop)) {
            $outer = self::grown($full, $backdrop->reach(), $surface);
            $glass = $this->lend($target);
            $glass->writeRgba8($target->flushRegion($outer, FormatSpec::rgba8()), $outer->width, $outer->height, $outer->x, $outer->y);
            $glass->filter($backdrop, $outer);
            $target->paintImage($glass, $identity, 255, Filter::NEAREST, $paint, 0xFFFFFFFF, null, $coverage);
            $this->release($glass, $outer);
        }

        $content = $this->lend($target);
        $this->run($children, $content, $surface, PixelMapper::for($content->hostFormat()));
        $content->filter($filter ?? FilterChain::of(), $full, true);
        $target->paintImage($content, $identity, $alpha, Filter::NEAREST, $paint, 0xFFFFFFFF, null, $coverage);
        $this->release($content, $full);
    }

    /**
     * The backdrop under a group of glass panes: its region read from the target now and taken down a dual-filter pyramid
     * (1/2, 1/4, 1/8, 1/16 as the radii need), then up to half size for each depth a pane uses. At the top level the
     * pyramid of the same place in the last frame is kept while nothing under its region was redrawn.
     *
     * @param  array{region: Region, depths: list<int>, members: list<int>}  $group
     * @return array{region: Region, samples: array<int, Framebuffer>, sharp: Framebuffer}
     */
    private function backdrop(array $group, Framebuffer $target): array
    {
        if ($target instanceof PagedFramebuffer) {
            throw DrawingException::badLayer('a framebuffer that holds one page of the surface cannot draw glass.');
        }
        ['region' => $region, 'depths' => $depths] = $group;
        $top = $target === $this->framebuffer;
        $n = $top ? $this->backdrops++ : null;
        $tag = $top ? (string) $n : 'layer';
        $key = "{$region->x},{$region->y},{$region->width},{$region->height}|".implode(',', $depths);
        $kept = $top ? ($this->pyramids[$n] ?? null) : null;
        if ($kept !== null && $kept['key'] === $key && array_filter($this->damage(), fn (Region $d): bool => ! is_null($d->intersect($region))) === []) {
            foreach ($kept['levels'] as $level) {
                $this->used[$level] = true;
            }

            return $kept['built'];
        }

        $this->made = [];
        $sharp = $this->level("capture{$tag}", $region->width, $region->height);
        $sharp->writeRgba8($target->flushRegion($region, FormatSpec::rgba8()), $region->width, $region->height);
        $down = [$sharp];
        for ($k = 1, $deepest = max($depths); $k <= $deepest; $k++) {
            [$w, $h] = [$down[$k - 1]->viewportWidth(), $down[$k - 1]->viewportHeight()];
            $down[$k] = $this->level("down{$tag}.{$k}", intdiv($w + 1, 2), intdiv($h + 1, 2))->kawaseDown($down[$k - 1], new Region(0, 0, $w, $h));
        }
        $samples = [];
        foreach ($depths as $depth) {
            $up = $down[$depth];
            for ($k = $depth - 1; $k >= 1; $k--) {
                $up = $this->level("up{$tag}.{$depth}.{$k}", $down[$k]->viewportWidth(), $down[$k]->viewportHeight())->kawaseUp($up);
            }
            $samples[$depth] = $up;
        }
        $built = ['region' => $region, 'samples' => $samples, 'sharp' => $sharp];
        if ($top) {
            $this->pyramids[$n] = ['key' => $key, 'built' => $built, 'levels' => $this->made];
        }

        return $built;
    }

    /** A pyramid level: an RGBA8 frame of the framebuffer's kind, made once for its role and size and kept. */
    private function level(string $role, int $width, int $height): Framebuffer
    {
        $key = "{$role}:{$width}x{$height}";
        $this->used[$key] = true;
        $this->made[] = $key;

        return $this->levels[$key] ??= $this->framebuffer->scratch($width, $height);
    }

    /** A blank RGBA8 frame the size of the surface, the same kind as $like. */
    private function lend(Framebuffer $like): Framebuffer
    {
        $this->scratches[$this->lent] ??= $like->scratch($this->width(), $this->height());

        return $this->scratches[$this->lent++];
    }

    /** Hands a frame back blank where the layer used it. */
    private function release(Framebuffer $scratch, Region $used): void
    {
        $scratch->setSegment($used->x, $used->y, $used->width, $used->height, 0);
        $this->lent--;
    }
}
