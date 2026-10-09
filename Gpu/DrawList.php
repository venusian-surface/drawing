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
 *     [Op::ELLIPSE, first, cx, cy, rx, ry, rgba]            a quad; coverage is the ellipse's, computed per pixel
 *     [Op::RING, first, cx, cy, rx, ry, stroke, rgba]       a quad; coverage is the band's between radii r ± stroke / 2
 *     [Op::UPLOAD, texture, Framebuffer]                    make the source texture number `texture`, from its toRgba8(); an
 *                                                           HdrImage's from its rgba16f() on a target in an HDR colour space
 *     [Op::IMAGE, first, texture, Affine, opacity, Filter]  a quad over the placed corners; the Affine maps a target pixel's
 *                                                           centre to source pixels, sampled as paintImage() samples;
 *                                                           opacity 1..255
 *     [Op::RECTS, first, count, rgba]                       count / 6 quads, one per span of whole pixels
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
