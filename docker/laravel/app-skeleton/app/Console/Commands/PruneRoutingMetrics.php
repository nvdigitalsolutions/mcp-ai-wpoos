<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prune routing metrics — Proposal 149 §73.
 *
 * `nvoos_routing_metrics` holds one row per routing deviation. Deviations are
 * rare, so the table grows slowly — but slowly is not never, and an unbounded
 * table is a leak dressed up as a feature.
 *
 * The window is configuration (`oos.routing.retention_days`, 0 disables) and
 * the schedule passes it explicitly, so a manual run and the scheduled run
 * cannot disagree about what "retention" means.
 *
 * A deployment that exports events elsewhere should prune *after* exporting;
 * the command says what it is about to delete before it deletes it, and
 * `--dry-run` counts without touching a row.
 */
class PruneRoutingMetrics extends Command
{
    private const TABLE = 'nvoos_routing_metrics';

    protected $signature = 'nvoos:prune-routing-metrics
                            {--days= : Days to keep (default: oos.routing.retention_days)}
                            {--dry-run : Count what would be deleted without deleting}';

    protected $description = 'Delete routing metrics older than the retention window.';

    public function handle(): int
    {
        $days = $this->option('days');
        $days = null === $days || '' === $days
            ? (int) config('oos.routing.retention_days', 90)
            : (int) $days;

        if ($days <= 0) {
            $this->info('Routing retention is disabled (0 days); nothing pruned.');

            return self::SUCCESS;
        }

        if (! Schema::hasTable(self::TABLE)) {
            $this->warn('Routing metrics table is not present; nothing to prune.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days)->startOfDay();

        $query = DB::table(self::TABLE)->where('created_at', '<', $cutoff);
        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->line(sprintf(
                '%d routing row(s) older than %s would be deleted (keeping %d days).',
                $count,
                $cutoff->toDateTimeString(),
                $days
            ));

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info(sprintf(
            '%d routing row(s) deleted; kept the last %d days.',
            $deleted,
            $days
        ));

        return self::SUCCESS;
    }
}
