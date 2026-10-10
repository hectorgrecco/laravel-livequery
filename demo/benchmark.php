<?php

use App\Models\Order;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LiveQuery\Events\LiveQueryInvalidated;
use LiveQuery\LiveQueryManager;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$iterations = 1_000;
$collections = 2;

DB::table('orders')->where('name', 'like', 'Benchmark order %')->delete();
Event::fake([LiveQueryInvalidated::class]);
app(LiveQueryManager::class)->register(
    name: 'benchmark_orders',
    model: Order::class,
    tenantColumn: 'tenant_id',
    authorize: fn (): bool => true,
);

$memoryBefore = memory_get_usage(true);
$startedAt = hrtime(true);

for ($index = 0; $index < $iterations; $index++) {
    Order::create([
        'tenant_id' => 'tenant-'.($index % 10),
        'name' => 'Benchmark order '.$index,
        'status' => 'pending',
    ]);
}

$elapsedNanoseconds = hrtime(true) - $startedAt;
$elapsedSeconds = $elapsedNanoseconds / 1_000_000_000;
$events = $iterations * $collections;

Event::assertDispatched(LiveQueryInvalidated::class, $events);

fwrite(STDOUT, json_encode([
    'database_driver' => DB::connection()->getDriverName(),
    'model_changes' => $iterations,
    'registered_collections_per_model' => $collections,
    'captured_invalidations' => $events,
    'single_invalidation_payload_bytes' => strlen(json_encode(['collection' => 'orders'], JSON_THROW_ON_ERROR)),
    'elapsed_ms' => round($elapsedNanoseconds / 1_000_000, 2),
    'changes_per_second' => round($iterations / $elapsedSeconds, 2),
    'peak_memory_delta_bytes' => max(0, memory_get_peak_usage(true) - $memoryBefore),
    'broadcasting' => 'faked; no queue worker or Reverb traffic',
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
