<?php

namespace Surface\Drawing;

use Surface\Contracts\Drawing\Drawing2D;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\TextureHandle;
use Surface\Contracts\Fonts\GFXFont;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\RastersNatively;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Drawing\Text\Typesetter;
use Surface\Framebuffers\PixelMapper;

/**
 * The one Drawing2D over any Framebuffer. Integer primitives, and text as
 * one span per glyph run, emitted as rects and spans (setSegment: one call
 * per axis-aligned rect, one per row for polygons) and point lists
 * (setPixels, one per shape) — never one call per pixel. Identity and
 * translate-only transforms take the fast path; rotate and scale go through
 * a scanline polygon fill. Colour is opaque; a texel under half alpha is
 * skipped.
 */
final class Rasterizer implements Drawing2D
{
    /** @var list<Affine> */
    private array $stack = [];

    private ?Region $page = null;

    private ?Region $user_clip = null;

    private int $width;

    private int $height;

    private ?RastersNatively $native;

    /** @var array<int, array{string, int, int}> id => [rgba8, w, h] */
    private array $textures = [];

    private int $next_texture = 1;

    private Typesetter $typesetter;

    public function __construct(private Framebuffer $buffer, private PixelMapper $mapper)
    {
        $this->width = $buffer->viewportWidth();
        $this->height = $buffer->viewportHeight();
        $this->native = $buffer instanceof RastersNatively ? $buffer : null;
        $this->typesetter = new Typesetter();
        $this->begin();
    }

    /** New frame: stack and user clip reset; $page narrows the target for the paged engine and survives unclip(). */
    public function begin(?Region $page = null): void
    {
        $this->stack = [Affine::identity()];
        $this->user_clip = null;
        $this->page = $page;
    }

    /** @return array<int, array{string, int, int}> */
    public function textures(): array
    {
        return $this->textures;
    }

    // ---- Drawing2D

    public function clear(Color $color): static
    {
        $b = $this->bounds();
        if ($b->isEmpty()) {
            return $this;
        }
        if ($b->x === 0 && $b->y === 0 && $b->width === $this->width && $b->height === $this->height) {
            $this->buffer->fill($this->mapper->map($color));
        } else {
            $this->buffer->setSegment($b->x, $b->y, $b->width, $b->height, $this->mapper->map($color));
        }

        return $this;
    }

    public function fillRect(float $x, float $y, float $width, float $height, Color $color): static
    {
        $top = $this->top();
        if ($top->isTranslation()) {
            $this->fillRegion(self::rect($x + $top->tx, $y + $top->ty, $width, $height), $this->mapper->map($color));

            return $this;
        }

        return $this->fillPolygon([[$x, $y], [$x + $width, $y], [$x + $width, $y + $height], [$x, $y + $height]], $color);
    }

    public function strokeRect(float $x, float $y, float $width, float $height, Color $color, float $stroke = 1.0): static
    {
        $this->fillRect($x, $y, $width, $stroke, $color);
        $this->fillRect($x, $y + $height - $stroke, $width, $stroke, $color);
        $this->fillRect($x, $y + $stroke, $stroke, $height - 2 * $stroke, $color);
        $this->fillRect($x + $width - $stroke, $y + $stroke, $stroke, $height - 2 * $stroke, $color);

        return $this;
    }

    public function line(float $x0, float $y0, float $x1, float $y1, Color $color, float $stroke = 1.0): static
    {
        if ($stroke <= 1.0) {
            [$ax, $ay] = $this->top()->apply($x0, $y0);
            [$bx, $by] = $this->top()->apply($x1, $y1);
            $this->bresenham((int) round($ax), (int) round($ay), (int) round($bx), (int) round($by), $this->mapper->map($color));

            return $this;
        }
        $corners = Geometry::segmentCorners($x0, $y0, $x1, $y1, $stroke);

        return $corners === [] ? $this : $this->fillPolygon($corners, $color);
    }

    public function polyline(array $points, Color $color, float $stroke = 1.0, bool $closed = false): static
    {
        $count = count($points);
        if ($count < 2) {
            return $this;
        }
        $last = $closed ? $count : $count - 1;
        for ($i = 0; $i < $last; $i++) {
            [$x0, $y0] = $points[$i];
            [$x1, $y1] = $points[($i + 1) % $count];
            $this->line($x0, $y0, $x1, $y1, $color, max(1.0, $stroke));
        }

        return $this;
    }

