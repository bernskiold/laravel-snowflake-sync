<?php

namespace Bernskiold\LaravelSnowflakeSync\Contracts;

/**
 * Holds the keys of models waiting to be written to Snowflake.
 *
 * The buffer exists so that a saved model costs one set write rather than one
 * queued job: Snowflake locks at table level for DML, so a hundred single-row
 * transactions against the same table are strictly slower than one MERGE of a
 * hundred rows. Repeated saves of the same row collapse into a single key,
 * which is where most of the saving comes from during bulk operations.
 *
 * A key is only ever pending in one direction. Queueing an upsert clears any
 * pending removal for that key and vice versa, so the last operation wins.
 */
interface PendingSyncBuffer
{
    /**
     * @param  class-string  $modelClass
     * @param  list<int|string>  $keys
     */
    public function queueUpsert(string $modelClass, array $keys): void;

    /**
     * @param  class-string  $modelClass
     * @param  list<int|string>  $keys
     */
    public function queueRemoval(string $modelClass, array $keys): void;

    /**
     * Remove and return up to $limit pending upsert keys.
     *
     * @param  class-string  $modelClass
     * @return list<string>
     */
    public function takeUpserts(string $modelClass, int $limit): array;

    /**
     * Remove and return up to $limit pending removal keys.
     *
     * @param  class-string  $modelClass
     * @return list<string>
     */
    public function takeRemovals(string $modelClass, int $limit): array;

    /**
     * Put previously taken keys back, without disturbing the opposite set.
     * Used when a flush fails so the work is not lost.
     *
     * @param  class-string  $modelClass
     * @param  list<int|string>  $keys
     */
    public function restoreUpserts(string $modelClass, array $keys): void;

    /**
     * @param  class-string  $modelClass
     * @param  list<int|string>  $keys
     */
    public function restoreRemovals(string $modelClass, array $keys): void;

    /**
     * The number of keys pending in both directions.
     *
     * @param  class-string  $modelClass
     */
    public function pendingCount(string $modelClass): int;

    /**
     * The model classes with work waiting. Implementations may drop classes
     * that have since been drained, so this is not a pure read.
     *
     * @return list<class-string>
     */
    public function pendingClasses(): array;

    /**
     * Discard everything pending, in both directions, for every class.
     */
    public function clear(): void;
}
