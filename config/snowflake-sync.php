<?php

return [

    /**
     * Master switch for syncing.
     *
     * When false, the model observer becomes a no-op for every event
     * (create/update/delete/restore/force-delete) and nothing is queued.
     * Drive this from an env var so syncing can be enabled only in environments
     * where the Snowflake connection is actually configured.
     */
    'enabled' => env('SNOWFLAKE_SYNC_ENABLED', true),

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
     * This is the number of models that will be synced in one go.
     */
    'chunk' => (int) env('SNOWFLAKE_SYNC_CHUNK', 500),

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
