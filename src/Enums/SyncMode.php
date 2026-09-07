<?php

namespace Bernskiold\LaravelSnowflakeSync\Enums;

enum SyncMode: string
{
    /**
     * Every buffered change dispatches a debounced flush job, so a save reaches
     * Snowflake within `snowflake-sync.flush.delay` seconds.
     */
    case Live = 'live';

    /**
     * Changes accumulate in the buffer and are written only when
     * `snowflake:flush` runs. Schedule that command at whatever interval suits.
     */
    case Periodic = 'periodic';
}
