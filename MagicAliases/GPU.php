<?php

namespace Surface\Drawing\MagicAliases;

use Voyager\MagicAliases\MagicAlias;

/**
 * @method static \Surface\Contracts\Drawing\GPUEngineDriver driver(string|null $engine = null)
 * @method static \Surface\Drawing\GPUEngineManager extend(string $engine, \Closure $callback)
 * @method static string getDefaultDriver()
 *
 * @see \Surface\Drawing\GPUEngineManager
 */
class GPU extends MagicAlias
{
    protected static function getMagicAliasAccessor(): string
    {
        return 'gpu-engines';
    }
}
