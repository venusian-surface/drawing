<?php

namespace Surface\Drawing\Gpu;

use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\Effects\Displace;
use Surface\Contracts\Drawing\Effects\FilterChain;
use Surface\Contracts\Drawing\Effects\Glass;
use Surface\Contracts\Drawing\Effects\Noise;
use Surface\Contracts\Drawing\Effects\NoiseType;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Framebuffers\Spans;
use Surface\Drawing\GlassGroups;
use Surface\Contracts\Rasterize\FillRule;
use Surface\Framebuffers\Native\NativeFilters;
use Surface\Framebuffers\Native\NativeFullFramebuffer;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\Gradient;
use Surface\NutsAndBolts\GradientSpread;
use Surface\Rasterize\Stroker;

/**
 * A frame's commands (RenderingEngine) as a DrawList, the same for every GPU
 * device and both edge modes. Paths are filled stencil-then-cover from
 * triangle lists: a contour of n points is the n − 2 triangles from its first
 * point, so nothing is tessellated and the cost is copying the points.
 */
final class Lowering
{
    /** Floats packed per pack() call. */
    private const int CHUNK = 4096;

    private string $vertices = '';

    /** @var list<array<int, mixed>> */
    private array $operations = [];

    private ?Region $scissor = null;

    /** Layer targets handed out so far this frame; 0 is the frame's own target. */
    private int $slots = 0;

    /** The target the operations draw into now. */
    private int $slot = 0;

    private Region $surface;

    /** @var array<int, int> Texture number by the source's object id. */
    private array $textures = [];

    /** @var list<array{region: Region, depths: list<int>, members: list<int>}> The backdrop groups of the list being lowered (GlassGroups). */
    private array $groups = [];

    /** @var array<int, int> Group by member command index, in the list being lowered. */
    private array $memberOf = [];

    /** The group whose backdrop the next panes draw from; null before the first. */
    private ?int $group = null;

    /** The index of the command being lowered in its list. */
    private int $at = 0;

    /** @var (\Closure(array<int, mixed>): ?Region)|null How far a command's pixels reach, for GlassGroups; null: every pane its own backdrop. */
    private ?\Closure $box = null;

    /** @var list<string> This frame's backdrops, by region and depths, in order: next frame's $previous. */
    private array $backdrops = [];

    /** @var list<string> The last frame's backdrops. */
    private array $previous = [];

    /** @var list<Region> This frame's damage. */
    private array $damage = [];

    private function __construct(private readonly ?GlyphAtlas $atlas, int $width, int $height, private readonly bool $glass = false)
    {
        $this->surface = Region::wholeSurface($width, $height);
    }

    /**
     * A first pass places every glyph mask of the frame, so all ATLAS and MASK operations come before any draw.
     *
     * @param  list<array<int, mixed>>  $commands
     * @param  list<string>  $backdrops  In: the last frame's glass backdrops (this method's own answer then); out: this frame's.
     * @param  list<Region>  $damage  This frame's damage: a backdrop it misses, of the region and depths it had last frame, is kept.
     * @param  (\Closure(array<int, mixed>): ?Region)|null  $box  How far a command's pixels reach (RenderingEngine::commandBox()): panes
     *                                                              whose read areas nothing was drawn into share a backdrop. Null: each pane has its own.
     *
     * @throws DrawingException When a frame has glyphs and no atlas is given, or its masks do not fit one.
     */
    public static function lower(array $commands, int $width, int $height, ?GlyphAtlas $atlas = null, bool $glass = false, array &$backdrops = [], array $damage = [], ?\Closure $box = null): DrawList
    {
        $lowering = new self($atlas, $width, $height, $glass);
        [$lowering->previous, $lowering->damage, $lowering->box] = [$backdrops, $damage, $box];
        $lowering->placeGlyphs($commands);
        $lowering->plan($commands);
        foreach ($commands as $i => $command) {
            $lowering->at = $i;
            $lowering->command($command);
        }

        $backdrops = $lowering->backdrops;

        return new DrawList($width, $height, $lowering->vertices, $lowering->operations);
    }

