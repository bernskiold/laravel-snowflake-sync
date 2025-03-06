<?php

return [

    'after_commit' => false,

    'chunk' => 500,

    'connections' => [
        'default' => 'snowflake',
    ],

    'queue' => [
        'connection' => null,
        'queue' => 'default',
    ],
];
