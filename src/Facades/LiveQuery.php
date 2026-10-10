<?php

namespace LiveQuery\Facades;

use Illuminate\Support\Facades\Facade;
use LiveQuery\CollectionDefinition;

/**
 * @method static CollectionDefinition register(string $name, string $model, string $tenantColumn, \Closure $authorize)
 */
final class LiveQuery extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'livequery';
    }
}
