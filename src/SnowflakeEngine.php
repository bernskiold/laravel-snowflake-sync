<?php

namespace Bernskiold\LaravelSnowflakeSync;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use function in_array;
use function strtoupper;

class SnowflakeEngine
{
    public function update(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $table = $models->first()->snowflakeTable();
        $connection = $models->first()->snowflakeConnection();
        $keyColumn = $models->first()->getSnowflakeKeyName();

        $existing = DB::connection($connection)
            ->table($table)
            ->select($keyColumn)
            ->get()
            ->pluck(strtoupper($keyColumn)) // Snowflake has uppercase column names.
            ->all();

        $data = $models
            ->map(function ($model) {
                $snowflakeData = $model->toSnowflake();

                if (empty($snowflakeData)) {
                    return;
                }

                return $snowflakeData;
            })
            ->filter()
            ->values();

        // Update existing records one by one.
        $data
            ->filter(fn($model) => in_array($model[$keyColumn], $existing))
            ->each(function (array $data) use ($table, $connection, $keyColumn) {
                DB::connection($connection)
                    ->table($table)
                    ->where($keyColumn, $data[$keyColumn])
                    ->update($data);
            });

        // Bulk-insert new records.
        $data
            ->filter(fn($model) => !in_array($model[$keyColumn], $existing))
            ->tap(function ($data) use ($table, $connection, $keyColumn, $existing) {
                if ($data->isEmpty()) {
                    return;
                }

                DB::connection($connection)
                    ->table($table)
                    ->insert($data->toArray());
            });
    }

    public function delete(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $table = $models->first()->snowflakeTable();
        $connection = $models->first()->snowflakeConnection();
        $keyColumn = $models->first()->getSnowflakeKeyName();

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
