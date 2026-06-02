<?php

namespace Bernskiold\LaravelSnowflakeSync;

use Bernskiold\LaravelSnowflakeSync\Console\ImportCommand;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

class LaravelSnowflakeSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/snowflake-sync.php', 'snowflake-sync'
        );
    }

    public function boot(): void
    {
        AboutCommand::add('Laravel Snowflake Sync', fn () => ['Version' => SnowflakeSync::VERSION]);

        $this->publishes([
            __DIR__.'/../config/snowflake-sync.php' => config_path('snowflake-sync.php'),
        ], 'laravel-snowflake-sync-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportCommand::class,
            ]);
        }
    }
}
