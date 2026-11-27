<?php

/**
 * The GTmetrix daily credit budget, as a reserve-then-release reservation.
 *
 * Proposal 164 sections 164.92 and 164.96. Two tools spend credits —
 * `gtmetrix_start_test` and `gtmetrix_retest_page` — and a money path with two
 * copies is a money path that will disagree with itself. The reservation is the
 * same in both:
 *
 * 1. Read the counter, compare `used + cost` against the budget.
 * 2. Increment first, then compare the *result*; two concurrent callers cannot
 *    both pass a check that happens before either writes.
 * 3. Restore the previous value when the reservation overshoots, the API
 *    refuses the call, or the reply carries no test id.
 *
 * The counter is in hundredths because a fractional price (0.7, 1.1, 2.5)
 * cannot be added to a credit counter without drift. The key carries the UTC
 * date and a two-day TTL, so yesterday's spend expires without a cleanup job.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\GTmetrix;

use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

final class GtmetrixBudget
{
    /** Two days, so yesterday's key expires on its own. */
    public const COUNTER_TTL = 172800;

    /**
     * Today's counter key, in UTC.
     */
    public static function counterKey(): string
    {
        return 'gtmetrix:credits_cents:' . gmdate('Ymd');
    }

    /**
     * Reserve a cost against the site's daily budget.
     *
     * @return array{ok: bool, key: string, budget_cents: int, used_cents: int, cost_cents: int}
     */
    public static function reserve(
        CacheStoreInterface $cache,
        SettingsStoreInterface $settings,
        float $cost
    ): array {
        $budgetCents = (int) round(GtmetrixConfig::budget($settings) * 100);
        $costCents   = (int) round($cost * 100);
        $key         = self::counterKey();
        $usedCents   = (int) $cache->getValue($key, 0);
        $result      = array(
            'ok'           => false,
            'key'          => $key,
            'budget_cents' => $budgetCents,
            'used_cents'   => $usedCents,
            'cost_cents'   => $costCents,
        );

        if ($usedCents + $costCents > $budgetCents) {
            return $result;
        }

        $reserved = (int) $cache->increment($key, $costCents, self::COUNTER_TTL);

        if ($reserved > $budgetCents) {
            self::release($cache, $key, $usedCents);

            return $result;
        }

        $result['ok'] = true;

        return $result;
    }

    /**
     * Return a reservation, restoring the pre-reservation value.
     */
    public static function release(CacheStoreInterface $cache, string $key, int $usedCents): void
    {
        $cache->setValue($key, $usedCents, self::COUNTER_TTL);
    }

    /**
     * What has been spent today, in hundredths of a credit.
     */
    public static function usedCents(CacheStoreInterface $cache, string $key): int
    {
        return (int) $cache->getValue($key, 0);
    }
}
