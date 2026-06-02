<?php

use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\SoftDeleteRemovesTestModel;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\SoftDeleteTestModel;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\TestModel;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

it('dispatches import job when model is created', function () {
    TestModel::create(['name' => 'Test']);

    Queue::assertPushed(SnowflakeSync::$importJob);
});

it('dispatches import job when model is updated', function () {
    $model = TestModel::create(['name' => 'Test']);
    Queue::fake();

    $model->update(['name' => 'Updated']);

    Queue::assertPushed(SnowflakeSync::$importJob);
});

it('dispatches remove job when model is deleted', function () {
    $model = TestModel::create(['name' => 'Test']);
    Queue::fake();

    $model->delete();

    Queue::assertPushed(SnowflakeSync::$removeJob);
});

it('dispatches import job when soft-deleted model is deleted', function () {
    $model = SoftDeleteTestModel::create(['name' => 'Test']);
    Queue::fake();

    $model->delete();

    Queue::assertPushed(SnowflakeSync::$importJob);
    Queue::assertNotPushed(SnowflakeSync::$removeJob);
});

it('dispatches remove job when soft-deleted model is force deleted', function () {
    $model = SoftDeleteTestModel::create(['name' => 'Test']);
    Queue::fake();

    $model->forceDelete();

    Queue::assertPushed(SnowflakeSync::$removeJob);
});

it('dispatches import job when soft-deleted model is restored', function () {
    $model = SoftDeleteTestModel::create(['name' => 'Test']);
    $model->delete();
    Queue::fake();

    $model->restore();

    Queue::assertPushed(SnowflakeSync::$importJob);
});

it('does not dispatch when syncing is disabled', function () {
    TestModel::disableSnowflakeSyncing();

    TestModel::create(['name' => 'Test']);

    Queue::assertNotPushed(SnowflakeSync::$importJob);

    TestModel::enableSnowflakeSyncing();
});

it('does not dispatch when syncing is disabled for force delete', function () {
    SoftDeleteTestModel::disableSnowflakeSyncing();

    $model = SoftDeleteTestModel::create(['name' => 'Test']);
    $model->forceDelete();

    Queue::assertNotPushed(SnowflakeSync::$removeJob);

    SoftDeleteTestModel::enableSnowflakeSyncing();
});

it('dispatches remove job on soft delete when the model opts in', function () {
    $model = SoftDeleteRemovesTestModel::create(['name' => 'Test']);
    Queue::fake();

    $model->delete();

    Queue::assertPushed(SnowflakeSync::$removeJob);
    Queue::assertNotPushed(SnowflakeSync::$importJob);
});

it('does not dispatch on create when syncing is disabled via config', function () {
    config(['snowflake-sync.enabled' => false]);

    TestModel::create(['name' => 'Test']);

    Queue::assertNotPushed(SnowflakeSync::$importJob);
});

it('does not dispatch on delete when syncing is disabled via config', function () {
    $model = TestModel::create(['name' => 'Test']);
    config(['snowflake-sync.enabled' => false]);
    Queue::fake();

    $model->delete();

    Queue::assertNotPushed(SnowflakeSync::$removeJob);
});

it('does not dispatch on restore when syncing is disabled via config', function () {
    $model = SoftDeleteTestModel::create(['name' => 'Test']);
    $model->delete();
    config(['snowflake-sync.enabled' => false]);
    Queue::fake();

    $model->restore();

    Queue::assertNotPushed(SnowflakeSync::$importJob);
});
