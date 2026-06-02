<?php

namespace Bernskiold\LaravelSnowflakeSync\Tests;

use Bernskiold\LaravelSnowflakeSync\LaravelSnowflakeSyncServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

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
