# Limitations

LiveQuery publishes invalidations for selected Eloquent model lifecycle events. It is not CDC, a database synchronization system, or a durable message service.

## Changes it observes

Registered models emit invalidations for `created`, `updated`, `deleted`, and `restored` events. A tenant reassignment sends invalidations to both the old and new tenant channels. Soft delete and force delete rely on Eloquent's normal model events.

Mass `update` and `delete` queries do not hydrate models and do not emit per-model events. Quiet saves, direct SQL, external writers, and writes that suppress model events are also not detected. Relationship changes, joins, aggregates, and arbitrary query dependencies are not tracked.

## Delivery and reconnects

Invalidation dispatch waits for a successful outer transaction commit. The database commit and broadcast publication are not atomic; a process crash or queue/broadcaster failure after commit can lose a notification. There is no durable outbox, replay log, or exactly-once guarantee.

Disconnected clients can miss notifications. The package does not implement automatic reconnect resynchronization; refresh the component after reconnect or navigation. Private channel authorization is checked at subscription time, so permission revocation does not automatically disconnect an established subscription.

## Query load

All clients on a collection and tenant channel receive the same invalidation, regardless of their filters. Each client may rerun its query. Keep result sets bounded and assess fanout and query bursts for busy tenants. LiveQuery does not debounce broadcasts or coordinate client refreshes.
