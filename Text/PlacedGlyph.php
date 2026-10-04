<?php

namespace Surface\Drawing\Text;

use Surface\Contracts\Fonts\Glyph;

/** One glyph on the page: its top-left relative to the text origin, in pixels. */
final readonly class PlacedGlyph
{
    public function __construct(
        public int $code,
        public int $x,
        public int $y,
        public Glyph $glyph,
    ) {}
}
