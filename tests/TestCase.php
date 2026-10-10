<?php

namespace LiveQuery\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LiveQuery\LiveQueryServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [LiveQueryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $databaseDriver = getenv('LIVEQUERY_TEST_DRIVER') ?: 'sqlite';
        $database = $databaseDriver === 'pgsql'
            ? [
                'driver' => 'pgsql',
                'host' => getenv('LIVEQUERY_TEST_HOST') ?: '127.0.0.1',
                'port' => getenv('LIVEQUERY_TEST_PORT') ?: '5432',
                'database' => getenv('LIVEQUERY_TEST_DATABASE') ?: 'livequery_package_test',
                'username' => getenv('LIVEQUERY_TEST_USERNAME') ?: 'postgres',
                'password' => getenv('LIVEQUERY_TEST_PASSWORD') ?: '',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]
            : ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        $app['config']->set('database.connections.testing', $database);
        $app['config']->set('broadcasting.default', 'log');
        $app['config']->set('queue.default', 'sync');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('status');
            $table->softDeletes();
        });
    }
}
