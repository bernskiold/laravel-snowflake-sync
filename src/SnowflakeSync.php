<?php

namespace Bernskiold\LaravelSnowflakeSync;

use Bernskiold\LaravelSnowflakeSync\Jobs\RemoveFromSnowflake;
use Bernskiold\LaravelSnowflakeSync\Jobs\SnowflakeImport;

class SnowflakeSync
{
    public const VERSION = '1.0.0';

    public static $importJob = SnowflakeImport::class;

    public static $removeJob = RemoveFromSnowflake::class;

    public static $engine = SnowflakeEngine::class;

    public static function importUsing(string $class): void
    {
        static::$importJob = $class;
    }

    public static function removeUsing(string $class): void
    {
        static::$removeJob = $class;
    }

    public static function engine(string $class): void
    {
        static::$engine = $class;
    }
}
