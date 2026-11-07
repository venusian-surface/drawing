<?php

namespace Surface\Drawing\Gpu;

use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Fonts\GlyphMask;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\Edges;

/**
 * Glyph masks in one single-channel texture that stays on the GPU across frames. Masks are packed on shelves,
 * rows as tall as the tallest mask on them, with 1 px of zero between masks. A frame whose new masks do not fit
 * resets the atlas at its start and places only its own masks; when those alone do not fit, the atlas doubles,
 * up to MAX.
 */
final class GlyphAtlas
{
    public const int MAX = 4096;

    /** The atlas's texture number in ATLAS, MASK and GLYPHS. */
    public const int TEXTURE = 0;

    /** @var array<string, Region> */
    private array $placed = [];

    private int $shelfY = 0;

    private int $shelfHeight = 0;

    private int $cursorX = 0;

    /** Whether the device holds this atlas: false until the first ATLAS, and after forget(). */
    private bool $made = false;

    public function __construct(
        private readonly Edges $edges,
        private int $size = 1024,
    ) {}

    public static function key(string $face, float $pixelSize, int $glyph, int $style): string
    {
        return "{$face}|{$pixelSize}|{$glyph}|{$style}";
    }

    public function size(): int
    {
        return $this->size;
    }

    /**
     * Places every mask the frame needs, before its first GLYPHS: ATLAS when the atlas is new or reset, then a
     * MASK per mask not already in it.
     *
     * @param  list<array{string, GlyphMask}>  $wanted  key and mask
     * @param  list<array<int, mixed>>  $operations
     *
     * @throws DrawingException When this frame's masks alone do not fit in MAX × MAX.
     */
    public function prepare(array $wanted, array &$operations): void
    {
        $masks = [];
        foreach ($wanted as [$key, $mask]) {
            $masks[$key] ??= $mask;
        }
        $new = $this->made ? array_diff_key($masks, $this->placed) : $masks;
        if ($new === []) {
            return;
        }

        $placing = $this->made ? $this->fit($new, $this->placed, $this->shelfY, $this->shelfHeight, $this->cursorX) : null;
        if (is_null($placing)) {
            // Reset at the start of the frame: only this frame's masks, doubling until they fit.
            while (is_null($placing = $this->fit($masks, [], 0, 0, 0))) {
                if ($this->size >= self::MAX) {
                    throw DrawingException::atlasFull(self::MAX);
                }
                $this->size *= 2;
            }
            $new = $masks;
            $operations[] = [Op::ATLAS, self::TEXTURE, $this->size, $this->size];
            $this->made = true;
        }
        [$this->placed, $this->shelfY, $this->shelfHeight, $this->cursorX] = $placing;

        foreach ($new as $key => $mask) {
            $operations[] = [Op::MASK, self::TEXTURE, $this->placed[$key], $this->bytes($mask->coverage)];
        }
    }

    /** Where a placed mask sits in the atlas. */
    public function where(string $key): Region
    {
        return $this->placed[$key];
    }

    /** The device let go of the atlas: the next prepare() makes it again. */
    public function forget(): void
    {
        $this->made = false;
        $this->placed = [];
        $this->shelfY = $this->shelfHeight = $this->cursorX = 0;
    }

    /**
     * The masks placed after $placed, from the given shelf state; null when they do not all fit.
     *
     * @param  array<string, GlyphMask>  $masks
     * @param  array<string, Region>  $placed
     * @return array{array<string, Region>, int, int, int}|null
     */
    private function fit(array $masks, array $placed, int $shelfY, int $shelfHeight, int $cursorX): ?array
    {
        foreach ($masks as $key => $mask) {
            if ($mask->width > $this->size || $mask->height > $this->size) {
                return null;
            }
            if ($cursorX + $mask->width > $this->size) {
                $shelfY += $shelfHeight + 1;
                $shelfHeight = $cursorX = 0;
            }
            if ($shelfY + $mask->height > $this->size) {
                return null;
            }
            $placed[$key] = new Region($cursorX, $shelfY, $mask->width, $mask->height);
            $cursorX += $mask->width + 1;
            $shelfHeight = max($shelfHeight, $mask->height);
        }

        return [$placed, $shelfY, $shelfHeight, $cursorX];
    }

    /** Hard edges keep coverage at or above 128 as 255 and drop the rest, as Velvet's spans do. */
    private function bytes(string $coverage): string
    {
        if ($this->edges !== Edges::HARD) {
            return $coverage;
        }
        static $from = null, $to = null;
        $from ??= implode(array_map(chr(...), range(0, 255)));
        $to ??= str_repeat("\x00", 128).str_repeat("\xFF", 128);

        return strtr($coverage, $from, $to);
    }
}
