<?php

namespace Surface\Drawing\Gpu;

use Surface\Contracts\Drawing\ColorSpace;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\OutputTarget;
use Surface\Contracts\Drawing\PresentTiming;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\TargetFormat;
use Surface\Contracts\Drawing\VSync;
use Surface\Contracts\Drawing\WindowOutput;
use Surface\Contracts\EmbeddedDisplays\EmbeddedDisplay;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\GLFramebuffer;
use Surface\Contracts\Framebuffers\HdrReadback;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\Edges;
use Surface\Drawing\DrawingManager;
use Surface\Drawing\RenderingEngine;
use Surface\Framebuffers\HdrImage;
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
 *   a window   the engine borrows a native surface from it and presents
 *              with its vsync; the window's present() copies the target in
 *              on the GPU
 *   a display  the display binds the engine's framebuffer; its present()
 *              reads back what changed, in the panel's format
 *   nothing    offscreen: whoever holds framebuffer() drains it
 */
class GpuRenderingEngine extends RenderingEngine implements SurfaceBorrower
{
    /** The arguments from() reads. */
    public const array ARGUMENTS = ['output', 'width', 'height', 'edges', 'format', 'colorspace', 'frames_in_flight', 'resolution'];

    protected GLFramebuffer $framebuffer;

    protected Edges $edges;

    protected ?LentSurface $surface = null;

    protected ?VSync $vsync = null;

    /** @var array{int, int} The surface size a fixed target was last placed for. */
    private array $placedFor = [0, 0];

    /**
     * @param  int  $width  The target's size; an output's pixelSize() when it draws for one.
     * @param  Edges|null  $edges  Null picks by output: hard for a display whose format cannot blend (mono, palette, planar), anti-aliased otherwise.
     * @param  OutputTarget|null  $output  A WindowOutput, an EmbeddedDisplay, or null for offscreen.
     * @param  TargetFormat  $format  The target's pixel format; past RGBA8 sRGB only for a window or offscreen, on a device that TargetsFormats.
     * @param  ColorSpace  $colorspace  One of $format->colorSpaces().
     * @param  int|null  $framesInFlight  1 to 3 on a device that QueuesFrames; null leaves the device's own.
     * @param  bool  $fixed  The target keeps $width × $height when the window resizes; the window scales it into its presentRect().
     *
     * @throws DrawingException When the window lends nothing the device presents into, the output is neither kind, or the device cannot make the target.
     */
    public function __construct(
        protected GpuDevice $device,
        int $width,
        int $height,
        ?Edges $edges = null,
        protected ?OutputTarget $output = null,
        protected TargetFormat $format = TargetFormat::Rgba8,
        protected ColorSpace $colorspace = ColorSpace::Srgb,
        protected ?int $framesInFlight = null,
        protected bool $fixed = false,
    ) {
        $this->edges = $edges ?? self::edgesFor($output);
        $this->checkFormat($output);
        $this->checkPacing();

        if ($output instanceof WindowOutput) {
            $this->surface = $this->borrow($output);
            try {
                $device->adopt($this->surface);
                $this->queueFrames();
                $this->followVsync($this->surface);
                $this->framebuffer = $this->makeTarget($width, $height);
                $this->placedFor = $this->surface->size();
            } catch (Throwable $e) {
                $output->reclaim();

                throw $e;
            }

            return;
        }
        if (! is_null($output) && ! $output instanceof EmbeddedDisplay) {
            throw new DrawingException('A GPU engine draws for a window (a WindowOutput), for a display (an EmbeddedDisplay), or for no output; got '.get_debug_type($output).'.');
        }

        $this->queueFrames();
        $this->framebuffer = $this->makeTarget($width, $height);
        try {
            $output?->bind($this->framebuffer);
        } catch (Throwable $e) {
            $device->release();

            throw $e;
        }
    }

