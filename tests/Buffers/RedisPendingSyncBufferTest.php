<?php

use Bernskiold\LaravelSnowflakeSync\Buffers\RedisPendingSyncBuffer;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\TestModel;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

beforeEach(function () {
    $this->redis = Mockery::mock(Connection::class);

    $factory = Mockery::mock(RedisFactory::class);
    $factory->shouldReceive('connection')->with('default')->andReturn($this->redis);

    $this->buffer = new RedisPendingSyncBuffer($factory, 'default', 'sf');
});

afterEach(function () {
    Mockery::close();
});

it('clears the opposite direction before adding an upsert', function () {
    $this->redis->shouldReceive('srem')->once()->with('sf:removals:'.TestModel::class, '1');
    $this->redis->shouldReceive('sadd')->once()->with('sf:upserts:'.TestModel::class, '1');
    $this->redis->shouldReceive('sadd')->once()->with('sf:classes', TestModel::class);

    $this->buffer->queueUpsert(TestModel::class, [1]);
});

it('clears the opposite direction before adding a removal', function () {
    $this->redis->shouldReceive('srem')->once()->with('sf:upserts:'.TestModel::class, '1');
    $this->redis->shouldReceive('sadd')->once()->with('sf:removals:'.TestModel::class, '1');
    $this->redis->shouldReceive('sadd')->once()->with('sf:classes', TestModel::class);

    $this->buffer->queueRemoval(TestModel::class, [1]);
});

it('deduplicates keys before writing them', function () {
    $this->redis->shouldReceive('srem')->once()->with('sf:removals:'.TestModel::class, '1', '2');
    $this->redis->shouldReceive('sadd')->once()->with('sf:upserts:'.TestModel::class, '1', '2');
    $this->redis->shouldReceive('sadd')->once()->with('sf:classes', TestModel::class);

    $this->buffer->queueUpsert(TestModel::class, [1, 1, 2, 2, 1]);
});

it('writes nothing when given no keys', function () {
    $this->redis->shouldNotReceive('srem');
    $this->redis->shouldNotReceive('sadd');

    $this->buffer->queueUpsert(TestModel::class, []);
});

it('pops pending keys as strings', function () {
    $this->redis->shouldReceive('spop')
        ->once()
        ->with('sf:upserts:'.TestModel::class, 500)
        ->andReturn([1, 2]);

    expect($this->buffer->takeUpserts(TestModel::class, 500))->toBe(['1', '2']);
});

it('treats an empty pop as no pending keys', function () {
    $this->redis->shouldReceive('spop')->once()->andReturn(false);

    expect($this->buffer->takeRemovals(TestModel::class, 10))->toBe([]);
});

it('counts both directions', function () {
    $this->redis->shouldReceive('scard')->with('sf:upserts:'.TestModel::class)->andReturn(2);
    $this->redis->shouldReceive('scard')->with('sf:removals:'.TestModel::class)->andReturn(3);

    expect($this->buffer->pendingCount(TestModel::class))->toBe(5);
});

it('drops drained classes from the registry', function () {
    $this->redis->shouldReceive('smembers')->with('sf:classes')->andReturn([TestModel::class]);
    $this->redis->shouldReceive('scard')->andReturn(0);
    $this->redis->shouldReceive('srem')->once()->with('sf:classes', TestModel::class);

    expect($this->buffer->pendingClasses())->toBe([]);
});

it('keeps classes that still have work waiting', function () {
    $this->redis->shouldReceive('smembers')->with('sf:classes')->andReturn([TestModel::class]);
    $this->redis->shouldReceive('scard')->with('sf:upserts:'.TestModel::class)->andReturn(1);
    $this->redis->shouldReceive('scard')->with('sf:removals:'.TestModel::class)->andReturn(0);

    expect($this->buffer->pendingClasses())->toBe([TestModel::class]);
});

it('restores keys without touching the opposite direction', function () {
    $this->redis->shouldReceive('sadd')->once()->with('sf:upserts:'.TestModel::class, '1');
    $this->redis->shouldReceive('sadd')->once()->with('sf:classes', TestModel::class);
    $this->redis->shouldNotReceive('srem');

    $this->buffer->restoreUpserts(TestModel::class, [1]);
});
