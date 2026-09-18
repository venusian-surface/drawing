<?php

namespace Surface\Drawing\Engines;

use Surface\Contracts\Drawing\CPUDrawTarget;
use Surface\Contracts\Drawing\CPUEngine;
use Surface\Contracts\Drawing\CPUHost;
use Surface\Drawing\Canvases\NFramesCanvas;

class NFramesEngine extends CPUEngineBase
{
    public function engine(): CPUEngine
    {
        return CPUEngine::NFRAMES;
    }

    public function attach(CPUHost $host): CPUDrawTarget
    {
        return new NFramesCanvas($this->engine(), $this->driver()->ring($host->format, $host->width, $host->height, $host->frames), $host);
    }
}
