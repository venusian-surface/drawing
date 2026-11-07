<?php

namespace Surface\Drawing\Velvet;

use Surface\Contracts\Fonts\TrueTypeFont;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\PagedFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Framebuffers\RingFramebuffer;
use Surface\Contracts\Rasterize\Edges;
use Surface\Contracts\Rasterize\RasterizeDriver;
use Surface\Contracts\Rasterize\Rasterizer;
use Surface\Drawing\RenderingEngine;
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

    /** Glyph runs kept; the least recently used goes first. */
    private const int GLYPH_RUNS = 2048;

    /** @var array<string, list<array{int, int, int, int}>> face|pixelSize|glyph|style|edges => [row, col, length, coverage] */
    private array $glyphRuns = [];

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
            if ($command[0] === 'glyphs') {
                $spans = $this->glyphSpans($command[1], $command[2], $command[3], $clip);
                if ($spans !== '') {
                    $target->paintSpans($spans, $command[4]);
                }

                continue;
            }
            $raster = $this->rasterizers["{$clip->x},{$clip->y},{$clip->width},{$clip->height}"] ??= $this->rasterize->rasterizer($clip, $this->edges);
            [$spans, $rgba] = match ($command[0]) {
                'path' => [$raster->fillPath($command[1], $command[2]), $command[3]],
                'polyline' => [$raster->polyline($command[1], $command[2], $command[3]), $command[4]],
                'ellipse' => [$raster->fillEllipse($command[1], $command[2], $command[3], $command[4]), $command[5]],
                'ring' => [$raster->strokeEllipse($command[1], $command[2], $command[3], $command[4], $command[5]), $command[6]],
                'roundrect' => [$raster->fillRoundRect($command[1], $command[2], $command[3] - $command[1], $command[4] - $command[2], $command[5]), $command[6]],
                'roundring' => [$raster->strokeRoundRect($command[1], $command[2], $command[3] - $command[1], $command[4] - $command[2], $command[5], $command[6]), $command[7]],
                'shadow' => [$raster->shadow($command[1], $command[2], $command[3] - $command[1], $command[4] - $command[2], $command[5], $command[6]), $command[7]],
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

    /**
     * Every glyph's mask runs at its place, cut to the clip, rows top to bottom.
     * shortcut: translucent text blends twice where two glyph boxes overlap; upgrade by merging spans per row when that shows.
     *
     * @param  list<array{string, int, int, int, int}>  $glyphs
     */
    private function glyphSpans(TrueTypeFont $font, float $pixelSize, array $glyphs, Region $clip): string
    {
        $right = $clip->right();
        $bottom = $clip->bottom();
        /** @var array<int, list<string>> $rows */
        $rows = [];
        foreach ($glyphs as [$face, $glyph, $left, $top, $style]) {
            foreach ($this->glyphRuns($font, $face, $pixelSize, $glyph, $style) as [$row, $col, $length, $coverage]) {
                $y = $top + $row;
                if ($y < $clip->y || $y >= $bottom) {
                    continue;
                }
                $x0 = max($left + $col, $clip->x);
                $x1 = min($left + $col + $length, $right);
                if ($x1 > $x0) {
                    $rows[$y][] = pack('vvvC', $y, $x0, $x1 - $x0, $coverage);
                }
            }
        }
        if ($rows === []) {
            return '';
        }
        ksort($rows);

        return implode('', array_merge(...$rows));
    }

    /**
     * A glyph mask as runs of equal non-zero coverage; hard edges keep coverage of 128 and up, at 255.
     *
     * @return list<array{int, int, int, int}>
     */
    private function glyphRuns(TrueTypeFont $font, string $face, float $pixelSize, int $glyph, int $style): array
    {
        $key = "{$face}|{$pixelSize}|{$glyph}|{$style}|{$this->edges->name}";
        if (isset($this->glyphRuns[$key])) {
            $runs = $this->glyphRuns[$key];
            unset($this->glyphRuns[$key]);

            return $this->glyphRuns[$key] = $runs;
        }

        $mask = $font->mask($face, $pixelSize, $glyph, $style);
        $hard = $this->edges === Edges::HARD;
        $runs = [];
        for ($row = 0; $row < $mask->height; $row++) {
            $start = $row * $mask->width;
            $col = 0;
            while ($col < $mask->width) {
                $c = ord($mask->coverage[$start + $col]);
                if ($hard) {
                    $c = $c >= 128 ? 255 : 0;
                }
                $end = $col + 1;
                while ($end < $mask->width && ($hard ? (ord($mask->coverage[$start + $end]) >= 128 ? 255 : 0) : ord($mask->coverage[$start + $end])) === $c) {
                    $end++;
                }
                if ($c > 0) {
                    $runs[] = [$row, $col, $end - $col, $c];
                }
                $col = $end;
            }
        }
        if (count($this->glyphRuns) >= self::GLYPH_RUNS) {
            unset($this->glyphRuns[array_key_first($this->glyphRuns)]);
        }

        return $this->glyphRuns[$key] = $runs;
    }
}
