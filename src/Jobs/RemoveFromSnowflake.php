<?php

namespace Bernskiold\LaravelSnowflakeSync\Jobs;

use Bernskiold\LaravelSnowflakeSync\Events\ModelsRemoved;
use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Queue\SerializesModels;

class RemoveFromSnowflake implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public Collection $models;

    public function __construct(Collection $models)
    {
        $this->models = $models;
    }

    public function handle(): void
    {
        if ($this->models->isEmpty()) {
            return;
        }

        app(SnowflakeSync::$engine)->delete($this->models);

        event(new ModelsRemoved($this->models));
    }
}
