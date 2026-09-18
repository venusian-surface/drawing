<?php

namespace Surface\Drawing\Canvases;

use Surface\Contracts\Framebuffers\Region;

/** Everything, every flush. No clear per frame; the sketch overwrites what it wants. */
class FullCanvas extends CPUCanvas
{
    protected function begin(): void {}

    protected function present(): void {}

    public function damage(): array
    {
        return [Region::wholeSurface($this->host->width, $this->host->height)];
    }
}