    public function fillTriangle(float $x0, float $y0, float $x1, float $y1, float $x2, float $y2, Color $color): static
    {
        return $this->fillPolygon([[$x0, $y0], [$x1, $y1], [$x2, $y2]], $color);
    }

    public function fillPolygon(array $points, Color $color): static
    {
        if (count($points) < 3) {
            return $this;
        }
        $top = $this->top();
        $pts = array_map(fn (array $p) => $top->apply($p[0], $p[1]), $points);
        $this->scanlines($pts, $this->mapper->map($color));

        return $this;
    }

    public function fillCircle(float $cx, float $cy, float $radius, Color $color): static
    {
        return $this->fillEllipse($cx, $cy, $radius, $radius, $color);
    }

    public function strokeCircle(float $cx, float $cy, float $radius, Color $color, float $stroke = 1.0): static
    {
        if ($stroke <= 1.0 && $this->top()->isTranslation()) {
            $this->midpointCircle((int) round($cx + $this->top()->tx), (int) round($cy + $this->top()->ty), (int) round($radius), $this->mapper->map($color));

            return $this;
        }

        return $this->polyline(Geometry::ellipsePoints($cx, $cy, $radius, $radius, Geometry::segments($radius)), $color, $stroke, true);
    }

    public function fillEllipse(float $cx, float $cy, float $rx, float $ry, Color $color): static
    {
        $top = $this->top();
        if (! $top->isTranslation()) {
            return $this->fillPolygon(Geometry::ellipsePoints($cx, $cy, $rx, $ry, Geometry::segments(max($rx, $ry))), $color);
        }
        if ($rx <= 0.0 || $ry <= 0.0) {
            return $this;
        }
        $cx += $top->tx;
        $cy += $top->ty;
        $word = $this->mapper->map($color);
        $bounds = $this->bounds();
        $y0 = max($bounds->y, (int) floor($cy - $ry));
        $y1 = min($bounds->bottom() - 1, (int) ceil($cy + $ry));
        for ($y = $y0; $y <= $y1; $y++) {
            $dy = ($y + 0.5 - $cy) / $ry;
            if ($dy * $dy > 1.0) {
                continue;
            }
            $half = $rx * sqrt(1.0 - $dy * $dy);
            $this->spanRow($y, (int) ceil($cx - $half - 0.5), (int) floor($cx + $half - 0.5), $word);
        }

        return $this;
    }

    public function image(TextureHandle $texture, float $x, float $y, float $width, float $height, ?array $source = null, float $alpha = 1.0): static
    {
        $tex = $this->textures[$texture->id] ?? throw new DrawingException('texture handle is not held by this rasterizer');
        [$rgba, $tw, $th] = $tex;
        [$sx, $sy, $sw, $sh] = $source ?? [0.0, 0.0, (float) $tw, (float) $th];
        if ($width <= 0.0 || $height <= 0.0 || $sw <= 0.0 || $sh <= 0.0) {
            return $this;
        }
        $top = $this->top();
        $bounds = $this->bounds();
        $points = [];
        $cache = [];

        $sample = function (float $lx, float $ly) use (&$cache, $rgba, $tw, $th, $x, $y, $width, $height, $sx, $sy, $sw, $sh): ?int {
            $u = (int) floor($sx + ($lx - $x) / $width * $sw);
            $v = (int) floor($sy + ($ly - $y) / $height * $sh);
            if ($u < 0 || $v < 0 || $u >= $tw || $v >= $th) {
                return null;
            }
            $o = ($v * $tw + $u) * 4;
            if (ord($rgba[$o + 3]) < 128) {
                return null;
            }
            $key = substr($rgba, $o, 3);

            return $cache[$key] ??= $this->mapper->map(new Color(ord($rgba[$o]) / 255, ord($rgba[$o + 1]) / 255, ord($rgba[$o + 2]) / 255));
        };

        if ($top->isTranslation()) {
            $dest = self::rect($x + $top->tx, $y + $top->ty, $width, $height)->intersect($bounds);
            if (is_null($dest)) {
                return $this;
            }
            for ($py = $dest->y; $py < $dest->bottom(); $py++) {
                for ($px = $dest->x; $px < $dest->right(); $px++) {
                    $word = $sample($px + 0.5 - $top->tx, $py + 0.5 - $top->ty);
                    if (! is_null($word)) {
                        $points[] = [$px, $py, $word];
                    }
                }
            }
        } else {
            $inverse = $top->invert();
            if (is_null($inverse)) {
                return $this;
            }
            $corners = [$top->apply($x, $y), $top->apply($x + $width, $y), $top->apply($x + $width, $y + $height), $top->apply($x, $y + $height)];
            $xs = array_column($corners, 0);
            $ys = array_column($corners, 1);
            $box = (new Region((int) floor(min($xs)), (int) floor(min($ys)), (int) ceil(max($xs)) - (int) floor(min($xs)), (int) ceil(max($ys)) - (int) floor(min($ys))))->intersect($bounds);
            if (is_null($box)) {
                return $this;
            }
            for ($py = $box->y; $py < $box->bottom(); $py++) {
                for ($px = $box->x; $px < $box->right(); $px++) {
                    [$lx, $ly] = $inverse->apply($px + 0.5, $py + 0.5);
                    if ($lx < $x || $ly < $y || $lx >= $x + $width || $ly >= $y + $height) {
                        continue;
                    }
                    $word = $sample($lx, $ly);
                    if (! is_null($word)) {
                        $points[] = [$px, $py, $word];
                    }
                }
            }
        }
        if ($points !== []) {
            $this->buffer->setPixels($points);
        }

        return $this;
    }