    /** @param array<int, mixed> $command */
    private function command(array $command): void
    {
        switch ($command[0]) {
            case 'clear':
                if (! isset($command[2])) {
                    $this->operations[] = [Op::CLEAR, $command[1]];

                    return;
                }
                $region = $command[2];
                $this->clip($region);
                $this->operations[] = [Op::SOLID, $this->quad($region->x, $region->y, $region->right(), $region->bottom()), $command[1]];

                return;

            case 'path':
                $this->fill($command[1], $command[2], $command[3], $command[4]);

                return;

            case 'layer':
                $this->layer($command);

                return;

            case 'glass':
                if ($this->glass) {
                    $this->pane($command);
                } else {
                    $command[5] === null || $this->command($command[5]);
                    $this->layer(self::glassLayer($command));
                }

                return;

            case 'gradpath':
                $this->fill($command[1], $command[2], 0, $command[4], $command[3]);

                return;

            case 'polyline':
                $outline = array_map(fn (array $flat): array => array_chunk($flat, 2), Stroker::outline($command[1], $command[2], $command[3]));
                $this->fill($outline, FillRule::NON_ZERO, $command[4], $command[5]);

                return;

            case 'ellipse':
                [, $cx, $cy, $rx, $ry, $rgba, $clip] = $command;
                $this->clip($clip);
                $this->operations[] = [Op::ELLIPSE, $this->quad($cx - $rx - 1.0, $cy - $ry - 1.0, $cx + $rx + 1.0, $cy + $ry + 1.0), $cx, $cy, $rx, $ry, $rgba];

                return;

            case 'ring':
                [, $cx, $cy, $rx, $ry, $stroke, $rgba, $clip] = $command;
                $reach_x = $rx + $stroke / 2 + 1.0;
                $reach_y = $ry + $stroke / 2 + 1.0;
                $this->clip($clip);
                $this->operations[] = [Op::RING, $this->quad($cx - $reach_x, $cy - $reach_y, $cx + $reach_x, $cy + $reach_y), $cx, $cy, $rx, $ry, $stroke, $rgba];

                return;

            case 'image':
                [, $source, $placement, $opacity, $filter, $style, $clip] = $command;
                $id = spl_object_id($source);
                if (! isset($this->textures[$id])) {
                    $this->textures[$id] = count($this->textures);
                    $this->operations[] = [Op::UPLOAD, $this->textures[$id], $source];
                }
                // the quad covers the window's footprint, or the whole source's
                [$u0, $v0, $u1, $v1] = $style['window'] ?? [0.0, 0.0, (float) $source->viewportWidth(), (float) $source->viewportHeight()];
                [$ax, $ay] = $placement->apply($u0, $v0);
                [$bx, $by] = $placement->apply($u1, $v0);
                [$cx, $cy] = $placement->apply($u1, $v1);
                [$dx, $dy] = $placement->apply($u0, $v1);
                $this->clip($clip);
                $this->operations[] = [Op::IMAGE, $this->push([$ax, $ay, $bx, $by, $cx, $cy, $ax, $ay, $cx, $cy, $dx, $dy]), $this->textures[$id], $placement->inverse(), $opacity, $filter,
                    $style['tint'] ?? 0xFFFFFFFF, $style['window'] ?? null, $style['round'] ?? null];

                return;

            case 'glyphs':
                [, $font, $pixelSize, $glyphs, $rgba, , $clip] = $command;
                $quads = [];
                $texels = [];
                foreach ($glyphs as [$face, $glyph, $left, $top, $style]) {
                    $at = $this->atlas->where(GlyphAtlas::key($face, $pixelSize, $glyph, $style));
                    array_push($quads, ...self::corners($left, $top, $left + $at->width, $top + $at->height));
                    array_push($texels, ...self::corners($at->x, $at->y, $at->right(), $at->bottom()));
                }
                $this->clip($clip);
                $first = $this->push($quads);
                $this->operations[] = [Op::GLYPHS, $first, intdiv(count($quads), 2), $this->push($texels), GlyphAtlas::TEXTURE, $rgba];

                return;

            case 'roundrect':
                [, $x0, $y0, $x1, $y1, $radii, $rgba, $clip] = $command;
                $this->clip($clip);
                $this->operations[] = [Op::ROUNDRECT, $this->quad($x0 - 1.0, $y0 - 1.0, $x1 + 1.0, $y1 + 1.0), $x0, $y0, $x1, $y1, $radii, $rgba];

                return;

            case 'roundring':
                [, $x0, $y0, $x1, $y1, $radii, $inset, $rgba, $clip] = $command;
                $this->clip($clip);
                $this->operations[] = [Op::ROUNDRING, $this->quad($x0 - 1.0, $y0 - 1.0, $x1 + 1.0, $y1 + 1.0), $x0, $y0, $x1, $y1, $radii, $inset, $rgba];

                return;

            case 'shadow':
                [, $x0, $y0, $x1, $y1, $radius, $blur, $rgba, $clip] = $command;
                $reach = $blur + 1.0;
                $this->clip($clip);
                $this->operations[] = [Op::SHADOW, $this->quad($x0 - $reach, $y0 - $reach, $x1 + $reach, $y1 + $reach), $x0, $y0, $x1, $y1, $radius, $blur, $rgba];

                return;

            case 'spans':
                [, $spans, $rgba, $clip] = $command;
                $floats = [];
                foreach (Spans::unpack($spans) as [$y, $x, $length]) {
                    array_push($floats, $x, $y, $x + $length, $y, $x + $length, $y + 1, $x, $y, $x + $length, $y + 1, $x, $y + 1);
                }
                $this->clip($clip);
                $this->operations[] = [Op::RECTS, $this->push($floats), intdiv(count($floats), 2), $rgba];

                return;
        }
    }

