<?php

namespace Bernskiold\LaravelSnowflakeSync\Console;

use Bernskiold\LaravelSnowflakeSync\Events\ModelsImported;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'snowflake:import')]
class ImportCommand extends Command
{
    protected $signature = 'snowflake:import
            {model : Class name of model to bulk import}
            {--c|chunk= : The number of records to import at a time (Defaults to configuration value: `snowflake-sync.chunk`)}';

    protected $description = 'Import the given model into Snowflake';

    public function handle(Dispatcher $events)
    {
        $class = $this->argument('model');

        $model = new $class;

        $events->listen(ModelsImported::class, function ($event) use ($class) {
            $key = $event->models->last()->getSnowflakeKey();

            $this->line('<comment>Imported ['.$class.'] models up to ID:</comment> '.$key);
        });

        $model::syncAllToSnowflake($this->option('chunk'));

        $events->forget(ModelsImported::class);

        $this->info('All ['.$class.'] records have been imported.');
    }
}
