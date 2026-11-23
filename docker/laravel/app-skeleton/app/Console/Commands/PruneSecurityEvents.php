<?php

namespace App\Console\Commands;

use App\Support\Security\SecurityEventLogger;
use Illuminate\Console\Command;

/**
 * Retention sweep for the security-event stream — Proposal 149 §186.
 *
 * `audit_log_retention_days` was writable on `/ops/settings` and read by nothing.
 * The field's own contract is explicit about the edge case: **`0` means unlimited
 * retention**, so the sweep must treat zero as "keep everything" rather than as
 * "delete everything". GDPR's 90–180 day guidance and SOC 2's 365-day expectation
 * are both expressed by the same number, which is why the setting exists rather
 * than a constant.
 *
 * Daily is granular enough for a day-based rule, and the delete rides the
 * `created_at` index rather than scanning the table.
 */
class PruneSecurityEvents extends Command
{
    protected $signature = 'nvoos:prune-security-events';

    protected $description = 'Delete security events beyond audit_log_retention_days (0 keeps them forever).';

    public function handle(SecurityEventLogger $events): int
    {
        $deleted = $events->prune();

        $this->info(sprintf('Pruned %d security event(s).', $deleted));

        return self::SUCCESS;
    }
}
