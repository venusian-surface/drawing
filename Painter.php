<?php

namespace Surface\Drawing;

use Surface\Contracts\Drawing\Drawing2D;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\Executor;
use Surface\Contracts\Drawing\TextureHandle;
use Surface\Contracts\Drawing\Topology;
use Surface\Contracts\Drawing\Transform;
use Surface\Contracts\Fonts\GFXFont;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Drawing\Text\GlyphAtlas;
use Surface\Drawing\Text\Typesetter;

/**
 * The one 2D implementation, over any Executor. Shapes become 9-float
 * vertices (x y z r g b a u v) in TARGET PIXELS — the sketch's affine stack
 * is applied here, per vertex, at emit time — batched by topology + texture,
 * and handed to the executor with one orthographic projection per frame.
 * Nothing per pixel, ever.
 */
class Painter implements Drawing2D
{
    protected int $width = 0;

    protected int $height = 0;

    protected float $scale = 1.0;

    protected Transform $projection;

    /** @var list<Affine> */
    protected array $stack = [];

    protected ?Topology $batch_topology = null;

    protected ?TextureHandle $batch_texture = null;

    protected string $batch_vertices = '';

    protected int $batch_count = 0;

    protected Typesetter $typesetter;

    /** @var array<class-string, array{TextureHandle, GlyphAtlas}> one atlas per face class, alive until released */
    protected array $atlases = [];

    public function __construct(protected Executor $executor)
    {
        $this->stack = [Affine::identity()];
        $this->projection = Transform::identity();
        $this->typesetter = new Typesetter();
    }

    /** Start a frame: target size in points, backing scale; the stack, batch and clip reset. */
    public function begin(int $width, int $height, float $scale): void
    {
        $this->width = $width;
        $this->height = $height;
        $this->scale = $scale;
        $this->stack = [Affine::identity()];
        $this->reset();
        $this->executor->unscissor();
        [$pw, $ph] = $this->executor->drawableSize();
        $this->projection = Transform::orthographic($pw, $ph);
    }

    /** Hand the pending batch to the executor. */
    public function flush(): void
    {
        if ($this->batch_count === 0 || is_null($this->batch_topology)) {
            return;
        }

        $this->executor->draw($this->batch_topology, $this->batch_vertices, $this->batch_count, $this->projection, $this->batch_texture);
        $this->reset();
    }

    /** Drop the pending batch without drawing it. */
    public function reset(): void
    {
        $this->batch_topology = null;
        $this->batch_texture = null;
        $this->batch_vertices = '';
        $this->batch_count = 0;
    }

    public function clear(Color $color): static
    {
        return $this->fillRect(0.0, 0.0, (float) $this->width, (float) $this->height, $color);
    }

    public function fillRect(float $x, float $y, float $width, float $height, Color $color): static
    {
        $c = $this->rgba($color);
        $this->emit(Topology::TRIANGLES, null, [
            $this->vertex($x, $y, $c), $this->vertex($x + $width, $y, $c), $this->vertex($x + $width, $y + $height, $c),
            $this->vertex($x, $y, $c), $this->vertex($x + $width, $y + $height, $c), $this->vertex($x, $y + $height, $c),
        ]);

        return $this;
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
        $c = $this->rgba($color);
        if ($stroke <= 1.0) {
            $this->emit(Topology::LINES, null, [$this->vertex($x0, $y0, $c), $this->vertex($x1, $y1, $c)]);

            return $this;
        }

        $this->emit(Topology::TRIANGLES, null, $this->segmentQuad($x0, $y0, $x1, $y1, $stroke, $c));

        return $this;
    }

    public function polyline(array $points, Color $color, float $stroke = 1.0, bool $closed = false): static
    {
        $count = count($points);
        if ($count < 2) {
            return $this;
        }
        $c = $this->rgba($color);
        $vertices = [];
        $last = $closed ? $count : $count - 1;
        for ($i = 0; $i < $last; $i++) {
            [$x0, $y0] = $points[$i];
            [$x1, $y1] = $points[($i + 1) % $count];
            array_push($vertices, ...$this->segmentQuad($x0, $y0, $x1, $y1, max(1.0, $stroke), $c));
        }
        $this->emit(Topology::TRIANGLES, null, $vertices);

        return $this;
    }

    public function fillTriangle(float $x0, float $y0, float $x1, float $y1, float $x2, float $y2, Color $color): static
    {
        $c = $this->rgba($color);
        $this->emit(Topology::TRIANGLES, null, [$this->vertex($x0, $y0, $c), $this->vertex($x1, $y1, $c), $this->vertex($x2, $y2, $c)]);

        return $this;
    }

