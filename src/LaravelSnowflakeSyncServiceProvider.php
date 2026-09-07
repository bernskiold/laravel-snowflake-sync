<?php

namespace Bernskiold\LaravelSnowflakeSync;

use Bernskiold\LaravelSnowflakeSync\Buffers\ArrayPendingSyncBuffer;
use Bernskiold\LaravelSnowflakeSync\Buffers\RedisPendingSyncBuffer;
use Bernskiold\LaravelSnowflakeSync\Console\FlushCommand;
use Bernskiold\LaravelSnowflakeSync\Console\ImportCommand;
use Bernskiold\LaravelSnowflakeSync\Contracts\PendingSyncBuffer;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class LaravelSnowflakeSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/snowflake-sync.php', 'snowflake-sync'
        );

        $this->app->singleton(PendingSyncBuffer::class, function ($app) {
            $config = $app['config']->get('snowflake-sync.buffer', []);
            $driver = $config['driver'] ?? 'redis';

            return match ($driver) {
                'array' => new ArrayPendingSyncBuffer,
                'redis' => new RedisPendingSyncBuffer(
                    $app->make(RedisFactory::class),
                    $config['connection'] ?? 'default',
                    $config['prefix'] ?? 'snowflake-sync',
                ),
                default => $this->customBuffer($app, $driver),
            };
        });
    }

    public function boot(): void
    {
        AboutCommand::add('Laravel Snowflake Sync', fn () => ['Version' => SnowflakeSync::VERSION]);

        $this->publishes([
            __DIR__.'/../config/snowflake-sync.php' => config_path('snowflake-sync.php'),
        ], 'laravel-snowflake-sync-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                FlushCommand::class,
                ImportCommand::class,
            ]);
        }
    }

    protected function customBuffer($app, string $driver): PendingSyncBuffer
    {
        if (! is_a($driver, PendingSyncBuffer::class, true)) {
            throw new InvalidArgumentException(
                "Unsupported snowflake-sync buffer driver [{$driver}]."
            );
        }

        return $app->make($driver);
    }
}
