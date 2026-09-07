<?php

namespace Bernskiold\LaravelSnowflakeSync\Observers;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ModelObserver
{
    protected bool $forceSaving = false;

    protected static array $syncingDisabledFor = [];

    public static function enableSyncingFor($class)
    {
        unset(static::$syncingDisabledFor[$class]);
    }

    public static function disableSyncingFor($class)
    {
        static::$syncingDisabledFor[$class] = true;
    }

    public static function syncingDisabledFor($class)
    {
        $class = is_object($class) ? get_class($class) : $class;

        return isset(static::$syncingDisabledFor[$class]);
    }

    public function saved(Model $model): void
    {
        if ($this->shouldSkip($model)) {
            return;
        }

        if (! $this->forceSaving && ! $model->snowflakeShouldBeUpdated()) {
            return;
        }

        if (! $model->shouldSyncToSnowflake()) {
            if ($model->wasSyncingToSnowflakeBeforeUpdate()) {
                $model->removeFromSnowflake();
            }

            return;
        }

        $model->syncToSnowflake();
    }

    public function deleted(Model $model): void
    {
        if ($this->shouldSkip($model)) {
            return;
        }

        if (! $model->wasSyncingToSnowflakeBeforeDelete()) {
            return;
        }

        if ($this->usesSoftDelete($model) && ! $model->removeFromSnowflakeOnSoftDelete()) {
            $this->whileForcingUpdate(function () use ($model) {
                $this->saved($model);
            });
        } else {
            $model->removeFromSnowflake();
        }
    }

    public function forceDeleted(Model $model): void
    {
        if ($this->shouldSkip($model)) {
            return;
        }

        $model->removeFromSnowflake();
    }

    public function restored(Model $model): void
    {
        if ($this->shouldSkip($model)) {
            return;
        }

        $this->whileForcingUpdate(function () use ($model) {
            $this->saved($model);
        });
    }

    protected function whileForcingUpdate(Closure $callback): mixed
    {
        $this->forceSaving = true;

        try {
            return $callback();
        } finally {
            $this->forceSaving = false;
        }
    }

    protected function usesSoftDelete(Model $model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }

    protected function shouldSkip(Model $model): bool
    {
        return static::syncingDisabledFor($model) || ! $this->syncingEnabled();
    }

    protected function syncingEnabled(): bool
    {
        return (bool) config('snowflake-sync.enabled', true);
    }
}
