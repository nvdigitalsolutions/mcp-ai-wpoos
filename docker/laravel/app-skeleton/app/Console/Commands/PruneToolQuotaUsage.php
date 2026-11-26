<?php

namespace App\Console\Commands;

use App\Support\ToolQuota\ToolQuotaStore;
use Illuminate\Console\Command;

/**
 * Prune tool-quota usage — Proposal 149 §176.
 *
 * `nvoos_tool_quota_usage` takes one row per estate, user, tool and day, so it
 * grows by the number of distinct callers rather than by the number of calls —
 * small, but unbounded. Nothing reads yesterday's counters (a daily quota resets
 * at midnight UTC), so the rows are kept only long enough to answer "what did
 * this tool cost us last week".
 *
 * **Deletes across every estate.** Retention is deployment maintenance; an
 * estate-scoped delete would leave every other estate's counters growing
 * forever. This is the one place the tenant scope is opted out of, and it only
 * ever deletes by date.
 *
 * A single `DELETE … WHERE day < ?` is enough here, unlike §76's chunked walk
 * over `nvoos_token_usage`: that table takes one row per AI call and can hold a
 * year of history, while this one is bounded by callers × tools × days.
 */
class PruneToolQuotaUsage extends Command
{
    protected $signature = 'nvoos:prune-tool-quota-usage
                            {--days= : Days to keep (default: oos.tool_quotas.retention_days)}
                            {--dry-run : Count what would be deleted without deleting}';

    protected $description = 'Delete per-tool quota usage rows older than the retention window.';

    public function handle(ToolQuotaStore $store): int
    {
        $days = $this->option('days');
        $days = null === $days || '' === $days
            ? (int) config('oos.tool_quotas.retention_days', 30)
            : (int) $days;

        if ($days <= 0) {
            $this->info('Tool quota retention is disabled (0 days); nothing pruned.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line(sprintf(
                'Tool quota usage older than %d day(s) would be deleted across every estate.',
                $days
            ));

            return self::SUCCESS;
        }

        $deleted = $store->pruneAll($days);

        $this->info(sprintf(
            '%d tool quota usage row(s) deleted; kept the last %d days.',
            $deleted,
            $days
        ));

        return self::SUCCESS;
    }
}
