<?php

namespace Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Queue\SerializesModels;
use SnowflakeSync;

class SnowflakeImport implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Collection $models;

    public function __construct(Collection $models)
    {
        $this->models = $models;
    }

    public function handle()
    {
        if (count($this->models) === 0) {
            return;
        }

        app(SnowflakeSync::$engine)->update($this->models);
    }
}
