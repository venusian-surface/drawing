<?php

namespace Surface\Drawing;

use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\RenderingEngine as RenderingEngineContract;
use Surface\Contracts\Fonts\GFXFont;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\PagedFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\FillRule;
use Surface\Drawing\Text\PlacedGlyph;
use Surface\Drawing\Text\Typesetter;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\Color;
use Throwable;

/**
 * What every rendering engine shares: frames, the transform and clip state,
 * and the lowering of each draw call to a command in framebuffer pixels. An
 * engine says what it is called, where it draws, and how it carries out a
 * frame's commands (execute); everything a caller touches is written here once.
 *
 * Commands, in call order, points already transformed, colours 0xRRGGBBAA,
 * every clip a rect inside the surface:
 *
 *     ['clear', rgba]                                     the whole surface
 *     ['clear', rgba, Region]                             only that region (a partial frame)
 *     ['path', contours, FillRule, rgba, clip]            contours: list of lists of [x, y]
 *     ['polyline', points, stroke, closed, rgba, clip]
 *     ['ellipse', cx, cy, rx, ry, rgba, clip]             axis-aligned
 *     ['ring', cx, cy, rx, ry, stroke, rgba, clip]        the band between radii r ± stroke / 2
 *     ['image', Framebuffer, Affine, opacity 1..255, Filter, clip]
 *     ['spans', bytes, rgba, clip]                        whole pixels, already inside the clip, rows top to bottom; 7 bytes each: y, x, length (uint16 LE), coverage 255
 */
abstract class RenderingEngine implements RenderingEngineContract
{
    /** A turned ellipse becomes a polygon that strays from it by less than this many pixels. */
    protected const float FLATNESS = 0.1;

    /** @var list<array<int, mixed>>|null The open frame's commands; null between frames. */
    private ?array $recording = null;

    /** @var list<array<int, mixed>>|null The last ended frame. */
    private ?array $last = null;

    private ?Affine $matrix = null;

    private ?Region $clip = null;

    /** @var list<array{Affine, Region}> */
    private array $stack = [];

    private ?Typesetter $typesetter = null;

    /** The next frame is drawn and reported whole. */
    private bool $whole = true;

    /** @var list<Region> Where the last ended frame changed the framebuffer. */
    private array $damage = [];

    /**
     * Carry out one frame.
     *
     * @param  list<array<int, mixed>>  $commands
     */
    abstract protected function execute(array $commands): void;

    public function width(): int
    {
        return $this->framebuffer()->viewportWidth();
    }

    public function height(): int
    {
        return $this->framebuffer()->viewportHeight();
    }

    public function begin(): static
    {
        if ($this->drawing()) {
            throw new DrawingException('A frame is already open: end() it before begin().');
        }
        $this->recording = [];
        $this->matrix = Affine::identity();
        $this->clip = $this->surface();
        $this->stack = [];

        return $this;
    }

    public function end(): static
    {
        $this->open('end');
        $commands = $this->recording;
        $before = $this->whole ? null : $this->last;
        $this->last = $commands;
        $this->whole = false;
        $this->close();

        $framebuffer = $this->framebuffer();
        if (is_null($before) || $framebuffer instanceof PagedFramebuffer) {
            $this->damage = [$this->surface()];
            $this->execute($commands);

            return $this;
        }

        // A frame that clears starts from nothing, so outside what changed it
        // repaints the pixels already there. One that does not draws over
        // them: all of it is drawn.
        if (($commands[0][0] ?? null) !== 'clear' || isset($commands[0][2])) {
            $this->damage = $this->merge(array_values(array_filter(array_map($this->box(...), $commands))));
            $this->execute($commands);

            return $this;
        }

        $this->damage = $this->changed($before, $commands);
        if ($this->damage !== []) {
            $this->execute($this->keepsFrame($framebuffer) ? $this->scissor($commands, $this->damage) : $commands);
        }

        return $this;
    }

    public function damage(): array
    {
        return $this->damage;
    }

    public function invalidate(): static
    {
        $this->whole = true;

        return $this;
    }

