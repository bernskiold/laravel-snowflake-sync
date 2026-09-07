<?php

namespace Bernskiold\LaravelSnowflakeSync\Buffers;

use Bernskiold\LaravelSnowflakeSync\Contracts\PendingSyncBuffer;

use function array_diff_key;
use function array_filter;
use function array_flip;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function max;

/**
 * An in-memory buffer, for tests and for single-process setups running the
 * `sync` queue driver. It holds nothing across requests, so a live-mode flush
 * dispatched to a real queue worker would find it empty — use the Redis buffer
 * anywhere the flush runs in a different process than the save.
 */
class ArrayPendingSyncBuffer implements PendingSyncBuffer
{
    /**
     * @var array<class-string, array<string, true>>
     */
    protected array $upserts = [];

    /**
     * @var array<class-string, array<string, true>>
     */
    protected array $removals = [];

    public function queueUpsert(string $modelClass, array $keys): void
    {
        $keys = $this->normalise($keys);

        $this->removals[$modelClass] = array_diff_key($this->removals[$modelClass] ?? [], $keys);
        $this->upserts[$modelClass] = ($this->upserts[$modelClass] ?? []) + $keys;
    }

    public function queueRemoval(string $modelClass, array $keys): void
    {
        $keys = $this->normalise($keys);

        $this->upserts[$modelClass] = array_diff_key($this->upserts[$modelClass] ?? [], $keys);
        $this->removals[$modelClass] = ($this->removals[$modelClass] ?? []) + $keys;
    }

    public function takeUpserts(string $modelClass, int $limit): array
    {
        return $this->take($this->upserts, $modelClass, $limit);
    }

    public function takeRemovals(string $modelClass, int $limit): array
    {
        return $this->take($this->removals, $modelClass, $limit);
    }

    public function restoreUpserts(string $modelClass, array $keys): void
    {
        $this->upserts[$modelClass] = ($this->upserts[$modelClass] ?? []) + $this->normalise($keys);
    }

    public function restoreRemovals(string $modelClass, array $keys): void
    {
        $this->removals[$modelClass] = ($this->removals[$modelClass] ?? []) + $this->normalise($keys);
    }

    public function pendingCount(string $modelClass): int
    {
        return count($this->upserts[$modelClass] ?? []) + count($this->removals[$modelClass] ?? []);
    }

    public function pendingClasses(): array
    {
        $classes = array_keys($this->upserts + $this->removals);

        return array_values(array_filter($classes, fn (string $class) => $this->pendingCount($class) > 0));
    }

    public function clear(): void
    {
        $this->upserts = [];
        $this->removals = [];
    }

    /**
     * @param  array<class-string, array<string, true>>  $store
     * @return list<string>
     */
    protected function take(array &$store, string $modelClass, int $limit): array
    {
        $taken = array_slice(array_keys($store[$modelClass] ?? []), 0, max(1, $limit));

        $store[$modelClass] = array_diff_key($store[$modelClass] ?? [], array_flip($taken));

        // PHP silently casts numeric array keys to int, so cast back: callers
        // compare against what the Redis buffer returns, which is always a
        // string.
        return array_map(strval(...), $taken);
    }

    /**
     * @param  list<int|string>  $keys
     * @return array<string, true>
     */
    protected function normalise(array $keys): array
    {
        return array_flip(array_map(strval(...), $keys));
    }
}
