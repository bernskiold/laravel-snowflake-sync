<?php

namespace Bernskiold\LaravelSnowflakeSync\Concerns;

use Bernskiold\LaravelSnowflakeSync\Contracts\PendingSyncBuffer;
use Bernskiold\LaravelSnowflakeSync\Enums\SyncMode;
use Bernskiold\LaravelSnowflakeSync\Events\ModelsImported;
use Bernskiold\LaravelSnowflakeSync\Observers\ModelObserver;
use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\SoftDeletes;

use function app;
use function array_diff;
use function array_filter;
use function array_keys;
use function array_values;
use function class_uses_recursive;
use function config;
use function dispatch;
use function event;
use function get_called_class;
use function in_array;

trait SyncsToSnowflake
{
    protected static bool $snowflakeObserverRegistered = false;

    public static function bootSyncsToSnowflake()
    {
        static::$snowflakeObserverRegistered = false;
    }

    // Registers the observer after boot completes to avoid the recursive
    // boot that observe() triggers via its internal `new static` call.
    public function initializeSyncsToSnowflake(): void
    {
        if (! static::$snowflakeObserverRegistered) {
            static::$snowflakeObserverRegistered = true;

            static::observe(new ModelObserver);
        }
    }

    /**
     * Queue this model's key for the next write to Snowflake.
     *
     * Nothing is dispatched per model: the observer fires once per saved row,
     * and Snowflake's table-level DML lock means a job per row is the slowest
     * possible way to write. The key goes into the buffer, where repeated saves
     * of the same row collapse into one, and a single debounced flush job turns
     * the accumulated keys into one MERGE.
     */
    public function syncToSnowflake(): void
    {
        $this->snowflakeBuffer()->queueUpsert(static::class, [$this->getSnowflakeKey()]);

        $this->requestSnowflakeFlush();
    }

    public function removeFromSnowflake(): void
    {
        $this->snowflakeBuffer()->queueRemoval(static::class, [$this->getSnowflakeKey()]);

        $this->requestSnowflakeFlush();
    }

    /**
     * Buffer keys for the next flush without loading the models.
     *
     * The entry point for code that writes rows with the query builder, which
     * the observer never sees. A bulk insert stays a single statement, and its
     * rows join the same batched flush as everything else instead of the caller
     * having to write Snowflake itself.
     *
     * @param  list<int|string>  $keys
     */
    public static function queueSnowflakeSyncForKeys(array $keys): void
    {
        if ($keys === [] || ! static::snowflakeSyncingActive()) {
            return;
        }

        $self = new static;
        $self->snowflakeBuffer()->queueUpsert(static::class, $keys);
        $self->requestSnowflakeFlush();
    }

    /**
     * Buffer keys for removal without loading the models — the counterpart for
     * rows deleted with the query builder.
     *
     * @param  list<int|string>  $keys
     */
    public static function queueSnowflakeRemovalForKeys(array $keys): void
    {
        if ($keys === [] || ! static::snowflakeSyncingActive()) {
            return;
        }

        $self = new static;
        $self->snowflakeBuffer()->queueRemoval(static::class, $keys);
        $self->requestSnowflakeFlush();
    }

    /**
     * The same two gates the observer applies, so a bulk writer honours the
     * master switch and `withoutSyncingToSnowflake()` as well.
     */
    protected static function snowflakeSyncingActive(): bool
    {
        return (bool) config('snowflake-sync.enabled', true)
            && ! ModelObserver::syncingDisabledFor(get_called_class());
    }

    /**
     * Write a batch of already-loaded models straight to Snowflake.
     *
     * The bulk path (`syncAllToSnowflake()`, `snowflake:import`) chunks its own
     * work and holds the models already, so it skips the buffer entirely.
     */
    public function queueSyncToSnowflake(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $this->dispatchSnowflakeJob((new SnowflakeSync::$importJob($models))
            ->onQueue($models->first()->syncWithSnowflakeUsingQueue())
            ->onConnection($models->first()->syncWithSnowflakeUsing()));
    }

    public function queueRemoveFromSnowflake(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $removeJob = SnowflakeSync::$removeJob;

        $this->dispatchSnowflakeJob($removeJob::forModels($models)
            ->onQueue($models->first()->syncWithSnowflakeUsingQueue())
            ->onConnection($models->first()->syncWithSnowflakeUsing()));
    }

    /**
     * Whether a save is worth a Snowflake write.
     *
     * A save that changed nothing still fires the `saved` event, so without
     * this every no-op `save()` bought a full round trip. Note that Eloquent
     * touches `updated_at` on any save of an existing model, so opting into
     * `ignore_timestamp_only_changes` is what filters out bare `touch()` calls
     * — at the cost of letting the warehouse's `updated_at` drift.
     */
    public function snowflakeShouldBeUpdated(): bool
    {
        if ($this->wasRecentlyCreated) {
            return true;
        }

        $changed = array_keys($this->getChanges());

        if ($changed === []) {
            return false;
        }

        if (! config('snowflake-sync.ignore_timestamp_only_changes', false)) {
            return true;
        }

        return array_diff($changed, $this->snowflakeIgnoredChangeColumns()) !== [];
    }

