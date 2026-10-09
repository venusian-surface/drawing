<?php

namespace Surface\Drawing\Gpu;

use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\GLFramebuffer;

/**
 * What an engine package implements: one GPU device (or GL context), made on
 * the thread that builds the engine and used only there. It owns a persistent
 * target, draws DrawLists into it, and copies it into a surface a window lent.
 */
interface GpuDevice
{
    /** The engine's name: 'metal', 'sdl3', 'opengl', 'vulkan'. */
    public function name(): string;

    /**
     * Make the persistent target: RGBA8 with a stencil, $samples 1 or 4
     * (4 resolves into a single-sample image every draw()). Asked again at
     * another size, the old target is let go and a new GLFramebuffer answered.
     */
    public function target(int $width, int $height, int $samples): GLFramebuffer;

    /** Encode and submit one list into the target. Does not wait for the GPU. */
    public function draw(DrawList $list): void;

    /**
     * The surface kinds this device presents into, best first.
     *
     * @return list<SurfaceKind>
     */
    public function surfaces(): array;

    /**
     * Native handles a window needs to make a surface for this device:
     * 'instance' (the VkInstance) for Vulkan; [] where it needs none.
     *
     * @return array<string, int>
     */
    public function handles(): array;

    /** The surface this device will present into. Called once, before target(): a GL device makes the lent context current here. */
    public function adopt(LentSurface $surface): void;

    /**
     * Copy the target into the lent surface on the GPU, into
     * $surface->presentRect() with the surface's scaling filter, clearing the
     * rest. What changed since the last present that landed is the target's
     * damage(); a DamageHistory turns it into surface rects per swapchain
     * image. False when no drawable was free and nothing was copied: the
     * damage stays for the next present.
     */
    public function present(LentSurface $surface): bool;

    /** Let go of the target and the device. */
    public function release(): void;
}
