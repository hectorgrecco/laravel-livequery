<?php

namespace LiveQuery\Facades;

use Illuminate\Support\Facades\Facade;
use LiveQuery\CollectionDefinition;

/**
 * @method static CollectionDefinition register(string $name, string $model, string $tenantColumn, \Closure $authorize, array $guards = [])
 * @method static void invalidate(string $name, int|string $tenant)
 */
final class LiveQuery extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'livequery';
    }
}
