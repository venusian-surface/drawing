<?php

namespace Surface\Drawing\Text;

use Surface\Contracts\Fonts\FontEncoding;
use Surface\Contracts\Fonts\GFXFont;
use Surface\Contracts\Fonts\Glyph;
use Surface\Contracts\Fonts\YOffsetMode;

/**
 * Engine-free text layout over any GFXFont. (0, 0) is the top-left of the
 * first line box; every encoding's offset rule collapses into top(). Glyph
 * coverage decodes once per (face class, glyph offset) and is cached —
 * faces are stateless data, so the class is the identity.
 */
final class Typesetter
{
    /** @var array<class-string, int> */
    private array $ascents = [];

    /** @var array<string, list<array{int, int, int}>> */
    private array $runs = [];

    /** Line top → reference line, so the topmost glyph of the face sits at row 0. */
    public function ascent(GFXFont $font): int
    {
        return $this->ascents[$font::class] ??= $this->computeAscent($font);
    }

    /** Glyph top relative to the line top. */
    public function top(GFXFont $font, Glyph $glyph): int
    {
        return $this->rawTop($font, $glyph) + $this->ascent($font);
    }

    /** @return list<PlacedGlyph> */
    public function layout(GFXFont $font, string $text): array
    {
        $placed = [];
        $x = 0;
        $y = 0;
        $line = $font->lineHeight();
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $code = ord($text[$i]);
            if ($code === 0x0A) {
                $x = 0;
                $y += $line;

                continue;
            }
            if ($code === 0x0D) {
                continue;
            }
            $glyph = $font->glyph($code);
            if (is_null($glyph)) {
                continue;
            }
            if ($glyph->width > 0 && $glyph->height > 0) {
                $placed[] = new PlacedGlyph($code, $x + $glyph->x_offset, $y + $this->top($font, $glyph), $glyph);
            }
            $x += $glyph->x_advance;
        }

        return $placed;
    }

    /** Ink box [x, y, w, h] relative to the origin; all zero when nothing inks. @return array{int, int, int, int} */
    public function bounds(GFXFont $font, string $text): array
    {
        $placed = $this->layout($font, $text);
        if ($placed === []) {
            return [0, 0, 0, 0];
        }
        $x0 = PHP_INT_MAX;
        $y0 = PHP_INT_MAX;
        $x1 = PHP_INT_MIN;
        $y1 = PHP_INT_MIN;
        foreach ($placed as $p) {
            $x0 = min($x0, $p->x);
            $y0 = min($y0, $p->y);
            $x1 = max($x1, $p->x + $p->glyph->width);
            $y1 = max($y1, $p->y + $p->glyph->height);
        }

        return [$x0, $y0, $x1 - $x0, $y1 - $y0];
    }

    /** Horizontal runs of ink, [row, x0, x1] inclusive, glyph-local. @return list<array{int, int, int}> */
    public function runs(GFXFont $font, Glyph $glyph): array
    {
        return $this->runs[$font::class.':'.$glyph->bitmap_offset] ??= $this->decodeRuns($font, $glyph);
    }

    /** width × height bytes, 0..255: 1bpp is 0 / 255, 4bpp is nibble × 17. Empty for a glyph with no area or a face with no bytes. */
    public function coverage(GFXFont $font, Glyph $glyph): string
    {
        $w = $glyph->width;
        $h = $glyph->height;
        if ($w <= 0 || $h <= 0 || ! $font->hasBitmapData()) {
            return '';
        }
        $out = str_repeat("\0", $w * $h);
        if ($font->isColumnMajor()) {
            for ($col = 0; $col < $w; $col++) {
                $bits = $font->byte($glyph->bitmap_offset + $col);
                for ($row = 0; $row < $h; $row++) {
                    if (($bits >> $row) & 1) {
                        $out[$row * $w + $col] = "\xff";
                    }
                }
            }

            return $out;
        }
        if ($font->bitsPerPixel() === 4) {
            for ($i = 0; $i < $w * $h; $i++) {
                $byte = $font->byte($glyph->bitmap_offset + ($i >> 1));
                $nibble = ($i & 1) === 0 ? ($byte >> 4) & 0x0F : $byte & 0x0F;
                $out[$i] = chr($nibble * 17);
            }

            return $out;
        }
        $offset = $glyph->bitmap_offset;
        $bits = 0;
        for ($i = 0; $i < $w * $h; $i++) {
            if (($i & 7) === 0) {
                $bits = $font->byte($offset++);
            }
            if ($bits & 0x80) {
                $out[$i] = "\xff";
            }
            $bits = ($bits << 1) & 0xFF;
        }

        return $out;
    }

    /** @return list<array{int, int, int}> */
    private function decodeRuns(GFXFont $font, Glyph $glyph): array
    {
        $coverage = $this->coverage($font, $glyph);
        if ($coverage === '') {
            return [];
        }
        $w = $glyph->width;
        $h = $glyph->height;
        $threshold = $font->bitsPerPixel() === 4 ? $font->alphaThreshold() * 17 : 128;
        $runs = [];
        for ($row = 0; $row < $h; $row++) {
            $start = null;
            for ($col = 0; $col <= $w; $col++) {
                $on = $col < $w && ord($coverage[$row * $w + $col]) >= $threshold;
                if ($on && is_null($start)) {
                    $start = $col;
                } elseif (! $on && ! is_null($start)) {
                    $runs[] = [$row, $start, $col - 1];
                    $start = null;
                }
            }
        }

        return $runs;
    }

    private function computeAscent(GFXFont $font): int
    {
        if ($font->isColumnMajor() || ($font->encoding() === FontEncoding::LVGL && $font->yOffsetMode() === YOffsetMode::LINE)) {
            return 0;
        }
        $min = 0;
        for ($code = $font->first(); $code <= $font->last(); $code++) {
            $glyph = $font->glyph($code);
            if (is_null($glyph) || $glyph->width <= 0 || $glyph->height <= 0) {
                continue;
            }
            $min = min($min, $this->rawTop($font, $glyph));
        }

        return -$min;
    }

    /** Top before the ascent shift; negative is above the reference line. The three 0.7 drawChar branches. */
    private function rawTop(GFXFont $font, Glyph $glyph): int
    {
        if ($font->isColumnMajor()) {
            return 0;
        }
        if ($font->encoding() === FontEncoding::LVGL) {
            if ($font->yOffsetMode() === YOffsetMode::LINE) {
                return $font->lineHeight() - $glyph->height - $glyph->y_offset;
            }
            $top = $glyph->y_offset;
            $cap = $font->capHeight();
            if ($top >= 0 && $glyph->height < $cap) {
                $top += $cap - $glyph->height;
            }

            return $top;
        }

        return $glyph->y_offset;
    }
}
