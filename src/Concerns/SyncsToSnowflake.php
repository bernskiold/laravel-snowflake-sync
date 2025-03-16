<?php

namespace Bernskiold\LaravelSnowflakeSync\Concerns;

use Bernskiold\LaravelSnowflakeSync\Observers\ModelObserver;
use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection as BaseCollection;

trait SyncsToSnowflake
{
    public static function bootSyncsToSnowflake()
    {
        static::observe(new ModelObserver);

        (new static)->registerSnowflakeSyncMacros();
    }

    public function registerSnowflakeSyncMacros()
    {
        $self = $this;

        BaseCollection::macro('syncToSnowflake', function () use ($self) {
            $self->queueSyncToSnowflake($this);
        });

        BaseCollection::macro('removeFromSnowflake', function () use ($self) {
            $self->queueRemoveFromSnowflake($this);
        });
    }

    public function queueSyncToSnowflake(BaseCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        dispatch((new SnowflakeSync::$importJob($models))
            ->onQueue($models->first()->syncWithSnowflakeUsingQueue())
            ->onConnection($models->first()->syncWithSnowflakeUsing()));
    }

    public function queueRemoveFromSnowflake(BaseCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        dispatch(new SnowflakeSync::$removeJob($models))
            ->onQueue($models->first()->syncWithSnowflakeUsingQueue())
            ->onConnection($models->first()->syncWithSnowflakeUsing());
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

        $softDelete = static::usesSoftDelete() ? 'withTrashed' : 'newQuery';

        $self->newQuery()
            ->when(true, function (EloquentBuilder $query) use ($self) {
                $self->syncAllToSnowflakeUsing($query);
            })
            ->when($softDelete === 'withTrashed', function (EloquentBuilder $query) {
                $query->withTrashed();
            })
            ->orderBy(
                $self->qualifyColumn($self->getSnowflakeKeyName())
            )
            ->get()
            ->syncToSnowflake($chunk);
    }

    public function syncToSnowflake(): void
    {
        $this->newCollection([$this])->syncToSnowflake();
    }

    protected function syncAllToSnowflakeUsing(EloquentBuilder $query)
    {
        return $query;
    }

    public function syncToSnowflakeUsing(BaseCollection $models)
    {
        return $models;
    }

    protected static function usesSoftDelete(): bool
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
