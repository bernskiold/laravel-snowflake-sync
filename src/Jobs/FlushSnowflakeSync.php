<?php

namespace Bernskiold\LaravelSnowflakeSync\Jobs;

use Bernskiold\LaravelSnowflakeSync\Contracts\PendingSyncBuffer;
use Bernskiold\LaravelSnowflakeSync\Events\ModelsImported;
use Bernskiold\LaravelSnowflakeSync\Events\ModelsRemoved;
use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

use function app;
use function config;
use function dispatch;
use function event;
use function max;

/**
 * Drains the pending buffer for one model class into batched Snowflake writes.
 *
 * Uniqueness is released when processing *begins*, not when it ends. That is
 * what makes the debounce safe: a save landing while the flush is running
 * dispatches a fresh delayed job rather than being swallowed by a lock the
 * running flush still holds. Two flushes for the same class overlapping is
 * harmless — keys are popped atomically and the MERGE is idempotent.
 */
class FlushSnowflakeSync implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    /**
     * Whether a backlog this flush could not finish is handed to a fresh queued
     * job. `snowflake:flush --sync` turns this off: the caller asked for the
     * work to happen in this process, not to have more of it queued.
     */
    public bool $queueContinuation = true;

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function __construct(public string $modelClass) {}

    /**
     * Build a flush job routed to the queue and connection the model asks for,
     * delayed so that a burst of saves coalesces into one write.
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function for(string $modelClass): self
    {
        $model = new $modelClass;

        $job = new self($modelClass);

        $job->onQueue($model->syncWithSnowflakeUsingQueue())
            ->onConnection($model->syncWithSnowflakeUsing());

        $delay = (int) config('snowflake-sync.flush.delay', 5);

        return $delay > 0 ? $job->delay($delay) : $job;
    }

    public function uniqueId(): string
    {
        return $this->modelClass;
    }

    public function uniqueFor(): int
    {
        return (int) config('snowflake-sync.flush.unique_for', 900);
    }

    public function handle(PendingSyncBuffer $buffer): void
    {
        // `enabled` has to stop writes here, not just at the observer. It is the
        // lever you reach for when Snowflake is unavailable and writes are
        // piling up, and by then the jobs it needs to stop are already queued.
        // Returning without draining leaves the buffer intact, so whatever was
        // pending goes out once syncing is switched back on.
        if (! config('snowflake-sync.enabled', true)) {
            return;
        }

        $engine = app(SnowflakeSync::$engine);
        $model = new $this->modelClass;
        $chunk = max(1, (int) config('snowflake-sync.chunk', 500));
        $rounds = max(1, (int) config('snowflake-sync.flush.max_chunks', 20));

        for ($round = 0; $round < $rounds; $round++) {
            $removed = $this->flushRemovals($buffer, $engine, $chunk);
            $upserted = $this->flushUpserts($buffer, $engine, $model, $chunk);

            if (! $removed && ! $upserted) {
                return;
            }
        }

        // Still work waiting. Hand it to a fresh job rather than run on towards
        // the queue timeout, and skip the debounce delay since we know there is
        // something to do right now.
        if ($this->queueContinuation && $buffer->pendingCount($this->modelClass) > 0) {
            dispatch((new self($this->modelClass))
                ->onQueue($model->syncWithSnowflakeUsingQueue())
                ->onConnection($model->syncWithSnowflakeUsing()));
        }
    }

    /**
     * @param  object  $engine  Whatever {@see SnowflakeSync::$engine} points at.
     */
    protected function flushRemovals(PendingSyncBuffer $buffer, object $engine, int $chunk): bool
    {
        $keys = $buffer->takeRemovals($this->modelClass, $chunk);

        if ($keys === []) {
            return false;
        }

        try {
            $engine->deleteKeys($this->modelClass, $keys);
        } catch (Throwable $e) {
            $buffer->restoreRemovals($this->modelClass, $keys);

            throw $e;
        }

        event(new ModelsRemoved($this->modelClass, $keys));

        return true;
    }

    protected function flushUpserts(PendingSyncBuffer $buffer, object $engine, Model $model, int $chunk): bool
    {
        $keys = $buffer->takeUpserts($this->modelClass, $chunk);

        if ($keys === []) {
            return false;
        }

        try {
            // Reloading from the source database rather than carrying a
            // serialised model means repeated saves collapse to the row's final
            // state, and a save that was rolled back never reaches Snowflake.
            $models = $model->newSnowflakeSyncQuery()
                ->whereIn($model->qualifyColumn($model->getSnowflakeKeyName()), $keys)
                ->get();

            [$syncable, $stale] = $models->partition(
                fn (Model $model) => $model->shouldSyncToSnowflake()
            );

            $this->write($engine, $syncable);
            $this->discard($engine, $stale);
        } catch (Throwable $e) {
            $buffer->restoreUpserts($this->modelClass, $keys);

            throw $e;
        }

        // Keys with no matching row are left alone: they were removed without
        // the observer seeing it, and only `snowflake:import` can say for sure
        // whether the warehouse row should go.

        return true;
    }

    protected function write(object $engine, Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $engine->update($models);

        event(new ModelsImported($models));
    }

    protected function discard(object $engine, Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $engine->delete($models);

        event(ModelsRemoved::forModels($models));
    }
}
