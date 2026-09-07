<?php

namespace Bernskiold\LaravelSnowflakeSync\Jobs;

use Bernskiold\LaravelSnowflakeSync\Events\ModelsRemoved;
use Bernskiold\LaravelSnowflakeSync\SnowflakeSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;

/**
 * Carries plain keys rather than models on purpose.
 *
 * `SerializesModels` stores a collection as class + ids and re-queries the
 * source database on unserialize, dropping anything it cannot find. For a
 * hard-deleted row that is always everything, so a model-carrying removal job
 * silently deleted nothing.
 */
class RemoveFromSnowflake implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<int|string>  $keys
     */
    public function __construct(
        public string $modelClass,
        public array $keys,
    ) {}

    public static function forModels(Collection $models): self
    {
        return new self(
            $models->first()::class,
            $models->map(fn (Model $model) => $model->getSnowflakeKey())->all(),
        );
    }

    public function handle(): void
    {
        if ($this->keys === []) {
            return;
        }

        app(SnowflakeSync::$engine)->deleteKeys($this->modelClass, $this->keys);

        event(new ModelsRemoved($this->modelClass, $this->keys));
    }
}
