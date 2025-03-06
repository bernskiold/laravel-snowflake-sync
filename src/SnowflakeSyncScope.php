
<?php

use Events\ModelsImported;
use Events\ModelsRemoved;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class SnowflakeSyncScope implements Scope
{

    public function apply(Builder $builder, Model $model)
    {
        //
    }

    public function extend(EloquentBuilder $builder)
    {
        $builder->macro('searchable', function (EloquentBuilder $builder, $chunk = null) {
            $snowflakeKeyName = $builder->getModel()->getSnowflakeKeyName();

            $builder->chunkById($chunk ?: config('snowflake-sync.chunk', 500), function ($models) {
                $models->filter->shouldSyncToSnowflake()->syncToSnowflake();

                event(new ModelsImported($models));
            }, $builder->qualifyColumn($snowflakeKeyName), $snowflakeKeyName);
        });

        $builder->macro('unsearchable', function (EloquentBuilder $builder, $chunk = null) {
            $snowflakeKeyName = $builder->getModel()->getSnowflakeKeyName();

            $builder->chunkById($chunk ?: config('snowflake-sync.chunk', 500), function ($models) {
                $models->removeFromSnowflake();

                event(new ModelsRemoved($models));
            }, $builder->qualifyColumn($snowflakeKeyName), $snowflakeKeyName);
        });

        HasManyThrough::macro('syncsToSnowflake', function ($chunk = null) {
            /** @var HasManyThrough $this */
            $this->chunkById($chunk ?: config('snowflake-sync.chunk', 500), function ($models) {
                $models->filter->shouldBeSearchable()->searchable();

                event(new ModelsImported($models));
            });
        });

        HasManyThrough::macro('unsearchable', function ($chunk = null) {
            /** @var HasManyThrough $this */
            $this->chunkById($chunk ?: config('snowflake-sync.chunk', 500), function ($models) {
                $models->unsearchable();

                event(new ModelsRemoved($models));
            });
        });
    }
}
