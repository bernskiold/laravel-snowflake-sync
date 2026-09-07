# A package for Laravel to sync data in models to a Snowflake database.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/bernskiold/laravel-snowflake-sync.svg?style=flat-square)](https://packagist.org/packages/bernskiold/laravel-snowflake-sync)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/bernskiold/laravel-snowflake-sync/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/bernskiold/laravel-snowflake-sync/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/bernskiold/laravel-snowflake-sync/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/bernskiold/laravel-snowflake-sync/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/bernskiold/laravel-snowflake-sync.svg?style=flat-square)](https://packagist.org/packages/bernskiold/laravel-snowflake-sync)

Keeps Snowflake tables in step with your Eloquent models.

## Installation

```bash
composer require bernskiold/laravel-snowflake-sync
php artisan vendor:publish --tag=laravel-snowflake-sync-config
```

Point `snowflake-sync.connections.default` at a Snowflake connection defined in
`config/database.php`, then add the trait to a model:

```php
use Bernskiold\LaravelSnowflakeSync\Concerns\SyncsToSnowflake;

class Brand extends Model
{
    use SyncsToSnowflake;

    public function snowflakeTable(): string
    {
        return 'brands';
    }

    public function toSnowflake(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
```

## How writes reach Snowflake

Snowflake takes a **table-level lock** for DML. A job per saved row is therefore
the slowest possible way to write: concurrent writers to one table cannot go
faster than a single writer, they only queue behind each other, and with
`LOCK_TIMEOUT` defaulting to twelve hours they queue for a very long time.

So nothing is written per save. Instead:

1. The observer puts the model's key into a **buffer** — one Redis set write.
   Repeated saves of the same row collapse into a single key.
2. A **debounced flush job**, unique per model class, drains the buffer,
   reloads the rows from your database and writes them with a single
   `MERGE` per batch.

A bulk operation touching fifty thousand rows costs a handful of statements
rather than fifty thousand transactions.

### Live and periodic modes

```php
'mode' => env('SNOWFLAKE_SYNC_MODE', 'live'),
```

- **`live`** (default) — every change dispatches a flush job delayed by
  `flush.delay` seconds. Changes reach Snowflake within that window, and every
  save inside the window is written together.
- **`periodic`** — nothing is dispatched. Changes accumulate in the buffer until
  `snowflake:flush` runs, which you schedule yourself:

  ```php
  Schedule::command('snowflake:flush')->everyFifteenMinutes();
  ```

Both modes coalesce; the mode only decides *when* the write happens. Override
per model with `snowflakeSyncMode()`:

```php
public function snowflakeSyncMode(): SyncMode
{
    return SyncMode::Periodic;
}
```

Scheduling `snowflake:flush` alongside live mode is a useful backstop: if a
flush job is ever lost, the scheduled run picks its keys back up.

### The buffer

`snowflake-sync.buffer.driver` is `redis` by default, which is the only option
that works when the flush runs in a queue worker — that is, in any real
deployment. `array` keeps the buffer in memory, for tests and for the `sync`
queue driver. A class-string implementing `PendingSyncBuffer` is also accepted
and resolved from the container.

## Commands

```bash
# Write whatever is buffered (queue a flush job per pending class)
php artisan snowflake:flush
php artisan snowflake:flush "App\Models\Brand"
php artisan snowflake:flush --sync

# Backfill or reconcile a whole table
php artisan snowflake:import "App\Models\Brand"
```

`snowflake:import` bypasses the buffer: it chunks the table and queues import
jobs directly. It is also the only thing that reconciles rows deleted behind
Eloquent's back, since the observer never saw those.

## Customising a model

| Method | Purpose |
| --- | --- |
| `snowflakeTable()` | Target table. Defaults to the model's table. |
| `toSnowflake()` | The row to write. Defaults to `toArray()`. |
| `snowflakeConnection()` | Connection to write over. |
| `getSnowflakeKey()` / `getSnowflakeKeyName()` | Key the MERGE matches on. |
| `shouldSyncToSnowflake()` | Whether this row belongs in Snowflake at all. |
| `snowflakeShouldBeUpdated()` | Whether this save is worth a write. |
| `removeFromSnowflakeOnSoftDelete()` | Delete the row on soft delete instead of re-syncing it. |
| `snowflakeSyncMode()` | Live or periodic, for this model. |
| `syncAllToSnowflakeUsing()` | Scope every sync path's query. |

Pause syncing around a bulk operation:

```php
Brand::withoutSyncingToSnowflake(function () {
    // ...
});
```

## Notes

- **Deletes carry keys, not models.** A hard-deleted row cannot be restored from
  a serialised model identifier, so the removal path only ever passes keys.
- **`after_commit`** defers the flush dispatch until the surrounding transaction
  commits. The buffer write itself is not deferred, but the flush reloads rows
  from your database, so a rolled-back save resolves to "row not found" and
  never reaches Snowflake.
- **`ignore_timestamp_only_changes`** skips the write when a save touched
  nothing but `updated_at`. Off by default, because it lets the warehouse's
  `updated_at` drift from the source.
- **Very large tables.** The engine writes with `MERGE`. If you are loading
  millions of rows, a staged file plus `COPY INTO` will beat it — swap the
  engine with `SnowflakeSync::engine(YourEngine::class)`.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

- [Bernskiold](https://github.com/bernskiold)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
