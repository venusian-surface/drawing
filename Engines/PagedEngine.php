<?php

namespace Surface\Drawing\Engines;

use Surface\Contracts\Drawing\CPUEngine;
use Surface\Contracts\Drawing\CPUHost;
use Surface\Contracts\Drawing\PagedDrawTarget;
use Surface\Drawing\Canvases\PagedCanvas;

class PagedEngine extends CPUEngineBase
{
    public function engine(): CPUEngine
    {
        return CPUEngine::PAGED;
    }

    public function attach(CPUHost $host): PagedDrawTarget
    {
        return new PagedCanvas($this->engine(), $this->driver()->paged($host->format, $host->width, $host->height, $host->page_rows), $host);
    }
}
