<?php

namespace Surface\Drawing\Canvases;

use Surface\Contracts\Drawing\CPUEngine;
use Surface\Contracts\Drawing\CPUHost;
use Surface\Contracts\Framebuffers\DamageTrackingFramebuffer;

/** Only what changed. renderFrame() opens an epoch; damage() answers it until the next. No clear per frame. */
class DirtyCanvas extends CPUCanvas
{
    public function __construct(CPUEngine $engine, protected DamageTrackingFramebuffer $dirty, CPUHost $host)
    {
        parent::__construct($engine, $dirty, $host);
        $dirty->beginEpoch();
    }

    protected function begin(): void
    {
        $this->dirty->beginEpoch();
    }

    protected function present(): void {}

    public function damage(): array
    {
        return $this->dirty->damage();
    }
}
