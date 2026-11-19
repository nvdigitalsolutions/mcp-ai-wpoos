<?php

namespace App\Support\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Token analytics — trend, patterns and anomalies (Proposal 149 §83).
 *
 * The WordPress token manager's `analytics` view has three sub-tabs: `trends`
 * (direction, confidence, average, projection), `patterns` (peak hours, peak
 * days, usage type) and `anomalies` (a z-score table). This class computes the
 * same three from one daily series, so the three sections cannot disagree about
 * the window.
 *
 * What is deliberately different from WordPress:
 *
 *  - **only high anomalies are flagged.** A day at zero is not a cost event;
 *    flagging it would make every quiet Sunday page somebody. The WordPress
 *    table shows the signed z-score; this one shows spikes only and says so.
 *  - **the trend needs data to speak.** Fewer than three days with usage
 *    returns `insufficient` rather than "stable", because "stable" on two data
 *    points is a claim the numbers do not support.
 *  - **the projection is the mean × 7**, not a fitted forecast. It is labelled
 *    as a run rate, not a prediction.
 *
 * Days with no usage are part of the series as zeros: dropping them would make
 * a weekly pattern and a trend line both lie.
 */
class TokenAnalyticsReport
{
    public const TABLE = 'nvoos_token_usage';

    /** Minimum days before a trend is reported at all. */
    public const MIN_TREND_DAYS = 3;

    /** Minimum days before anomalies are computed (a mean and σ need history). */
    public const MIN_ANOMALY_DAYS = 7;

    /** A day above this z-score is an anomaly; severity bands follow. */
    public const ANOMALY_Z = 2.0;

    /**
     * Daily series for the window, oldest first, with empty days as zeros.
     *
     * @return array<string, array{events: int, tokens: int, cost: float}>
     */
    public function series(int $days): array
    {
        $start = $this->windowStart($days);

        $byDay = array();

        if (Schema::hasTable(self::TABLE)) {
            $rows = DB::table(self::TABLE)
                ->where('created_at', '>=', $start)
                ->selectRaw('DATE(created_at) as day, COUNT(*) as events, SUM(total_tokens) as tokens, SUM(cost) as cost')
                ->groupBy('day')
                ->get();

            foreach ($rows as $row) {
                $byDay[substr((string) $row->day, 0, 10)] = array(
                    'events' => (int) $row->events,
                    'tokens' => (int) $row->tokens,
                    'cost' => (float) $row->cost,
                );
            }
        }

        $series = array();

        for ($i = 0; $i < $this->normalise($days); $i++) {
            $date = $start->addDays($i)->toDateString();

            $series[$date] = $byDay[$date] ?? array('events' => 0, 'tokens' => 0, 'cost' => 0.0);
        }

        return $series;
    }

    /**
     * Linear trend over the series.
     *
     * `confidence` is R² as a percentage: how much of the day-to-day variation
     * the straight line explains. A ramp scores high; a flat line with noise
     * scores low even when the slope is non-zero.
     *
     * @return array{
     *     direction: string,
     *     confidence: float,
     *     average_daily: float,
     *     projected_7d: float,
     *     slope: float,
     *     active_days: int
     * }
     */
    public function trend(int $days): array
    {
        $series = array_values($this->series($days));
        $values = array_map(static fn (array $day): int => (int) $day['tokens'], $series);

        $n = count($values);
        $active = count(array_filter($values, static fn (int $tokens): bool => $tokens > 0));
        $meanY = $n > 0 ? array_sum($values) / $n : 0.0;

        $insufficient = array(
            'direction' => 'insufficient',
            'confidence' => 0.0,
            'average_daily' => $meanY,
            'projected_7d' => $meanY * 7,
            'slope' => 0.0,
            'active_days' => $active,
        );

        if ($active < self::MIN_TREND_DAYS || $meanY <= 0) {
            return $insufficient;
        }

        $meanX = ($n - 1) / 2;
        $sxx = 0.0;
        $sxy = 0.0;

        foreach ($values as $x => $y) {
            $sxx += ($x - $meanX) ** 2;
            $sxy += ($x - $meanX) * ($y - $meanY);
        }

        $slope = $sxx > 0 ? $sxy / $sxx : 0.0;
        $intercept = $meanY - $slope * $meanX;

        $ssRes = 0.0;
        $ssTot = 0.0;

        foreach ($values as $x => $y) {
            $ssRes += ($y - ($intercept + $slope * $x)) ** 2;
            $ssTot += ($y - $meanY) ** 2;
        }

        $r2 = $ssTot > 0 ? max(0.0, 1 - $ssRes / $ssTot) : 0.0;

        // Direction is judged by the change the line predicts across the whole
        // window, relative to the mean — a slope in tokens/day is unreadable
        // without the window length.
        $change = $slope * max(1, $n - 1);
        $threshold = 0.10 * $meanY;

        $direction = 'stable';

        if ($change > $threshold) {
            $direction = 'rising';
        } elseif ($change < -$threshold) {
            $direction = 'falling';
        }

        return array(
            'direction' => $direction,
            'confidence' => round($r2 * 100, 1),
            'average_daily' => $meanY,
            'projected_7d' => $meanY * 7,
            'slope' => $slope,
            'active_days' => $active,
        );
    }

