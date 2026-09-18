<?php

namespace Surface\Drawing\Engines;

use Surface\Contracts\Drawing\CPUDrawTarget;
use Surface\Contracts\Drawing\CPUEngine;
use Surface\Contracts\Drawing\CPUHost;
use Surface\Drawing\Canvases\EPaperCanvas;

class EPaperEngine extends CPUEngineBase
{
    public function engine(): CPUEngine
    {
        return CPUEngine::EPAPER;
    }

    public function attach(CPUHost $host): CPUDrawTarget
    {
        return new EPaperCanvas($this->engine(), $this->driver()->epaper($host->format, $host->width, $host->height), $host);
    }
}
