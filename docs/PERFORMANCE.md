# Performance measurements

Measurements below use the checked-in [demo](../demo/README.md). They are local observations, not production capacity claims.

## Environment

- Apple M4, macOS 26.5, arm64
- PHP 8.4.1, CLI memory limit 128 MB, CLI OPcache disabled
- Laravel 13.35.0, Reverb 1.12.0, Livewire 4.4.7, Echo 2.6.1
- PostgreSQL 15.14, local Homebrew server
- Playwright 1.64.0 and Chromium 156

## Observer and invalidation generation

Command: `cd demo && php benchmark.php`.

Each run inserts 1,000 Eloquent orders split across ten tenant keys. Two collections are registered for the model, so the benchmark captures 2,000 invalidation events. Event dispatch is faked after event generation; no queue worker or Reverb traffic is included. Each serialized invalidation payload is 23 bytes (`{"collection":"orders"}`).

Three earlier runs took 302.11 ms, 264.32 ms, and 263.76 ms. A later run took 517.94 ms (about 1,931 model changes per second). Peak allocated memory grew by 2 MiB. These times include PostgreSQL inserts, Eloquent lifecycle events, observer work, and capturing event objects. They do not measure actual broadcast throughput. The variance illustrates why these local samples should not be treated as a capacity guarantee.

After adding pre-save tenant-key validation, one run took 340.55 ms (about 2,936 model changes per second) with the same 2 MiB peak-memory delta. This single run is an observation of the added validation path, not a before-and-after benchmark; database and machine variance remain significant.

## Reverb fanout

Command: `cd demo && node fanout.mjs`, with the demo HTTP server, Reverb server, and database queue worker running.

Ten independent authenticated browser sessions subscribed to the same tenant channel while 100 orders were created in one database transaction. Reverb and the asynchronous database queue delivered the invalidations. All ten rendered queries ended with 100 visible orders. Three local runs took 1,894 ms, 1,730 ms, and 974 ms; browsers made 33, 36, and 35 Livewire refresh requests in aggregate.

This is a bounded smoke workload on one local machine. It does not establish a supported client limit, worst-case latency, or production capacity. Network distance, database query shape, queue latency, page filters, browser scheduling, and tenant fanout change the result. LiveQuery does not debounce broadcasts or coordinate query work across users.
