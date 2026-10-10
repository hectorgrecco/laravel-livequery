# LiveQuery demo

This Laravel 13 application exercises Laravel LiveQuery with PostgreSQL, Reverb, Laravel Echo, Livewire 4, class-based Volt, and Spatie Laravel Permission. It includes an automated Playwright smoke test with two isolated browser sessions.

## Requirements

- PHP 8.3 or later with `pdo_pgsql`
- Composer and Node.js 20+
- PostgreSQL 15+
- A Chromium build supported by Playwright

## Run it

From the repository root:

```bash
cd demo
composer install
npm ci
cp .env.example .env
```

Edit `.env` with the credentials for a local PostgreSQL server and create the `livequery_demo` database. Then run:

```bash
php artisan key:generate
php artisan vendor:publish --tag=permission-migrations
php artisan vendor:publish --tag=permission-config
php artisan migrate --seed
npm run build
npx playwright install chromium
```

Start each process in its own terminal from `demo/`:

```bash
php artisan reverb:start --host=127.0.0.1 --port=8080
php artisan queue:work --tries=1
php artisan serve --host=127.0.0.1 --port=8000
```

Run the browser smoke test in a fourth terminal:

```bash
VITE_REVERB_HOST=reverb.test npm run build
node smoke.mjs
```

The smoke test's browser maps `reverb.test` to loopback so it can interrupt one session's WebSocket independently. Rebuild with plain `npm run build` before using the manual browser demo afterward.

Run the bounded fanout exercise when you want to measure more subscribers:

```bash
node fanout.mjs
```

`benchmark.php` measures 1,000 model creates with two registered collections while capturing invalidation events locally. It does not send queue jobs or Reverb traffic.

Open `http://127.0.0.1:8000/demo/login/a@example.test` and `http://127.0.0.1:8000/demo/login/direct@example.test` in separate browser profiles to try the UI. The seeded users share tenant `tenant-a`; `a@example.test` receives the `view orders` permission through a role and `direct@example.test` receives it directly. `b@example.test` belongs to `tenant-b` and has no permission; `tenant-b-reader@example.test` can view tenant B orders.

The demo login routes intentionally identify seeded users by email without a password. They are only suitable for this local sample and must not be used in a deployed application.

## What the smoke test checks

The test verifies authorized and rejected requests to Laravel's actual `/broadcasting/auth` endpoint; isolated authenticated browser sessions in two tenants; create, update, reorder, filter, reopen, soft-delete, and restore behavior; an initial WebSocket connection failure and query/subscription race; repeated TCP disconnect/reconnect cycles after missed events; Chromium page freeze/wake while disconnected; Livewire navigation/remount; session expiration; tenant membership changes; and direct plus role-derived permission revocation. It waits for the database queue to drain before considering broadcasts processed. No cloud service is used.
