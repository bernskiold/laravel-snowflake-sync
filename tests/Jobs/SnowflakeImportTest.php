<?php

use Bernskiold\LaravelSnowflakeSync\Events\ModelsImported;
use Bernskiold\LaravelSnowflakeSync\Jobs\SnowflakeImport;
use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->originalEngine = SnowflakeSync::$engine;
});

it('does not call the engine when collection is empty', function () {
    Event::fake();

    $engineMock = Mockery::mock('engine');
    $engineMock->shouldNotReceive('update');

    SnowflakeSync::$engine = get_class($engineMock);
    app()->instance(get_class($engineMock), $engineMock);

    $job = new SnowflakeImport(new Collection);
    $job->handle();

    Event::assertNotDispatched(ModelsImported::class);
});

it('calls the engine and fires event when collection is not empty', function () {
    Event::fake();

    $model = new class extends Model {};
    $collection = new Collection([$model]);

    $engineMock = Mockery::mock('engine');
    $engineMock->shouldReceive('update')
        ->once()
        ->with(Mockery::type(Collection::class));

    SnowflakeSync::$engine = get_class($engineMock);
    app()->instance(get_class($engineMock), $engineMock);

    $job = new SnowflakeImport($collection);
    $job->handle();

    Event::assertDispatched(ModelsImported::class);
});

it('has retry configuration', function () {
    $job = new SnowflakeImport(new Collection);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60, 300]);
});

afterEach(function () {
    SnowflakeSync::$engine = $this->originalEngine;
    Mockery::close();
});
