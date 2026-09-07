<?php

use Bernskiold\LaravelSnowflakeSync\Jobs\FlushSnowflakeSync;
use Bernskiold\LaravelSnowflakeSync\Jobs\RemoveFromSnowflake;
use Bernskiold\LaravelSnowflakeSync\Jobs\SnowflakeImport;
use Bernskiold\LaravelSnowflakeSync\SnowflakeEngine;
use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;

it('has a version constant', function () {
    expect(SnowflakeSync::VERSION)->toBe('0.3.0');
});

it('has default import job class', function () {
    expect(SnowflakeSync::$importJob)->toBe(SnowflakeImport::class);
});

it('has default remove job class', function () {
    expect(SnowflakeSync::$removeJob)->toBe(RemoveFromSnowflake::class);
});

it('has default engine class', function () {
    expect(SnowflakeSync::$engine)->toBe(SnowflakeEngine::class);
});

it('can change import job class', function () {
    $original = SnowflakeSync::$importJob;
    $customClass = 'App\\Jobs\\CustomImport';
    SnowflakeSync::importUsing($customClass);

    expect(SnowflakeSync::$importJob)->toBe($customClass);

    SnowflakeSync::$importJob = $original;
});

it('can change remove job class', function () {
    $original = SnowflakeSync::$removeJob;
    $customClass = 'App\\Jobs\\CustomRemove';
    SnowflakeSync::removeUsing($customClass);

    expect(SnowflakeSync::$removeJob)->toBe($customClass);

    SnowflakeSync::$removeJob = $original;
});

it('can change engine class', function () {
    $original = SnowflakeSync::$engine;
    $customClass = 'App\\Engines\\CustomEngine';
    SnowflakeSync::engine($customClass);

    expect(SnowflakeSync::$engine)->toBe($customClass);

    SnowflakeSync::$engine = $original;
});

it('has default flush job class', function () {
    expect(SnowflakeSync::$flushJob)->toBe(FlushSnowflakeSync::class);
});

it('can change flush job class', function () {
    $original = SnowflakeSync::$flushJob;

    SnowflakeSync::flushUsing('CustomFlushJob');
    expect(SnowflakeSync::$flushJob)->toBe('CustomFlushJob');

    SnowflakeSync::$flushJob = $original;
});
