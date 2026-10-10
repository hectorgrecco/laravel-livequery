# Roadmap

## V0: Eloquent event invalidation (prototype)

- [x] Service provider and registered collections
- [x] Private tenant-scoped channel with explicit authorization
- [x] Created, updated, deleted, restored observation
- [x] `ShouldDispatchAfterCommit` transport event
- [x] Old/new tenant invalidation
- [x] Optional class-based Volt trait
- [x] Test cases prepared for Laravel Testbench
- [x] Focused Pest suite executed on Laravel 12 and 13
- [ ] Browser + Reverb end-to-end test
- [ ] Full readiness and security review

## V0.2: Reliable UX

- [ ] Connection established before snapshot / race-free setup
- [ ] Auto resubscribe and refetch on reconnect
- [ ] Debounce/coalesce duplicate invalidations
- [ ] Rate limiting and load tests for hot tenants
- [ ] Livewire adapter verification for dynamic listeners
- [ ] Bulk mutation helper for explicit invalidation

## V0.3: Query awareness

- [ ] Dependency tracking for a limited whitelisted subset of Builder operations
- [ ] Query fingerprinting with authorization-aware scopes
- [ ] Versioned snapshots and selective refetch
- [ ] Invalidation-aware cache integration

## V1: Production reliability, if users validate demand

- [ ] Optional transactional outbox
- [ ] Version cursors and replay/resync protocol
- [ ] Benchmarks for N subscribers, multiple tenants and high write throughput
- [ ] Optional Postgres CDC adapter, separate process and explicit operational docs

## Business direction (not committed)

Keep core self-hosted under MIT. Explore hosted runner/diagnostics or paid operations only after real usage. No cloud dependency in the public SDK.
