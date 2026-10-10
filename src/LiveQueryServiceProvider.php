<?php

namespace LiveQuery;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

final class LiveQueryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LiveQueryManager::class);
        $this->app->alias(LiveQueryManager::class, 'livequery');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'livequery');

        Blade::directive('liveQueryScripts', fn (): string => "<?php echo view('livequery::scripts')->render(); ?>");
    }
}
