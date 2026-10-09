<?php

namespace Surface\Drawing\Gpu;

/** A device that bounds how many frames the CPU runs ahead of the GPU. */
interface QueuesFrames
{
    /** 1 to 3, from the next frame on. Called once, after adopt() and before target(). */
    public function setFramesInFlight(int $frames): void;
}