    /**
     * An engine from the arguments every GPU engine shares: 'output' alone,
     * or 'width' and 'height'; 'edges'; 'format' (a TargetFormat) and
     * 'colorspace' (a ColorSpace, by default the format's first);
     * 'frames_in_flight' (1 to 3); 'resolution' ([width, height], with a
     * window's 'output'). A package's creator:
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
        [$format, $space] = self::formatFrom($args);
        $frames = $args['frames_in_flight'] ?? null;
        if (! is_null($frames) && (! is_int($frames) || $frames < 1 || $frames > 3)) {
            throw new DrawingException("'frames_in_flight' is 1, 2 or 3.");
        }
        $resolution = $args['resolution'] ?? null;
        if (! is_null($resolution)) {
            if (! is_array($resolution) || ! array_is_list($resolution) || count($resolution) !== 2
                || ! is_int($resolution[0]) || ! is_int($resolution[1]) || $resolution[0] < 1 || $resolution[1] < 1) {
                throw new DrawingException("'resolution' is [width, height], each an integer of at least 1.");
            }
            if (! ($args['output'] ?? null) instanceof WindowOutput) {
                throw new DrawingException("'resolution' renders a window's frames at a fixed size: give it with a window's 'output'.");
            }
        }

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
            [$width, $height] = $resolution ?? $output->pixelSize();
            if ($width < 1 || $height < 1) {
                throw new DrawingException('The output has no size yet: show its window first.');
            }

            return new static($device, $width, $height, $edges, $output, $format, $space, $frames, ! is_null($resolution));
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

        return new static($device, $args['width'], $args['height'], $edges, format: $format, colorspace: $space, framesInFlight: $frames);
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

    /** The vsync the device presents with; null offscreen, for a display, or for a device that keeps its own. */
    public function vsync(): ?VSync
    {
        return $this->vsync;
    }

    public function format(): TargetFormat
    {
        return $this->format;
    }

    public function colorspace(): ColorSpace
    {
        return $this->colorspace;
    }

    /**
     * The whole target in half floats: what an HDR frame holds past SDR white, for a screenshot.
     *
     * @throws DrawingException When the target reads back only RGBA8.
     */
    public function hdrSnapshot(): HdrImage
    {
        if (! $this->framebuffer instanceof HdrReadback) {
            throw new DrawingException("{$this->name()}'s target reads back no float pixels: read it as RGBA8 through framebuffer().");
        }

        return HdrImage::fromRgba16f($this->framebuffer->readRgba16f(Region::wholeSurface($this->width(), $this->height())), $this->width(), $this->height());
    }

    /** The frames in flight the engine asked of the device; null where it left the device's own. */
    public function framesInFlight(): ?int
    {
        return $this->framesInFlight;
    }

    /** Presents that landed; null for a device that does not report them. */
    public function submitted(): ?int
    {
        return $this->device instanceof ReportsPresents ? $this->device->submitted() : null;
    }

    /** The newest present the display showed; null before any, or for a device that does not report them. */
    public function presents(): ?PresentTiming
    {
        return $this->device instanceof ReportsPresents ? $this->device->lastPresented() : null;
    }

    /**
     * Block until present $frame is shown or $timeoutNs passes; true when it was shown.
     *
     * @throws DrawingException For a device that does not report presents, or a frame below 1 or a negative timeout.
     */
    public function waitPresented(int $frame, int $timeoutNs): bool
    {
        if (! $this->device instanceof ReportsPresents) {
            throw new DrawingException("{$this->name()} reports no presents: presents() is null for it.");
        }
        if ($frame < 1 || $timeoutNs < 0) {
            throw new DrawingException("waitPresented() takes a frame of at least 1 and a timeout of at least 0 ns, got {$frame} and {$timeoutNs}.");
        }

        return $this->device->waitPresented($frame, $timeoutNs);
    }