    public function texture(string $rgba8, int $width, int $height): TextureHandle
    {
        $id = $this->next_texture++;
        $this->textures[$id] = [$rgba8, max(1, $width), max(1, $height)];

        return new TextureHandle($id, max(1, $width), max(1, $height));
    }

    public function releaseTexture(TextureHandle $texture): static
    {
        unset($this->textures[$texture->id]);

        return $this;
    }

    public function push(): static
    {
        $this->stack[] = $this->top();

        return $this;
    }

    public function pop(): static
    {
        if (count($this->stack) < 2) {
            throw DrawingException::emptyStack();
        }
        array_pop($this->stack);

        return $this;
    }

    public function translate(float $dx, float $dy): static
    {
        return $this->compose(Affine::translation($dx, $dy));
    }

    public function rotate(float $radians): static
    {
        return $this->compose(Affine::rotation($radians));
    }

    public function scale(float $sx, float $sy): static
    {
        return $this->compose(Affine::scaling($sx, $sy));
    }

    public function clip(float $x, float $y, float $width, float $height): static
    {
        $this->user_clip = self::rect($x, $y, $width, $height);

        return $this;
    }

    public function unclip(): static
    {
        $this->user_clip = null;

        return $this;
    }

    public function size(): array
    {
        return [$this->width, $this->height];
    }

    public function text(string $text, float $x, float $y, Color $color, GFXFont $font): static
    {
        $word = $this->mapper->map($color);
        $top = $this->top();
        foreach ($this->typesetter->layout($font, $text) as $placed) {
            $gx = $x + $placed->x;
            $gy = $y + $placed->y;
            foreach ($this->typesetter->runs($font, $placed->glyph) as [$row, $x0, $x1]) {
                $rx = $gx + $x0;
                $ry = $gy + $row;
                $rw = (float) ($x1 - $x0 + 1);
                if ($top->isTranslation()) {
                    $this->fillRegion(self::rect($rx + $top->tx, $ry + $top->ty, $rw, 1.0), $word);
                } else {
                    $this->scanlines([$top->apply($rx, $ry), $top->apply($rx + $rw, $ry), $top->apply($rx + $rw, $ry + 1.0), $top->apply($rx, $ry + 1.0)], $word);
                }
            }
        }

        return $this;
    }

    public function textBounds(string $text, GFXFont $font): array
    {
        [$bx, $by, $bw, $bh] = $this->typesetter->bounds($font, $text);

        return [(float) $bx, (float) $by, (float) $bw, (float) $bh];
    }

    // ---- internals

    private function top(): Affine
    {
        return $this->stack[count($this->stack) - 1];
    }

    private function compose(Affine $m): static
    {
        $this->stack[count($this->stack) - 1] = $this->top()->compose($m);

        return $this;
    }

    /** Target ∩ page ∩ user clip; may be empty. */
    private function bounds(): Region
    {
        $r = Region::wholeSurface($this->width, $this->height);
        foreach ([$this->page, $this->user_clip] as $clip) {
            if (! is_null($clip)) {
                $r = $r->intersect($clip) ?? new Region(0, 0, 0, 0);
            }
        }

        return $r;
    }

