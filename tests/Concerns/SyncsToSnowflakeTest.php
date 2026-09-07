<?php

use Bernskiold\LaravelSnowflakeSync\Contracts\PendingSyncBuffer;
use Bernskiold\LaravelSnowflakeSync\Enums\SyncMode;
use Bernskiold\LaravelSnowflakeSync\Jobs\FlushSnowflakeSync;
use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\SoftDeleteTestModel;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\TestModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->buffer = app(PendingSyncBuffer::class);
});

it('buffers a model for syncing', function () {
    $model = new TestModel(['name' => 'Test']);
    $model->id = 7;

    $model->syncToSnowflake();

    expect($this->buffer->takeUpserts(TestModel::class, 10))->toBe(['7']);
    Queue::assertPushed(FlushSnowflakeSync::class);
});

it('buffers a model for removal', function () {
    $model = new TestModel(['name' => 'Test']);
    $model->id = 7;

    $model->removeFromSnowflake();

    expect($this->buffer->takeRemovals(TestModel::class, 10))->toBe(['7']);
    Queue::assertPushed(FlushSnowflakeSync::class);
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

it('defaults to the configured sync mode', function () {
    expect((new TestModel)->snowflakeSyncMode())->toBe(SyncMode::Live);

    config(['snowflake-sync.mode' => 'periodic']);

    expect((new TestModel)->snowflakeSyncMode())->toBe(SyncMode::Periodic);
});

it('falls back to live mode when the configured mode is not recognised', function () {
    config(['snowflake-sync.mode' => 'nonsense']);

    expect((new TestModel)->snowflakeSyncMode())->toBe(SyncMode::Live);
});

it('includes trashed rows in the sync query for soft-deletable models', function () {
    $model = SoftDeleteTestModel::create(['name' => 'Test']);
    $model->delete();

    expect((new SoftDeleteTestModel)->newSnowflakeSyncQuery()->count())->toBe(1)
        ->and(SoftDeleteTestModel::count())->toBe(0);
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

    expect($this->buffer->pendingCount(TestModel::class))->toBe(0);

    TestModel::enableSnowflakeSyncing();

    $model->update(['name' => 'Updated']);

    expect($this->buffer->pendingCount(TestModel::class))->toBe(1);
});

it('disables syncing within callback and re-enables after', function () {
    TestModel::withoutSyncingToSnowflake(function () {
        TestModel::create(['name' => 'Test']);
    });

    expect($this->buffer->pendingCount(TestModel::class))->toBe(0);

    TestModel::create(['name' => 'Test 2']);

    expect($this->buffer->pendingCount(TestModel::class))->toBe(1);
});

it('re-enables syncing even if callback throws', function () {
    try {
        TestModel::withoutSyncingToSnowflake(function () {
            throw new RuntimeException('test');
        });
    } catch (RuntimeException) {
    }

    TestModel::create(['name' => 'Test']);

    expect($this->buffer->pendingCount(TestModel::class))->toBe(1);
});

it('defers the dispatch until after commit when configured', function () {
    config(['snowflake-sync.after_commit' => true]);

    $model = new TestModel(['name' => 'Test']);
    $model->id = 7;
    $model->syncToSnowflake();

    $pushed = Queue::pushed(FlushSnowflakeSync::class)->first();

    expect($pushed->afterCommit)->toBeTrue();
});

it('buffers keys handed over by a bulk writer', function () {
    TestModel::queueSnowflakeSyncForKeys([4, 5]);

    expect($this->buffer->takeUpserts(TestModel::class, 10))->toBe(['4', '5']);
    Queue::assertPushed(FlushSnowflakeSync::class);
});

it('buffers removals handed over by a bulk writer', function () {
    TestModel::queueSnowflakeRemovalForKeys([4]);

    expect($this->buffer->takeRemovals(TestModel::class, 10))->toBe(['4']);
});

it('ignores handed-over keys when syncing is disabled', function () {
    config(['snowflake-sync.enabled' => false]);

    TestModel::queueSnowflakeSyncForKeys([4]);

    expect($this->buffer->pendingCount(TestModel::class))->toBe(0);
});

it('ignores handed-over keys inside withoutSyncingToSnowflake', function () {
    TestModel::withoutSyncingToSnowflake(function () {
        TestModel::queueSnowflakeSyncForKeys([4]);
    });

    expect($this->buffer->pendingCount(TestModel::class))->toBe(0);
});

it('does nothing when handed no keys', function () {
    TestModel::queueSnowflakeSyncForKeys([]);

    expect($this->buffer->pendingCount(TestModel::class))->toBe(0);
    Queue::assertNotPushed(FlushSnowflakeSync::class);
});
