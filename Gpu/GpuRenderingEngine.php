<?php

namespace Surface\Drawing\Gpu;

use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\OutputTarget;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\WindowOutput;
use Surface\Contracts\EmbeddedDisplays\EmbeddedDisplay;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Framebuffers\GLFramebuffer;
use Surface\Contracts\Rasterize\Edges;
use Surface\Drawing\DrawingManager;
use Surface\Drawing\RenderingEngine;
use Surface\Framebuffers\PixelMapper;
use Surface\Framebuffers\PixelMapperMode;
use Throwable;

/**
 * Every GPU engine: a GpuDevice from an engine package, and everything
 * engine-neutral here. A frame's commands are lowered once (Lowering) and
 * handed to the device; frames, damage and partial frames are the base
 * class's, so a partial frame draws only the changed regions into a target
 * that keeps its pixels.
 *
 * Where the frame goes:
 *
 *   a window   the engine borrows a native surface from it; the window's
 *              present() copies the target in on the GPU
 *   a display  the display binds the engine's framebuffer; its present()
 *              reads back what changed, in the panel's format
 *   nothing    offscreen: whoever holds framebuffer() drains it
 */
class GpuRenderingEngine extends RenderingEngine implements SurfaceBorrower
{
    /** The arguments from() reads. */
    public const array ARGUMENTS = ['output', 'width', 'height', 'edges'];

    protected GLFramebuffer $framebuffer;

    protected Edges $edges;

    protected ?LentSurface $surface = null;

    /**
     * @param  int  $width  The target's size; an output's pixelSize() when it draws for one.
     * @param  Edges|null  $edges  Null picks by output: hard for a display whose format cannot blend (mono, palette, planar), anti-aliased otherwise.
     * @param  OutputTarget|null  $output  A WindowOutput, an EmbeddedDisplay, or null for offscreen.
     *
     * @throws DrawingException When the window lends nothing the device presents into, or the output is neither kind.
     */
    public function __construct(
        protected GpuDevice $device,
        int $width,
        int $height,
        ?Edges $edges = null,
        protected ?OutputTarget $output = null,
    ) {
        $this->edges = $edges ?? self::edgesFor($output);

        if ($output instanceof WindowOutput) {
            $this->surface = $this->borrow($output);
            try {
                $device->adopt($this->surface);
                $this->framebuffer = $device->target($width, $height, $this->samples());
            } catch (Throwable $e) {
                $output->reclaim();

                throw $e;
            }

            return;
        }
        if (! is_null($output) && ! $output instanceof EmbeddedDisplay) {
            throw new DrawingException('A GPU engine draws for a window (a WindowOutput), for a display (an EmbeddedDisplay), or for no output; got '.get_debug_type($output).'.');
        }

        $this->framebuffer = $device->target($width, $height, $this->samples());
        try {
            $output?->bind($this->framebuffer);
        } catch (Throwable $e) {
            $device->release();

            throw $e;
        }
    }

    /**
     * An engine from the arguments every GPU engine shares: 'output' alone,
     * or 'width' and 'height'; 'edges'. A package's creator:
     *
     *     $drawing->extend('metal', fn (array $args, DrawingManager $drawing) => GpuRenderingEngine::from(new MetalDevice, $args, $drawing));
     *
     * Arguments are checked before the device is touched.
     *
     * @param  array<string, mixed>  $args
     *
     * @throws DrawingException When the engine cannot be built from $args.
     */
    public static function from(GpuDevice $device, array $args, DrawingManager $drawing): static
    {
        $name = $device->name();
        foreach (array_keys($args) as $key) {
            if ($key === 'framebuffer') {
                throw new DrawingException("{$name} draws into its own framebuffer and does not take one: read it from the engine's framebuffer().");
            }
            if (! in_array($key, self::ARGUMENTS, true)) {
                throw new DrawingException("{$name} does not take '{$key}'. It takes: ".implode(', ', self::ARGUMENTS).'.');
            }
        }
        $edges = $drawing->edgesFrom($args);

        if (array_key_exists('output', $args)) {
            $output = $args['output'];
            if (! $output instanceof OutputTarget) {
                throw new DrawingException("'output' is an OutputTarget: a canvas or a display.");
            }
            foreach (['width', 'height'] as $key) {
                if (array_key_exists($key, $args)) {
                    throw new DrawingException("'output' comes alone: '{$key}' describes a framebuffer to be made.");
                }
            }
            [$width, $height] = $output->pixelSize();
            if ($width < 1 || $height < 1) {
                throw new DrawingException('The output has no size yet: show its window first.');
            }

            return new static($device, $width, $height, $edges, $output);
        }

        if (! isset($args['width'], $args['height'])) {
            throw new DrawingException("{$name} needs an 'output', or a 'width' and a 'height'.");
        }
        if (! is_int($args['width']) || ! is_int($args['height'])) {
            throw new DrawingException("'width' and 'height' are integers.");
        }
        if ($args['width'] < 1 || $args['height'] < 1) {
            throw new DrawingException("'width' and 'height' are at least 1.");
        }

        return new static($device, $args['width'], $args['height'], $edges);
    }

