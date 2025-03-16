<?php

use Bernskiold\LaravelSnowflakeSync\Jobs\RemoveFromSnowflake;
use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;
use Illuminate\Database\Eloquent\Collection;
use Mockery;

it('does not call the engine when collection is empty', function () {
    // Create a mock of the engine
    $engineMock = Mockery::mock('engine');
    $engineMock->shouldNotReceive('delete');

    // Bind the mock to the container
    SnowflakeSync::$engine = get_class($engineMock);
    app()->instance(get_class($engineMock), $engineMock);

    // Create an empty collection
    $collection = new Collection;

    // Create and dispatch the job
    $job = new RemoveFromSnowflake($collection);
    $job->handle();
});

it('calls the engine with models when collection is not empty', function () {
    // Create a mock model
    $model = new class extends \Illuminate\Database\Eloquent\Model {};
    $collection = new Collection([$model]);

    // Create a mock of the engine
    $engineMock = Mockery::mock('engine');
    $engineMock->shouldReceive('delete')
        ->once()
        ->with(Mockery::type(Collection::class));

    // Bind the mock to the container
    SnowflakeSync::$engine = get_class($engineMock);
    app()->instance(get_class($engineMock), $engineMock);

    // Create and dispatch the job
    $job = new RemoveFromSnowflake($collection);
    $job->handle();
});

afterEach(function () {
    Mockery::close();
});
