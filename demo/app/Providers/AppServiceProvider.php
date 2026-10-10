<?php

namespace App\Providers;

use App\Models\Order;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use LiveQuery\Facades\LiveQuery;
use Livewire\Volt\Volt;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Volt::mount([resource_path('views/livewire')]);
        Gate::define('viewTenantOrders', fn ($user, string $tenant): bool => (string) $user->tenant_id === $tenant && $user->can('view orders'));

        LiveQuery::register(
            name: 'orders',
            model: Order::class,
            tenantColumn: 'tenant_id',
            authorize: fn ($user, string $tenant): bool => $user !== null && Gate::forUser($user)->allows('viewTenantOrders', $tenant),
            guards: ['web'],
        );
    }
}
