<?php

namespace Surface\Drawing;

use Closure;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\OutputTarget;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Rasterize\Edges;
use Surface\Drawing\Velvet\VelvetGE;
use Surface\Framebuffers\FramebufferManager;
use Surface\Rasterize\RasterizeManager;

/**
 * Hands out rendering engines, built from their arguments, a new one on every
 * call:
 *
 *     app('drawing')->renderer('velvet', ['framebuffer' => $fb]);
 *     app('drawing')->renderer('velvet', ['width' => 320, 'height' => 240, 'mode' => 'ring', 'frames' => 3]);
 *     app('drawing')->renderer('velvet', ['output' => $canvas]);
 *
 * 'output' draws over the target's own framebuffer: what a canvas or a display
 * would show. 'velvet', the software engine, is built in. A package that
 * brings another engine registers it with extend(); its arguments are its own.
 */
class DrawingManager
{
    /** The packages the GPU engines come with. */
    protected const array PACKAGES = [
        'metal' => 'jovian/venusian-metal',
        'opengl' => 'jovian/venusian-opengl',
        'vulkan' => 'jovian/venusian-vulkan',
        'sdl3' => 'jovian/venusian-sdl3',
    ];

    protected const array VELVET_ARGUMENTS = ['output', 'framebuffer', 'width', 'height', 'mode', 'format', 'page_rows', 'frames', 'framebuffers', 'rasterize', 'edges'];

    /** @var array<string, Closure(array<string, mixed>, self): RenderingEngine> */
    protected array $creators = [];

    /**
     * @param  \Voyager\Contracts\Config\Repository  $config
     */
    public function __construct(
        protected $config,
        protected FramebufferManager $framebuffers,
        protected RasterizeManager $rasterize,
    ) {
        $this->creators['velvet'] = fn (array $args): RenderingEngine => $this->createVelvetEngine($args);
    }

    public function getDefaultEngine(): string
    {
        return $this->config->get('drawing.default', 'velvet');
    }

    /**
     * A new engine. Null names config's default.
     *
     * @param  array<string, mixed>  $args  What the engine needs to start; each engine names its own.
     *
     * @throws DrawingException When the engine is not registered, or cannot be built from $args.
     */
    public function renderer(?string $engine = null, array $args = []): RenderingEngine
    {
        $engine ??= $this->getDefaultEngine();
        if (! isset($this->creators[$engine])) {
            $registered = implode(', ', $this->engines());
            $package = isset(self::PACKAGES[$engine]) ? ' It comes with '.self::PACKAGES[$engine].'.' : '';

            throw new DrawingException("No rendering engine named '{$engine}' is registered (registered: {$registered}).{$package}");
        }

        return $this->creators[$engine]($args, $this);
    }

    /**
     * Register an engine, or replace one.
     *
     * @param  Closure(array<string, mixed>, self): RenderingEngine  $creator
     */
    public function extend(string $engine, Closure $creator): static
    {
        $this->creators[$engine] = $creator;

        return $this;
    }

    /** @return list<string> */
    public function engines(): array
    {
        return array_keys($this->creators);
    }

    /**
     * VelvetGE over a framebuffer handed in ('framebuffer'), over an output
     * target's own framebuffer ('output'), or over one made here: 'width' and
     * 'height', 'mode' (full, dirty, epaper, paged with 'page_rows', ring
     * with 'frames'), 'format' (RGBA8 unless given) and 'framebuffers' (the
     * driver; config's unless given). 'rasterize' names the rasterize driver,
     * 'edges' the edge mode; both have defaults.
     *
     * @param  array<string, mixed>  $args
     */
    protected function createVelvetEngine(array $args): RenderingEngine
    {
        foreach (array_keys($args) as $key) {
            if (! in_array($key, self::VELVET_ARGUMENTS, true)) {
                throw new DrawingException("velvet does not take '{$key}'. It takes: ".implode(', ', self::VELVET_ARGUMENTS).'.');
            }
        }

        $edges = $args['edges'] ?? null;
        if (is_string($edges)) {
            $edges = Edges::tryFrom($edges) ?? throw new DrawingException("'edges' is 'hard' or 'antialiased', got '{$edges}'.");
        }
        if (! is_null($edges) && ! $edges instanceof Edges) {
            throw new DrawingException("'edges' is 'hard' or 'antialiased', or an Edges.");
        }

        return new VelvetGE($this->velvetFramebuffer($args), $this->rasterize->driver($args['rasterize'] ?? null), $edges);
    }

    /** @param array<string, mixed> $args */
    private function velvetFramebuffer(array $args): Framebuffer
    {
        if (array_key_exists('output', $args)) {
            if (! $args['output'] instanceof OutputTarget) {
                throw new DrawingException("'output' is an OutputTarget: a canvas or a display.");
            }
            foreach (['framebuffer', 'width', 'height', 'mode', 'format', 'page_rows', 'frames', 'framebuffers'] as $key) {
                if (array_key_exists($key, $args)) {
                    throw new DrawingException("'output' comes alone: '{$key}' describes a framebuffer to be made.");
                }
            }

            return $args['output']->framebuffer();
        }

        if (array_key_exists('framebuffer', $args)) {
            if (! $args['framebuffer'] instanceof Framebuffer) {
                throw new DrawingException("'framebuffer' is a Framebuffer.");
            }
            foreach (['width', 'height', 'mode', 'format', 'page_rows', 'frames', 'framebuffers'] as $key) {
                if (array_key_exists($key, $args)) {
                    throw new DrawingException("'framebuffer' comes alone: '{$key}' describes one to be made.");
                }
            }

            return $args['framebuffer'];
        }

        if (! isset($args['width'], $args['height'])) {
            throw new DrawingException("velvet needs a 'framebuffer', or a 'width' and a 'height' to make one.");
        }
        if (! is_int($args['width']) || ! is_int($args['height'])) {
            throw new DrawingException("'width' and 'height' are integers.");
        }
        $format = $args['format'] ?? FormatSpec::rgba8();
        if (! $format instanceof FormatSpec) {
            throw new DrawingException("'format' is a FormatSpec.");
        }
        foreach (['page_rows', 'frames'] as $key) {
            if (isset($args[$key]) && ! is_int($args[$key])) {
                throw new DrawingException("'{$key}' is an integer.");
            }
        }

        $driver = $this->framebuffers->driver($args['framebuffers'] ?? null);
        $mode = $args['mode'] ?? 'full';

        return match ($mode) {
            'full' => $driver->full($format, $args['width'], $args['height']),
            'dirty' => $driver->dirty($format, $args['width'], $args['height']),
            'epaper' => $driver->epaper($format, $args['width'], $args['height']),
            'paged' => $driver->paged($format, $args['width'], $args['height'], $args['page_rows'] ?? throw new DrawingException("'paged' needs 'page_rows'.")),
            'ring' => $driver->ring($format, $args['width'], $args['height'], $args['frames'] ?? 2),
            default => throw new DrawingException("'mode' is one of full, dirty, epaper, paged, ring, got '".(is_string($mode) ? $mode : get_debug_type($mode))."'."),
        };
    }
}
