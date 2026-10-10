<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LiveQuery\CollectionDefinition;
use LiveQuery\Events\LiveQueryInvalidated;
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

it('emits a minimal event on create without exposing model fields', function (): void {
    $events = [];
    Event::listen(LiveQueryInvalidated::class, function (LiveQueryInvalidated $event) use (&$events): void {
        $events[] = $event;
    });

    Order::create(['tenant_id' => 'company_a', 'status' => 'pending']);

    expect($events)->toHaveCount(1)
        ->and($events[0]->broadcastWith())->toBe(['collection' => 'orders'])
        ->and($events[0]->broadcastAs())->toBe('livequery.invalidated');
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
