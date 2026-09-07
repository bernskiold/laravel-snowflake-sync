<?php

use Bernskiold\LaravelSnowflakeSync\Buffers\ArrayPendingSyncBuffer;
use Bernskiold\LaravelSnowflakeSync\Tests\Testing\TestModel;

beforeEach(function () {
    $this->buffer = new ArrayPendingSyncBuffer;
});

it('deduplicates repeated keys', function () {
    $this->buffer->queueUpsert(TestModel::class, [1, 1, 1, 2]);

    expect($this->buffer->takeUpserts(TestModel::class, 10))->toBe(['1', '2']);
});

it('lets a removal supersede a pending upsert', function () {
    $this->buffer->queueUpsert(TestModel::class, [1]);
    $this->buffer->queueRemoval(TestModel::class, [1]);

    expect($this->buffer->takeUpserts(TestModel::class, 10))->toBe([])
        ->and($this->buffer->takeRemovals(TestModel::class, 10))->toBe(['1']);
});

it('lets an upsert supersede a pending removal', function () {
    $this->buffer->queueRemoval(TestModel::class, [1]);
    $this->buffer->queueUpsert(TestModel::class, [1]);

    expect($this->buffer->takeRemovals(TestModel::class, 10))->toBe([])
        ->and($this->buffer->takeUpserts(TestModel::class, 10))->toBe(['1']);
});

it('takes no more than the requested limit', function () {
    $this->buffer->queueUpsert(TestModel::class, [1, 2, 3, 4, 5]);

    expect($this->buffer->takeUpserts(TestModel::class, 2))->toHaveCount(2)
        ->and($this->buffer->pendingCount(TestModel::class))->toBe(3);
});

it('removes taken keys from the buffer', function () {
    $this->buffer->queueUpsert(TestModel::class, [1, 2]);
    $this->buffer->takeUpserts(TestModel::class, 10);

    expect($this->buffer->takeUpserts(TestModel::class, 10))->toBe([]);
});

it('restores keys without disturbing the opposite direction', function () {
    $this->buffer->queueRemoval(TestModel::class, [2]);
    $this->buffer->restoreUpserts(TestModel::class, [1]);

    expect($this->buffer->takeUpserts(TestModel::class, 10))->toBe(['1'])
        ->and($this->buffer->takeRemovals(TestModel::class, 10))->toBe(['2']);
});

it('counts pending keys in both directions', function () {
    $this->buffer->queueUpsert(TestModel::class, [1, 2]);
    $this->buffer->queueRemoval(TestModel::class, [3]);

    expect($this->buffer->pendingCount(TestModel::class))->toBe(3);
});

it('reports only classes with work waiting', function () {
    $this->buffer->queueUpsert(TestModel::class, [1]);
    expect($this->buffer->pendingClasses())->toBe([TestModel::class]);

    $this->buffer->takeUpserts(TestModel::class, 10);
    expect($this->buffer->pendingClasses())->toBe([]);
});

it('clears everything', function () {
    $this->buffer->queueUpsert(TestModel::class, [1]);
    $this->buffer->queueRemoval(TestModel::class, [2]);

    $this->buffer->clear();

    expect($this->buffer->pendingCount(TestModel::class))->toBe(0);
});
