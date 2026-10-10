<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use LiveQuery\LiveQueryManager;
use LiveQuery\Tests\Fixtures\Order;
use Symfony\Component\HttpFoundation\Response;

function liveQueryBroadcastAuthResponse(array $payload, array $headers = []): Response
{
    $request = Request::create(
        'http://localhost/broadcasting/auth',
        'POST',
        $payload,
        server: ['HTTP_ACCEPT' => 'application/json', ...$headers],
    );

    return app(Kernel::class)->handle($request);
}

beforeEach(function (): void {
    Auth::forgetGuards();
    config()->set('broadcasting.default', 'pusher');
    config()->set('broadcasting.connections.pusher', [
        'driver' => 'pusher',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'app_id' => 'test-app',
        'options' => ['host' => '127.0.0.1', 'port' => 6001, 'scheme' => 'http', 'useTLS' => false],
    ]);
    config()->set('auth.guards.alternate', ['driver' => 'alternate', 'provider' => null]);
    Auth::viaRequest('alternate', fn (Request $request): ?GenericUser => $request->header('X-Alternate-User') === 'valid' ? new GenericUser(['tenant_id' => 'company_a']) : null);

    Broadcast::purge();
    app(LiveQueryManager::class)->register(
        name: 'orders',
        model: Order::class,
        tenantColumn: 'tenant_id',
        authorize: fn ($user, string $tenant): bool => $user !== null && (string) $user->tenant_id === $tenant,
        guards: ['alternate', 'web'],
    );
    Broadcast::routes();
});

it('authorizes private channels through the http endpoint', function (): void {
    $payload = [
        'socket_id' => '123.456',
        'channel_name' => 'private-livequery.orders.company_a',
    ];

    expect(liveQueryBroadcastAuthResponse($payload)->getStatusCode())->toBe(403);

    Auth::guard('web')->setUser(new GenericUser(['tenant_id' => 'company_a']));
    $webResponse = liveQueryBroadcastAuthResponse($payload);
    expect($webResponse->getStatusCode())->toBe(200)
        ->and(json_decode((string) $webResponse->getContent(), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('auth');

    $alternateResponse = liveQueryBroadcastAuthResponse($payload, ['HTTP_X_ALTERNATE_USER' => 'valid']);
    expect($alternateResponse->getStatusCode())->toBe(200)
        ->and(json_decode((string) $alternateResponse->getContent(), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('auth');

    expect(liveQueryBroadcastAuthResponse([
        ...$payload,
        'channel_name' => 'private-livequery.orders.company_b',
    ])->getStatusCode())->toBe(403);

    expect(liveQueryBroadcastAuthResponse([
        ...$payload,
        'channel_name' => 'private-livequery.orders.company_a.extra',
    ])->getStatusCode())->toBe(403);
});
