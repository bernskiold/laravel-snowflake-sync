<?php

namespace Bernskiold\LaravelSnowflakeSync;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SnowflakeEngine
{
    public function update(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $table = $models->first()->snowflakeTable();
        $connection = $models->first()->snowflakeConnection();
        $keyColumn = $models->first()->getSnowflakeKey();

        $models
            ->map(function ($model) {
                $snowflakeData = $model->toSnowflake();

                if (empty($snowflakeData)) {
                    return;
                }

                return $snowflakeData;
            })
            ->filter()
            ->values()
            ->each(function (array $data) use ($table, $connection, $keyColumn) {
                DB::connection($connection)
                    ->table($table)
                    ->updateOrInsert(
                        [$keyColumn => $data[$keyColumn]],
                        $data
                    );
            });
    }

    public function delete(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $table = $models->first()->snowflakeTable();
        $connection = $models->first()->snowflakeConnection();
        $keyColumn = $models->first()->getSnowflakeKey();

        $keys = $models
            ->map(function ($model) {
                return $model->getSnowflakeKey();
            })
            ->values();

        DB::connection($connection)
            ->table($table)
            ->whereIn($keyColumn, $keys)
            ->delete();
    }
}
