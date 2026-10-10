# RFC 0001: Tenant-scoped Eloquent LiveQuery invalidation over Reverb

Status: **prototype / independent package**
Date: 2026-10-10

## Problem

Laravel's `BroadcastsEvents` and Livewire Echo listeners can broadcast and react to individual model events. Developers still manually maintain matching authorization, channel names, listener wiring and queries when multiple clients observe filtered collections.

An initial package should centralize these conventions while leaving Eloquent and application policy in control. This is intentionally **not** a replacement for Reverb, an ORM, a database synchronization engine or CDC.

## Goals

1. Registration of a named, single-model collection with a required authorization callback.
2. Emit minimal per-tenant invalidation on model `created`, `updated`, `deleted`, `restored` events.
3. Delay dispatch until successful transaction commit.
4. Handle tenant reassignment safely by notifying both scopes.
5. Allow Livewire/Volt components to refresh request-scoped `#[Computed]` queries without defining separate event classes and channel auth callbacks.
6. Be self-hosted with native Laravel broadcasting, Reverb and queue infrastructure.
7. Be simple enough to test with Pest and Orchestra Testbench.

## Non-goals for V0

- Incremental INSERT/UPDATE/DELETE payloads; arbitrary `Builder` AST/dependency inspection.
- SQL triggers, WAL/CDC, database writes executed outside Eloquent events.
- Automatic browser reconnect recovery, durable event log or replay.
- Query deduplication, database caching, realtime pagination or range backfill.
- Authorization inference from Eloquent scopes; application must provide a policy.

## Public API

```php
LiveQuery::register(
    name: 'orders',
    model: Order::class,
    tenantColumn: 'tenant_id',
    authorize: fn ($user, string $tenant) => $user->belongsToTenant($tenant),
);
```

The `InteractsWithLiveQueries` trait supplies Echo listeners for private channels. Query body and authorization are still enforced by the application at query time.

## Protocol

Private channel: `livequery.{collection}.{tenant}`

Broadcast event: `.livequery.invalidated`

Payload:

```json
{"collection":"orders"}
```

No model values, raw SQL, primary key or other tenant metadata are in the broadcast payload. Channel name has tenant context; a tenant identifier is not automatically secret, so applications should use opaque identifiers if enumeration is a concern.

## Correctness details

- Eloquent observers fire during a write and send a `ShouldDispatchAfterCommit` event with scalar snapshot values. The event is queued for normal broadcasting only after the successful outer transaction commit.
- The observer captures the original tenant before Eloquent synchronizes original attributes. On an update moving tenants, two separate scoped invalidations are published.
- A failed dispatch after commit is not durable. Transactional outbox is a future enhancement.
- Observers are not called for mass updates, quiet saves, raw queries, or external writers. We explicitly do not promise completeness without CDC.
- A client cannot infer authorized visibility from a broadcast; every Eloquent query must scope itself to the authenticated user's tenant.
- A temporarily disconnected client may miss events. Until reconnect resync is implemented, the application must refresh on reconnection or full navigation.
- Broadcasting queues, Redis, Reverb and Laravel jobs are operated by the installing application.

## Performance

The initial algorithm has constant work per registered collection per changed model, and fanout proportional to subscribers of its tenant channel. It avoids sending model attributes but does not eliminate refresh storms. Future work may batch invalidations by tenant and introduce a version/cursor mechanism with selective snapshot refresh.

## Risks and alternatives

| Option | Tradeoff |
|---|---|
| Laravel `BroadcastsEvents` alone | Already native; requires repetitive application wiring |
| Livewire Echo events directly | Best for small apps; lower dependency than this package |
| Eloquent observers + tenant invalidation | Thin and maintainable; no SQL change completeness |
| PostgreSQL CDC | Detects raw writes, but adds slots, WAL retention and an operational service |
| Full incremental query engine | Less refetch; hard joins, windows, aggregate, authorization and replay correctness |

## Acceptance gates

1. Testbench/Pest matrix passes on Laravel 12/13 and PHP 8.3/8.4/8.5.
2. Tenant A cannot subscribe to tenant B's channel.
3. Rollbacks do not publish invalidations.
4. Tenant reassignment invalidates both audiences, without model serialization.
5. Demo with two authenticated browser sessions, running Reverb + Livewire/Volt.
6. Document memory/cpu consumption and fanout before proposing production use.
7. Human review of auth, concurrency, Laravel semantics and contributions.

## Potential future upstream discussion

After external users validate the package, ask Laravel maintainers whether a small opt-in extension point in Eloquent or broadcasting would make packages cleaner. Do not submit a large upstream PR or mass-produced AI content. Follow https://laravel.com/docs/13.x/contributions.
