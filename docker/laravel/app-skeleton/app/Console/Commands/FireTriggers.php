<?php

namespace App\Console\Commands;

use App\Support\TriggerDispatcher;
use App\Support\TriggerNotRunnable;
use Illuminate\Console\Command;

/**
 * Fire due workflow triggers.
 *
 * The runtime has no WP-Cron and no WordPress actions, so `cron_schedule`
 * triggers are evaluated here. Registered on the scheduler in
 * `bootstrap/app.php` as `everyMinute()->withoutOverlapping()`, which only
 * runs where `php artisan schedule:work` (or a `schedule:run` cron) exists —
 * the default compose worker runs `queue:work` alone, so a deployment that
 * expects triggers to fire must run the scheduler too. `--dry-run` exists so
 * that requirement can be verified without side effects.
 */
class FireTriggers extends Command
{
    protected $signature = 'nvoos:fire-triggers
        {--dry-run : List the triggers that are due without firing them}';

    protected $description = 'Fire workflow triggers whose schedule slot is due.';

    public function handle(TriggerDispatcher $triggers): int
    {
        $due = $triggers->due();

        if ([] === $due) {
            $this->line('No triggers are due.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            foreach ($due as $trigger) {
                $this->line(sprintf(
                    'would fire #%d %s (workflow %s)',
                    (int) $trigger->id,
                    (string) $trigger->name,
                    (string) ($trigger->workflow_id ?? '-'),
                ));
            }

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($due as $trigger) {
            try {
                $result = $triggers->fire($trigger);
            } catch (TriggerNotRunnable $exception) {
                ++$failed;
                $this->warn(sprintf(
                    '#%d %s skipped: %s',
                    (int) $trigger->id,
                    (string) $trigger->name,
                    $exception->getMessage(),
                ));

                continue;
            }

            $this->line(sprintf(
                '#%d %s → run %d (%s)',
                (int) $trigger->id,
                (string) $trigger->name,
                (int) $result['run_id'],
                (string) $result['status'],
            ));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