    /**
     * Peak hours, peak weekdays and the shape of the usage.
     *
     * `usage_type` follows the WordPress vocabulary, from the coefficient of
     * variation: consistent (< 0.5), sporadic (< 1.0), bursty (≥ 1.0).
     *
     * @return array{peak_hours: list<int>, peak_days: list<string>, usage_type: string, hourly: array<int, int>, daily: array<string, int>}
     */
    public function patterns(int $days): array
    {
        $series = $this->series($days);

        $daily = array();

        foreach ($series as $date => $day) {
            $weekday = CarbonImmutable::parse($date)->format('l');
            $daily[$weekday] = ($daily[$weekday] ?? 0) + (int) $day['tokens'];
        }

        arsort($daily);

        $peakDays = array();
        foreach (array_slice(array_keys($daily), 0, 2, true) as $weekday) {
            if (($daily[$weekday] ?? 0) > 0) {
                $peakDays[] = $weekday;
            }
        }

        $hourly = $this->hourlyTokens($days);
        arsort($hourly);

        $peakHours = array();
        foreach (array_slice(array_keys($hourly), 0, 2, true) as $hour) {
            if (($hourly[$hour] ?? 0) > 0) {
                $peakHours[] = (int) $hour;
            }
        }

        return array(
            'peak_hours' => $peakHours,
            'peak_days' => $peakDays,
            'usage_type' => $this->usageType($series),
            'hourly' => $hourly,
            'daily' => $daily,
        );
    }

    /**
     * Days whose usage is a spike against the window's own mean.
     *
     * Only positive deviations: a quiet day is not a cost event. `reason` is
     * null when there is nothing to show but not because nothing happened.
     *
     * @return array{reason: string|null, points: list<array{date: string, tokens: int, expected: float, z: float, severity: string}>}
     */
    public function anomalies(int $days): array
    {
        $series = $this->series($days);
        $values = array_map(static fn (array $day): int => (int) $day['tokens'], $series);
        $active = count(array_filter($values, static fn (int $tokens): bool => $tokens > 0));

        // A window is always ≥ 7 calendar days (the smallest period), so the
        // reachable guard is the number of days that actually have usage: a
        // mean and a σ over two active days are not an expectation.
        if (count($values) < self::MIN_ANOMALY_DAYS || $active < 3) {
            return array(
                'reason' => 'not enough history (needs '.self::MIN_ANOMALY_DAYS.' days, at least 3 with usage)',
                'points' => array(),
            );
        }

        $mean = array_sum($values) / count($values);
        $variance = 0.0;

        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        $stddev = sqrt($variance / count($values));

        if ($stddev <= 0.0) {
            return array('reason' => 'usage is flat across the window', 'points' => array());
        }

        $points = array();

        foreach ($series as $date => $day) {
            $tokens = (int) $day['tokens'];
            $z = ($tokens - $mean) / $stddev;

            if ($z < self::ANOMALY_Z) {
                continue;
            }

            $points[] = array(
                'date' => $date,
                'tokens' => $tokens,
                'expected' => $mean,
                'z' => round($z, 2),
                'severity' => $z >= 3.0 ? 'high' : ($z >= 2.5 ? 'medium' : 'low'),
            );
        }

        usort($points, static fn (array $a, array $b): int => $b['z'] <=> $a['z']);

        return array('reason' => null, 'points' => $points);
    }

    /**
     * Tokens per hour of day.
     *
     * The hour extraction is the one driver-specific expression in this
     * report: MySQL has `HOUR()`, SQLite needs `strftime()`. Everything else is
     * plain `DATE()` / `SUM()`, which both accept.
     *
     * @return array<int, int> hour => tokens, only hours with usage
     */
    private function hourlyTokens(int $days): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return array();
        }

        $expression = 'sqlite' === DB::connection()->getDriverName()
            ? "CAST(strftime('%H', created_at) AS INTEGER)"
            : 'HOUR(created_at)';

        $rows = DB::table(self::TABLE)
            ->where('created_at', '>=', $this->windowStart($days))
            ->selectRaw($expression.' as hour, SUM(total_tokens) as tokens')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        $hourly = array();

        foreach ($rows as $row) {
            $hourly[(int) $row->hour] = (int) $row->tokens;
        }

        return $hourly;
    }

    /**
     * @param array<string, array{events: int, tokens: int, cost: float}> $series
     */
    private function usageType(array $series): string
    {
        $values = array_map(static fn (array $day): int => (int) $day['tokens'], $series);
        $count = count($values);

        if (0 === $count) {
            return 'consistent';
        }

        $mean = array_sum($values) / $count;

        if ($mean <= 0) {
            return 'consistent';
        }

        $variance = 0.0;

        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        $cv = sqrt($variance / $count) / $mean;

        if ($cv < 0.5) {
            return 'consistent';
        }

        return $cv < 1.0 ? 'sporadic' : 'bursty';
    }

    public function windowStart(int $days): CarbonImmutable
    {
        return CarbonImmutable::now()
            ->subDays($this->normalise($days) - 1)
            ->startOfDay();
    }

    public function normalise(int $days): int
    {
        return in_array($days, TokenUsageReport::PERIODS, true)
            ? $days
            : TokenUsageReport::DEFAULT_PERIOD;
    }
}
