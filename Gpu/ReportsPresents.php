<?php

namespace Surface\Drawing\Gpu;

use Surface\Contracts\Drawing\PresentTiming;

/**
 * A device that knows when its presents reach the display: what a game
 * loop paces frames by.
 */
interface ReportsPresents
{
    /** The number of presents that landed (present() answered true); 0 before the first. */
    public function submitted(): int;

    /** The newest present the display is known to have shown; null before any. */
    public function lastPresented(): ?PresentTiming;

    /** Block until present $frame is shown or $timeoutNs passes; true when it was shown. */
    public function waitPresented(int $frame, int $timeoutNs): bool;
}
