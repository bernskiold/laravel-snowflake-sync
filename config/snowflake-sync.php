<?php

return [

    /**
     * Whether to run the sync after a commit.
     *
     * This is useful if you are using transactions and want to ensure
     * that the sync is run after all the database
     * operations have been committed.
     */
    'after_commit' => false,

    /**
     * The chunk size for the sync.
     *
     * This is the number of models that will be synced in one go.
     */
    'chunk' => 500,

    'connections' => [

        /**
         * The Snowflake database connection to use.
         *
         * This is the connection that will be used to sync the
         * models to Snowflake. It should be a valid connection
         * name as defined in your database.php config file.
         */
        'default' => 'snowflake',
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
        'connection' => null,

        /**
         * The queue to use for the sync.
         *
         * This is the queue that will be used to sync the models to Snowflake.
         */
        'queue' => 'default',
    ],

];
