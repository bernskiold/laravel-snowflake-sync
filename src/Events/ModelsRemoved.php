<?php

namespace Bernskiold\LaravelSnowflakeSync\Events;

use Illuminate\Database\Eloquent\Collection;

class ModelsRemoved
{
    public Collection $models;

    public function __construct(Collection $models)
    {
        $this->models = $models;
    }
}
