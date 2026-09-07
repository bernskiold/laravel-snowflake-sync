<?php

use Bernskiold\LaravelSnowflakeSync\Contracts\PendingSyncBuffer;
use Bernskiold\LaravelSnowflakeSync\Jobs\FlushSnowflakeSync;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\TestModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->buffer = app(PendingSyncBuffer::class);
});

it('reports when nothing is waiting', function () {
    $this->artisan('snowflake:flush')
        ->expectsOutputToContain('Nothing is waiting')
        ->assertSuccessful();
});

it('queues a flush job for every class with pending work', function () {
    Queue::fake();

    $this->buffer->queueUpsert(TestModel::class, [1, 2]);

    $this->artisan('snowflake:flush')->assertSuccessful();

    Queue::assertPushed(FlushSnowflakeSync::class);
    expect($this->buffer->pendingCount(TestModel::class))->toBe(2);
});

it('queues a flush job for a single named model', function () {
    Queue::fake();

    $this->buffer->queueUpsert(TestModel::class, [1]);

    $this->artisan('snowflake:flush', ['model' => TestModel::class])->assertSuccessful();

    Queue::assertPushed(FlushSnowflakeSync::class);
});

it('writes in process when asked to run synchronously', function () {
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

    $model = TestModel::withoutSyncingToSnowflake(fn () => TestModel::create(['name' => 'Test']));

    $this->buffer->queueUpsert(TestModel::class, [$model->id]);

    $this->artisan('snowflake:flush', ['--sync' => true])->assertSuccessful();

    expect(DB::connection('snowflake')->table('test_models')->count())->toBe(1)
        ->and($this->buffer->pendingCount(TestModel::class))->toBe(0);
});
