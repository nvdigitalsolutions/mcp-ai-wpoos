<?php

namespace App\Support\Schedules;

use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Carbon;

/**
 * Cron arithmetic for the Schedule Manager.
 *
 * WordPress stores a registered interval slug (`daily`, `twicedaily`, …);
 * this runtime stores a cron expression, which is what the scheduler and the
 * Schedule Manager console both understand. The interval map exists so an
 * installed preset keeps its WordPress cadence instead of drifting.
 */
final class ScheduleTiming
{
    /**
     * Registered WordPress interval → cron expression.
     *
     * `wp_mcp_ai_monthly` is the product's own custom interval (the manager
     * registers it on WordPress); it maps to the first of the month.
     */
    private const WORDPRESS_INTERVALS = [
        'hourly' => '0 * * * *',
        'twicedaily' => '0 */12 * * *',
        'daily' => '0 0 * * *',
        'weekly' => '0 0 * * 1',
        'monthly' => '0 0 1 * *',
        'wp_mcp_ai_monthly' => '0 0 1 * *',
    ];

    public static function fromWordPressInterval(string $interval): ?string
    {
        return self::WORDPRESS_INTERVALS[strtolower(trim($interval))] ?? null;
    }

    /**
     * Next occurrence of a cron expression, or null when it cannot be parsed.
     */
    public static function nextRunAt(?string $expression, ?CarbonInterface $from = null): ?Carbon
    {
        $expression = trim((string) $expression);

        if ('' === $expression || ! CronExpression::isValidExpression($expression)) {
            return null;
        }

        $next = (new CronExpression($expression))->getNextRunDate(
            $from ?? Carbon::now(),
            0,
            false,
            'UTC',
        );

        return Carbon::instance($next)->utc();
    }
}
