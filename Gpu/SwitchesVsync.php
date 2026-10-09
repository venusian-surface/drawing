<?php

namespace Surface\Drawing\Gpu;

use Surface\Contracts\Drawing\VSync;

/**
 * A device whose presents can wait for the display or not. The engine
 * applies the window's vsync through it, falling back by VSync::fallbacks().
 */
interface SwitchesVsync
{
    /** @return list<VSync> The modes it presents with; always VSync::On. */
    public function vsyncModes(): array;

    /** Present with $vsync from the next present on: one of vsyncModes(). */
    public function applyVsync(VSync $vsync): void;
}
