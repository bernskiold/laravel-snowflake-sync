<?php

use Bernskiold\LaravelSnowflakeSync\SnowflakeEngine;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\TestModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->app['config']->set('database.connections.snowflake', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    DB::connection('snowflake')->getSchemaBuilder()->create('test_models', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
});

it('inserts new models', function () {
    $model = TestModel::create(['name' => 'Test']);
    $engine = new SnowflakeEngine;

    $engine->update(new Collection([$model]));

    $rows = DB::connection('snowflake')->table('test_models')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->name)->toBe('Test');
});

it('replaces existing models on update', function () {
    $model = TestModel::create(['name' => 'Original']);
    $engine = new SnowflakeEngine;

    $engine->update(new Collection([$model]));

    $model->name = 'Updated';
    $engine->update(new Collection([$model]));

    $rows = DB::connection('snowflake')->table('test_models')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->name)->toBe('Updated');
});

it('handles multiple models in a batch', function () {
    $model1 = TestModel::create(['name' => 'First']);
    $model2 = TestModel::create(['name' => 'Second']);
    $engine = new SnowflakeEngine;

    $engine->update(new Collection([$model1, $model2]));

    $rows = DB::connection('snowflake')->table('test_models')->get();
    expect($rows)->toHaveCount(2);
});

it('does nothing with empty collection on update', function () {
    $engine = new SnowflakeEngine;
    $engine->update(new Collection);

    $rows = DB::connection('snowflake')->table('test_models')->get();
    expect($rows)->toHaveCount(0);
});

it('deletes models by key', function () {
    $model = TestModel::create(['name' => 'Test']);
    $engine = new SnowflakeEngine;

    $engine->update(new Collection([$model]));
    expect(DB::connection('snowflake')->table('test_models')->count())->toBe(1);

    $engine->delete(new Collection([$model]));
    expect(DB::connection('snowflake')->table('test_models')->count())->toBe(0);
});

it('does nothing with empty collection on delete', function () {
    $engine = new SnowflakeEngine;
    $engine->delete(new Collection);

    $rows = DB::connection('snowflake')->table('test_models')->get();
    expect($rows)->toHaveCount(0);
});

it('skips models with empty toSnowflake data', function () {
    $model = new class extends TestModel
    {
        public function toSnowflake(): array
        {
            return [];
        }
    };
    $model->id = 1;
    $model->name = 'Test';
    $model->exists = true;

    $engine = new SnowflakeEngine;
    $engine->update(new Collection([$model]));

    $rows = DB::connection('snowflake')->table('test_models')->get();
    expect($rows)->toHaveCount(0);
});
