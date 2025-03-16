<?php

namespace Tests\Unit\Events;

use Bernskiold\LaravelSnowflakeSync\Events\ModelsRemoved;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Mockery;

it('holds a collection of models', function () {
    $model = Mockery::mock(Model::class);
    $collection = new Collection([$model]);

    $event = new ModelsRemoved($collection);

    expect($event->models)->toBe($collection);
});

afterEach(function () {
    Mockery::close();
});
