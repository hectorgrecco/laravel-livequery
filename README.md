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

Then a Livewire or class-based Volt component can subscribe to its tenant channel and query only records the current user may read. Add `@liveQueryScripts` to the page layout to refresh mounted components after a Reverb reconnect or when a hidden page becomes visible again. See the [Livewire example](#livewire-and-volt).

## Installation

Laravel LiveQuery requires PHP 8.3 or later and Laravel 12 or 13. Reverb and Livewire are optional application dependencies. Install the package from Packagist when published:

```bash
composer require hectorgrecco/laravel-livequery
```

For local development, add this repository as a Composer `path` repository. Laravel package discovery registers the service provider automatically. Configure Laravel broadcasting and Reverb using the [official Laravel broadcasting guide](https://laravel.com/docs/13.x/broadcasting). Start Reverb and the queue worker used by your configured broadcast connection.

## Reverb configuration

The application must configure Reverb and Echo. For a local Reverb server, the relevant environment values look like this:

```dotenv
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database
REVERB_APP_ID=local
REVERB_APP_KEY=your-key
REVERB_APP_SECRET=your-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

Use Laravel's `php artisan install:broadcasting` command to configure broadcasting routes and the Echo client for the application. Start the Reverb server and the queue worker that handles broadcast jobs. Production applications should use HTTPS/WSS and their normal queue infrastructure.

## Register a collection

Call `LiveQuery::register()` during provider boot. Collection names use lowercase letters, digits, and underscores. Tenant keys must be simple ASCII identifiers no longer than 64 characters; registered model saves reject invalid tenant values before writing. The authorization callback is required and denies access when there is no authenticated user or it returns anything other than `true`.

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

Add the recovery script once to the layout used by the component:

```blade
<body>
    {{ $slot }}
    @livewireScripts
    @liveQueryScripts
</body>
```

The script listens for Reverb connection recovery and page visibility changes, then dispatches a Livewire refresh event. The trait listens for that event and lets Livewire rerun the component. For components with a custom `getListeners()`, include `$this->liveQueryListeners()` as shown above; that helper includes the recovery listener too. This refresh closes the gap for events missed while disconnected, but does not replay events or make delivery durable.

## How invalidations work

The package observes `created`, `updated`, `deleted`, and `restored` events on registered models. It emits a Laravel `ShouldBroadcast` event on a private `livequery.{collection}.{tenant}` channel. Laravel defers dispatch until the outer database transaction commits. A tenant reassignment invalidates both the previous and new tenant channels.

The payload contains only the collection name. Each listening component reruns its query, so a record can enter or leave filtered results. Broadcasting is invalidation, not synchronization; clients can miss events while disconnected.

## Manual invalidation

Bulk writes, direct SQL, and external writers bypass Eloquent model events. After such a write, invalidate each affected tenant explicitly:

```php
LiveQuery::invalidate('orders', $tenantId);
```

The invalidation is deferred if called inside a database transaction. The application is responsible for determining every affected tenant.

## Supported operations and limitations

Regular Eloquent model creates, saves, deletes, restores, and force deletes that fire model lifecycle events are observed. Mass updates/deletes, quiet saves, direct SQL, and writes from external systems bypass these events. Relationships, joins, aggregates, pagination, and query dependencies are not analyzed.

There is no durable event log or transactional outbox. A process failure after the database commit can lose an invalidation. Laravel private channel authorization occurs at subscription time; revoking access does not automatically disconnect an existing WebSocket subscription. The recovery script refreshes the component query on reconnect and when a hidden page becomes visible. The query must still enforce authorization on every request. See [limitations](docs/LIMITATIONS.md) and [architecture](docs/ARCHITECTURE.md) for details.

Invalidations fan out to every subscriber of the collection and tenant channel. Each client may issue a new query; applications should limit result sizes and plan for bursts on busy tenants.

## Performance

The broadcast payload is limited to the registered collection name. Fanout still reaches every subscriber to that tenant's collection, and each connected component may issue a query. Keep queries bounded and avoid registering high write-rate models unless the resulting refresh load is acceptable. LiveQuery does not debounce broadcasts or deduplicate refreshes across components.

## Troubleshooting

- **The view does not update:** confirm `BROADCAST_CONNECTION=reverb`, that Reverb is reachable from the browser, and that the broadcast queue worker is running.
- **Channel authentication returns 403:** inspect the collection callback, the requested tenant key, the authenticated guard, and the authorization rules. A valid login alone does not grant collection access.
- **The browser reconnects but remains stale:** add `@liveQueryScripts` to the layout, make sure the component uses `InteractsWithLiveQueries` or merges `$this->liveQueryListeners()`, and confirm Echo is initialized.
- **A bulk or external write remains stale:** dispatch a manual invalidation for every affected tenant after the write.
- **Records are missing or continue to appear:** confirm that the component query applies the current user's tenant and policy rules. LiveQuery does not scope or inspect the query.

## Demo

The [runnable demo](demo/README.md) exercises two authenticated sessions, tenant isolation, Reverb, PostgreSQL, Livewire 4, class-based Volt, a database queue, and Spatie permissions. It includes a Playwright smoke test.

To use LiveQuery instead of implementing `BroadcastsEvents` and Echo listeners manually, register one collection and let its observer, private channel, event name, and Livewire refresh wiring follow a single convention. For a single model or a custom protocol, Laravel's native broadcasting APIs may be the simpler choice.

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
