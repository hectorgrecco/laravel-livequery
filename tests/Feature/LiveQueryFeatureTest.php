<?php

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LiveQuery\CollectionDefinition;
use LiveQuery\Events\LiveQueryInvalidated;
use LiveQuery\Facades\LiveQuery as LiveQueryFacade;
use LiveQuery\LiveQueryManager;
use LiveQuery\Tests\Fixtures\Order;

beforeEach(function (): void {
    app(LiveQueryManager::class)->register(
        name: 'orders',
        model: Order::class,
        tenantColumn: 'tenant_id',
        authorize: fn (mixed $user, string $tenant): bool => (string) $user->tenant_id === $tenant,
    );
});

it('restricts access to the authorized tenant', function (): void {
    /** @var CollectionDefinition $collection */
    $collection = app(LiveQueryManager::class)->collectionsFor(new Order)[0];

    expect($collection->authorizes((object) ['tenant_id' => 'company_a'], 'company_a'))->toBeTrue()
        ->and($collection->authorizes((object) ['tenant_id' => 'company_a'], 'company_b'))->toBeFalse();
});

it('denies subscriptions when no authenticated identity is available', function (): void {
    $collection = app(LiveQueryManager::class)->collectionsFor(new Order)[0];

    expect($collection->authorizes(null, 'company_a'))->toBeFalse();
});

it('denies malformed tenant keys before calling the authorization callback', function (): void {
    $collection = app(LiveQueryManager::class)->collectionsFor(new Order)[0];
    $callbackWasCalled = false;
    $definition = new CollectionDefinition(
        name: 'invoices',
        model: Order::class,
        tenantColumn: 'tenant_id',
        authorize: function () use (&$callbackWasCalled): bool {
            $callbackWasCalled = true;

            return true;
        },
    );

    expect($collection->authorizes((object) ['id' => 1], 'company.a'))->toBeFalse()
        ->and($definition->authorizes((object) ['id' => 1], 'company.a'))->toBeFalse()
        ->and($callbackWasCalled)->toBeFalse();
});

it('requires a registered model and a simple tenant column', function (): void {
    expect(fn () => new CollectionDefinition(
        name: 'invoices',
        model: stdClass::class,
        tenantColumn: 'tenant_id',
        authorize: fn (): bool => true,
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CollectionDefinition(
            name: 'invoices',
            model: Order::class,
            tenantColumn: 'tenant-id',
            authorize: fn (): bool => true,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CollectionDefinition(
            name: 'orders.invalid',
            model: Order::class,
            tenantColumn: 'tenant_id',
            authorize: fn (): bool => true,
        ))->toThrow(InvalidArgumentException::class);
});

it('denies when the authorization callback returns false', function (): void {
    $definition = new CollectionDefinition(
        name: 'invoices',
        model: Order::class,
        tenantColumn: 'tenant_id',
        authorize: fn (mixed $user, string $tenant): bool => false,
    );

    expect($definition->authorizes((object) ['id' => 1], 'company_a'))->toBeFalse();
});

it('rejects non-scalar tenant attributes', function (): void {
    $definition = new CollectionDefinition(
        name: 'invoices',
        model: Order::class,
        tenantColumn: 'tenant_id',
        authorize: fn (): bool => true,
    );
    $order = new Order;
    $order->setAttribute('tenant_id', true);

    expect(fn () => $definition->currentTenant($order))->toThrow(InvalidArgumentException::class);
});

it('returns null for a model without current or original tenant values', function (): void {
    $definition = new CollectionDefinition(
        name: 'invoices',
        model: Order::class,
        tenantColumn: 'tenant_id',
        authorize: fn (): bool => true,
    );
    $order = new Order;

    expect($definition->currentTenant($order))->toBeNull()
        ->and($definition->originalTenant($order))->toBeNull();
});

it('returns no collections for an unregistered model', function (): void {
    $model = new class extends Model {};

    expect(app(LiveQueryManager::class)->collectionsFor($model))->toBe([]);
});

it('rejects invalid channel guard configurations', function (): void {
    expect(fn () => app(LiveQueryManager::class)->register(
        name: 'invoices',
        model: Order::class,
        tenantColumn: 'tenant_id',
        authorize: fn (): bool => true,
        guards: ['web', '../admin'],
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects duplicate collection registrations', function (): void {
    expect(fn () => app(LiveQueryManager::class)->register(
        name: 'orders',
        model: Order::class,
        tenantColumn: 'tenant_id',
        authorize: fn (): bool => true,
    ))->toThrow(InvalidArgumentException::class);
});

it('renders the reconnect and visibility recovery script', function (): void {
    expect(Blade::render('@liveQueryScripts'))
        ->toContain('window.__liveQueryResyncBound')
        ->toContain("Livewire.dispatch('livequery-resync')")
        ->toContain("document.addEventListener('visibilitychange'");
});

it('emits a minimal event on create without exposing model fields', function (): void {
    $events = [];
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);

    expect($events)->toHaveCount(1)
        ->and($events[0]->broadcastWith())->toBe(['collection' => 'orders'])
        ->and($events[0]->broadcastAs())->toBe('livequery.invalidated')
        ->and((string) $events[0]->broadcastOn()[0])->toBe('private-livequery.orders.company_a');
});

it('supports manual invalidation for writes that bypass Eloquent events', function (): void {
    $events = [];
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    LiveQueryFacade::invalidate('orders', 'company_a');

    expect($events)->toHaveCount(1)
        ->and($events[0]->broadcastWith())->toBe(['collection' => 'orders'])
        ->and(fn () => app(LiveQueryManager::class)->invalidate('missing', 'company_a'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => LiveQueryFacade::invalidate('orders', ''))
        ->toThrow(InvalidArgumentException::class);
});

it('defers invalidation until commit and does not dispatch rolled-back events', function (): void {
    $events = [];
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    DB::transaction(function () use (&$events): void {
        Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);
        expect($events)->toHaveCount(0);
    });
    expect($events)->toHaveCount(1);

    try {
        DB::transaction(function (): void {
            Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);
            throw new RuntimeException('Abort transaction');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    expect($events)->toHaveCount(1);
});

it('invalidates both tenants when a record changes tenant', function (): void {
    $order = Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);
    $events = [];
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    $order->update(['tenant_id' => 'company_b']);

    $tenants = array_map(fn ($event): string => $event->tenant, $events);
    sort($tenants);

    expect($tenants)->toBe(['company_a', 'company_b']);
});

it('notifies each collection registered for the changed model', function (): void {
    app(LiveQueryManager::class)->register(
        name: 'all_orders',
        model: Order::class,
        tenantColumn: 'tenant_id',
        authorize: fn (): bool => true,
    );

    $events = [];
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);

    expect(array_map(fn (LiveQueryInvalidated $event): string => $event->collection, $events))
        ->toBe(['orders', 'all_orders']);
});

it('waits until commit before invalidating create and delete transitions', function (): void {
    $events = [];
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    DB::transaction(function () use (&$events): void {
        $order = Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);
        $order->delete();

        expect($events)->toBe([]);
    });

    expect($events)->toHaveCount(2)
        ->and(array_map(fn (LiveQueryInvalidated $event): string => $event->tenant, $events))
        ->toBe(['company_a', 'company_a']);
});

it('does not claim to capture bulk updates that bypass model events', function (): void {
    Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);
    $events = [];
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    Order::query()->where('status', 'pending')->update(['status' => 'shipped']);

    expect($events)->toBe([]);
});

it('emits invalidations when a model is deleted', function (): void {
    $order = Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);
    $events = [];
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    $order->delete();

    expect($events)->toHaveCount(1)
        ->and($events[0]->tenant)->toBe('company_a');
});

