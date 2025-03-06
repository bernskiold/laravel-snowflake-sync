<?php

namespace Events;

use Illuminate\Database\Eloquent\Collection;

class ModelsImported
{
    public Collection $models;

    public function __construct(Collection $models)
    {
        $this->models = $models;
    }
}
