<?php

namespace Surface\Drawing\Velvet;

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
        if ($target instanceof RingFramebuffer) {
            $target->repair();
        }

        // A paged framebuffer holds one page: only its rows are worth rasterizing.
        $rows = $target instanceof PagedFramebuffer ? $target->pageRegion($target->page()) : $this->surface();
        $this->rasterizers = [];

        foreach ($commands as $command) {
            if ($command[0] === 'clear') {
                $target->fill($this->mapper->fromRgba8($command[1] >> 24, ($command[1] >> 16) & 0xFF, ($command[1] >> 8) & 0xFF, $command[1] & 0xFF));

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
            $raster = $this->rasterizers["{$clip->x},{$clip->y},{$clip->width},{$clip->height}"] ??= $this->rasterize->rasterizer($clip, $this->edges);
            [$spans, $rgba] = match ($command[0]) {
                'path' => [$raster->fillPath($command[1], $command[2]), $command[3]],
                'polyline' => [$raster->polyline($command[1], $command[2], $command[3]), $command[4]],
                'ellipse' => [$raster->fillEllipse($command[1], $command[2], $command[3], $command[4]), $command[5]],
                'ring' => [$raster->strokeEllipse($command[1], $command[2], $command[3], $command[4], $command[5]), $command[6]],
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
}
