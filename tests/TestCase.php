<?php

namespace Bernskiold\LaravelSnowflakeSync\Tests;

use Bernskiold\LaravelSnowflakeSync\LaravelSnowflakeSyncServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // The Redis buffer is the production default; the suite has no Redis,
        // and an in-memory buffer is enough because nothing here crosses a
        // process boundary.
        $this->app['config']->set('snowflake-sync.buffer.driver', 'array');

        // Testbench defaults to the `sync` driver, which would run every flush
        // job the observer asks for in the middle of an unrelated test. Tests
        // that care about dispatching use Queue::fake().
        $this->app['config']->set('queue.connections.null', ['driver' => 'null']);
        $this->app['config']->set('queue.default', 'null');

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelSnowflakeSyncServiceProvider::class,
        ];
    }

    protected function setUpDatabase()
    {
        $this->app['config']->set('database.default', 'testing');
        $this->app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $migration = include __DIR__.'/database/migrations/create_test_models_table.php';
        $migration->up();

        $softDeleteMigration = include __DIR__.'/database/migrations/create_soft_delete_test_models_table.php';
        $softDeleteMigration->up();
    }
}