    /**
     * @param  list<list<array{float, float}>>  $contours
     * @param  array{kind: string, inverse: Affine, spread: GradientSpread, ramp: string}|null  $gradient
     */
    private function fill(array $contours, FillRule $rule, int $rgba, Region $clip, ?array $gradient = null): void
    {
        $floats = [];
        $xs = [];
        $ys = [];
        foreach ($contours as $contour) {
            [$x0, $y0] = $contour[0];
            for ($i = 1, $last = count($contour) - 1; $i < $last; $i++) {
                array_push($floats, $x0, $y0, $contour[$i][0], $contour[$i][1], $contour[$i + 1][0], $contour[$i + 1][1]);
            }
            foreach ($contour as [$x, $y]) {
                $xs[] = $x;
                $ys[] = $y;
            }
        }
        if ($floats === []) {
            return;
        }

        $this->clip($clip);
        $this->operations[] = [Op::STENCIL_FILL, $this->push($floats), intdiv(count($floats), 2), $rule];
        $quad = $this->quad(floor(min($xs)), floor(min($ys)), ceil(max($xs)), ceil(max($ys)));
        if (is_null($gradient)) {
            $this->operations[] = [Op::COVER, $quad, $rgba];

            return;
        }
        // the ramp is a source of its own; its UPLOAD op holds it, so its object id is not reused
        $ramp = new NativeFullFramebuffer(FormatSpec::rgba8(), Gradient::STEPS, 1);
        $ramp->writeRgba8($gradient['ramp'], Gradient::STEPS, 1);
        $texture = count($this->textures);
        $this->textures[spl_object_id($ramp)] = $texture;
        $this->operations[] = [Op::UPLOAD, $texture, $ramp];
        $this->operations[] = [Op::COVER_GRADIENT, $quad, $texture, $gradient['kind'] === 'linear' ? 0 : 1, $gradient['inverse'], match ($gradient['spread']) {
            GradientSpread::PAD => 0,
            GradientSpread::REPEAT => 1,
            GradientSpread::REFLECT => 2,
        }];
    }

    /** @param list<array<int, mixed>> $commands */
    private function placeGlyphs(array $commands): void
    {
        $wanted = [];
        foreach (self::flat($commands) as $command) {
            if ($command[0] !== 'glyphs') {
                continue;
            }
            [, $font, $pixelSize, $glyphs] = $command;
            foreach ($glyphs as [$face, $glyph, , , $style]) {
                $wanted[] = [GlyphAtlas::key($face, $pixelSize, $glyph, $style), $font->mask($face, $pixelSize, $glyph, $style)];
            }
        }
        if ($wanted === []) {
            return;
        }
        if (is_null($this->atlas)) {
            throw DrawingException::noAtlas();
        }
        $this->atlas->prepare($wanted, $this->operations);
    }

    /**
     * The commands with the children of every layer, at any depth, in the places of the layers.
     *
     * @param  list<array<int, mixed>>  $commands
     * @return list<array<int, mixed>>
     */
    private static function flat(array $commands): array
    {
        $out = [];
        foreach ($commands as $command) {
            $out[] = $command;
            if ($command[0] === 'layer') {
                array_push($out, ...self::flat($command[3]));
            }
        }

        return $out;
    }

