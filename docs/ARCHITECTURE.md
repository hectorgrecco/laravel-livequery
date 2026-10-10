# Architecture

LiveQuery has four responsibilities: register model collections, observe Eloquent lifecycle events, authorize private channel subscriptions, and broadcast invalidations after commit. It does not inspect query builders or read model records to choose recipients.

## Event path

1. An application registers a collection name, Eloquent model, tenant column, and authorization callback during service-provider boot.
2. The manager registers one observer per model class and stores collection names for that model.
3. An observed model event resolves its current tenant. An update also resolves the original tenant so reassignment invalidates both scopes.
4. `LiveQueryInvalidated` implements Laravel's `ShouldDispatchAfterCommit` and `ShouldBroadcast` contracts. Laravel dispatches it after the outer transaction commits and sends it through the configured broadcasting connection.
5. A private-channel subscriber receives only the collection name, then its application component reruns an independently authorized Eloquent query.

The broadcast path performs no recipient lookup and serializes no model. Its work grows with the number of registered collections for the changed model and broadcasting fanout for the tenant channel.

## Authorization boundaries

The callback attached to a private channel is responsible for subscription authorization. It receives Laravel's authenticated user and the requested tenant key; null users are denied before invoking the callback. Tenant identifiers are channel routing values, not credentials.

The package cannot make an arbitrary application query safe. Components must derive their tenant from trusted application state and apply all relevant policies/scopes when reading records. See [authorization](AUTHORIZATION.md).

## State and workers

Collection registration is application configuration held by the singleton manager. Do not register request-dependent callbacks or mutate this registry per request. The manager has no per-subscriber state. Long-lived workers should use the same static registration for every request and must obtain the current authenticated user from Laravel's channel authorization lifecycle.

## Delivery semantics

Laravel transaction callbacks prevent publishing before a successful commit and discard callbacks on rollback. The database commit and external broadcast are not one atomic operation: a process or queue failure after commit can lose a notification. This package does not provide an outbox, replay, exactly-once delivery, or automatic recovery for disconnected clients.
