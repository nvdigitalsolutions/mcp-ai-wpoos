<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Nvoos\Laravel\Adapter\TokenUsageRollup;

/**
 * Roll token usage into daily aggregates — Proposal 149 §80.
 *
 * Retention deletes raw rows; the rollup is what keeps the cost history after
 * they are gone. The default window re-rolls the last seven complete days
 * rather than only yesterday, because the rollup is idempotent: overlapping
 * runs cost a few reads and heal a day that a failed or missed run skipped.
 *
 * Today is never rolled up — a day is only complete once it is over, and
 * rolling a partial day would write an aggregate that a later run must correct.
 */
class RollupTokenUsage extends Command
{
    protected $signature = 'nvoos:rollup-token-usage
                            {--date= : Roll up one day (YYYY-MM-DD) instead of a window}
                            {--days=7 : How many complete days to roll up, excluding today}
                            {--dry-run : Count what would be aggregated without writing}';

    protected $description = 'Aggregate token usage into the daily rollup that outlives retention.';

    public function handle(TokenUsageRollup $rollup): int
    {
        $date = $this->option('date');

        if (null !== $date && '' !== (string) $date) {
            $day = $this->parseDay((string) $date);

            if (null === $day) {
                $this->error(sprintf('"%s" is not a YYYY-MM-DD date.', (string) $date));

                return self::FAILURE;
            }

            return $this->rollupOne($rollup, $day);
        }

        $days = max(1, (int) $this->option('days'));
        $today = CarbonImmutable::now()->startOfDay();

        for ($i = 1; $i <= $days; $i++) {
            $this->rollupOne($rollup, $today->subDays($i));
        }

        return self::SUCCESS;
    }

    private function rollupOne(TokenUsageRollup $rollup, CarbonImmutable $day): int
    {
        if ($this->option('dry-run')) {
            $this->line(sprintf(
                '%s: %d row(s) would be rolled up.',
                $day->toDateString(),
                $rollup->countDay($day)
            ));

            return self::SUCCESS;
        }

        $result = $rollup->rollupDay($day);

        if (0 === $result['rows']) {
            $this->line(sprintf('%s: no rows to roll up.', $day->toDateString()));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s: %d group(s) from %d row(s).',
            $day->toDateString(),
            $result['groups'],
            $result['rows']
        ));

        return self::SUCCESS;
    }

    private function parseDay(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $day = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }

        // Carbon rolls 2026-02-31 forward to March; a day that does not exist
        // must not silently aggregate a different one.
        return $day->toDateString() === $value ? $day->startOfDay() : null;
    }
}