    public function frame(callable $draw): static
    {
        $this->begin();
        try {
            $draw($this);
        } catch (Throwable $e) {
            $this->close();

            throw $e;
        }

        return $this->end();
    }

    public function replay(): static
    {
        if ($this->drawing()) {
            throw new DrawingException('replay() comes after end(): a frame is open.');
        }
        if (is_null($this->last)) {
            throw new DrawingException('There is no frame to replay: none has ended yet.');
        }
        $this->execute($this->last);

        return $this;
    }

    public function drawing(): bool
    {
        return ! is_null($this->recording);
    }

    public function push(): static
    {
        $this->open('push');
        $this->stack[] = [$this->matrix, $this->clip];

        return $this;
    }

    public function pop(): static
    {
        $this->open('pop');
        if ($this->stack === []) {
            throw new DrawingException('pop() without a push().');
        }
        [$this->matrix, $this->clip] = array_pop($this->stack);

        return $this;
    }

    public function translate(float $x, float $y): static
    {
        $this->open('translate');
        self::finite(['x' => $x, 'y' => $y]);

        return $this->compose(Affine::translation($x, $y));
    }

    public function scale(float $x, ?float $y = null): static
    {
        $this->open('scale');
        self::finite(['x' => $x, 'y' => $y ?? $x]);

        return $this->compose(Affine::scaling($x, $y ?? $x));
    }

    public function rotate(float $radians): static
    {
        $this->open('rotate');
        self::finite(['radians' => $radians]);

        return $this->compose(Affine::rotation($radians));
    }

    public function transform(Affine $matrix): static
    {
        $this->open('transform');

        return $this->compose($matrix);
    }

    public function matrix(): Affine
    {
        return $this->matrix ?? Affine::identity();
    }

    public function clip(?Region $region): static
    {
        $this->open('clip');
        $this->clip = is_null($region) ? $this->surface() : ($region->intersect($this->surface()) ?? new Region(0, 0, 0, 0));

        return $this;
    }

    public function clipRegion(): Region
    {
        return $this->clip ?? $this->surface();
    }

    public function clear(Color $color): static
    {
        $this->open('clear');
        $this->recording[] = ['clear', self::rgba($color)];

        return $this;
    }

