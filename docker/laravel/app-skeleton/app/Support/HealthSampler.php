<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Health sampling for the command-centre uptime KPI.
 *
 * A sample is one row: a timestamp and whether the dependency probe answered.
 * Uptime is then the share of healthy samples inside a window — a real
 * measurement rather than a guess, and one that can report **unknown**
 * (null) when nothing has been sampled, which is the honest reading a bare
 * `0` was standing in for.
 *
 * What is probed: the cache store. The database cannot be its own witness —
 * if it is down the sample cannot be written, so a database outage shows up
 * as a gap in the series, not as a failing row. Say so wherever the number is
 * displayed.
 */
final class HealthSampler
{
    /** The recorded check name, so other probes can be added later. */
    private const CHECK = 'core';

    /**
     * Record one sample.
     *
     * @return bool Whether the probe answered.
     */
    public function sample(): bool
    {
        $healthy = $this->cacheResponds();

        DB::table('nvoos_health_samples')->insert(array(
            'check' => self::CHECK,
            'healthy' => $healthy,
            'recorded_at' => now(),
        ));

        return $healthy;
    }

    /**
     * Healthy share of the samples inside the window, or null when there are none.
     *
     * @param int $hours Window size in hours.
     */
    public function uptimePercent(int $hours = 24): ?float
    {
        $row = DB::table('nvoos_health_samples')
            ->where('check', self::CHECK)
            ->where('recorded_at', '>=', now()->subHours($hours))
            ->selectRaw('count(*) as total, sum(case when healthy then 1 else 0 end) as ok')
            ->first();

        $total = (int) ($row->total ?? 0);

        if ($total < 1) {
            return null;
        }

        return round(((int) ($row->ok ?? 0)) * 100 / $total, 2);
    }

    /**
     * Drop samples older than the retention window.
     *
     * @return int Rows deleted.
     */
    public function prune(int $days = 30): int
    {
        return DB::table('nvoos_health_samples')
            ->where('recorded_at', '<', now()->subDays($days))
            ->delete();
    }

    /**
     * Probe the cache store.
     *
     * Any throwable counts as unhealthy — a store that cannot answer is the
     * definition of degraded — and is deliberately not re-thrown, or the
     * sampler would fail exactly when it is most needed.
     */
    private function cacheResponds(): bool
    {
        try {
            $key = 'nvoos:health:probe';

            Cache::put($key, '1', 60);

            return '1' === Cache::get($key);
        } catch (Throwable) {
            return false;
        }
    }
}
