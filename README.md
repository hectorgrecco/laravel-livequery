# Laravel LiveQuery

Keep Eloquent queries up to date with Laravel Reverb.

Laravel LiveQuery observes selected Eloquent model events and broadcasts a small, tenant-scoped invalidation. A connected Livewire component can then rerun its own authorized query. LiveQuery does not send model data or compute query diffs.

> **Preview:** this package is not yet production-certified. Review the [limitations](docs/LIMITATIONS.md) and [authorization guidance](docs/AUTHORIZATION.md) before using it with sensitive data.

## Quick example

Register a collection in an application service provider. The callback must check the application's actual membership or policy rules:

```php
use App\Models\Order;
use Illuminate\Support\Facades\Gate;
use LiveQuery\Facades\LiveQuery;

LiveQuery::register(
    name: 'orders',
    model: Order::class,
    tenantColumn: 'tenant_id',
    authorize: fn ($user, string $tenant): bool =>
        $user !== null && Gate::forUser($user)->allows('viewTenantOrders', $tenant),
);
```

Then a Livewire or class-based Volt component can subscribe to its tenant channel and query only records the current user may read. See the [Livewire example](#livewire-and-volt).

## Installation

Laravel LiveQuery requires PHP 8.3 or later and Laravel 12 or 13. Reverb and Livewire are optional application dependencies. Install the package from Packagist when published:

```bash
composer require hectorgrecco/laravel-livequery
```

For local development, add this repository as a Composer `path` repository. Laravel package discovery registers the service provider automatically. Configure Laravel broadcasting and Reverb using the [official Laravel broadcasting guide](https://laravel.com/docs/13.x/broadcasting). Start Reverb and the queue worker used by your configured broadcast connection.

## Register a collection

Call `LiveQuery::register()` during provider boot. Collection names use lowercase letters, digits, and underscores. Tenant keys must be simple ASCII identifiers no longer than 64 characters. The authorization callback is required and denies access when there is no authenticated user or it returns anything other than `true`.

The `tenantColumn` identifies the model attribute used to select the invalidation channel. It does not establish who may subscribe, and it does not scope your Eloquent query. Use Laravel Gates or policies and keep query authorization in the application.

## Livewire and Volt

The core package has no Livewire dependency. Add `InteractsWithLiveQueries` to a Livewire component or class-based Volt component and return its authorized collection-to-tenant subscriptions:

```php
use App\Models\Order;
use LiveQuery\Concerns\InteractsWithLiveQueries;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {
    use InteractsWithLiveQueries;

    protected function liveQuerySubscriptions(): array
    {
        return ['orders' => (string) auth()->user()->tenant_id];
    }

    #[Computed]
    public function orders()
    {
        return Order::query()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->where('status', 'pending')
            ->latest()
            ->limit(20)
            ->get();
    }
};
?>

<div>
    @foreach ($this->orders as $order)
        <div wire:key="order-{{ $order->id }}">{{ $order->status }}</div>
    @endforeach
</div>
```

The query must independently enforce tenant and record visibility. If a component already defines `getListeners()`, merge `$this->liveQueryListeners()` into its return value. Echo must be initialized by the application. Computed values should be request-scoped; persisted computed properties are not supported by this adapter.

## How invalidations work

The package observes `created`, `updated`, `deleted`, and `restored` events on registered models. It emits a Laravel `ShouldBroadcast` event on a private `livequery.{collection}.{tenant}` channel. Laravel defers dispatch until the outer database transaction commits. A tenant reassignment invalidates both the previous and new tenant channels.

The payload contains only the collection name. Each listening component reruns its query, so a record can enter or leave filtered results. Broadcasting is invalidation, not synchronization; clients can miss events while disconnected.

## Supported operations and limitations

Regular Eloquent model creates, saves, deletes, restores, and force deletes that fire model lifecycle events are observed. Mass updates/deletes, quiet saves, direct SQL, and writes from external systems bypass these events. Relationships, joins, aggregates, pagination, and query dependencies are not analyzed.

There is no durable event log or transactional outbox. A process failure after the database commit can lose an invalidation. Laravel private channel authorization occurs at subscription time; revoking access does not automatically disconnect an existing WebSocket subscription. Refresh the authorized query on reconnect or navigation. See [limitations](docs/LIMITATIONS.md) and [architecture](docs/ARCHITECTURE.md) for details.

Invalidations fan out to every subscriber of the collection and tenant channel. Each client may issue a new query; applications should limit result sizes and plan for bursts on busy tenants.

## Testing and contributing

```bash
composer install
composer validate --strict
composer test
composer format:check
composer analyse
composer audit
```

See [CONTRIBUTING.md](CONTRIBUTING.md), [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md), and [SECURITY.md](SECURITY.md). Report vulnerabilities through GitHub Security Advisories when private reporting is enabled for the repository.

## License

Laravel LiveQuery is open-sourced under the [MIT license](LICENSE).
