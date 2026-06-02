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

        $first = $models->first();
        $table = $first->snowflakeTable();
        $connection = $first->snowflakeConnection();
        $keyColumn = $first->getSnowflakeKeyName();

        $data = $models
            ->map(fn ($model) => $model->toSnowflake())
            ->filter()
            ->values();

        if ($data->isEmpty()) {
            return;
        }

        $keys = $data->pluck($keyColumn)->filter()->values();

        DB::connection($connection)
            ->table($table)
            ->whereIn($keyColumn, $keys)
            ->delete();

        DB::connection($connection)
            ->table($table)
            ->insert($data->toArray());
    }

    public function delete(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $first = $models->first();
        $table = $first->snowflakeTable();
        $connection = $first->snowflakeConnection();
        $keyColumn = $first->getSnowflakeKeyName();

        $keys = $models
            ->map(fn ($model) => $model->getSnowflakeKey())
            ->values();

        DB::connection($connection)
            ->table($table)
            ->whereIn($keyColumn, $keys)
            ->delete();
    }
}
