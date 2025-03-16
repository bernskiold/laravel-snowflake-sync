<?php

use Bernskiold\LaravelSnowflakeSync\Jobs\RemoveFromSnowflake;
use Bernskiold\LaravelSnowflakeSync\Jobs\SnowflakeImport;
use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;

it('has a version constant', function () {
    expect(SnowflakeSync::VERSION)->toBe('1.0.0');
});

it('has default import job class', function () {
    expect(SnowflakeSync::$importJob)->toBe(SnowflakeImport::class);
});

it('has default remove job class', function () {
    expect(SnowflakeSync::$removeJob)->toBe(RemoveFromSnowflake::class);
});

it('can change import job class', function () {
    $customClass = 'App\\Jobs\\CustomImport';
    SnowflakeSync::importUsing($customClass);

    expect(SnowflakeSync::$importJob)->toBe($customClass);
});

it('can change remove job class', function () {
    $customClass = 'App\\Jobs\\CustomRemove';
    SnowflakeSync::removeUsing($customClass);

    expect(SnowflakeSync::$removeJob)->toBe($customClass);
});

it('can change engine class', function () {
    $customClass = 'App\\Engines\\CustomEngine';
    SnowflakeSync::engine($customClass);

    expect(SnowflakeSync::$engine)->toBe($customClass);
});
