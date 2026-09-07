<?php

return [

    /**
     * Master switch for syncing.
     *
     * When false, the model observer becomes a no-op for every event
     * (create/update/delete/restore/force-delete) and nothing is buffered.
     * Drive this from an env var so syncing can be enabled only in environments
     * where the Snowflake connection is actually configured.
     */
    'enabled' => env('SNOWFLAKE_SYNC_ENABLED', true),

    /**
     * How buffered changes reach Snowflake.
     *
     * `live`     — each change dispatches a debounced flush job, so a save lands
     *              in Snowflake within `flush.delay` seconds.
     * `periodic` — nothing is dispatched. Changes accumulate until the
     *              `snowflake:flush` command runs; schedule it yourself.
     *
     * Both modes go through the same buffer, so both coalesce repeated saves of
     * the same row into a single write. The mode only decides *when* that write
     * happens. Individual models can override with `snowflakeSyncMode()`.
     */
    'mode' => env('SNOWFLAKE_SYNC_MODE', 'live'),

    /**
     * Whether to run the sync after a commit.
     *
     * This is useful if you are using transactions and want to ensure
     * that the sync is run after all the database
     * operations have been committed.
     */
    'after_commit' => env('SNOWFLAKE_SYNC_AFTER_COMMIT', false),

    /**
     * The chunk size for the sync.
     *
     * How many keys one flush round takes off the buffer, how many rows go into
     * one MERGE, and how many models a full import queues at a time.
     */
    'chunk' => (int) env('SNOWFLAKE_SYNC_CHUNK', 500),

    /**
     * Skip the write when a save touched nothing but the `updated_at` column.
     *
     * Eloquent bumps `updated_at` on every save of an existing model, so this
     * is what stops a bare `touch()` (or a save that changed nothing anyone
     * cares about) from costing a Snowflake round trip. Off by default because
     * it lets the warehouse's `updated_at` drift from the source.
     */
    'ignore_timestamp_only_changes' => env('SNOWFLAKE_SYNC_IGNORE_TIMESTAMP_ONLY_CHANGES', false),

    'flush' => [

        /**
         * Seconds to wait before a live-mode flush job runs.
         *
         * This is the debounce window: every save inside it is written by the
         * same flush, so a bulk operation costs one MERGE instead of thousands
         * of transactions. It is also the sync's worst-case lag, so keep it
         * short if freshness matters more than batching.
         */
        'delay' => (int) env('SNOWFLAKE_SYNC_FLUSH_DELAY', 5),

        /**
         * How many chunks one flush job writes before handing the rest to a
         * fresh job. Bounds a single flush's runtime against the queue timeout
         * when a large backlog has built up.
         */
        'max_chunks' => (int) env('SNOWFLAKE_SYNC_FLUSH_MAX_CHUNKS', 20),

        /**
         * How long a flush job's uniqueness lock survives if the job is lost
         * before it starts processing. Only a backstop; the lock is released
         * the moment processing begins.
         */
        'unique_for' => (int) env('SNOWFLAKE_SYNC_FLUSH_UNIQUE_FOR', 900),
    ],

    'buffer' => [

        /**
         * Where pending keys are held between a save and its write.
         *
         * `redis` is the only option that works when the flush runs in a queue
         * worker, which is to say in any real deployment. `array` keeps the
         * buffer in memory for tests and for the `sync` queue driver. A
         * class-string implementing PendingSyncBuffer is also accepted and
         * resolved from the container.
         */
        'driver' => env('SNOWFLAKE_SYNC_BUFFER', 'redis'),

        'connection' => env('SNOWFLAKE_SYNC_BUFFER_CONNECTION', 'default'),

        'prefix' => env('SNOWFLAKE_SYNC_BUFFER_PREFIX', 'snowflake-sync'),
    ],

    'connections' => [

        /**
         * The Snowflake database connection to use.
         *
         * This is the connection that will be used to sync the
         * models to Snowflake. It should be a valid connection
         * name as defined in your database.php config file.
         */
        'default' => env('SNOWFLAKE_SYNC_CONNECTION', 'snowflake'),
    ],

    'queue' => [

        /**
         * The connection to use for the queue.
         *
         * This is the connection that will be used for the queue
         * that will be used to sync the models to Snowflake.
         *
         * Defaults to the default connection when null.
         */
        'connection' => env('SNOWFLAKE_SYNC_QUEUE_CONNECTION'),

        /**
         * The queue to use for the sync.
         *
         * This is the queue that will be used to sync the models to Snowflake.
         */
        'queue' => env('SNOWFLAKE_SYNC_QUEUE', 'default'),
    ],

];