    /**
     * A layer: its backdrop read from the target it lies on, filtered, and laid back; its children drawn into a target of
     * their own, filtered and laid down. The same steps as VelvetGE's, as operations.
     *
     * @param  array<int, mixed>  $command
     */
    private function layer(array $command): void
    {
        [, $full, $geometry, $children, $alpha, $filter, $backdrop, $clip] = $command;
        $round = $geometry;
        if (! is_null($backdrop)) {
            $by = (int) ceil($backdrop->reach());
            $outer = (new Region($full->x - $by, $full->y - $by, $full->width + 2 * $by, $full->height + 2 * $by))->intersect($this->surface);
            $glass = ++$this->slots;
            $this->operations[] = [Op::BACKDROP, $this->slot, $glass, $outer];
            $this->filterOp($glass, $outer, $backdrop, false);
            $this->lay($glass, $clip, 255, $round);
        }

        $content = ++$this->slots;
        $parent = $this->slot;
        $this->operations[] = [Op::TARGET, $content, $full];
        [$this->slot, $this->scissor] = [$content, null];
        [$groups, $memberOf, $group, $at] = [$this->groups, $this->memberOf, $this->group, $this->at];
        $this->plan($children);
        foreach ($children as $i => $child) {
            $this->at = $i;
            $this->command($child);
        }
        [$this->groups, $this->memberOf, $this->group, $this->at] = [$groups, $memberOf, $group, $at];
        $this->operations[] = [Op::TARGET, $parent, null];
        [$this->slot, $this->scissor] = [$parent, null];
        $this->filterOp($content, $full, $filter ?? FilterChain::of(), true);
        $this->lay($content, $clip, $alpha, $round);
    }

    /** The glass groups of a command list (GlassGroups), for the panes among it to find theirs. */
    private function plan(array $commands): void
    {
        [$this->groups, $this->memberOf, $this->group] = [[], [], null];
        if (! $this->glass) {
            return;
        }
        $this->groups = GlassGroups::of($commands, $this->surface, $this->box);
        foreach ($this->groups as $g => $group) {
            foreach ($group['members'] as $i) {
                $this->memberOf[$i] = $g;
            }
        }
    }

    /**
     * A glass pane on a device with its own glass pass: its group's backdrop read and blurred at the group's first pane, then
     * one draw over the pane's box.
     *
     * @param  array<int, mixed>  $command
     */
    private function pane(array $command): void
    {
        $g = $this->memberOf[$this->at] ?? null;
        if ($g === null) {
            return;
        }
        $group = $this->groups[$g];
        if ($this->group !== $g) {
            // the same region and depths as last frame's backdrop in this place, and nothing under it redrawn: the device keeps its pyramid
            $region = $group['region'];
            $key = "{$region->x},{$region->y},{$region->width},{$region->height}|".implode(',', $group['depths']);
            $n = count($this->backdrops);
            $this->backdrops[] = $key;
            $keep = ($this->previous[$n] ?? null) === $key && array_filter($this->damage, fn (Region $d): bool => ! is_null($d->intersect($region))) === [];
            $this->operations[] = [Op::GLASS_BACKDROP, $this->slot, $region, $group['depths'], $n, $keep];
            $this->group = $g;
            $this->scissor = null;
        }
        if ($command[5] !== null) {
            // after the backdrop was read and before the pane: the pane covers its own rounded box
            $this->command($command[5]);
        }
        [$x0, $y0, $x1, $y1] = $command[2];
        $this->clip($command[6]);
        $this->operations[] = [Op::GLASS, $this->quad(floor($x0), floor($y0), ceil($x1), ceil($y1)), $command[2], $command[3], Glass::levels($command[4]), $group['region']];
    }

    /** A filter chain run in place over a region of a layer target. */
    private function filterOp(int $slot, Region $region, FilterChain $chain, bool $premultiplied): void
    {
        $maps = [];
        $tables = [];
        foreach ($chain->steps as $i => $step) {
            if ($step instanceof Displace && ! is_string($step->map)) {
                $id = spl_object_id($step->map);
                if (! isset($this->textures[$id])) {
                    $this->textures[$id] = count($this->textures);
                    $this->operations[] = [Op::UPLOAD, $this->textures[$id], $step->map];
                }
                $maps[$i] = $this->textures[$id];
            } elseif ($step instanceof Noise && $step->type !== NoiseType::HASH) {
                $tables[$i] = NativeFilters::noiseTables($step->seed);
            }
        }
        $this->operations[] = [Op::FILTER, $slot, $region, $chain, $premultiplied, $maps, $tables];
        $this->scissor = null;
    }

