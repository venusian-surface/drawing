<?php

namespace Surface\Drawing\MagicAliases;

use Voyager\MagicAliases\MagicAlias;

/**
 * @method static \Surface\Contracts\Drawing\CPUEngineDriver driver(string|null $engine = null)
 * @method static \Surface\Drawing\CPUEngineManager extend(string $engine, \Closure $callback)
 * @method static string getDefaultDriver()
 *
 * @see \Surface\Drawing\CPUEngineManager
 */
class CPU extends MagicAlias
{
    protected static function getMagicAliasAccessor(): string
    {
        return 'cpu-engines';
    }
}
