<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Laravel\Adapter\TokenUsageRollup;

/**
 * Prune token usage — Proposal 149 §76.
 *
 * `nvoos_token_usage` takes one row per AI call, which makes it the
 * highest-volume table the console reads and the one the cost pages aggregate
 * over. Nothing bounded it, so a long-lived deployment grows it forever.
 *
 * Two differences from routing retention (§73) drive the shape of this command:
 *
 *  - **These rows are the cost history.** There is no export to re-import from,
 *    so a deleted row is gone unless it was rolled up first (§80). The default
 *    window is therefore a year, not 90 days, and `0` disables pruning for a
 *    deployment that invoices from the table.
 *  - **The first run can be large.** Deleting a year of history in one
 *    statement would hold a single transaction for an unbounded time, so rows
 *    are selected by id in bounded chunks and deleted by id. Chunking by id
 *    rather than by `DELETE … LIMIT` keeps the command portable across MySQL
 *    and SQLite, which do not agree on whether that syntax exists.
 *
 * A day is only deletable once it has been rolled up: the daily aggregate is
 * what survives the raw rows, and a day that was never aggregated is kept —
 * the same direction as §74's "never delete unexported rows".
 */
class PruneTokenUsage extends Command
{
    private const TABLE = 'nvoos_token_usage';

    protected $signature = 'nvoos:prune-token-usage
                            {--days= : Days to keep (default: oos.token_usage.retention_days)}
                            {--dry-run : Count what would be deleted without deleting}';

    protected $description = 'Delete token usage rows older than the retention window.';

    public function handle(): int
    {
        $days = $this->option('days');
        $days = null === $days || '' === $days
            ? (int) config('oos.token_usage.retention_days', 365)
            : (int) $days;

        if ($days <= 0) {
            $this->info('Token usage retention is disabled (0 days); nothing pruned.');

            return self::SUCCESS;
        }

        if (! Schema::hasTable(self::TABLE)) {
            $this->warn('Token usage table is not present; nothing to prune.');

            return self::SUCCESS;
        }

        // Same boundary as §73: the whole cutoff day is kept, so "30 days"
        // means thirty complete days plus today, not a rolling 720 hours.
        // Rows with a NULL `created_at` never match and are never deleted —
        // the safe direction for data that cannot be recovered.
        $cutoff = now()->subDays($days)->startOfDay();

        // §80 — the raw rows are the source; the daily rollup is what survives
        // them. Deleting a day that was never rolled up would lose cost history
        // that cannot be reconstructed, so retention only deletes days at or
        // below the last rolled-up day. A missing rollup means "delete
        // nothing", never "delete everything".
        $lastRolledUp = app(TokenUsageRollup::class)->lastRolledUpDay();

        if (null === $lastRolledUp) {
            $this->warn(
                'No token usage rollup exists; nothing pruned. Run nvoos:rollup-token-usage first: '
                .'retention only deletes days that were aggregated.'
            );

            return self::SUCCESS;
        }

        $coveredThrough = $lastRolledUp->addDay()->startOfDay();
        $rollupBound = $coveredThrough->lt($cutoff);
        $cutoff = $rollupBound ? $coveredThrough : $cutoff;

        $scope = $rollupBound ? ' (rolled up through '.$lastRolledUp->toDateString().')' : '';

        if ($this->option('dry-run')) {
            $count = DB::table(self::TABLE)->where('created_at', '<', $cutoff)->count();

            $this->line(sprintf(
                '%d token usage row(s) older than %s would be deleted (keeping %d days)%s.',
                $count,
                $cutoff->toDateTimeString(),
                $days,
                $scope
            ));

            return self::SUCCESS;
        }

        $chunk = max(1, min(50000, (int) config('oos.token_usage.prune_chunk', 5000)));
        $total = 0;

        do {
            $ids = DB::table(self::TABLE)
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id')
                ->all();

            if ([] === $ids) {
                break;
            }

            $deleted = DB::table(self::TABLE)->whereIn('id', $ids)->delete();
            $total += $deleted;

            // A concurrent prune (or a row deleted between the select and the
            // delete) can make a batch a no-op; without this the loop would
            // re-select the same ids forever.
            if (0 === $deleted) {
                break;
            }
        } while (true);

        $this->info(sprintf(
            '%d token usage row(s) deleted; kept the last %d days%s.',
            $total,
            $days,
            $scope
        ));

        return self::SUCCESS;
    }
}
