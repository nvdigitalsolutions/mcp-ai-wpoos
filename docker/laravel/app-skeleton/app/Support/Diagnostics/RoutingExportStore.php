<?php

namespace App\Support\Diagnostics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Export cursor and run history — Proposal 149 §74.
 *
 * The cursor is the whole safety story of the scheduled push: a run may only
 * advance past rows a collector has acknowledged. Everything else follows —
 * a failed push leaves the watermark alone, the next run retries the same rows,
 * and retention refuses to delete anything above the watermark (§74.4).
 *
 * Missing tables are "nothing exported yet", not an error: the same guard the
 * router and the metrics store use, for the same reason.
 */
final class RoutingExportStore
{
    private const EXPORTS = 'nvoos_routing_exports';

    private const METRICS = 'nvoos_routing_metrics';

    /**
     * The highest metric id a collector has acknowledged, or 0.
     */
    public function watermark(): int
    {
        try {
            if (! Schema::hasTable(self::EXPORTS)) {
                return 0;
            }

            $value = DB::table(self::EXPORTS)
                ->where('status', 'ok')
                ->max('last_metric_id');
        } catch (\Throwable $exception) {
            report($exception);

            return 0;
        }

        return (int) $value;
    }

    /**
     * Routing rows that have not been acknowledged yet, oldest first.
     *
     * @return list<object>
     */
    public function pending(int $limit = 500): array
    {
        $limit = max(1, $limit);

        try {
            if (! Schema::hasTable(self::METRICS)) {
                return [];
            }

            return DB::table(self::METRICS)
                ->where('id', '>', $this->watermark())
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->all();
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * Record one export attempt.
     *
     * A failure must pass the unchanged watermark: a later success then covers
     * the same rows again, which is the only way "never drop before a
     * successful send" can hold without a second queue.
     */
    public function record(string $status, int $sent, int $lastMetricId, ?string $error = null): void
    {
        try {
            if (! Schema::hasTable(self::EXPORTS)) {
                return;
            }

            DB::table(self::EXPORTS)->insert([
                'status' => mb_substr($status, 0, 16),
                'sent' => max(0, $sent),
                'last_metric_id' => max(0, $lastMetricId),
                'error' => null === $error ? null : mb_substr($error, 0, 2000),
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The most recent attempt, for the diagnostics page.
     */
    public function last(): ?object
    {
        try {
            if (! Schema::hasTable(self::EXPORTS)) {
                return null;
            }

            return DB::table(self::EXPORTS)->orderByDesc('id')->first();
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
