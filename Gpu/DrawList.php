<?php

namespace Surface\Drawing\Gpu;

/**
 * One frame for a GPU device: operations in order over one vertex string.
 * Vertices are little-endian 32-bit float pairs, x then y, in target pixels
 * with the origin top-left. first and count are in vertices; a quad is six:
 * x0,y0  x1,y0  x1,y1  x0,y0  x1,y1  x0,y1. Colours are 0xRRGGBBAA, blended
 * source-over as Framebuffer::paintSpans() blends, except where noted.
 *
 *     [Op::CLEAR, rgba]                                     the whole target, no blending
 *     [Op::SCISSOR, Region]                                 holds until the next SCISSOR
 *     [Op::SOLID, first, rgba]                              a quad, no blending
 *     [Op::STENCIL_FILL, first, count, FillRule]            a triangle list into the stencil: NON_ZERO counts up on
 *                                                           front faces and down on back faces, EVEN_ODD inverts
 *     [Op::COVER, first, rgba]                              a quad, drawn where the stencil is non-zero, resetting it to zero
 *     [Op::COVER_GRADIENT, first, texture, kind, Affine, spread]
 *                                                           the same quad, drawn in a gradient: `texture` is the ramp, a 256 x 1
 *                                                           RGBA8 source made by an UPLOAD just before; `kind` 0 linear, 1 radial;
 *                                                           the Affine takes a target pixel's centre to the gradient's own space,
 *                                                           where t is x (linear) or the distance from the origin (radial); `spread`
 *                                                           0 pad, 1 repeat, 2 reflect applies to t; the pixel is ramp entry
 *                                                           floor(t * 255 + 1/2), with the alpha of a shape of that alpha: (entry
 *                                                           alpha * coverage + 127) / 255, the coverage the stencil's
 *     [Op::ELLIPSE, first, cx, cy, rx, ry, rgba]            a quad; coverage is the ellipse's, computed per pixel
 *     [Op::RING, first, cx, cy, rx, ry, stroke, rgba]       a quad; coverage is the band's between radii r ± stroke / 2
 *     [Op::UPLOAD, texture, Framebuffer]                    make the source texture number `texture`, from its toRgba8(); an
 *                                                           HdrImage's from its rgba16f() on a target in an HDR colour space
 *     [Op::IMAGE, first, texture, Affine, opacity, Filter, tint, window, round]
 *                                                           a quad over the placed corners of the window (or of the whole source);
 *                                                           the Affine maps a target pixel's centre to source pixels, sampled as
 *                                                           paintImage() samples; opacity 1..255. Each sampled pixel is multiplied by
 *                                                           `tint` (0xRRGGBBAA, per channel, alpha too: c = (c × t + 127) / 255), then
 *                                                           its alpha by the opacity the same way, then by the coverage of `round`.
 *                                                           `window` [u0, v0, u1, v1] (null: all): a pixel whose centre maps outside it
 *                                                           is skipped; smooth sampling still reads the whole texture, held at its
 *                                                           edges. `round` [x0, y0, x1, y1, [tl, tr, br, bl]] (null: none): coverage
 *                                                           per sample as ROUNDRECT's for that box and those radii, 0 outside
 *     [Op::RECTS, first, count, rgba]                       count / 6 quads, one per span of whole pixels
 *     [Op::ATLAS, atlas, width, height]                     make texture number `atlas`, single-channel 8-bit, all zero; it is
 *                                                           kept across frames until the next ATLAS for that number or release();
 *                                                           remaking it lets go of the old texture once no frame in flight reads it
 *     [Op::MASK, atlas, Region, bytes]                      write Region->width * Region->height coverage bytes, rows top-down,
 *                                                           into that region of the atlas
 *     [Op::GLYPHS, first, count, texels, atlas, rgba]       count vertices of quads at first; the atlas texel coordinates of the
 *                                                           same vertices are float pairs from vertex `texels`. Coverage is sampled
 *                                                           nearest at the texel and blended as paintSpans() blends that coverage
 *     [Op::ROUNDRECT, first, x0, y0, x1, y1, radii, rgba]   a quad 1 px past the box; coverage per sample is inside the box with
 *                                                           corner radii [tl, tr, br, bl]
 *     [Op::ROUNDRING, first, x0, y0, x1, y1, radii, inset, rgba]
 *                                                           the same quad; coverage per sample is inside the box and outside the
 *                                                           box inset by `inset`, whose radii are max(0, r − inset)
 *     [Op::SHADOW, first, x0, y0, x1, y1, radius, blur, rgba]
 *                                                           a quad blur + 1 past the box; coverage per pixel centre is
 *                                                           1 − smoothstep(−blur, blur, d), d the signed distance to the rounded box;
 *                                                           hard edges keep coverage of 128 and up, at 255
 */
final readonly class DrawList
{
    /**
     * @param  string  $vertices  float32 LE pairs
     * @param  list<array<int, mixed>>  $operations
     */
    public function __construct(
        public int $width,
        public int $height,
        public string $vertices,
        public array $operations,
    ) {}

    public function vertexCount(): int
    {
        return intdiv(strlen($this->vertices), 8);
    }
}
