<?php

namespace App\Console\Commands;

use App\Support\Schedules\ScheduleRunner;
use Illuminate\Console\Command;

/**
 * Run the due schedules in `nvoos_schedules`.
 *
 * Registered in `routes/console.php` every minute and started by the
 * `scheduler` container role (`php artisan schedule:work`). Without this the
 * Schedule Manager console is a list of rows that never fire — the presets
 * would install and then sit idle.
 */
class RunSchedules extends Command
{
    protected $signature = 'nvoos:run-schedules
                            {--dry-run : List the due schedules without running them}';

    protected $description = 'Run enabled schedules whose next run is due';

    public function handle(ScheduleRunner $runner): int
    {
        $timed = $runner->bootstrap();
        $due = $runner->due();

        if ($this->option('dry-run')) {
            $this->line(sprintf('%d schedule(s) timed, %d due.', $timed, count($due)));

            foreach ($due as $row) {
                $this->line(sprintf('  %s (%s)', $row->id, (string) ($row->cron_expression ?? '')));
            }

            return self::SUCCESS;
        }

        // Reap before anything else: a wedged schedule is precisely the case
        // where nothing is due, so this must not sit behind the early return.
        // A worker that died hard never wrote a terminal row, and leaving the
        // clock in the past would re-fire a handler that kills its process.
        $reaped = $runner->reapStale();

        if ($reaped > 0) {
            $this->warn(sprintf('%d stale run(s) marked timed out.', $reaped));
        }

        if ([] === $due) {
            return self::SUCCESS;
        }

        $failed = false;

        foreach ($due as $row) {
            $result = $runner->run($row);

            $line = sprintf('%s: %s', $row->id, $result['status']);
            if (null !== $result['error']) {
                $line .= ' — '.$result['error'];
            }

            if ('success' === $result['status']) {
                $this->info($line);
            } else {
                $this->error($line);
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
