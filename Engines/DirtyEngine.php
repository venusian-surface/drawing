<?php

namespace Surface\Drawing\Engines;

use Surface\Contracts\Drawing\CPUDrawTarget;
use Surface\Contracts\Drawing\CPUEngine;
use Surface\Contracts\Drawing\CPUHost;
use Surface\Drawing\Canvases\DirtyCanvas;

class DirtyEngine extends CPUEngineBase
{
    public function engine(): CPUEngine
    {
        return CPUEngine::DIRTY;
    }

    public function attach(CPUHost $host): CPUDrawTarget
    {
        return new DirtyCanvas($this->engine(), $this->driver()->dirty($host->format, $host->width, $host->height), $host);
    }
}
