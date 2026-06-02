<?php

namespace Bernskiold\LaravelSnowflakeSync\Concerns;

use Bernskiold\LaravelSnowflakeSync\Events\ModelsImported;
use Bernskiold\LaravelSnowflakeSync\Observers\ModelObserver;
use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\SoftDeletes;

use function class_uses_recursive;
use function config;
use function dispatch;
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

    public function queueSyncToSnowflake(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        dispatch((new SnowflakeSync::$importJob($models))
            ->onQueue($models->first()->syncWithSnowflakeUsingQueue())
            ->onConnection($models->first()->syncWithSnowflakeUsing()));
    }

    public function queueRemoveFromSnowflake(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        dispatch((new SnowflakeSync::$removeJob($models))
            ->onQueue($models->first()->syncWithSnowflakeUsingQueue())
            ->onConnection($models->first()->syncWithSnowflakeUsing()));
    }

    public function snowflakeShouldBeUpdated(): bool
    {
        return true;
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

    public static function syncAllToSnowflake(?int $chunk = null): void
    {
        $self = new static;
        $chunk = $chunk ?? config('snowflake-sync.chunk', 500);

        $self->newQuery()
            ->when(static::usesSoftDeleteSnowflakeSync(), fn (EloquentBuilder $query) => $query->withTrashed())
            ->tap(fn (EloquentBuilder $query) => $self->syncAllToSnowflakeUsing($query))
            ->orderBy($self->qualifyColumn($self->getSnowflakeKeyName()))
            ->chunkById($chunk, function (EloquentCollection $models) use ($self) {
                $syncable = $models->filter(fn ($model) => $model->shouldSyncToSnowflake());

                if ($syncable->isNotEmpty()) {
                    $self->queueSyncToSnowflake($syncable);
                }

                event(new ModelsImported($models));
            });
    }

    public function syncToSnowflake(): void
    {
        $this->queueSyncToSnowflake($this->newCollection([$this]));
    }

    public function removeFromSnowflake(): void
    {
        $this->queueRemoveFromSnowflake($this->newCollection([$this]));
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
