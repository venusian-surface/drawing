<?php

namespace Surface\Drawing;

use ReflectionException;
use Voyager\Contracts\Core\FrameworkCore;
use Voyager\NutsAndBolts\ServiceProvider;

class DrawingServiceProvider extends ServiceProvider
{
    /**
     * @return void
     * @throws ReflectionException
     */
    public function register(): void
    {
        $this->app->registerSingleton('drawing', fn (FrameworkCore $app) => new DrawingManager($app->make('config'), $app->make('framebuffers'), $app->make('rasterize')));
        $this->app->alias('drawing', DrawingManager::class);
    }

    public function boot(): void
    {

    }
}
