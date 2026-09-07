<?php

use Bernskiold\LaravelSnowflakeSync\Events\ModelsRemoved;
use Bernskiold\LaravelSnowflakeSync\Jobs\RemoveFromSnowflake;
use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\TestModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->originalEngine = SnowflakeSync::$engine;
});

it('does not call the engine when there are no keys', function () {
    Event::fake();

    $engineMock = Mockery::mock('engine');
    $engineMock->shouldNotReceive('deleteKeys');

    SnowflakeSync::$engine = get_class($engineMock);
    app()->instance(get_class($engineMock), $engineMock);

    (new RemoveFromSnowflake(TestModel::class, []))->handle();

    Event::assertNotDispatched(ModelsRemoved::class);
});

it('calls the engine with keys and fires the event', function () {
    Event::fake();

    $engineMock = Mockery::mock('engine');
    $engineMock->shouldReceive('deleteKeys')
        ->once()
        ->with(TestModel::class, [1, 2]);

    SnowflakeSync::$engine = get_class($engineMock);
    app()->instance(get_class($engineMock), $engineMock);

    (new RemoveFromSnowflake(TestModel::class, [1, 2]))->handle();

    Event::assertDispatched(ModelsRemoved::class, function (ModelsRemoved $event) {
        return $event->modelClass === TestModel::class && $event->keys === [1, 2];
    });
});

it('builds itself from a collection of models', function () {
    $first = TestModel::create(['name' => 'First']);
    $second = TestModel::create(['name' => 'Second']);

    $job = RemoveFromSnowflake::forModels(new Collection([$first, $second]));

    expect($job->modelClass)->toBe(TestModel::class)
        ->and($job->keys)->toBe([$first->id, $second->id]);
});

it('deletes rows whose models no longer exist in the source database', function () {
    // The whole point of carrying keys: a hard-deleted row cannot be restored
    // from a serialised model identifier, so a model-carrying job deleted
    // nothing at all.
    Event::fake();

    $model = TestModel::create(['name' => 'Test']);
    $job = RemoveFromSnowflake::forModels(new Collection([$model]));

    $model->forceDelete();

    $engineMock = Mockery::mock('engine');
    $engineMock->shouldReceive('deleteKeys')
        ->once()
        ->with(TestModel::class, [$model->id]);

    SnowflakeSync::$engine = get_class($engineMock);
    app()->instance(get_class($engineMock), $engineMock);

    $job->handle();

    Event::assertDispatched(ModelsRemoved::class);
});

it('has retry configuration', function () {
    $job = new RemoveFromSnowflake(TestModel::class, []);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60, 300]);
});

afterEach(function () {
    SnowflakeSync::$engine = $this->originalEngine;
    Mockery::close();
});
