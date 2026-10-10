# Authorization

LiveQuery uses Laravel's private broadcasting channels. Every collection requires an application callback; a missing authenticated user is denied. Integrate existing Laravel Gates, policies, guards, and permission packages through that callback. Spatie Laravel Permission is not a dependency.

```php
use Illuminate\Support\Facades\Gate;
use LiveQuery\Facades\LiveQuery;

LiveQuery::register(
    name: 'orders',
    model: \App\Models\Order::class,
    tenantColumn: 'tenant_id',
    authorize: fn ($user, string $tenant): bool =>
        $user !== null && Gate::forUser($user)->allows('viewTenantOrders', $tenant),
);
```

The channel tenant is supplied during subscription and must be checked against trusted membership or policy state. Do not grant access merely because a user is authenticated, and do not treat a frontend tenant ID as proof of membership. For multiple guards, configure Laravel's broadcasting authentication route to use the intended guard; the package callback uses the user Laravel resolves for that request.

Subscription and query authorization are separate checks. Every component must derive its tenant from trusted server-side state and scope its Eloquent query. A valid channel subscription does not grant access to all records in that tenant.

Laravel authorizes a private channel when a client subscribes. Revoking a permission later does not by itself terminate an open WebSocket subscription. Reverb does not reauthorize each event. Applications with immediate revocation requirements must disconnect affected connections or use short-lived credentials and separately protect subsequent data reads.

Tenant keys appear in channel names and are not secret. Use opaque identifiers if disclosure of internal tenant IDs is undesirable. The event payload omits tenant and model attributes, but the channel name necessarily contains the tenant routing key.