    public function fillRect(float $x, float $y, float $width, float $height, Color $color): static
    {
        $this->open('fillRect');
        self::finite(['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height]);
        if ($width <= 0 || $height <= 0) {
            return $this;
        }

        return $this->path([$this->corners($x, $y, $width, $height)], FillRule::NON_ZERO, $color);
    }

    public function strokeRect(float $x, float $y, float $width, float $height, Color $color, float $stroke = 1.0): static
    {
        $this->open('strokeRect');
        self::finite(['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height, 'stroke' => $stroke]);
        if ($width <= 0 || $height <= 0) {
            return $this;
        }

        return $this->stroked($this->corners($x, $y, $width, $height), $stroke, true, $color);
    }

    public function line(float $x0, float $y0, float $x1, float $y1, Color $color, float $stroke = 1.0): static
    {
        $this->open('line');
        self::finite(['x0' => $x0, 'y0' => $y0, 'x1' => $x1, 'y1' => $y1, 'stroke' => $stroke]);

        return $this->stroked([$this->point($x0, $y0, 'x0', 'y0'), $this->point($x1, $y1, 'x1', 'y1')], $stroke, false, $color);
    }

    public function polyline(array $points, Color $color, float $stroke = 1.0, bool $closed = false): static
    {
        $this->open('polyline');
        self::finite(['stroke' => $stroke]);
        $points = $this->points($points);

        return $points === [] ? $this : $this->stroked($points, $stroke, $closed, $color);
    }

    public function fillTriangle(float $x0, float $y0, float $x1, float $y1, float $x2, float $y2, Color $color): static
    {
        $this->open('fillTriangle');
        self::finite(['x0' => $x0, 'y0' => $y0, 'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2]);

        return $this->path([[$this->point($x0, $y0, 'x0', 'y0'), $this->point($x1, $y1, 'x1', 'y1'), $this->point($x2, $y2, 'x2', 'y2')]], FillRule::NON_ZERO, $color);
    }

    public function fillPolygon(array $points, Color $color, FillRule $rule = FillRule::NON_ZERO): static
    {
        $this->open('fillPolygon');
        $points = $this->points($points);

        return count($points) < 3 ? $this : $this->path([$points], $rule, $color);
    }

    public function fillPath(array $contours, Color $color, FillRule $rule = FillRule::NON_ZERO): static
    {
        $this->open('fillPath');
        $kept = [];
        foreach (array_values($contours) as $position => $contour) {
            if (! is_array($contour) || ! array_is_list($contour)) {
                throw new DrawingException("Contour {$position} is not a list of points.");
            }
            $points = $this->points($contour);
            if (count($points) >= 3) {
                $kept[] = $points;
            }
        }

        return $kept === [] ? $this : $this->path($kept, $rule, $color);
    }

    public function fillEllipse(float $cx, float $cy, float $rx, float $ry, Color $color): static
    {
        $this->open('fillEllipse');
        self::finite(['cx' => $cx, 'cy' => $cy, 'rx' => $rx, 'ry' => $ry]);
        if ($rx <= 0 || $ry <= 0) {
            return $this;
        }
        if (! $this->matrix->isAxisAligned()) {
            return $this->path([$this->flatten($cx, $cy, $rx, $ry)], FillRule::NON_ZERO, $color);
        }

        [$x, $y, $radius_x, $radius_y] = $this->upright($cx, $cy, $rx, $ry);
        if ($radius_x > 0 && $radius_y > 0 && ! $this->clip->isEmpty()) {
            $this->recording[] = ['ellipse', $x, $y, $radius_x, $radius_y, self::rgba($color), $this->clip];
        }

        return $this;
    }

    public function strokeEllipse(float $cx, float $cy, float $rx, float $ry, Color $color, float $stroke = 1.0): static
    {
        $this->open('strokeEllipse');
        self::finite(['cx' => $cx, 'cy' => $cy, 'rx' => $rx, 'ry' => $ry, 'stroke' => $stroke]);
        if ($rx <= 0 || $ry <= 0) {
            return $this;
        }
        if (! $this->matrix->isAxisAligned()) {
            return $this->stroked($this->flatten($cx, $cy, $rx, $ry), $stroke, true, $color);
        }

        [$x, $y, $radius_x, $radius_y] = $this->upright($cx, $cy, $rx, $ry);
        $stroke = $this->strokeWidth($stroke);
        if ($radius_x > 0 && $radius_y > 0 && $stroke > 0 && ! $this->clip->isEmpty()) {
            $this->recording[] = ['ring', $x, $y, $radius_x, $radius_y, $stroke, self::rgba($color), $this->clip];
        }

        return $this;
    }

    public function image(Framebuffer $source, float $x, float $y, ?float $width = null, ?float $height = null, float $opacity = 1.0, Filter $filter = Filter::NEAREST): static
    {
        $this->open('image');
        $width ??= (float) $source->viewportWidth();
        $height ??= (float) $source->viewportHeight();
        self::finite(['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height, 'opacity' => $opacity]);
        if ($opacity < 0.0 || $opacity > 1.0) {
            throw new DrawingException("The opacity is 0..1, got {$opacity}.");
        }
        $alpha = (int) round($opacity * 255);
        if ($width <= 0 || $height <= 0 || $alpha === 0 || $this->clip->isEmpty()) {
            return $this;
        }

        // Its four corners, to hold the placed image to the same limit as every other shape.
        $this->corners($x, $y, $width, $height);
        $placement = $this->matrix
            ->multiply(Affine::translation($x, $y))
            ->multiply(Affine::scaling($width / $source->viewportWidth(), $height / $source->viewportHeight()));
        if (! is_null($placement->inverse())) {
            $this->recording[] = ['image', $source, $placement, $alpha, $filter, $this->clip];
        }

        return $this;
    }

    /** The whole surface, as a clip. */
    public function text(string $text, float $x, float $y, Color $color, GFXFont $font): static
    {
        $this->open('text');
        self::finite(['x' => $x, 'y' => $y]);

        $typesetter = $this->typesetter ??= new Typesetter;
        $placed_glyphs = $typesetter->layout($font, $text);

        // Upright, at a whole-number scale, on whole pixels, in an opaque
        // colour: every glyph pixel is a block of whole framebuffer pixels,
        // so the spans are written here and nothing is rasterised.
        $m = $this->matrix;
        [$left, $top] = $this->point($x, $y);
        if ($m->b === 0.0 && $m->c === 0.0 && $m->a >= 1.0 && $m->d >= 1.0 && $color->alpha >= 1.0
            && floor($m->a) === $m->a && floor($m->d) === $m->d && floor($left) === $left && floor($top) === $top) {
            return $this->blocks($placed_glyphs, $font, (int) $left, (int) $top, (int) $m->a, (int) $m->d, $color);
        }

        // Otherwise every run of inked pixels in every glyph is one rectangle
        // of one path, so a text is rasterised once, under the transform.
        $contours = [];
        foreach ($placed_glyphs as $placed) {
            foreach ($typesetter->runs($font, $placed->glyph) as [$row, $first, $last]) {
                $contours[] = $this->corners($x + $placed->x + $first, $y + $placed->y + $row, $last - $first + 1, 1);
            }
        }

        return $contours === [] ? $this : $this->path($contours, FillRule::NON_ZERO, $color);
    }

    /**
     * @param  list<PlacedGlyph>  $placed_glyphs
     */
    private function blocks(array $placed_glyphs, GFXFont $font, int $left, int $top, int $sx, int $sy, Color $color): static
    {
        $clip = $this->clip;
        $clip_right = $clip->x + $clip->width;
        $clip_bottom = $clip->y + $clip->height;
        /** @var array<int, list<string>> $rows spans by row, so the list runs top to bottom */
        $rows = [];

        foreach ($placed_glyphs as $placed) {
            foreach ($this->typesetter->runs($font, $placed->glyph) as [$row, $first, $last]) {
                $x0 = max($left + ($placed->x + $first) * $sx, $clip->x);
                $x1 = min($left + ($placed->x + $last + 1) * $sx, $clip_right);
                $y0 = max($top + ($placed->y + $row) * $sy, $clip->y);
                $y1 = min($top + ($placed->y + $row + 1) * $sy, $clip_bottom);
                if ($x1 <= $x0) {
                    continue;
                }
                for ($line = $y0; $line < $y1; $line++) {
                    $rows[$line][] = pack('vvvC', $line, $x0, $x1 - $x0, 255);
                }
            }
        }

        if ($rows !== []) {
            ksort($rows);
            $this->recording[] = ['spans', implode('', array_merge(...$rows)), self::rgba($color), $clip];
        }

        return $this;
    }

    public function textBounds(string $text, GFXFont $font): array
    {
        return array_map(floatval(...), ($this->typesetter ??= new Typesetter)->bounds($font, $text));
    }

    /**
     * Whether the framebuffer still holds the last frame when the next is
     * drawn, so drawing only what changed leaves the rest correct. An engine
     * that brings a framebuffer up to date itself before drawing (a ring it
     * repairs) answers true for it.
     */
    protected function keepsFrame(Framebuffer $framebuffer): bool
    {
        return $framebuffer->preservesContentsOnPresent();
    }

    /**
     * Where two frames differ. Commands are compared by position: one equal to
     * the command at the same place in the last frame changed nothing. Every
     * other command marks its box, and the box of the command it replaced. An
     * image is never equal, because its source may hold new pixels.
     *
     * @param  list<array<int, mixed>>  $before
     * @param  list<array<int, mixed>>  $after
     * @return list<Region>
     */
    private function changed(array $before, array $after): array
    {
        $boxes = [];
        for ($i = 0, $n = max(count($before), count($after)); $i < $n; $i++) {
            $old = $before[$i] ?? null;
            $new = $after[$i] ?? null;
            if (! is_null($old) && ! is_null($new) && $new[0] !== 'image' && $old == $new) {
                continue;
            }
            foreach ([$old, $new] as $command) {
                if (! is_null($command) && ! is_null($box = $this->box($command))) {
                    $boxes[] = $box;
                }
            }
        }

        return $this->merge($boxes);
    }

    /**
     * The whole pixels a command can write, cut by its clip: its geometry,
     * grown by a pixel for anti-aliased edges, and for a stroke by the miter
     * corners, which reach four half-strokes out. Null when it writes nothing.
     *
     * @param  array<int, mixed>  $command
     */
    private function box(array $command): ?Region
    {
        $surface = $this->surface();
        if ($command[0] === 'clear') {
            return $command[2] ?? $surface;
        }

        [$x0, $y0, $x1, $y1] = match ($command[0]) {
            'path' => self::extent(array_merge(...$command[1]), 1.0),
            'polyline' => self::extent($command[1], 2.0 * $command[2] + 1.0),
            'ellipse' => [$command[1] - $command[3] - 1.0, $command[2] - $command[4] - 1.0, $command[1] + $command[3] + 1.0, $command[2] + $command[4] + 1.0],
            'ring' => [$command[1] - $command[3] - $command[5] / 2 - 1.0, $command[2] - $command[4] - $command[5] / 2 - 1.0, $command[1] + $command[3] + $command[5] / 2 + 1.0, $command[2] + $command[4] + $command[5] / 2 + 1.0],
            'image' => self::extent(self::placedCorners($command[1], $command[2]), 1.0),
            'spans' => self::spansExtent($command[1]),
        };
        $x0 = (int) max(floor($x0), 0);
        $y0 = (int) max(floor($y0), 0);
        $x1 = (int) min(ceil($x1), $surface->width);
        $y1 = (int) min(ceil($y1), $surface->height);
        if ($x1 <= $x0 || $y1 <= $y0) {
            return null;
        }

        return (new Region($x0, $y0, $x1 - $x0, $y1 - $y0))->intersect($command[array_key_last($command)]);
    }

    /**
     * Snapped to the framebuffer's damage granularity, and merged until no two
     * overlap: drawing a frame into each region once draws no pixel twice.
     *
     * @param  list<Region>  $boxes
     * @return list<Region>
     */
    private function merge(array $boxes): array
    {
        $granularity = $this->framebuffer()->damageGranularity();
        $merged = [];
        foreach ($boxes as $box) {
            $box = $box->snap($granularity);
            do {
                $grew = false;
                foreach ($merged as $index => $kept) {
                    if (! is_null($kept->intersect($box))) {
                        $box = $box->union($kept)->snap($granularity);
                        unset($merged[$index]);
                        $grew = true;
                    }
                }
            } while ($grew);
            $merged[] = $box;
        }

        return array_values($merged);
    }

    /**
     * The frame drawn into each region alone: every clip cut to the region, a
     * clear limited to it, spans trimmed to it. Commands that miss a region
     * are left out of it.
     *
     * @param  list<array<int, mixed>>  $commands
     * @param  list<Region>  $regions
     * @return list<array<int, mixed>>
     */
    private function scissor(array $commands, array $regions): array
    {
        $out = [];
        foreach ($regions as $region) {
            foreach ($commands as $command) {
                if ($command[0] === 'clear') {
                    $area = isset($command[2]) ? $command[2]->intersect($region) : $region;
                    if (! is_null($area)) {
                        $out[] = ['clear', $command[1], $area];
                    }

                    continue;
                }

                $last = array_key_last($command);
                $clip = $command[$last]->intersect($region);
                if (is_null($clip)) {
                    continue;
                }
                $command[$last] = $clip;
                if ($command[0] === 'spans') {
                    $command[1] = self::trimSpans($command[1], $clip);
                    if ($command[1] === '') {
                        continue;
                    }
                }
                $out[] = $command;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{float, float}>  $points
     * @return array{float, float, float, float} x0, y0, x1, y1 grown by $grow
     */
    private static function extent(array $points, float $grow): array
    {
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);

        return [min($xs) - $grow, min($ys) - $grow, max($xs) + $grow, max($ys) + $grow];
    }

    /** @return list<array{float, float}> An image source's corners, placed. */
    private static function placedCorners(Framebuffer $source, Affine $placement): array
    {
        $width = (float) $source->viewportWidth();
        $height = (float) $source->viewportHeight();

        return [$placement->apply(0.0, 0.0), $placement->apply($width, 0.0), $placement->apply($width, $height), $placement->apply(0.0, $height)];
    }

    /** @return array{float, float, float, float} The pixels a span list covers. */
    private static function spansExtent(string $spans): array
    {
        $x0 = $y0 = PHP_INT_MAX;
        $x1 = $y1 = 0;
        for ($at = 0, $length = strlen($spans); $at < $length; $at += 7) {
            $y = ord($spans[$at]) | (ord($spans[$at + 1]) << 8);
            $x = ord($spans[$at + 2]) | (ord($spans[$at + 3]) << 8);
            $right = $x + (ord($spans[$at + 4]) | (ord($spans[$at + 5]) << 8));
            if ($x < $x0) {
                $x0 = $x;
            }
            if ($right > $x1) {
                $x1 = $right;
            }
            if ($y < $y0) {
                $y0 = $y;
            }
            if ($y >= $y1) {
                $y1 = $y + 1;
            }
        }

        return [(float) $x0, (float) $y0, (float) $x1, (float) $y1];
    }

    /**
     * Spans cut to $region; a span outside it is dropped, one wholly inside is
     * copied as it is. The list runs top to bottom, so the first row inside is
     * found by halving and the walk stops below the region.
     */
    private static function trimSpans(string $spans, Region $region): string
    {
        $left = $region->x;
        $top = $region->y;
        $right = $region->right();
        $bottom = $region->bottom();

        $low = 0;
        $high = intdiv(strlen($spans), 7);
        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            $at = $middle * 7;
            if ((ord($spans[$at]) | (ord($spans[$at + 1]) << 8)) < $top) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $out = '';
        for ($at = $low * 7, $length = strlen($spans); $at < $length; $at += 7) {
            $y = ord($spans[$at]) | (ord($spans[$at + 1]) << 8);
            if ($y >= $bottom) {
                break;
            }
            $x = ord($spans[$at + 2]) | (ord($spans[$at + 3]) << 8);
            $end = $x + (ord($spans[$at + 4]) | (ord($spans[$at + 5]) << 8));
            if ($end <= $left || $x >= $right) {
                continue;
            }
            if ($x >= $left && $end <= $right) {
                $out .= substr($spans, $at, 7);

                continue;
            }
            $start = max($x, $left);
            $out .= pack('vvvC', $y, $start, min($end, $right) - $start, ord($spans[$at + 6]));
        }

        return $out;
    }

    protected function surface(): Region
    {
        return Region::wholeSurface($this->width(), $this->height());
    }

    /** @throws DrawingException When no frame is open. */
    private function open(string $call): void
    {
        if (! $this->drawing()) {
            throw DrawingException::outsideFrame($call);
        }
    }

    private function compose(Affine $matrix): static
    {
        $this->matrix = $this->matrix->multiply($matrix);

        return $this;
    }

    private function close(): void
    {
        $this->recording = null;
        $this->matrix = null;
        $this->clip = null;
        $this->stack = [];
    }

    /** @param list<list<array{float, float}>> $contours Already transformed. */
    private function path(array $contours, FillRule $rule, Color $color): static
    {
        if (! $this->clip->isEmpty()) {
            $this->recording[] = ['path', $contours, $rule, self::rgba($color), $this->clip];
        }

        return $this;
    }

    /** @param list<array{float, float}> $points Already transformed. */
    private function stroked(array $points, float $stroke, bool $closed, Color $color): static
    {
        $stroke = $this->strokeWidth($stroke);
        if ($stroke > 0 && ! $this->clip->isEmpty()) {
            $this->recording[] = ['polyline', $points, $stroke, $closed, self::rgba($color), $this->clip];
        }

        return $this;
    }

    /** A stroke as wide as the transform makes it: scaled by the square root of the area it scales by. */
    private function strokeWidth(float $stroke): float
    {
        $scaled = $stroke * sqrt(abs($this->matrix->determinant()));
        if (abs($scaled) > self::LIMIT) {
            throw DrawingException::pastLimit('stroke', $scaled);
        }

        return $scaled;
    }

    /** @return array{float, float} A point through the transform, held to the limit. */
    private function point(float $x, float $y, string $x_name = 'x', string $y_name = 'y'): array
    {
        [$tx, $ty] = $this->matrix->apply($x, $y);
        foreach ([$x_name => $tx, $y_name => $ty] as $name => $value) {
            if (! is_finite($value) || abs($value) > self::LIMIT) {
                throw DrawingException::pastLimit($name, $value);
            }
        }

        return [$tx, $ty];
    }

    /** @return list<array{float, float}> A rect's corners through the transform: top-left, top-right, bottom-right, bottom-left. */
    private function corners(float $x, float $y, float $width, float $height): array
    {
        return [$this->point($x, $y), $this->point($x + $width, $y), $this->point($x + $width, $y + $height), $this->point($x, $y + $height)];
    }

    /**
     * [x, y] points, each checked, through the transform.
     *
     * @return list<array{float, float}>
     */
    private function points(array $points): array
    {
        $out = [];
        foreach (array_values($points) as $position => $point) {
            if (! is_array($point) || ! array_is_list($point) || count($point) !== 2
                || ! (is_int($point[0]) || is_float($point[0])) || ! (is_int($point[1]) || is_float($point[1]))) {
                throw DrawingException::notAPoint($position);
            }
            self::finite(["Point {$position} x" => (float) $point[0], "Point {$position} y" => (float) $point[1]]);
            $out[] = $this->point((float) $point[0], (float) $point[1], "Point {$position} x", "Point {$position} y");
        }

        return $out;
    }

    /**
     * An ellipse under a transform that keeps its axes: its centre and radii in framebuffer pixels.
     *
     * @return array{float, float, float, float}
     */
    private function upright(float $cx, float $cy, float $rx, float $ry): array
    {
        [$x, $y] = $this->point($cx, $cy, 'cx', 'cy');
        $radius_x = $rx * abs($this->matrix->a);
        $radius_y = $ry * abs($this->matrix->d);
        foreach (['rx' => $radius_x, 'ry' => $radius_y] as $name => $radius) {
            if ($radius > self::LIMIT) {
                throw DrawingException::pastLimit($name, $radius);
            }
        }

        return [$x, $y, $radius_x, $radius_y];
    }

    /**
     * An ellipse as a polygon through the transform, for transforms that turn or shear it. A polygon of n sides strays
     * from a circle of radius r by r·(1 − cos(π / n)), about r·π² / 2n², so n = π·sqrt(r / 2·FLATNESS) sides keep it within FLATNESS.
     *
     * @return list<array{float, float}>
     */
    private function flatten(float $cx, float $cy, float $rx, float $ry): array
    {
        $m = $this->matrix;
        $radius = max($rx, $ry) * sqrt(max($m->a * $m->a + $m->b * $m->b, $m->c * $m->c + $m->d * $m->d));
        $sides = (int) max(12, min(1024, ceil(M_PI * sqrt($radius / (2 * self::FLATNESS)) * 1.01)));

        $points = [];
        for ($i = 0; $i < $sides; $i++) {
            $angle = 2 * M_PI * $i / $sides;
            $points[] = $this->point($cx + $rx * cos($angle), $cy + $ry * sin($angle), 'cx', 'cy');
        }

        return $points;
    }

    /** @param array<string, float> $numbers */
    private static function finite(array $numbers): void
    {
        foreach ($numbers as $name => $value) {
            if (! is_finite($value)) {
                throw DrawingException::notFinite($name);
            }
        }
    }

    private static function rgba(Color $color): int
    {
        return ((int) round($color->red * 255) << 24) | ((int) round($color->green * 255) << 16) | ((int) round($color->blue * 255) << 8) | (int) round($color->alpha * 255);
    }
}
