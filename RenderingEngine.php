<?php

namespace Surface\Drawing;

use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\RenderingEngine as RenderingEngineContract;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\FillRule;
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
 *     ['clear', rgba]
 *     ['path', contours, FillRule, rgba, clip]            contours: list of lists of [x, y]
 *     ['polyline', points, stroke, closed, rgba, clip]
 *     ['ellipse', cx, cy, rx, ry, rgba, clip]             axis-aligned
 *     ['ring', cx, cy, rx, ry, stroke, rgba, clip]        the band between radii r ± stroke / 2
 *     ['image', Framebuffer, Affine, opacity 1..255, Filter, clip]
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
        $this->last = $this->recording;
        $this->close();
        $this->execute($this->last);

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
