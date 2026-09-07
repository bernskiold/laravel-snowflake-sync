<?php

use Bernskiold\LaravelSnowflakeSync\Contracts\PendingSyncBuffer;
use Bernskiold\LaravelSnowflakeSync\Jobs\FlushSnowflakeSync;
use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\SoftDeleteRemovesTestModel;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\SoftDeleteTestModel;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\TestModel;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->buffer = app(PendingSyncBuffer::class);
});

it('buffers an upsert when a model is created', function () {
    $model = TestModel::create(['name' => 'Test']);

    expect($this->buffer->takeUpserts(TestModel::class, 10))->toBe([(string) $model->id]);
});

it('buffers an upsert when a model is updated', function () {
    $model = TestModel::create(['name' => 'Test']);
    $this->buffer->clear();

    $model->update(['name' => 'Updated']);

    expect($this->buffer->takeUpserts(TestModel::class, 10))->toBe([(string) $model->id]);
});

it('buffers a removal when a model is deleted', function () {
    $model = TestModel::create(['name' => 'Test']);
    $this->buffer->clear();

    $model->delete();

    expect($this->buffer->takeRemovals(TestModel::class, 10))->toBe([(string) $model->id])
        ->and($this->buffer->takeUpserts(TestModel::class, 10))->toBe([]);
});

it('buffers an upsert when a soft-deleted model is deleted', function () {
    $model = SoftDeleteTestModel::create(['name' => 'Test']);
    $this->buffer->clear();

    $model->delete();

    expect($this->buffer->takeUpserts(SoftDeleteTestModel::class, 10))->toBe([(string) $model->id])
        ->and($this->buffer->takeRemovals(SoftDeleteTestModel::class, 10))->toBe([]);
});

it('buffers a removal when a soft-deleted model is force deleted', function () {
    $model = SoftDeleteTestModel::create(['name' => 'Test']);
    $this->buffer->clear();

    $model->forceDelete();

    expect($this->buffer->takeRemovals(SoftDeleteTestModel::class, 10))->toBe([(string) $model->id]);
});

it('buffers an upsert when a soft-deleted model is restored', function () {
    $model = SoftDeleteTestModel::create(['name' => 'Test']);
    $model->delete();
    $this->buffer->clear();

    $model->restore();

    expect($this->buffer->takeUpserts(SoftDeleteTestModel::class, 10))->toBe([(string) $model->id]);
});

it('buffers a removal on soft delete when the model opts in', function () {
    $model = SoftDeleteRemovesTestModel::create(['name' => 'Test']);
    $this->buffer->clear();

    $model->delete();

    expect($this->buffer->takeRemovals(SoftDeleteRemovesTestModel::class, 10))->toBe([(string) $model->id])
        ->and($this->buffer->takeUpserts(SoftDeleteRemovesTestModel::class, 10))->toBe([]);
});

it('collapses repeated saves of the same model into one buffered key', function () {
    $model = TestModel::create(['name' => 'Test']);

    foreach (range(1, 25) as $i) {
        $model->update(['name' => 'Update '.$i]);
    }

    expect($this->buffer->takeUpserts(TestModel::class, 100))->toBe([(string) $model->id]);
});

it('dispatches a single flush job for a burst of saves', function () {
    $model = TestModel::create(['name' => 'Test']);

    foreach (range(1, 25) as $i) {
        $model->update(['name' => 'Update '.$i]);
    }

    // Every save asks for a flush, but the job is unique per model class, so
    // the queue only ever holds one of them at a time.
    Queue::assertPushed(FlushSnowflakeSync::class);
    expect(Queue::pushed(FlushSnowflakeSync::class))->toHaveCount(1);
});

it('does not dispatch a flush job in periodic mode', function () {
    config(['snowflake-sync.mode' => 'periodic']);

    $model = TestModel::create(['name' => 'Test']);

    Queue::assertNotPushed(FlushSnowflakeSync::class);
    expect($this->buffer->takeUpserts(TestModel::class, 10))->toBe([(string) $model->id]);
});

it('does not buffer a save that changed nothing', function () {
    $model = TestModel::create(['name' => 'Test']);
    $this->buffer->clear();

    $fresh = TestModel::find($model->id);
    $fresh->timestamps = false;
    $fresh->save();

    expect($this->buffer->pendingCount(TestModel::class))->toBe(0);
});

it('does not buffer a timestamp-only change when configured to ignore them', function () {
    config(['snowflake-sync.ignore_timestamp_only_changes' => true]);

    $model = TestModel::create(['name' => 'Test']);
    $this->buffer->clear();

    // A fresh instance, because `wasRecentlyCreated` stays true for the life of
    // the object that created the row and short-circuits the check. Moving the
    // clock too, or the new `updated_at` matches the old one to the second and
    // the save is a no-op that would pass for the wrong reason.
    $this->travel(1)->second();
    TestModel::find($model->id)->touch();

    expect($this->buffer->pendingCount(TestModel::class))->toBe(0);
});

it('buffers a timestamp-only change by default', function () {
    $model = TestModel::create(['name' => 'Test']);
    $this->buffer->clear();

    $this->travel(1)->second();
    TestModel::find($model->id)->touch();

    expect($this->buffer->pendingCount(TestModel::class))->toBe(1);
});

it('does not buffer when syncing is disabled', function () {
    TestModel::disableSnowflakeSyncing();

    TestModel::create(['name' => 'Test']);

    expect($this->buffer->pendingCount(TestModel::class))->toBe(0);

    TestModel::enableSnowflakeSyncing();
});

it('does not buffer force deletes when syncing is disabled', function () {
    SoftDeleteTestModel::disableSnowflakeSyncing();

    $model = SoftDeleteTestModel::create(['name' => 'Test']);
    $model->forceDelete();

    expect($this->buffer->pendingCount(SoftDeleteTestModel::class))->toBe(0);

    SoftDeleteTestModel::enableSnowflakeSyncing();
});

it('does not buffer on create when syncing is disabled via config', function () {
    config(['snowflake-sync.enabled' => false]);

    TestModel::create(['name' => 'Test']);

    expect($this->buffer->pendingCount(TestModel::class))->toBe(0);
});

it('does not buffer on delete when syncing is disabled via config', function () {
    $model = TestModel::create(['name' => 'Test']);
    config(['snowflake-sync.enabled' => false]);
    $this->buffer->clear();

    $model->delete();

    expect($this->buffer->pendingCount(TestModel::class))->toBe(0);
});

it('does not buffer on restore when syncing is disabled via config', function () {
    $model = SoftDeleteTestModel::create(['name' => 'Test']);
    $model->delete();
    config(['snowflake-sync.enabled' => false]);
    $this->buffer->clear();

    $model->restore();

    expect($this->buffer->pendingCount(SoftDeleteTestModel::class))->toBe(0);
});

it('still routes the bulk import path through the import job', function () {
    TestModel::create(['name' => 'Test']);
    Queue::fake();

    TestModel::syncAllToSnowflake();

    Queue::assertPushed(SnowflakeSync::$importJob);
});
