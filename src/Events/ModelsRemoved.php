<?php

namespace Bernskiold\LaravelSnowflakeSync\Events;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ModelsRemoved
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<int|string>  $keys
     */
    public function __construct(
        public string $modelClass,
        public array $keys,
    ) {}

    /**
     * The removal path normally has only keys to work with — a hard-deleted row
     * no longer exists to be loaded — but callers that still hold the models
     * can build the event from them.
     */
    public static function forModels(Collection $models): self
    {
        return new self(
            $models->first()::class,
            $models->map(fn (Model $model) => $model->getSnowflakeKey())->all(),
        );
    }
}
