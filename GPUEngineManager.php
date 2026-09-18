<?php

namespace Surface\Drawing;

use Surface\Contracts\Drawing\GPUEngineDriver;
use Voyager\NutsAndBolts\Manager;

/**
 * Names an engine and resolves the container alias its package bound. Reads
 * the injected config repository, never the global helper, so it is provable
 * with a fake vessel. Missing package: the container's own not-found — the
 * same decision as the bridge seam.
 */
class GPUEngineManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('gpu.default', device_os_family() === 'mac' ? 'metal' : 'opengl');
    }

    protected function createMetalDriver(): GPUEngineDriver
    {
        return $this->resolveAlias('metal', 'gpu.metal');
    }

    protected function createOpenglDriver(): GPUEngineDriver
    {
        return $this->resolveAlias('opengl', 'gpu.opengl');
    }

    protected function createVulkanDriver(): GPUEngineDriver
    {
        return $this->resolveAlias('vulkan', 'gpu.vulkan');
    }

    protected function createSdl3Driver(): GPUEngineDriver
    {
        return $this->resolveAlias('sdl3', 'gpu.sdl3');
    }

    protected function resolveAlias(string $engine, string $default): GPUEngineDriver
    {
        return $this->vessel->get($this->config->get("gpu.engines.{$engine}.alias", $default));
    }
}
