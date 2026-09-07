<?php

use Bernskiold\LaravelSnowflakeSync\Contracts\PendingSyncBuffer;
use Bernskiold\LaravelSnowflakeSync\Events\ModelsImported;
use Bernskiold\LaravelSnowflakeSync\Events\ModelsRemoved;
use Bernskiold\LaravelSnowflakeSync\Jobs\FlushSnowflakeSync;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\TestModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

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

    $this->buffer = app(PendingSyncBuffer::class);
});

it('writes every buffered upsert in one statement', function () {
    $models = collect(range(1, 4))->map(fn (int $i) => TestModel::create(['name' => 'Model '.$i]));

    $this->buffer->clear();
    $this->buffer->queueUpsert(TestModel::class, $models->pluck('id')->all());

    DB::connection('snowflake')->enableQueryLog();
    (new FlushSnowflakeSync(TestModel::class))->handle($this->buffer);

    expect(DB::connection('snowflake')->getQueryLog())->toHaveCount(1)
        ->and(DB::connection('snowflake')->table('test_models')->count())->toBe(4);
});

it('writes the current state of a row, not the state at the time of the save', function () {
    $model = TestModel::create(['name' => 'Original']);

    $this->buffer->clear();
    $this->buffer->queueUpsert(TestModel::class, [$model->id]);

    DB::table('test_models')->where('id', $model->id)->update(['name' => 'Latest']);

    (new FlushSnowflakeSync(TestModel::class))->handle($this->buffer);

    expect(DB::connection('snowflake')->table('test_models')->first()->name)->toBe('Latest');
});

it('deletes buffered removals whose rows are already gone', function () {
    $model = TestModel::create(['name' => 'Test']);
    $id = $model->id;

    DB::connection('snowflake')->table('test_models')->insert([
        'id' => $id, 'name' => 'Test',
    ]);

    $model->forceDelete();

    $this->buffer->clear();
    $this->buffer->queueRemoval(TestModel::class, [$id]);

    (new FlushSnowflakeSync(TestModel::class))->handle($this->buffer);

    expect(DB::connection('snowflake')->table('test_models')->count())->toBe(0);
});

it('leaves nothing pending after a successful flush', function () {
    $model = TestModel::create(['name' => 'Test']);

    $this->buffer->clear();
    $this->buffer->queueUpsert(TestModel::class, [$model->id]);

    (new FlushSnowflakeSync(TestModel::class))->handle($this->buffer);

    expect($this->buffer->pendingCount(TestModel::class))->toBe(0);
});

it('skips keys whose rows no longer exist', function () {
    $this->buffer->clear();
    $this->buffer->queueUpsert(TestModel::class, [999]);

    (new FlushSnowflakeSync(TestModel::class))->handle($this->buffer);

    expect(DB::connection('snowflake')->table('test_models')->count())->toBe(0)
        ->and($this->buffer->pendingCount(TestModel::class))->toBe(0);
});

it('fires an imported event for the rows it wrote', function () {
    Event::fake();

    $model = TestModel::create(['name' => 'Test']);
    $this->buffer->queueUpsert(TestModel::class, [$model->id]);

    (new FlushSnowflakeSync(TestModel::class))->handle($this->buffer);

    Event::assertDispatched(ModelsImported::class);
});

it('fires a removed event for the keys it deleted', function () {
    Event::fake();

    $this->buffer->queueRemoval(TestModel::class, [1]);

    (new FlushSnowflakeSync(TestModel::class))->handle($this->buffer);

    Event::assertDispatched(ModelsRemoved::class, function (ModelsRemoved $event) {
        return $event->keys === ['1'];
    });
});

it('puts keys back on the buffer when the write fails', function () {
    $model = TestModel::create(['name' => 'Test']);

    $this->buffer->clear();
    $this->buffer->queueUpsert(TestModel::class, [$model->id]);

    DB::connection('snowflake')->getSchemaBuilder()->drop('test_models');

    expect(fn () => (new FlushSnowflakeSync(TestModel::class))->handle($this->buffer))
        ->toThrow(Exception::class);

    expect($this->buffer->takeUpserts(TestModel::class, 10))->toBe([(string) $model->id]);
});

it('hands a backlog it cannot finish to a fresh job', function () {
    config(['snowflake-sync.chunk' => 1, 'snowflake-sync.flush.max_chunks' => 2]);

    // Seeding without the observer keeps the flush job's uniqueness lock free,
    // so the continuation dispatch is not mistaken for a duplicate.
    $models = TestModel::withoutSyncingToSnowflake(
        fn () => collect(range(1, 5))->map(fn (int $i) => TestModel::create(['name' => 'Model '.$i]))
    );

    $this->buffer->clear();
    $this->buffer->queueUpsert(TestModel::class, $models->pluck('id')->all());

    Queue::fake();

    (new FlushSnowflakeSync(TestModel::class))->handle($this->buffer);

    expect($this->buffer->pendingCount(TestModel::class))->toBe(3);
    Queue::assertPushed(FlushSnowflakeSync::class);
});

it('stops as soon as the buffer is empty', function () {
    Queue::fake();

    (new FlushSnowflakeSync(TestModel::class))->handle($this->buffer);

    Queue::assertNotPushed(FlushSnowflakeSync::class);
});

it('routes a debounced flush to the model queue and connection', function () {
    config([
        'snowflake-sync.queue.queue' => 'snowflake-sync',
        'snowflake-sync.queue.connection' => 'null',
        'snowflake-sync.flush.delay' => 30,
    ]);

    $job = FlushSnowflakeSync::for(TestModel::class);

    expect($job->queue)->toBe('snowflake-sync')
        ->and($job->connection)->toBe('null')
        ->and($job->delay)->toBe(30)
        ->and($job->uniqueId())->toBe(TestModel::class);
});

it('leaves the buffer alone when syncing is disabled', function () {
    // The kill switch has to work here too: by the time you reach for it, the
    // flush jobs it needs to stop are already on the queue.
    config(['snowflake-sync.enabled' => false]);

    $model = TestModel::withoutSyncingToSnowflake(fn () => TestModel::create(['name' => 'Test']));
    $this->buffer->queueUpsert(TestModel::class, [$model->id]);

    (new FlushSnowflakeSync(TestModel::class))->handle($this->buffer);

    expect(DB::connection('snowflake')->table('test_models')->count())->toBe(0)
        ->and($this->buffer->pendingCount(TestModel::class))->toBe(1);
});

it('does not queue a continuation when asked not to', function () {
    config(['snowflake-sync.chunk' => 1, 'snowflake-sync.flush.max_chunks' => 1]);

    $models = TestModel::withoutSyncingToSnowflake(
        fn () => collect(range(1, 3))->map(fn (int $i) => TestModel::create(['name' => 'Model '.$i]))
    );

    $this->buffer->clear();
    $this->buffer->queueUpsert(TestModel::class, $models->pluck('id')->all());

    Queue::fake();

    $job = new FlushSnowflakeSync(TestModel::class);
    $job->queueContinuation = false;
    $job->handle($this->buffer);

    expect($this->buffer->pendingCount(TestModel::class))->toBe(2);
    Queue::assertNotPushed(FlushSnowflakeSync::class);
});
