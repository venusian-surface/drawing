<?php

namespace Surface\Drawing\Canvases;

use Surface\Contracts\Drawing\CPUEngine;
use Surface\Contracts\Drawing\CPUHost;
use Surface\Contracts\Framebuffers\EInkColor;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\Region;

/** Whole panel, every refresh. Preserving; paper is the default clear, not black — a black attach would ink the panel. */
class EPaperCanvas extends CPUCanvas
{
    public function __construct(CPUEngine $engine, Framebuffer $buffer, CPUHost $host)
    {
        $this->clear_color = EInkColor::WHITE->color();   // before bootSchedule() runs in the parent
        parent::__construct($engine, $buffer, $host);
    }

    protected function begin(): void {}

    protected function present(): void {}

    public function damage(): array
    {
        return [Region::wholeSurface($this->host->width, $this->host->height)];
    }
}
