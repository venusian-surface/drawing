<?php

namespace Surface\Drawing;

use Surface\Drawing\Engines\DirtyEngine;
use Surface\Drawing\Engines\EPaperEngine;
use Surface\Drawing\Engines\FullEngine;
use Surface\Drawing\Engines\NFramesEngine;
use Surface\Drawing\Engines\PagedEngine;
use Surface\Framebuffers\FramebufferManager;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\NutsAndBolts\ServiceProvider;

class DrawingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__, 3).'/config/gpu.php',
            'gpu',
        );

        $this->app->singleton(GPUEngineManager::class, fn (Vessel $app) => new GPUEngineManager($app));

        $this->app->singleton('gpu-engines', fn ($app) => $app->make(GPUEngineManager::class));

        $this->mergeConfigFrom(dirname(__DIR__, 3).'/config/cpu.php', 'cpu');

        $this->app->singleton(CPUEngineManager::class, fn (Vessel $app) => new CPUEngineManager($app));
        $this->app->singleton('cpu-engines', fn ($app) => $app->make(CPUEngineManager::class));

        foreach ([
            'cpu.dirty' => DirtyEngine::class,
            'cpu.full' => FullEngine::class,
            'cpu.epaper' => EPaperEngine::class,
            'cpu.paged' => PagedEngine::class,
            'cpu.nframes' => NFramesEngine::class,
        ] as $alias => $engine) {
            $this->app->singleton($alias, fn ($app) => new $engine($app->make(FramebufferManager::class)));
        }
    }

    public function boot(): void
    {
        $this->publishes([
            dirname(__DIR__, 3).'/config/gpu.php' => $this->app->configPath('gpu.php'),
            dirname(__DIR__, 3).'/config/cpu.php' => $this->app->configPath('cpu.php'),
        ], 'surface-config');
    }
}
