<?php

namespace Surface\Drawing\Canvases;

use Surface\Contracts\Drawing\CPUEngine;
use Surface\Contracts\Drawing\CPUHost;
use Surface\Contracts\Framebuffers\MultiFrameFramebuffer;
use Surface\Contracts\Framebuffers\Region;

/** A ring of frames: the back frame is cleared and drawn, present() flips, every read is the front. Video and games. */
class NFramesCanvas extends CPUCanvas
{
    public function __construct(CPUEngine $engine, protected MultiFrameFramebuffer $ring, CPUHost $host)
    {
        parent::__construct($engine, $ring, $host);
    }

    protected function begin(): void
    {
        $this->ring->fill($this->word($this->clear_color));
    }

    protected function present(): void
    {
        $this->ring->present();
    }

    public function damage(): array
    {
        return [Region::wholeSurface($this->host->width, $this->host->height)];
    }
}
