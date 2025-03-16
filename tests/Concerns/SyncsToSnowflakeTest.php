<?php

use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\TestModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

it('can sync a model to snowflake', function () {
    $model = new TestModel(['name' => 'Test']);
    $model->syncToSnowflake();

    Queue::assertPushed(SnowflakeSync::$importJob);
});

it('can sync multiple models to snowflake', function () {
    $models = new Collection([
        new TestModel(['name' => 'Test 1']),
        new TestModel(['name' => 'Test 2']),
    ]);

    $models->syncToSnowflake();

    Queue::assertPushed(SnowflakeSync::$importJob);
});

it('can remove models from snowflake', function () {
    $models = new Collection([
        new TestModel(['name' => 'Test 1']),
    ]);

    $models->removeFromSnowflake();

    Queue::assertPushed(SnowflakeSync::$removeJob);
});

it('returns correct snowflake table name', function () {
    $model = new TestModel;
    expect($model->snowflakeTable())->toBe('test_models');
});

it('uses correct queue connection and queue name', function () {
    $model = new TestModel;

    expect($model->syncWithSnowflakeUsing())
        ->toBe(config('snowflake-sync.queue.connection') ?: config('queue.default'))
        ->and($model->syncWithSnowflakeUsingQueue())
        ->toBe(config('snowflake-sync.queue.queue'));
});

it('converts model to snowflake format', function () {
    $model = new TestModel(['name' => 'Test']);
    $snowflakeData = $model->toSnowflake();

    expect($snowflakeData)->toBe($model->toArray());
});

it('can sync all models to snowflake', function () {
    DB::table('test_models')->insert([
        ['name' => 'Test 1'],
        ['name' => 'Test 2'],
    ]);

    TestModel::syncAllToSnowflake();

    Queue::assertPushed(SnowflakeSync::$importJob);
});