it('emits an invalidation when a soft-deletable model is force deleted', function (): void {
    $order = Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);
    $events = [];
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    $order->forceDelete();

    expect($events)->toHaveCount(1)
        ->and($events[0]->tenant)->toBe('company_a');
});

it('does not emit an invalidation for unchanged models', function (): void {
    $order = Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);
    $events = [];
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    $order->save();

    expect($events)->toBe([]);
});

it('does not publish an invalidation when an outer transaction rolls back', function (): void {
    $events = [];
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    DB::beginTransaction();
    DB::transaction(function (): void {
        Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);
    });
    DB::rollBack();

    expect($events)->toBe([]);
});

it('waits for the outer commit after a nested transaction commits', function (): void {
    $events = [];
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    DB::beginTransaction();
    DB::transaction(function (): void {
        Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);
    });

    expect($events)->toBe([]);

    DB::commit();

    expect($events)->toHaveCount(1);
});

it('discards callbacks from a transaction attempt that Laravel retries', function (): void {
    $events = [];
    $attempts = 0;
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    DB::transaction(function () use (&$attempts): void {
        $attempts++;
        Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);

        if ($attempts === 1) {
            throw new QueryException('testing', 'select 1', [], new RuntimeException('deadlock detected'));
        }
    }, attempts: 2);

    expect($attempts)->toBe(2)
        ->and(Order::query()->count())->toBe(1)
        ->and($events)->toHaveCount(1);
});

it('keeps a committed database write when the broadcaster fails after commit', function (): void {
    app(BroadcastManager::class)->extend('throwing', fn () => new class implements Broadcaster
    {
        public function auth($request)
        {
            return null;
        }

        public function validAuthenticationResponse($request, $result)
        {
            return null;
        }

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new RuntimeException('Broadcast transport failed');
        }
    });
    config(['broadcasting.connections.throwing' => ['driver' => 'throwing']]);
    config(['broadcasting.default' => 'throwing']);
    app(BroadcastManager::class)->purge('throwing');

    expect(fn () => DB::transaction(function (): void {
        Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);
    }))->toThrow(RuntimeException::class, 'Broadcast transport failed')
        ->and(Order::query()->count())->toBe(1);
});

it('invalidates restored records using their tenant', function (): void {
    $order = Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);
    $order->delete();
    $events = [];
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    $order->restore();

    expect($events[0]->tenant)->toBe('company_a');
});
