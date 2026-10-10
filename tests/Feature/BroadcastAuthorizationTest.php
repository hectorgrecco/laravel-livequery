<?php

namespace LiveQuery\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use LiveQuery\LiveQueryManager;
use LiveQuery\Tests\Fixtures\Order;
use LiveQuery\Tests\TestCase;

final class BroadcastAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
    }

    public function test_authorizes_private_channels_through_the_http_endpoint(): void
    {
        $payload = [
            'socket_id' => '123.456',
            'channel_name' => 'private-livequery.orders.company_a',
        ];

        $this->postJson('/broadcasting/auth', $payload)->assertForbidden();

        $this->actingAs(new GenericUser(['tenant_id' => 'company_a']))
            ->postJson('/broadcasting/auth', $payload)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->postJson('/broadcasting/auth', $payload, ['X-Alternate-User' => 'valid'])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->postJson('/broadcasting/auth', [
            ...$payload,
            'channel_name' => 'private-livequery.orders.company_b',
        ])->assertForbidden();

        $this->postJson('/broadcasting/auth', [
            ...$payload,
            'channel_name' => 'private-livequery.orders.company_a.extra',
        ])->assertForbidden();
    }
}