    /** A layer target laid down on the current one: the clip's pixels, as an image placed at the origin, through the layer's rounded box. */
    private function lay(int $slot, Region $clip, int $alpha, array $round): void
    {
        $texture = $this->textures["layer{$slot}"] = count($this->textures);
        $this->operations[] = [Op::LAYER_SOURCE, $texture, $slot];
        $this->clip($clip);
        $this->operations[] = [Op::IMAGE, $this->quad($clip->x, $clip->y, $clip->right(), $clip->bottom()), $texture, Affine::identity(), $alpha, Filter::NEAREST, 0xFFFFFFFF, null, $round];
    }

    /** @return list<float> The six vertices of a quad. */
    private static function corners(float $x0, float $y0, float $x1, float $y1): array
    {
        return [$x0, $y0, $x1, $y0, $x1, $y1, $x0, $y0, $x1, $y1, $x0, $y1];
    }

    private function clip(Region $region): void
    {
        if ($this->scissor != $region) {
            $this->operations[] = [Op::SCISSOR, $region];
            $this->scissor = $region;
        }
    }

    /** @return int The quad's first vertex. */
    private function quad(float $x0, float $y0, float $x1, float $y1): int
    {
        return $this->push(self::corners($x0, $y0, $x1, $y1));
    }

    /**
     * @param  list<int|float>  $floats  x, y pairs
     * @return int The first vertex pushed.
     */
    private function push(array $floats): int
    {
        $first = intdiv(strlen($this->vertices), 8);
        foreach (array_chunk($floats, self::CHUNK) as $chunk) {
            $this->vertices .= pack('g*', ...$chunk);
        }

        return $first;
    }

    /**
     * A glass pane as a backdrop layer: blur, saturation, tint, light and opacity as a filter chain over its box.
     * shortcut: no refraction, rim or grain, and full-size filter passes; upgrade with each device's glass pass (G4).
     *
     * @param  array<int, mixed>  $command
     * @return array<int, mixed>
     */
    private static function glassLayer(array $command): array
    {
        [$frost, , , $saturation, $light, , $tint, , $alpha] = $command[3];
        $radius = $command[4] * $frost;
        $steps = [];
        $last = \Surface\Contracts\Drawing\Effects\FilterStep::SOURCE;
        $name = function () use (&$steps): string {
            return 'g'.count($steps);
        };
        if ($radius >= 0.75) {
            $steps[] = \Surface\Contracts\Drawing\Effects\Blur::spiral($radius, (int) min(64, max(16, ceil($radius * 0.75))), \Surface\Contracts\Drawing\Effects\EdgeMode::DUPLICATE, result: $last = $name());
        }
        if ($saturation != 1.0) {
            $steps[] = \Surface\Contracts\Drawing\Effects\ColorMatrix::saturate($saturation, $last, $last = $name());
        }
        if (($tint & 0xFF) > 0) {
            $veil = $name();
            $steps[] = new \Surface\Contracts\Drawing\Effects\Flood(new \Surface\NutsAndBolts\Color(($tint >> 24 & 0xFF) / 255, ($tint >> 16 & 0xFF) / 255, ($tint >> 8 & 0xFF) / 255, ($tint & 0xFF) / 255), $veil);
            $steps[] = new \Surface\Contracts\Drawing\Effects\Composite($last, \Surface\Contracts\Drawing\Effects\CompositeOperator::OVER, in: $veil, result: $last = $name());
        }
        if ($light != 0.0) {
            $steps[] = \Surface\Contracts\Drawing\Effects\ColorMatrix::brightness(1.0, $light, $last, $last = $name());
        }
        if ($alpha < 255) {
            $steps[] = \Surface\Contracts\Drawing\Effects\Composite::arithmetic(\Surface\Contracts\Drawing\Effects\FilterStep::SOURCE, 0.0, $alpha / 255, 1.0 - $alpha / 255, 0.0, $last, $last = $name());
        }
        if ($steps === []) {
            $steps[] = \Surface\Contracts\Drawing\Effects\ColorMatrix::brightness(1.0);
        }

        // shortcut: its shadow goes first, so the backdrop the layer reads has it; upgrade when a device without DrawsGlass is in use
        return ['layer', $command[1], $command[2], [], 255, null, \Surface\Contracts\Drawing\Effects\FilterChain::of(...$steps), $command[6]];
    }
}
