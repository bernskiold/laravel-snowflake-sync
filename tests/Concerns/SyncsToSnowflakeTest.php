<?php

use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\TestModel;
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

it('can remove a model from snowflake', function () {
    $model = new TestModel(['name' => 'Test']);
    $model->removeFromSnowflake();

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

it('can sync all models to snowflake using chunkById', function () {
    DB::table('test_models')->insert([
        ['name' => 'Test 1'],
        ['name' => 'Test 2'],
    ]);

    TestModel::syncAllToSnowflake();

    Queue::assertPushed(SnowflakeSync::$importJob);
});

it('does not dispatch job for empty sync', function () {
    TestModel::syncAllToSnowflake();

    Queue::assertNotPushed(SnowflakeSync::$importJob);
});

it('can disable and enable syncing', function () {
    TestModel::disableSnowflakeSyncing();

    $model = TestModel::create(['name' => 'Test']);

    Queue::assertNotPushed(SnowflakeSync::$importJob);

    TestModel::enableSnowflakeSyncing();

    $model->update(['name' => 'Updated']);

    Queue::assertPushed(SnowflakeSync::$importJob);
});

it('disables syncing within callback and re-enables after', function () {
    TestModel::withoutSyncingToSnowflake(function () {
        TestModel::create(['name' => 'Test']);
    });

    Queue::assertNotPushed(SnowflakeSync::$importJob);

    TestModel::create(['name' => 'Test 2']);

    Queue::assertPushed(SnowflakeSync::$importJob);
});

it('re-enables syncing even if callback throws', function () {
    try {
        TestModel::withoutSyncingToSnowflake(function () {
            throw new RuntimeException('test');
        });
    } catch (RuntimeException) {
    }

    TestModel::create(['name' => 'Test']);

    Queue::assertPushed(SnowflakeSync::$importJob);
});
