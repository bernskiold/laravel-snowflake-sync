# Changelog

All notable changes to `laravel-snowflake-sync` will be documented in this file.

## 0.3.0

Writes are now gathered and written in batches instead of one job per saved row.

### Added

- A pending-sync buffer (`redis` or `array` driver, or your own implementation
  of `PendingSyncBuffer`). The observer writes a key to it rather than
  dispatching a job, so repeated saves of the same row collapse into one write.
- `FlushSnowflakeSync`, a per-model-class flush job that drains the buffer and
  writes it in batches. Unique until processing, and delayed by
  `snowflake-sync.flush.delay`, so a burst of saves costs one flush.
- `live` and `periodic` sync modes (`snowflake-sync.mode`, or `snowflakeSyncMode()`
  per model). Periodic mode never dispatches; schedule `snowflake:flush` instead.
- `snowflake:flush` command, with `--sync` to write in-process.
- `newSnowflakeSyncQuery()` on the trait — the single query every sync path
  loads rows through.

### Changed

- **`SnowflakeEngine::update()` writes one `MERGE` per batch** rather than a
  transaction-wrapped `DELETE` + `INSERT` per call. One statement means one
  lock acquisition, and no window in which rows are deleted but not yet
  reinserted. Rows are deduplicated by key first, which Snowflake requires:
  a MERGE whose source matches a target row twice is an error.
- **`RemoveFromSnowflake` carries a model class and keys** instead of a model
  collection, and `SnowflakeEngine::deleteKeys()` is the new entry point.
- **`ModelsRemoved` carries `$modelClass` and `$keys`** instead of `$models`.
  Use `ModelsRemoved::forModels()` when you do still hold the models.
- **`snowflakeShouldBeUpdated()` now returns false for a save that changed
  nothing** rather than always returning true. Set
  `snowflake-sync.ignore_timestamp_only_changes` to also skip saves that
  touched nothing but `updated_at`.
- `snowflake-sync.chunk` now applies to the observer path as well: it is the
  number of keys a flush round takes and the number of rows in one MERGE.

### Fixed

- **Deletes never reached Snowflake for models without soft deletes.**
  `SerializesModels` restores a queued collection by re-querying the source
  database and dropping what it cannot find; for a hard-deleted row that is
  everything, so the job restored an empty collection and deleted nothing.
- **`after_commit` did nothing.** `ModelObserver::$afterCommit` was read from
  config and then never used by anything, including the framework. The setting
  now defers the dispatch as documented, and the dead property is gone.

### Upgrading

- Set `SNOWFLAKE_SYNC_BUFFER=redis` (the default) and make sure the configured
  Redis connection is reachable from both the web and queue processes.
- If you listen for `ModelsRemoved`, switch from `$event->models` to
  `$event->modelClass` and `$event->keys`.
- If you construct `RemoveFromSnowflake` directly, use
  `RemoveFromSnowflake::forModels($collection)` or the new constructor.
- Run `snowflake:import` once per model to reconcile rows whose deletes were
  silently dropped by the old removal path.
