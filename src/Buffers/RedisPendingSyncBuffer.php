<?php

namespace Bernskiold\LaravelSnowflakeSync\Buffers;

use Bernskiold\LaravelSnowflakeSync\Contracts\PendingSyncBuffer;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

use function array_chunk;
use function array_map;
use function array_unique;
use function array_values;
use function is_array;
use function max;

/**
 * Keeps pending keys in two Redis sets per model class. Sets give free
 * deduplication — the thing that turns a bulk operation touching the same row
 * a hundred times into a single Snowflake write.
 */
class RedisPendingSyncBuffer implements PendingSyncBuffer
{
    /**
     * Redis caps the argument count of a single command, and a very wide
     * variadic call is slow to marshal, so multi-key writes go out in batches.
     */
    protected const ARGUMENT_BATCH = 1000;

    public function __construct(
        protected RedisFactory $redis,
        protected string $connection = 'default',
        protected string $prefix = 'snowflake-sync',
    ) {}

    public function queueUpsert(string $modelClass, array $keys): void
    {
        $this->move($modelClass, $keys, from: 'removals', to: 'upserts');
    }

    public function queueRemoval(string $modelClass, array $keys): void
    {
        $this->move($modelClass, $keys, from: 'upserts', to: 'removals');
    }

    public function takeUpserts(string $modelClass, int $limit): array
    {
        return $this->take($this->key($modelClass, 'upserts'), $limit);
    }

    public function takeRemovals(string $modelClass, int $limit): array
    {
        return $this->take($this->key($modelClass, 'removals'), $limit);
    }

    public function restoreUpserts(string $modelClass, array $keys): void
    {
        $this->add($this->key($modelClass, 'upserts'), $keys);
        $this->register($modelClass);
    }

    public function restoreRemovals(string $modelClass, array $keys): void
    {
        $this->add($this->key($modelClass, 'removals'), $keys);
        $this->register($modelClass);
    }

    public function pendingCount(string $modelClass): int
    {
        return (int) $this->connection()->scard($this->key($modelClass, 'upserts'))
            + (int) $this->connection()->scard($this->key($modelClass, 'removals'));
    }

    public function pendingClasses(): array
    {
        $classes = [];

        foreach ((array) $this->connection()->smembers($this->registryKey()) as $class) {
            if ($this->pendingCount($class) > 0) {
                $classes[] = $class;

                continue;
            }

            // Drained since it was registered; stop advertising it so the
            // periodic flush does not walk a growing list of empty classes.
            $this->connection()->srem($this->registryKey(), $class);
        }

        return $classes;
    }

    public function clear(): void
    {
        $connection = $this->connection();

        foreach ((array) $connection->smembers($this->registryKey()) as $class) {
            $connection->del($this->key($class, 'upserts'), $this->key($class, 'removals'));
        }

        $connection->del($this->registryKey());
    }

    /**
     * @param  list<int|string>  $keys
     */
    protected function move(string $modelClass, array $keys, string $from, string $to): void
    {
        $keys = $this->normalise($keys);

        if ($keys === []) {
            return;
        }

        // Clearing the opposite direction first means an interrupted move can
        // only ever lose the key from both sets, never leave it in both — and a
        // lost key is recovered by the next save or by `snowflake:import`.
        $this->remove($this->key($modelClass, $from), $keys);
        $this->add($this->key($modelClass, $to), $keys);

        $this->register($modelClass);
    }

    /**
     * @return list<string>
     */
    protected function take(string $key, int $limit): array
    {
        $members = $this->connection()->spop($key, max(1, $limit));

        if (! is_array($members)) {
            return $members === null || $members === false ? [] : [(string) $members];
        }

        return array_values(array_map(strval(...), $members));
    }

    /**
     * @param  list<string>  $keys
     */
    protected function add(string $key, array $keys): void
    {
        foreach (array_chunk($this->normalise($keys), self::ARGUMENT_BATCH) as $batch) {
            $this->connection()->sadd($key, ...$batch);
        }
    }

    /**
     * @param  list<string>  $keys
     */
    protected function remove(string $key, array $keys): void
    {
        foreach (array_chunk($keys, self::ARGUMENT_BATCH) as $batch) {
            $this->connection()->srem($key, ...$batch);
        }
    }

    protected function register(string $modelClass): void
    {
        $this->connection()->sadd($this->registryKey(), $modelClass);
    }

    /**
     * @param  list<int|string>  $keys
     * @return list<string>
     */
    protected function normalise(array $keys): array
    {
        return array_values(array_unique(array_map(strval(...), $keys)));
    }

    protected function key(string $modelClass, string $direction): string
    {
        return $this->prefix.':'.$direction.':'.$modelClass;
    }

    protected function registryKey(): string
    {
        return $this->prefix.':classes';
    }

    protected function connection(): Connection
    {
        return $this->redis->connection($this->connection);
    }
}
