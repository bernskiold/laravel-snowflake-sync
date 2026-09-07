<?php

use Bernskiold\LaravelSnowflakeSync\SnowflakeEngine;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\TestModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
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

it('leaves the existing row untouched when the write fails', function () {
    $model = TestModel::create(['name' => 'Original']);
    $engine = new SnowflakeEngine;
    $engine->update(new Collection([$model]));

    // A single MERGE either applies or it does not, so a NOT NULL violation
    // cannot leave the row deleted-but-not-reinserted the way the old
    // delete-then-insert transaction could.
    $bad = new class extends TestModel
    {
        public function toSnowflake(): array
        {
            return ['id' => 1, 'name' => null];
        }
    };
    $bad->id = 1;
    $bad->exists = true;

    expect(fn () => $engine->update(new Collection([$bad])))->toThrow(QueryException::class);

    $rows = DB::connection('snowflake')->table('test_models')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->name)->toBe('Original');
});

it('writes one statement per batch rather than one per model', function () {
    $models = new Collection(
        collect(range(1, 5))->map(fn (int $i) => TestModel::create(['name' => 'Model '.$i]))->all()
    );

    $engine = new SnowflakeEngine;

    DB::connection('snowflake')->enableQueryLog();
    $engine->update($models);
    $queries = DB::connection('snowflake')->getQueryLog();

    expect($queries)->toHaveCount(1)
        ->and(DB::connection('snowflake')->table('test_models')->count())->toBe(5);
});

it('chunks a batch larger than the configured chunk size', function () {
    config(['snowflake-sync.chunk' => 2]);

    $models = new Collection(
        collect(range(1, 5))->map(fn (int $i) => TestModel::create(['name' => 'Model '.$i]))->all()
    );

    $engine = new SnowflakeEngine;

    DB::connection('snowflake')->enableQueryLog();
    $engine->update($models);

    expect(DB::connection('snowflake')->getQueryLog())->toHaveCount(3)
        ->and(DB::connection('snowflake')->table('test_models')->count())->toBe(5);
});

it('keeps only the last row when a model appears twice in one batch', function () {
    // Snowflake refuses a MERGE whose source matches a target row more than
    // once, so duplicates have to collapse before the statement is built.
    $model = TestModel::create(['name' => 'First']);

    $later = clone $model;
    $later->name = 'Last';

    $engine = new SnowflakeEngine;
    $engine->update(new Collection([$model, $later]));

    $rows = DB::connection('snowflake')->table('test_models')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->name)->toBe('Last');
});

it('separates rows that do not describe the same columns', function () {
    $full = TestModel::create(['name' => 'Full']);

    $partial = new class extends TestModel
    {
        public function toSnowflake(): array
        {
            return ['id' => 99, 'name' => 'Partial'];
        }
    };
    $partial->id = 99;
    $partial->exists = true;

    $engine = new SnowflakeEngine;
    $engine->update(new Collection([$full, $partial]));

    expect(DB::connection('snowflake')->table('test_models')->count())->toBe(2);
});

it('deletes by key when the source row is already gone', function () {
    $model = TestModel::create(['name' => 'Test']);
    $engine = new SnowflakeEngine;
    $engine->update(new Collection([$model]));

    $id = $model->id;
    $model->forceDelete();

    $engine->deleteKeys(TestModel::class, [$id]);

    expect(DB::connection('snowflake')->table('test_models')->count())->toBe(0);
});

it('does nothing when deleting an empty set of keys', function () {
    $model = TestModel::create(['name' => 'Test']);
    $engine = new SnowflakeEngine;
    $engine->update(new Collection([$model]));

    $engine->deleteKeys(TestModel::class, []);

    expect(DB::connection('snowflake')->table('test_models')->count())->toBe(1);
});

it('shares one statement between rows whose columns differ only in order', function () {
    $first = new class extends TestModel
    {
        public function toSnowflake(): array
        {
            return ['id' => 1, 'name' => 'First'];
        }
    };
    $first->id = 1;
    $first->exists = true;

    $second = new class extends TestModel
    {
        public function toSnowflake(): array
        {
            return ['name' => 'Second', 'id' => 2];
        }
    };
    $second->id = 2;
    $second->exists = true;

    DB::connection('snowflake')->enableQueryLog();
    (new SnowflakeEngine)->update(new Collection([$first, $second]));

    expect(DB::connection('snowflake')->getQueryLog())->toHaveCount(1)
        ->and(DB::connection('snowflake')->table('test_models')->count())->toBe(2);
});
