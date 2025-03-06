<?php

namespace Observers;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ModelObserver
{

    public bool $afterCommit = false;

    protected bool $usingSoftDeletes = false;

    protected bool $forceSaving = false;

    protected static array $syncingDisabledFor = [];

    public function __construct()
    {
        $this->afterCommit = config('snowflake-sync.', false);
    }

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
        if (static::syncingDisabledFor($model)) {
            return;
        }

        if (!$model->snowflakeShouldBeUpdated()) {
            return;
        }

        if (!$model->shouldBeSearchable()) {
            if ($model->wasSyncingToSnowflakeBeforeUpdate()) {
                $model->removeFromSnowflake();
            }

            return;
        }

        $model->syncToSnowflake();
    }

    public function deleted(Model $model): void
    {
        if (static::syncingDisabledFor($model)) {
            return;
        }

        if (!$model->wasSyncingToSnowflakeBeforeDelete()) {
            return;
        }

        if ($this->usingSoftDeletes && $this->usesSoftDelete($model)) {
            $this->whileForcingUpdate(function () use ($model) {
                $this->saved($model);
            });
        } else {
            $model->removeFromSnowflake();
        }
    }

    public function forceDeleted(Model $model): void
    {
        if (static::syncingDisabledFor($model)) {
            return;
        }

        $model->removeFromSnowflake();
    }

    public function restored(Model $model): void
    {
        $this->whileForcingUpdate(function () use ($model) {
            $this->saved($model);
        });
    }

    protected function whileForcingUpdate(Closure $callback): mixed
    {
        $this->forceSaving = true;

        $result = $callback();

        $this->forceSaving = false;

        return $result;
    }

    protected function usesSoftDelete(Model $model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }
}
