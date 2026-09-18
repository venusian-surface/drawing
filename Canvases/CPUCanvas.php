<?php

namespace Surface\Drawing\Canvases;

use Surface\Contracts\Drawing\CPUDrawTarget;
use Surface\Contracts\Drawing\CPUEngine;
use Surface\Contracts\Drawing\CPUHost;
use Surface\Contracts\Drawing\Drawing2D;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Drawing\Concerns\SchedulesFrames;
use Surface\Drawing\Rasterizer;
use Surface\Framebuffers\PixelMapper;

/**
 * A CPUDrawTarget over one framebuffer: SchedulesFrames for the hook and
 * clock, a Rasterizer for the drawing, and a begin/present pair each engine
 * fills. A hook exception propagates after present() — the frame is never
 * left half-done. Preserving engines fill with the clear colour once here.
 */
abstract class CPUCanvas implements CPUDrawTarget
{
    use SchedulesFrames;

    protected Rasterizer $rasterizer;

    protected PixelMapper $mapper;

    public function __construct(
        protected CPUEngine $engine,
        protected Framebuffer $buffer,
        protected CPUHost $host,
    ) {
        $this->bootSchedule();
        $this->mapper = PixelMapper::for($host->format);
        $this->rasterizer = new Rasterizer($buffer, $this->mapper);
        if ($buffer->preservesContentsOnPresent()) {
            $buffer->fill($this->word($this->clear_color));
        }
    }

    public function engine(): CPUEngine
    {
        return $this->engine;
    }

    public function hostFormat(): FormatSpec
    {
        return $this->host->format;
    }

    public function drawing(): Drawing2D
    {
        return $this->rasterizer;
    }

    public function drawableSize(): array
    {
        return [$this->host->width, $this->host->height];
    }

    public function renderFrame(): bool
    {
        if (! $this->frameWanted()) {
            return false;
        }
        $frame = $this->nextFrame($this->host->width, $this->host->height, 1.0);
        $this->begin();
        try {
            $this->rasterizer->begin();
            ($this->on_draw)($this->rasterizer, $frame);
        } finally {
            $this->present();
        }

        return true;
    }

    public function flush(?FormatSpec $spec = null, bool $as_array = false): string|array
    {
        return $this->buffer->flush($spec ?? $this->host->format, $as_array);
    }

    public function flushRegion(Region $region, ?FormatSpec $spec = null, bool $as_array = false): string|array
    {
        return $this->buffer->flushRegion($region, $spec ?? $this->host->format, $as_array);
    }

    public function rgba8(): string
    {
        return $this->buffer->toRgba8();
    }

    /** Before the hook: epoch, clear, page — whatever the engine does. */
    abstract protected function begin(): void;

    /** After the hook, exception or not. */
    abstract protected function present(): void;

    protected function word(Color $color): int
    {
        return $this->mapper->map($color);
    }
}