    /**
     * Columns whose change alone does not justify a write.
     *
     * @return list<string>
     */
    public function snowflakeIgnoredChangeColumns(): array
    {
        return array_values(array_filter([$this->getUpdatedAtColumn()]));
    }

    public function shouldSyncToSnowflake(): bool
    {
        return true;
    }

    public function wasSyncingToSnowflakeBeforeUpdate()
    {
        return true;
    }

    public function wasSyncingToSnowflakeBeforeDelete()
    {
        return true;
    }

    /**
     * When a soft-deletable model is (soft) deleted, should the row be removed
     * from Snowflake? Defaults to false — the row is re-synced so the warehouse
     * keeps soft-deleted records. Override to true on models whose Snowflake
     * table does not retain soft-deleted rows.
     */
    public function removeFromSnowflakeOnSoftDelete(): bool
    {
        return false;
    }

    /**
     * How buffered changes for this model reach Snowflake. Defaults to the
     * `snowflake-sync.mode` setting; override to put a high-churn model on a
     * periodic schedule while the rest stay live, or the reverse.
     */
    public function snowflakeSyncMode(): SyncMode
    {
        return SyncMode::tryFrom((string) config('snowflake-sync.mode', 'live')) ?? SyncMode::Live;
    }

    public function snowflakeBuffer(): PendingSyncBuffer
    {
        return app(PendingSyncBuffer::class);
    }

    public function snowflakeTable(): string
    {
        return $this->getTable();
    }

    public function toSnowflake(): array
    {
        return $this->toArray();
    }

    public function snowflakeConnection(): string
    {
        return config('snowflake-sync.connections.default', 'snowflake');
    }

    public function syncWithSnowflakeUsing(): string
    {
        return config('snowflake-sync.queue.connection') ?: config('queue.default');
    }

    public function syncWithSnowflakeUsingQueue(): string
    {
        return config('snowflake-sync.queue.queue');
    }

    public function getSnowflakeKey()
    {
        return $this->getKey();
    }

    public function getSnowflakeKeyName(): string
    {
        return $this->getKeyName();
    }

    /**
     * The query every sync path loads models through, so the buffered flush and
     * a full import agree on which rows belong in Snowflake.
     */
    public function newSnowflakeSyncQuery(): EloquentBuilder
    {
        return $this->newQuery()
            ->when(static::usesSoftDeleteSnowflakeSync(), fn (EloquentBuilder $query) => $query->withTrashed())
            ->tap(fn (EloquentBuilder $query) => $this->syncAllToSnowflakeUsing($query));
    }

    public static function syncAllToSnowflake(?int $chunk = null): void
    {
        $self = new static;
        $chunk = $chunk ?? config('snowflake-sync.chunk', 500);

        $self->newSnowflakeSyncQuery()
            ->orderBy($self->qualifyColumn($self->getSnowflakeKeyName()))
            ->chunkById($chunk, function (EloquentCollection $models) use ($self) {
                $syncable = $models->filter(fn ($model) => $model->shouldSyncToSnowflake());

                if ($syncable->isNotEmpty()) {
                    $self->queueSyncToSnowflake($syncable);
                }

                event(new ModelsImported($models));
            });
    }

    /**
     * Ask for the buffered work to be written. In live mode that is a debounced
     * flush job; in periodic mode the keys simply wait for `snowflake:flush`.
     */
    protected function requestSnowflakeFlush(): void
    {
        if ($this->snowflakeSyncMode() !== SyncMode::Live) {
            return;
        }

        $flushJob = SnowflakeSync::$flushJob;

        $this->dispatchSnowflakeJob($flushJob::for(static::class));
    }

    /**
     * The buffer is written inside any open transaction, but the flush only
     * reads keys and reloads rows from the source database, so a rolled-back
     * save resolves to "row not found" and never reaches Snowflake. Deferring
     * the dispatch itself still matters when the queue is fast enough to beat
     * the commit.
     */
    protected function dispatchSnowflakeJob(object $job): void
    {
        $dispatch = dispatch($job);

        if (config('snowflake-sync.after_commit', false)) {
            $dispatch->afterCommit();
        }
    }

    protected function syncAllToSnowflakeUsing(EloquentBuilder $query)
    {
        return $query;
    }

    protected static function usesSoftDeleteSnowflakeSync(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive(get_called_class()));
    }

    public static function enableSnowflakeSyncing()
    {
        ModelObserver::enableSyncingFor(get_called_class());
    }

    public static function disableSnowflakeSyncing()
    {
        ModelObserver::disableSyncingFor(get_called_class());
    }

    public static function withoutSyncingToSnowflake($callback)
    {
        static::disableSnowflakeSyncing();

        try {
            return $callback();
        } finally {
            static::enableSnowflakeSyncing();
        }
    }
}
