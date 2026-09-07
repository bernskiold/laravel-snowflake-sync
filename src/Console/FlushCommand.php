<?php

namespace Bernskiold\LaravelSnowflakeSync\Console;

use Bernskiold\LaravelSnowflakeSync\Contracts\PendingSyncBuffer;
use Bernskiold\LaravelSnowflakeSync\Jobs\FlushSnowflakeSync;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

use function dispatch;

/**
 * Writes whatever is waiting in the buffer to Snowflake.
 *
 * In periodic mode this is the only thing that writes, so schedule it. In live
 * mode it is a useful backstop: if a flush job is ever lost, a scheduled run
 * picks its keys back up.
 */
#[AsCommand(name: 'snowflake:flush')]
class FlushCommand extends Command
{
    protected $signature = 'snowflake:flush
            {model? : Class name of the model to flush (defaults to every model with pending changes)}
            {--sync : Write in this process instead of queueing a flush job}';

    protected $description = 'Write buffered model changes to Snowflake';

    public function handle(PendingSyncBuffer $buffer): int
    {
        if (! config('snowflake-sync.enabled', true)) {
            $this->warn('Snowflake syncing is disabled; leaving the buffer untouched.');

            return self::SUCCESS;
        }

        $classes = $this->argument('model')
            ? [$this->argument('model')]
            : $buffer->pendingClasses();

        if ($classes === []) {
            $this->info('Nothing is waiting to be written to Snowflake.');

            return self::SUCCESS;
        }

        foreach ($classes as $class) {
            $pending = $buffer->pendingCount($class);

            if ($pending === 0) {
                continue;
            }

            $this->line('<comment>Flushing ['.$class.']:</comment> '.$pending.' pending');

            if ($this->option('sync')) {
                $this->flushInProcess($buffer, $class);

                continue;
            }

            dispatch(FlushSnowflakeSync::for($class));
        }

        $this->info($this->option('sync') ? 'Buffered changes written.' : 'Flush jobs queued.');

        return self::SUCCESS;
    }

    /**
     * One flush stops after `flush.max_chunks`, so a large backlog needs
     * several. The stall guard is there because a buffer that stops shrinking
     * means something is wrong — better to stop than spin.
     */
    protected function flushInProcess(PendingSyncBuffer $buffer, string $class): void
    {
        while (($pending = $buffer->pendingCount($class)) > 0) {
            $job = new FlushSnowflakeSync($class);
            $job->queueContinuation = false;

            $job->handle($buffer);

            if ($buffer->pendingCount($class) >= $pending) {
                $this->warn('Stopped flushing ['.$class.']: '.$pending.' keys are not draining.');

                return;
            }
        }
    }
}