    public function fillPolygon(array $points, Color $color): static
    {
        $count = count($points);
        if ($count < 3) {
            return $this;
        }
        $c = $this->rgba($color);
        $vertices = [];
        for ($i = 1; $i < $count - 1; $i++) {
            $vertices[] = $this->vertex($points[0][0], $points[0][1], $c);
            $vertices[] = $this->vertex($points[$i][0], $points[$i][1], $c);
            $vertices[] = $this->vertex($points[$i + 1][0], $points[$i + 1][1], $c);
        }
        $this->emit(Topology::TRIANGLES, null, $vertices);

        return $this;
    }

    public function fillCircle(float $cx, float $cy, float $radius, Color $color): static
    {
        return $this->fillEllipse($cx, $cy, $radius, $radius, $color);
    }

    public function strokeCircle(float $cx, float $cy, float $radius, Color $color, float $stroke = 1.0): static
    {
        return $this->polyline($this->ellipsePoints($cx, $cy, $radius, $radius, $this->segments($radius)), $color, $stroke, true);
    }

    public function fillEllipse(float $cx, float $cy, float $rx, float $ry, Color $color): static
    {
        $c = $this->rgba($color);
        $segments = $this->segments(max($rx, $ry));
        $ring = $this->ellipsePoints($cx, $cy, $rx, $ry, $segments);
        $vertices = [];
        for ($i = 0; $i < $segments; $i++) {
            $vertices[] = $this->vertex($cx, $cy, $c);
            $vertices[] = $this->vertex($ring[$i][0], $ring[$i][1], $c);
            $vertices[] = $this->vertex($ring[($i + 1) % $segments][0], $ring[($i + 1) % $segments][1], $c);
        }
        $this->emit(Topology::TRIANGLES, null, $vertices);

        return $this;
    }

    public function image(TextureHandle $texture, float $x, float $y, float $width, float $height, ?array $source = null, float $alpha = 1.0): static
    {
        [$sx, $sy, $sw, $sh] = $source ?? [0.0, 0.0, (float) $texture->width, (float) $texture->height];
        $u0 = $sx / $texture->width;
        $v0 = $sy / $texture->height;
        $u1 = ($sx + $sw) / $texture->width;
        $v1 = ($sy + $sh) / $texture->height;
        $c = $this->rgba(new Color(1.0, 1.0, 1.0, $alpha));

        $this->emit(Topology::TRIANGLES, $texture, [
            $this->vertex($x, $y, $c, $u0, $v0), $this->vertex($x + $width, $y, $c, $u1, $v0), $this->vertex($x + $width, $y + $height, $c, $u1, $v1),
            $this->vertex($x, $y, $c, $u0, $v0), $this->vertex($x + $width, $y + $height, $c, $u1, $v1), $this->vertex($x, $y + $height, $c, $u0, $v1),
        ]);

        return $this;
    }

    public function texture(string $rgba8, int $width, int $height): TextureHandle
    {
        return $this->executor->texture($rgba8, $width, $height);
    }

    public function releaseTexture(TextureHandle $texture): static
    {
        $this->flush();
        $this->executor->releaseTexture($texture);

        return $this;
    }

    public function push(): static
    {
        $this->stack[] = $this->stack[count($this->stack) - 1];

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
        $this->flush();
        $this->executor->scissor(
            (int) round($x * $this->scale), (int) round($y * $this->scale),
            (int) round($width * $this->scale), (int) round($height * $this->scale),
        );

        return $this;
    }

    public function unclip(): static
    {
        $this->flush();
        $this->executor->unscissor();

        return $this;
    }

    public function size(): array
    {
        return [$this->width, $this->height];
    }

