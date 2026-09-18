<?php

namespace Surface\Drawing\Concerns;

use Closure;
use Surface\Contracts\Drawing\Frame;
use Surface\Contracts\NativeWindows\Views\Color;

/**
 * The engine-neutral half of a frame loop: one hook, the clear colour,
 * continuous or on-demand frames, and the Frame clock. GPU targets add the
 * Painter and Executor through RunsFrames; CPU canvases add a Rasterizer.
 */
trait SchedulesFrames
{
    protected ?Closure $on_draw = null;

    protected Color $clear_color;

    protected bool $continuous = true;

    protected bool $pending_redraw = false;

    protected int $frame_index = 0;

    protected ?float $first_frame_at = null;

    protected ?float $last_frame_at = null;

    /** Default opaque black unless the class set a colour first (EPaperCanvas sets paper). */
    protected function bootSchedule(): void
    {
        if (! isset($this->clear_color)) {
            $this->clear_color = new Color(0.0, 0.0, 0.0, 1.0);
        }
    }

    public function onDraw(callable $hook): static
    {
        $this->on_draw = $hook(...);

        return $this;
    }

    public function setClearColor(Color $color): static
    {
        $this->clear_color = $color;

        return $this;
    }

    public function setContinuous(bool $continuous): static
    {
        $this->continuous = $continuous;

        return $this;
    }

    public function redraw(): static
    {
        $this->requestFrame();

        return $this;
    }

    /** Mark a frame wanted and let the class queue it natively — the door a self-driving target (GtkGLArea) uses; the default queues nothing. */
    public function requestFrame(): void
    {
        $this->pending_redraw = true;
        $this->queueNativeFrame();
    }

    /** True when the engine calls renderFrame() from its own render signal. */
    public function drivesOwnFrames(): bool
    {
        return false;
    }

    /** A hook is set, and frames are continuous or one was asked for. */
    protected function frameWanted(): bool
    {
        return ! is_null($this->on_draw) && ($this->continuous || $this->pending_redraw);
    }

    /** Advance the clock and answer this frame; clears the pending flag. */
    protected function nextFrame(int $width, int $height, float $scale): Frame
    {
        $this->pending_redraw = false;
        $now = microtime(true);
        $this->first_frame_at ??= $now;
        $frame = new Frame(
            index: $this->frame_index,
            time: $now - $this->first_frame_at,
            delta: is_null($this->last_frame_at) ? 0.0 : $now - $this->last_frame_at,
            width: $width,
            height: $height,
            scale: $scale,
        );
        $this->last_frame_at = $now;
        $this->frame_index++;

        return $frame;
    }

    /** Door for a self-driving target to queue a native render. Default: nothing. */
    protected function queueNativeFrame(): void {}
}