    public function name(): string
    {
        return $this->device->name();
    }

    public function framebuffer(): Framebuffer
    {
        return $this->framebuffer;
    }

    public function edges(): Edges
    {
        return $this->edges;
    }

    public function device(): GpuDevice
    {
        return $this->device;
    }

    /** A window resized since the last frame: the target is re-made at its new size and the frame is drawn whole. */
    public function begin(): static
    {
        if (! $this->drawing() && ! is_null($this->surface) && ! $this->surface->released()) {
            [$width, $height] = $this->surface->size();
            if ($width > 0 && $height > 0 && ($width !== $this->framebuffer->viewportWidth() || $height !== $this->framebuffer->viewportHeight())) {
                $this->framebuffer = $this->device->target($width, $height, $this->samples());
                $this->invalidate();
            }
        }

        return parent::begin();
    }

    /** A replay draws the whole last frame: the whole surface is damage. */
    public function replay(): static
    {
        parent::replay();
        $this->framebuffer->drawn([Region::wholeSurface($this->width(), $this->height())]);

        return $this;
    }

    public function lendingHandles(): array
    {
        return $this->device->handles();
    }

    public function presentInto(LentSurface $surface): bool
    {
        return $this->device->present($surface);
    }

    /** Give the window its surface back and let go of the device. The engine draws nothing after. */
    public function release(): void
    {
        if ($this->output instanceof WindowOutput && ! is_null($this->surface) && ! $this->surface->released()) {
            $this->output->reclaim();
        }
        $this->device->release();
    }

    protected function execute(array $commands): void
    {
        $this->device->draw(Lowering::lower($commands, $this->width(), $this->height()));
        $this->framebuffer->drawn($this->damage());
    }

    /** Four samples resolve to anti-aliased edges; one sample keeps a pixel when its centre is inside. */
    protected function samples(): int
    {
        return $this->edges === Edges::ANTIALIASED ? 4 : 1;
    }

    /** The first surface kind the device presents into that the window lends. */
    protected function borrow(WindowOutput $window): LentSurface
    {
        $lends = $window->surfaces();
        foreach ($this->device->surfaces() as $kind) {
            if (in_array($kind, $lends, true)) {
                return $window->lend($kind, $this);
            }
        }

        $hosts = array_values(array_unique(array_merge(['velvet'], ...array_map(fn (SurfaceKind $kind): array => $kind->engines(), $lends))));
        $lent = $lends === [] ? 'no surface' : implode(', ', array_map(fn (SurfaceKind $kind): string => $kind->value, $lends));

        throw new DrawingException("This window cannot host '{$this->device->name()}': it lends {$lent}. It can host: ".implode(', ', $hosts).'.');
    }

    /** Velvet's rule, read from the output: anti-aliased where its format can blend. */
    private static function edgesFor(?OutputTarget $output): Edges
    {
        if (is_null($output)) {
            return Edges::ANTIALIASED;
        }

        return in_array(PixelMapper::for($output->pixelFormat())->mode(), [PixelMapperMode::RGB, PixelMapperMode::GREY], true)
            ? Edges::ANTIALIASED
            : Edges::HARD;
    }
}
