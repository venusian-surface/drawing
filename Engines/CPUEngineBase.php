<?php

namespace Surface\Drawing\Engines;

use Surface\Contracts\Drawing\CPUEngineDriver;
use Surface\Contracts\Framebuffers\FramebufferDriver;
use Surface\Framebuffers\FramebufferManager;

/** Stateless; asks the framebuffer manager for the configured driver at every attach, so a config change needs no reboot. */
abstract class CPUEngineBase implements CPUEngineDriver
{
    public function __construct(protected FramebufferManager|FramebufferDriver $framebuffers) {}

    protected function driver(): FramebufferDriver
    {
        return $this->framebuffers instanceof FramebufferDriver ? $this->framebuffers : $this->framebuffers->driver();
    }
}
