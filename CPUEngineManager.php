<?php

namespace Surface\Drawing;

use Surface\Contracts\Drawing\CPUEngineDriver;
use Voyager\NutsAndBolts\Manager;

/**
 * Names a CPU engine and resolves the container alias its package (in-house
 * for the five) bound. Same seam as GPUEngineManager: injected config, no
 * global helper, container not-found on a missing binding.
 */
class CPUEngineManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('cpu.default', 'dirty');
    }

    protected function createDirtyDriver(): CPUEngineDriver
    {
        return $this->resolveAlias('dirty', 'cpu.dirty');
    }

    protected function createFullDriver(): CPUEngineDriver
    {
        return $this->resolveAlias('full', 'cpu.full');
    }

    protected function createEpaperDriver(): CPUEngineDriver
    {
        return $this->resolveAlias('epaper', 'cpu.epaper');
    }

    protected function createPagedDriver(): CPUEngineDriver
    {
        return $this->resolveAlias('paged', 'cpu.paged');
    }

    protected function createNframesDriver(): CPUEngineDriver
    {
        return $this->resolveAlias('nframes', 'cpu.nframes');
    }

    protected function resolveAlias(string $engine, string $default): CPUEngineDriver
    {
        return $this->vessel->get($this->config->get("cpu.engines.{$engine}.alias", $default));
    }
}
