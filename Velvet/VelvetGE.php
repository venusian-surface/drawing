<?php

namespace Surface\Drawing\Velvet;

use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\PagedFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Framebuffers\RingFramebuffer;
use Surface\Contracts\Rasterize\Edges;
use Surface\Contracts\Rasterize\RasterizeDriver;
use Surface\Contracts\Rasterize\Rasterizer;
use Surface\Drawing\RenderingEngine;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\LinearGradient;
use Surface\Framebuffers\PixelMapper;
use Surface\Framebuffers\PixelMapperMode;

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

    /** @var array<string, Rasterizer> One per clip met in the frame being drawn. */
    private array $rasterizers = [];

    /**
     * @param  Edges|null  $edges  Null picks by format: anti-aliased where the format can blend (RGB, grey), hard where it cannot (mono, palette, planar).
     */
    public function __construct(
        protected Framebuffer $framebuffer,
        protected RasterizeDriver $rasterize,
        ?Edges $edges = null,
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

    /** Exact under a transform that only moves and scales evenly; the base class's paths otherwise. */
    public function roundRect(float $x, float $y, float $width, float $height, array $radii, Color $fill, ?Color $border = null, float $borderWidth = 0.0, ?LinearGradient $gradient = null): static
    {
        $s = $this->evenScale();
        if (is_null($s) || $width <= 0 || $height <= 0) {
            return parent::roundRect($x, $y, $width, $height, $radii, $fill, $border, $borderWidth, $gradient);
        }
        self::finite(['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height, 'borderWidth' => $borderWidth]);
        [$box, $radii] = $this->pixelBox($x, $y, $width, $height, self::radii($radii, $width, $height), $s);
        $inset = is_null($border) ? 0.0 : min(max(0.0, $borderWidth), min($width, $height) / 2) * $s;
        $edge = fn (float $d): float => min(1.0, max(0.0, 0.5 - $d));

        $inner = fn (float $d): float => $edge($d + $inset);
        if (is_null($gradient)) {
            $this->spans($this->sdfSpans($box, $radii, 0.0, $inner), $fill);
        } else {
            foreach ($this->gradientSpans($box, $radii, $inner, $gradient) as $step => $spans) {
                $this->spans($spans, $gradient->at($step / 255));
            }
        }
        if ($inset <= 0) {
            return $this;
        }

        return $this->spans($this->sdfSpans($box, $radii, 0.0, fn (float $d): float => $edge($d) - $edge($d + $inset)), $border);
    }

    /** 1 - smoothstep(-blur, blur, distance), set blur / 4 lower; exact under an even transform. */
    public function shadow(float $x, float $y, float $width, float $height, float $radius, float $blur, Color $color): static
    {
        $s = $this->evenScale();
        if (is_null($s) || $width <= 0 || $height <= 0) {
            return parent::shadow($x, $y, $width, $height, $radius, $blur, $color);
        }
        self::finite(['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height, 'radius' => $radius, 'blur' => $blur]);
        $blur = max(0.0, $blur);
        [$box, $radii] = $this->pixelBox($x, $y + $blur * 0.25, $width, $height, self::radii(array_fill(0, 4, $radius), $width, $height), $s);
        $spread = $blur * $s;
        $falloff = $spread <= 0
            ? fn (float $d): float => min(1.0, max(0.0, 0.5 - $d))
            : function (float $d) use ($spread): float {
                $t = min(1.0, max(0.0, ($d + $spread) / (2 * $spread)));

                return 1.0 - $t * $t * (3 - 2 * $t);
            };

        return $this->spans($this->sdfSpans($box, $radii, $spread, $falloff), $color);
    }

    /** Each byte blended exactly: one span command, each span carrying its byte as coverage. */
    public function coverage(string $mask, int $width, int $height, float $x, float $y, Color $color): static
    {
        if (! $this->drawing()) {
            return parent::coverage($mask, $width, $height, $x, $y, $color);
        }
        $place = $this->maskPlace($mask, $width, $height, $x, $y);
        if (is_null($place)) {
            return $this;
        }
        $hard = $this->edges === Edges::HARD;
        $spans = '';
        $this->maskRuns($mask, $width, $place, fn (int $byte): int => $hard ? ($byte >= 128 ? 255 : 0) : $byte, function (int $level, string $span) use (&$spans): void {
            $spans .= $span;
        }, true);

        return $this->spans($spans, $color);
    }

    /** The scale of a transform that only moves and scales the same both ways; null for any other. */
    private function evenScale(): ?float
    {
        if (! $this->drawing()) {
            return null;
        }
        $m = $this->matrix();

        return $m->b == 0.0 && $m->c == 0.0 && $m->a > 0 && abs($m->a - $m->d) < 1e-9 ? $m->a : null;
    }

    /**
     * A rect and its radii in framebuffer pixels.
     *
     * @param  list<float>  $radii
     * @return array{array{float, float, float, float}, list<float>} [x0, y0, x1, y1] and [tl, tr, br, bl]
     */
    private function pixelBox(float $x, float $y, float $width, float $height, array $radii, float $s): array
    {
        $m = $this->matrix();
        [$x0, $y0] = $m->apply($x, $y);

        return [[$x0, $y0, $x0 + $width * $s, $y0 + $height * $s], array_map(fn (float $r) => $r * $s, $radii)];
    }

    /**
     * Spans of a rounded box's signed distance through $coverage (distance in pixels to 0..1), sampled at pixel
     * centres over the box grown by $grow, inside the clip. Away from the corners and sides a row's distance does
     * not change along x, so the middle of each row is one span from one sample.
     *
     * @param  array{float, float, float, float}  $box
     * @param  list<float>  $radii
     */
    private function sdfSpans(array $box, array $radii, float $grow, callable $coverage): string
    {
        [$x0, $y0, $x1, $y1] = $box;
        $clip = $this->clipRegion();
        $left = max($clip->x, (int) floor($x0 - $grow));
        $right = min($clip->x + $clip->width, (int) ceil($x1 + $grow));
        $top = max($clip->y, (int) floor($y0 - $grow));
        $bottom = min($clip->y + $clip->height, (int) ceil($y1 + $grow));
        $cx = ($x0 + $x1) / 2;
        // columns this far in from the box's sides see a distance that depends on y only
        $band = (int) ceil(max($radii) + $grow) + 2;
        $distance = self::distance($box, $radii);
        $level = $this->level($coverage);

        $out = '';
        $middle_from = (int) ceil($x0) + $band;
        $middle_to = (int) floor($x1) - $band;
        for ($row = $top; $row < $bottom; $row++) {
            $py = $row + 0.5;
            $run_x = $left;
            $run_c = -1;
            $flush = function (int $end) use (&$out, &$run_x, &$run_c, $row): void {
                if ($run_c > 0 && $end > $run_x) {
                    $out .= pack('vvvC', $row, $run_x, $end - $run_x, $run_c);
                }
            };
            for ($col = $left; $col < $right; $col++) {
                if ($col === $middle_from && $middle_to > $middle_from) {
                    $c = $level($distance($cx, $py));
                    if ($c !== $run_c) {
                        $flush($col);
                        [$run_x, $run_c] = [$col, $c];
                    }
                    $col = min($middle_to, $right) - 1;

                    continue;
                }
                $c = $level($distance($col + 0.5, $py));
                if ($c !== $run_c) {
                    $flush($col);
                    [$run_x, $run_c] = [$col, $c];
                }
            }
            $flush($right);
        }

        return $out;
    }

    /**
     * A gradient fill's spans by colour step 0..255 along the gradient, every pixel sampled.
     *
     * @param  array{float, float, float, float}  $box
     * @param  list<float>  $radii
     * @return array<int, string>
     */
    private function gradientSpans(array $box, array $radii, callable $coverage, LinearGradient $gradient): array
    {
        [$x0, $y0, $x1, $y1] = $box;
        $clip = $this->clipRegion();
        $distance = self::distance($box, $radii);
        $level = $this->level($coverage);
        $angle = deg2rad($gradient->angleDegrees);
        [$ux, $uy] = [cos($angle), sin($angle)];
        $reach = abs($ux) * ($x1 - $x0) + abs($uy) * ($y1 - $y0);
        [$cx, $cy] = [($x0 + $x1) / 2, ($y0 + $y1) / 2];

        $out = [];
        for ($row = max($clip->y, (int) floor($y0)); $row < min($clip->y + $clip->height, (int) ceil($y1)); $row++) {
            $py = $row + 0.5;
            $run = null;   // [x, coverage, step]
            for ($col = max($clip->x, (int) floor($x0)), $end = min($clip->x + $clip->width, (int) ceil($x1)); $col <= $end; $col++) {
                $here = null;
                if ($col < $end) {
                    $px = $col + 0.5;
                    $c = $level($distance($px, $py));
                    $t = $reach > 0 ? (($px - $cx) * $ux + ($py - $cy) * $uy) / $reach + 0.5 : 0.0;
                    $here = [$c, (int) round(min(1.0, max(0.0, $t)) * 255)];
                }
                if (! is_null($run) && [$run[1], $run[2]] !== $here) {
                    if ($run[1] > 0) {
                        $out[$run[2]] = ($out[$run[2]] ?? '').pack('vvvC', $row, $run[0], $col - $run[0], $run[1]);
                    }
                    $run = null;
                }
                if (is_null($run) && ! is_null($here)) {
                    $run = [$col, ...$here];
                }
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * The signed distance, in pixels, from a pixel centre to a rounded box: negative inside.
     *
     * @param  array{float, float, float, float}  $box
     * @param  list<float>  $radii  [tl, tr, br, bl]
     */
    private static function distance(array $box, array $radii): \Closure
    {
        [$x0, $y0, $x1, $y1] = $box;
        [$cx, $cy, $hx, $hy] = [($x0 + $x1) / 2, ($y0 + $y1) / 2, ($x1 - $x0) / 2, ($y1 - $y0) / 2];

        return function (float $px, float $py) use ($cx, $cy, $hx, $hy, $radii): float {
            $dx = $px - $cx;
            $dy = $py - $cy;
            $r = $dx < 0 ? ($dy < 0 ? $radii[0] : $radii[3]) : ($dy < 0 ? $radii[1] : $radii[2]);
            $qx = abs($dx) - $hx + $r;
            $qy = abs($dy) - $hy + $r;

            return min(max($qx, $qy), 0.0) + sqrt(max($qx, 0.0) ** 2 + max($qy, 0.0) ** 2) - $r;
        };
    }

    /** A coverage function (distance to 0..1) as span coverage 0..255, all or nothing for hard edges. */
    private function level(callable $coverage): \Closure
    {
        $hard = $this->edges === Edges::HARD;

        return function (float $d) use ($coverage, $hard): int {
            $c = (int) round(min(1.0, max(0.0, $coverage($d))) * 255);

            return $hard ? ($c >= 128 ? 255 : 0) : $c;
        };
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

        foreach ($commands as $command) {
            if ($command[0] === 'clear') {
                $word = $this->mapper->fromRgba8($command[1] >> 24, ($command[1] >> 16) & 0xFF, ($command[1] >> 8) & 0xFF, $command[1] & 0xFF);
                $area = isset($command[2]) ? $command[2]->intersect($rows) : null;
                match (true) {
                    ! isset($command[2]) => $target->fill($word),
                    ! is_null($area) => $target->setSegment($area->x, $area->y, $area->width, $area->height, $word),
                    default => null,
                };

                continue;
            }
            if ($command[0] === 'image') {
                [, $source, $placement, $opacity, $filter, $clip] = $command;
                $target->paintImage($source, $placement, $opacity, $filter, $clip);

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
            $raster = $this->rasterizers["{$clip->x},{$clip->y},{$clip->width},{$clip->height}"] ??= $this->rasterize->rasterizer($clip, $this->edges);
            [$spans, $rgba] = match ($command[0]) {
                'path' => [$raster->fillPath($command[1], $command[2]), $command[3]],
                'polyline' => [$raster->polyline($command[1], $command[2], $command[3]), $command[4]],
                'ellipse' => [$raster->fillEllipse($command[1], $command[2], $command[3], $command[4]), $command[5]],
                'ring' => [$raster->strokeEllipse($command[1], $command[2], $command[3], $command[4], $command[5]), $command[6]],
            };
            if ($spans !== '') {
                $target->paintSpans($spans, $rgba);
            }
        }
        $this->rasterizers = [];

        if ($target instanceof RingFramebuffer) {
            $target->present();
        }
    }
}