    public function text(string $text, float $x, float $y, Color $color, GFXFont $font): static
    {
        [$handle, $atlas] = $this->atlas($font);
        $c = $this->rgba($color);
        $aw = $atlas->width();
        $ah = $atlas->height();
        $vertices = [];
        foreach ($this->typesetter->layout($font, $text) as $placed) {
            $rect = $atlas->rect($placed->code);
            if (is_null($rect)) {
                continue;
            }
            [$rx, $ry, $rw, $rh] = $rect;
            $u0 = $rx / $aw;
            $v0 = $ry / $ah;
            $u1 = ($rx + $rw) / $aw;
            $v1 = ($ry + $rh) / $ah;
            $gx = $x + $placed->x;
            $gy = $y + $placed->y;
            array_push($vertices,
                $this->vertex($gx, $gy, $c, $u0, $v0), $this->vertex($gx + $rw, $gy, $c, $u1, $v0), $this->vertex($gx + $rw, $gy + $rh, $c, $u1, $v1),
                $this->vertex($gx, $gy, $c, $u0, $v0), $this->vertex($gx + $rw, $gy + $rh, $c, $u1, $v1), $this->vertex($gx, $gy + $rh, $c, $u0, $v1),
            );
        }
        $this->emit(Topology::TRIANGLES, $handle, $vertices);

        return $this;
    }

    public function textBounds(string $text, GFXFont $font): array
    {
        [$bx, $by, $bw, $bh] = $this->typesetter->bounds($font, $text);

        return [(float) $bx, (float) $by, (float) $bw, (float) $bh];
    }

    /** Drop every glyph atlas: flushes first, then releases each texture. The next text() bakes again. */
    public function releaseAtlases(): static
    {
        $this->flush();
        foreach ($this->atlases as [$handle]) {
            $this->executor->releaseTexture($handle);
        }
        $this->atlases = [];

        return $this;
    }

    /** Post-multiply the top of the stack: current × m, so m applies to a point first. */
    protected function compose(Affine $m): static
    {
        $this->stack[count($this->stack) - 1] = $this->stack[count($this->stack) - 1]->compose($m);

        return $this;
    }

    /** @return array{float, float, float, float} opaque when the engine cannot blend */
    protected function rgba(Color $color): array
    {
        $alpha = $this->executor->capabilities()->blending ? $color->alpha : 1.0;

        return [$color->red, $color->green, $color->blue, $alpha];
    }

    /** One vertex through the stack and the backing scale: nine floats, pixels. */
    protected function vertex(float $x, float $y, array $c, float $u = 0.0, float $v = 0.0): array
    {
        [$px, $py] = $this->stack[count($this->stack) - 1]->apply($x, $y);
        $px *= $this->scale;
        $py *= $this->scale;

        return [$px, $py, 0.0, $c[0], $c[1], $c[2], $c[3], $u, $v];
    }

    /** A butt-ended quad of $stroke width along the segment, as two triangles. */
    protected function segmentQuad(float $x0, float $y0, float $x1, float $y1, float $stroke, array $c): array
    {
        $corners = Geometry::segmentCorners($x0, $y0, $x1, $y1, $stroke);
        if ($corners === []) {
            return [];
        }
        [$c0, $c1, $c2, $c3] = $corners;

        return [
            $this->vertex($c0[0], $c0[1], $c), $this->vertex($c1[0], $c1[1], $c), $this->vertex($c2[0], $c2[1], $c),
            $this->vertex($c0[0], $c0[1], $c), $this->vertex($c2[0], $c2[1], $c), $this->vertex($c3[0], $c3[1], $c),
        ];
    }

    protected function segments(float $radius): int
    {
        return Geometry::segments($radius);
    }

    /** @return list<array{float, float}> */
    protected function ellipsePoints(float $cx, float $cy, float $rx, float $ry, int $segments): array
    {
        return Geometry::ellipsePoints($cx, $cy, $rx, $ry, $segments);
    }

    /** The face's atlas and texture, baked at the executor's texture limit on first use. @return array{TextureHandle, GlyphAtlas} */
    protected function atlas(GFXFont $font): array
    {
        if (! isset($this->atlases[$font::class])) {
            $atlas = GlyphAtlas::bake($this->typesetter, $font, $this->executor->capabilities()->max_texture_size);
            $this->atlases[$font::class] = [$this->executor->texture($atlas->rgba8(), $atlas->width(), $atlas->height()), $atlas];
        }

        return $this->atlases[$font::class];
    }

    /** Append vertices to the batch; a topology or texture change flushes first. */
    protected function emit(Topology $topology, ?TextureHandle $texture, array $vertices): void
    {
        if ($vertices === []) {
            return;
        }
        if ($this->batch_count > 0 && ($this->batch_topology !== $topology || $this->batch_texture !== $texture)) {
            $this->flush();
        }
        $this->batch_topology = $topology;
        $this->batch_texture = $texture;
        foreach ($vertices as $vertex) {
            $this->batch_vertices .= pack('g9', ...$vertex);
            $this->batch_count++;
        }
    }
}
