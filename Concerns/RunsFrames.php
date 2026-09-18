<?php

namespace Surface\Drawing\Concerns;

use Surface\Contracts\Drawing\Drawing2D;
use Surface\Contracts\Drawing\Executor;
use Surface\Drawing\Painter;

/**
 * The GPU frame loop every GPUDrawTarget shares: SchedulesFrames plus the
 * Painter over the executor and the rule that a begun frame is always ended.
 * The using class answers its executor, size in points, backing scale and
 * visibility, and calls bootFrames() from its constructor.
 */
trait RunsFrames
{
    use SchedulesFrames;

    protected Painter $painter;

    abstract public function executor(): Executor;

    /** @return array{int, int} Target size in points. */
    abstract protected function frameSize(): array;

    abstract protected function frameScale(): float;

    abstract protected function frameVisible(): bool;

    protected function bootFrames(Executor $executor): void
    {
        $this->painter = new Painter($executor);
        $this->bootSchedule();
    }

    public function drawing(): Drawing2D
    {
        return $this->painter;
    }

    /**
     * One frame now. Skipped when not visible, hookless, or neither continuous
     * nor pending; skipped when the executor has no drawable. A hook exception
     * propagates — sketch code, sketch problem — but the frame is ended first.
     */
    public function renderFrame(): bool
    {
        if (! $this->frameVisible() || ! $this->frameWanted()) {
            return false;
        }

        $executor = $this->executor();
        if (! $executor->beginFrame($this->clear_color)) {
            return false;
        }

        [$width, $height] = $this->frameSize();
        $frame = $this->nextFrame($width, $height, $this->frameScale());

        try {
            $this->painter->begin($width, $height, $frame->scale);
            ($this->on_draw)($this->painter, $frame);
            $this->painter->flush();
        } finally {
            $this->painter->reset();
            $executor->endFrame();
        }

        return true;
    }
}