    /** Edges to the nearest pixel boundary; a negative size is normalised. */
    private static function rect(float $x, float $y, float $w, float $h): Region
    {
        $x0 = (int) round($x);
        $y0 = (int) round($y);
        $x1 = (int) round($x + $w);
        $y1 = (int) round($y + $h);

        return new Region(min($x0, $x1), min($y0, $y1), abs($x1 - $x0), abs($y1 - $y0));
    }

    private function fillRegion(Region $r, int $word): void
    {
        $c = $r->intersect($this->bounds());
        if (is_null($c)) {
            return;
        }
        if (! is_null($this->native)) {
            $this->native->fillRect($c, $word);

            return;
        }
        $this->buffer->setSegment($c->x, $c->y, $c->width, $c->height, $word);
    }

    /** One horizontal run, inclusive x, clipped to bounds. */
    private function spanRow(int $y, int $x0, int $x1, int $word): void
    {
        $b = $this->bounds();
        if ($y < $b->y || $y >= $b->bottom()) {
            return;
        }
        $x0 = max($x0, $b->x);
        $x1 = min($x1, $b->right() - 1);
        if ($x1 < $x0) {
            return;
        }
        $this->buffer->setSegment($x0, $y, $x1 - $x0 + 1, 1, $word);
    }

    /** Even-odd scanline fill, pixel centres sampled, one span per crossing pair per row. @param list<array{float, float}> $pts */
    private function scanlines(array $pts, int $word): void
    {
        $n = count($pts);
        $ys = array_column($pts, 1);
        $b = $this->bounds();
        $y0 = max($b->y, (int) ceil(min($ys) - 0.5));
        $y1 = min($b->bottom() - 1, (int) floor(max($ys) - 0.5));
        for ($y = $y0; $y <= $y1; $y++) {
            $yc = $y + 0.5;
            $xs = [];
            for ($i = 0; $i < $n; $i++) {
                [$ax, $ay] = $pts[$i];
                [$bx, $by] = $pts[($i + 1) % $n];
                if ($ay === $by) {
                    continue;
                }
                if (($yc >= $ay && $yc < $by) || ($yc >= $by && $yc < $ay)) {
                    $xs[] = $ax + ($yc - $ay) * ($bx - $ax) / ($by - $ay);
                }
            }
            sort($xs);
            for ($i = 0; $i + 1 < count($xs); $i += 2) {
                $this->spanRow($y, (int) ceil($xs[$i] - 0.5), (int) floor($xs[$i + 1] - 0.5), $word);
            }
        }
    }

    private function bresenham(int $x0, int $y0, int $x1, int $y1, int $word): void
    {
        $b = $this->bounds();
        if (! is_null($this->native)) {
            $this->native->line($x0, $y0, $x1, $y1, $word, $b);

            return;
        }
        $dx = abs($x1 - $x0);
        $dy = -abs($y1 - $y0);
        $sx = $x0 < $x1 ? 1 : -1;
        $sy = $y0 < $y1 ? 1 : -1;
        $err = $dx + $dy;
        $points = [];
        while (true) {
            if ($b->contains($x0, $y0)) {
                $points[] = [$x0, $y0, $word];
            }
            if ($x0 === $x1 && $y0 === $y1) {
                break;
            }
            $e2 = 2 * $err;
            if ($e2 >= $dy) {
                $err += $dy;
                $x0 += $sx;
            }
            if ($e2 <= $dx) {
                $err += $dx;
                $y0 += $sy;
            }
        }
        if ($points !== []) {
            $this->buffer->setPixels($points);
        }
    }

    /** Eight-way symmetric midpoint circle, one setPixels. */
    private function midpointCircle(int $cx, int $cy, int $r, int $word): void
    {
        if ($r < 1) {
            return;
        }
        $b = $this->bounds();
        $seen = [];
        $x = $r;
        $y = 0;
        $err = 1 - $r;
        while ($x >= $y) {
            foreach ([[$x, $y], [$y, $x], [-$y, $x], [-$x, $y], [-$x, -$y], [-$y, -$x], [$y, -$x], [$x, -$y]] as [$ox, $oy]) {
                $px = $cx + $ox;
                $py = $cy + $oy;
                if ($b->contains($px, $py)) {
                    $seen["{$px},{$py}"] = [$px, $py, $word];
                }
            }
            $y++;
            if ($err < 0) {
                $err += 2 * $y + 1;
            } else {
                $x--;
                $err += 2 * ($y - $x) + 1;
            }
        }
        if ($seen !== []) {
            $this->buffer->setPixels(array_values($seen));
        }
    }
}
