<?php

namespace Surface\Drawing\Text;

use Surface\Contracts\Fonts\FontException;
use Surface\Contracts\Fonts\GFXFont;
use Surface\Contracts\Fonts\Glyph;

/**
 * Every glyph of a face in one RGBA8 texture: white texels, alpha = coverage,
 * shelf-packed with a one-texel gutter. Width starts at min(512, max) and
 * doubles while the packed height would exceed it; both stop at max.
 */
final class GlyphAtlas
{
    /** @param array<int, array{int, int, int, int}> $rects code => [x, y, w, h] */
    private function __construct(
        private string $rgba8,
        private int $width,
        private int $height,
        private array $rects,
    ) {}

    /** @throws FontException When the face cannot fit a max_size square. */
    public static function bake(Typesetter $typesetter, GFXFont $font, int $max_size): self
    {
        if (! $font->hasBitmapData()) {
            return new self("\0\0\0\0", 1, 1, []);
        }
        $glyphs = [];
        for ($code = $font->first(); $code <= $font->last(); $code++) {
            $glyph = $font->glyph($code);
            if (! is_null($glyph) && $glyph->width > 0 && $glyph->height > 0) {
                $glyphs[$code] = $glyph;
            }
        }
        $width = min(512, $max_size);
        while (true) {
            $pack = self::shelfPack($glyphs, $width);
            $height = is_null($pack) ? PHP_INT_MAX : self::powerOfTwo($pack[1]);
            if ($height <= $max_size && ($height <= $width || $width >= $max_size)) {
                break;
            }
            if ($width >= $max_size) {
                throw FontException::atlasTooLarge($font::class, is_null($pack) ? $width * 2 : $height, $max_size);
            }
            $width = min($width * 2, $max_size);
        }
        [$rects] = $pack;
        $rgba8 = str_repeat("\xff\xff\xff\x00", $width * $height);
        foreach ($rects as $code => [$x, $y, $w, $h]) {
            $coverage = $typesetter->coverage($font, $glyphs[$code]);
            for ($row = 0; $row < $h; $row++) {
                for ($col = 0; $col < $w; $col++) {
                    $rgba8[(($y + $row) * $width + $x + $col) * 4 + 3] = $coverage[$row * $w + $col];
                }
            }
        }

        return new self($rgba8, $width, $height, $rects);
    }

    public function rgba8(): string
    {
        return $this->rgba8;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    /** @return array{int, int, int, int}|null texel [x, y, w, h] */
    public function rect(int $code): ?array
    {
        return $this->rects[$code] ?? null;
    }

    /**
     * Rows of glyphs left to right; null when a glyph is wider than the shelf.
     *
     * @param  array<int, Glyph>  $glyphs
     * @return array{array<int, array{int, int, int, int}>, int}|null rects and the packed height
     */
    private static function shelfPack(array $glyphs, int $width): ?array
    {
        $rects = [];
        $x = 1;
        $y = 1;
        $shelf = 0;
        foreach ($glyphs as $code => $glyph) {
            if ($glyph->width + 2 > $width) {
                return null;
            }
            if ($x + $glyph->width + 1 > $width) {
                $y += $shelf + 1;
                $x = 1;
                $shelf = 0;
            }
            $rects[$code] = [$x, $y, $glyph->width, $glyph->height];
            $x += $glyph->width + 1;
            $shelf = max($shelf, $glyph->height);
        }

        return [$rects, $y + $shelf + 1];
    }

    private static function powerOfTwo(int $n): int
    {
        $p = 1;
        while ($p < $n) {
            $p <<= 1;
        }

        return $p;
    }
}
