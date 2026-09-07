<?php

namespace Bernskiold\LaravelSnowflakeSync;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

use function array_chunk;
use function array_keys;
use function array_values;
use function config;
use function implode;
use function max;

class SnowflakeEngine
{
    /**
     * Write the given models to Snowflake with a single MERGE per batch.
     *
     * Snowflake takes a table-level lock for DML, so the number of statements
     * matters far more than the number of rows in them: one MERGE of 500 rows
     * costs a single lock acquisition where 500 single-row transactions cost
     * 500, each queueing behind the last. The MERGE is also atomic on its own,
     * so there is no window in which rows have been deleted but not re-inserted.
     *
     * Note the semantic difference from the DELETE + INSERT this replaced: a
     * MERGE updates only the columns present in the row, where a full replace
     * blanked everything `toSnowflake()` left out. A model that returns a fixed
     * column set — which is the normal case — behaves identically; one that
     * conditionally omits a column now leaves the warehouse's old value in
     * place instead of nulling it.
     */
    public function update(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $first = $models->first();
        $keyColumn = $first->getSnowflakeKeyName();

        $rows = $this->rowsKeyedByKeyColumn($models, $keyColumn);

        if ($rows === []) {
            return;
        }

        $connection = DB::connection($first->snowflakeConnection());
        $table = $first->snowflakeTable();

        // A MERGE takes its column list from the first row, so rows that do not
        // all describe the same columns have to go in separate statements.
        foreach ($this->groupByColumns($rows) as $group) {
            foreach (array_chunk($group, $this->batchSize()) as $batch) {
                $connection->table($table)->upsert($batch, [$keyColumn]);
            }
        }
    }

    public function delete(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $this->deleteKeys(
            $models->first()::class,
            $models->map(fn (Model $model) => $model->getSnowflakeKey())->all(),
        );
    }

    /**
     * Delete rows by key alone.
     *
     * The removal path cannot carry models: by the time a queued job runs, a
     * hard-deleted row is gone from the source database and cannot be restored
     * from a serialised model identifier. Keys are all that survive.
     *
     * @param  class-string<Model>  $modelClass
     * @param  list<int|string>  $keys
     */
    public function deleteKeys(string $modelClass, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $model = new $modelClass;
        $connection = DB::connection($model->snowflakeConnection());

        foreach (array_chunk(array_values($keys), $this->batchSize()) as $batch) {
            $connection->table($model->snowflakeTable())
                ->whereIn($model->getSnowflakeKeyName(), $batch)
                ->delete();
        }
    }

    /**
     * Reduce the models to their Snowflake rows, keyed by the Snowflake key so
     * that repeated appearances of the same row collapse to the last one.
     *
     * Snowflake rejects a MERGE whose source matches a target row more than
     * once (ERROR_ON_NONDETERMINISTIC_MERGE), so this deduplication is required
     * for correctness, not just efficiency.
     *
     * @return array<array-key, array<string, mixed>>
     */
    protected function rowsKeyedByKeyColumn(Collection $models, string $keyColumn): array
    {
        $rows = [];

        foreach ($models as $model) {
            $row = $model->toSnowflake();

            if (empty($row) || ! isset($row[$keyColumn])) {
                continue;
            }

            $rows[(string) $row[$keyColumn]] = $row;
        }

        return $rows;
    }

    /**
     * Group rows by their column set.
     *
     * This is load-bearing, not tidiness: the query builder takes the column
     * list from the first row and then flattens every row's values positionally
     * into the bindings, so a batch whose rows describe different columns would
     * bind values against the wrong columns. Sorting the signature means rows
     * that differ only in key order still share one statement.
     *
     * @param  array<array-key, array<string, mixed>>  $rows
     * @return list<list<array<string, mixed>>>
     */
    protected function groupByColumns(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $columns = array_keys($row);
            sort($columns);

            $groups[implode(',', $columns)][] = $row;
        }

        return array_values($groups);
    }

    protected function batchSize(): int
    {
        return max(1, (int) config('snowflake-sync.chunk', 500));
    }
}
