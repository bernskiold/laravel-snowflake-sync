<?php

use Bernskiold\LaravelSnowflakeSync\Events\ModelsRemoved;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\TestModel;
use Illuminate\Database\Eloquent\Collection;

it('holds the model class and the removed keys', function () {
    $event = new ModelsRemoved(TestModel::class, [1, 2]);

    expect($event->modelClass)->toBe(TestModel::class)
        ->and($event->keys)->toBe([1, 2]);
});

it('can be built from a collection of models', function () {
    $first = TestModel::create(['name' => 'First']);
    $second = TestModel::create(['name' => 'Second']);

    $event = ModelsRemoved::forModels(new Collection([$first, $second]));

    expect($event->modelClass)->toBe(TestModel::class)
        ->and($event->keys)->toBe([$first->id, $second->id]);
});
