<?php

namespace LiveQuery;

use Illuminate\Support\ServiceProvider;

final class LiveQueryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LiveQueryManager::class);
        $this->app->alias(LiveQueryManager::class, 'livequery');
    }
}