    /**
     * A window resized since the last frame: the target is re-made at its new
     * size and the frame is drawn whole. A fixed target keeps its size, and
     * the whole of it is marked drawn so the next present repaints its new
     * placement.
     */
    public function begin(): static
    {
        if (! $this->drawing() && ! is_null($this->surface) && ! $this->surface->released()) {
            [$width, $height] = $this->surface->size();
            if ($this->fixed) {
                if ([$width, $height] !== $this->placedFor) {
                    $this->placedFor = [$width, $height];
                    $this->framebuffer->drawn([Region::wholeSurface($this->width(), $this->height())]);
                }
            } elseif ($width > 0 && $height > 0 && ($width !== $this->framebuffer->viewportWidth() || $height !== $this->framebuffer->viewportHeight())) {
                $this->framebuffer = $this->makeTarget($width, $height);
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
    /** The target in the engine's format: through target() for RGBA8 sRGB, targetAs() otherwise. */
    protected function makeTarget(int $width, int $height): GLFramebuffer
    {
        if ($this->format === TargetFormat::Rgba8 && $this->colorspace === ColorSpace::Srgb) {
            return $this->device->target($width, $height, $this->samples());
        }
        /** @var GpuDevice&TargetsFormats $device */
        $device = $this->device;

        return $device->targetAs($width, $height, $this->samples(), $this->format, $this->colorspace);
    }

    /** A format past RGBA8 sRGB: a pair the format carries, not for a display, one the device makes. Asked before the device is touched. */
    private function checkFormat(?OutputTarget $output): void
    {
        if ($this->format === TargetFormat::Rgba8 && $this->colorspace === ColorSpace::Srgb) {
            return;
        }
        $carries = $this->format->colorSpaces();
        if (! in_array($this->colorspace, $carries, true)) {
            throw new DrawingException("{$this->format->value} carries ".implode(', ', array_map(fn (ColorSpace $space): string => $space->value, $carries))."; not {$this->colorspace->value}.");
        }
        if ($output instanceof EmbeddedDisplay) {
            throw new DrawingException("A display shows RGBA8 sRGB: 'format' and 'colorspace' are for a window or an offscreen target.");
        }
        $makes = $this->device instanceof TargetsFormats ? $this->device->targetFormats() : [];
        foreach ($makes as [$format, $space]) {
            if ($format === $this->format && $space === $this->colorspace) {
                return;
            }
        }
        $names = array_merge(['rgba8 srgb'], array_map(fn (array $pair): string => "{$pair[0]->value} {$pair[1]->value}", $makes));

        throw new DrawingException("{$this->device->name()} makes no {$this->format->value} {$this->colorspace->value} target. It makes: ".implode(', ', $names).'.');
    }

    /** Frames in flight on a device that bounds them, asked before the device is touched. */
    private function checkPacing(): void
    {
        if (! is_null($this->framesInFlight) && ! $this->device instanceof QueuesFrames) {
            throw new DrawingException("{$this->device->name()} does not bound frames in flight: leave 'frames_in_flight' out for it.");
        }
    }

    private function queueFrames(): void
    {
        if (! is_null($this->framesInFlight) && $this->device instanceof QueuesFrames) {
            $this->device->setFramesInFlight($this->framesInFlight);
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{TargetFormat, ColorSpace}
     */
    private static function formatFrom(array $args): array
    {
        $format = $args['format'] ?? TargetFormat::Rgba8;
        if (! $format instanceof TargetFormat) {
            throw new DrawingException("'format' is a TargetFormat: ".implode(', ', array_map(fn (TargetFormat $case): string => $case->value, TargetFormat::cases())).'.');
        }
        $space = $args['colorspace'] ?? $format->colorSpaces()[0];
        if (! $space instanceof ColorSpace) {
            throw new DrawingException("'colorspace' is a ColorSpace: ".implode(', ', array_map(fn (ColorSpace $case): string => $case->value, ColorSpace::cases())).'.');
        }

        return [$format, $space];
    }

    /** A device that switches vsync presents with the window's, now and as it changes. */
    private function followVsync(LentSurface $surface): void
    {
        if (! $this->device instanceof SwitchesVsync) {
            return;
        }
        $this->switchVsync($surface->vsync());
        $surface->onVsync(fn (VSync $wanted) => $this->switchVsync($wanted));
    }

    /** The first of $wanted's fallbacks the device has; applied only when it differs from the current one. */
    private function switchVsync(VSync $wanted): void
    {
        /** @var GpuDevice&SwitchesVsync $device */
        $device = $this->device;
        $modes = $device->vsyncModes();
        foreach ($wanted->fallbacks() as $mode) {
            if (in_array($mode, $modes, true)) {
                if ($mode !== $this->vsync) {
                    $device->applyVsync($mode);
                    $this->vsync = $mode;
                }

                return;
            }
        }

        $lists = implode(', ', array_map(fn (VSync $mode): string => $mode->value, $modes)) ?: 'none';

        throw new DrawingException("{$device->name()} lists no vsync it can present with: it lists {$lists}; every device presents with on.");
    }

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
