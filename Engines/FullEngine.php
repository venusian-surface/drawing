<?php

namespace Surface\Drawing\Engines;

use Surface\Contracts\Drawing\CPUDrawTarget;
use Surface\Contracts\Drawing\CPUEngine;
use Surface\Contracts\Drawing\CPUHost;
use Surface\Drawing\Canvases\FullCanvas;

class FullEngine extends CPUEngineBase
{
    public function engine(): CPUEngine
    {
        return CPUEngine::FULL;
    }

    public function attach(CPUHost $host): CPUDrawTarget
    {
        return new FullCanvas($this->engine(), $this->driver()->full($host->format, $host->width, $host->height), $host);
    }
}
