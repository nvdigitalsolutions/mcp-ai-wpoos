<?php

namespace App\Support\Diagnostics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Domain\Contract\RoutingMetricsInterface;

/**
 * Durable counters for model fallbacks — Proposal 149 §71.
 *
 * §69 made a fallback succeed silently: the caller gets an answer and only the
 * `_nvoos_routing` marker says anything changed. This store is what turns that
 * marker into a number an operator can see on `/ops/diagnostics`.
 *
 * Three rules:
 *
 *  1. **A write never throws.** The router does not wrap `recordModelFallback()`,
 *     and a metrics row is not worth failing a chat over. Every path catches
 *     and reports.
 *  2. **Missing schema is not an error.** A deployment mid-migration, or a
 *     suite that runs without migrations, records nothing rather than
 *     exploding — the same guard the token-usage table uses.
 *  3. **One row per attempt.** Reads aggregate; nothing is pre-computed, so a
 *     rule change never needs a backfill.
 */
final class RoutingMetricStore implements RoutingMetricsInterface
{
    private const TABLE = 'nvoos_routing_metrics';

    public function recordModelFallback(
        string $provider,
        string $fromModel,
        string $toModel,
        bool $succeeded
    ): void {
        try {
            if (! Schema::hasTable(self::TABLE)) {
                return;
            }

            DB::table(self::TABLE)->insert([
                'provider' => mb_substr($provider, 0, 64),
                'from_model' => mb_substr($fromModel, 0, 191),
                'to_model' => mb_substr($toModel, 0, 191),
                'succeeded' => $succeeded,
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Fallback counts over the last N days, for the diagnostics page.
     *
     * @return array{
     *     total: int,
     *     succeeded: int,
     *     failed: int,
     *     last_at: string|null,
     *     pairs: list<array{provider: string, from: string, to: string, total: int, failed: int}>
     * }
     */
    public function summary(int $days = 30): array
    {
        $empty = [
            'total' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'last_at' => null,
            'pairs' => [],
        ];

        try {
            if (! Schema::hasTable(self::TABLE)) {
                return $empty;
            }

            $since = now()->subDays(max(1, $days) - 1)->startOfDay();

            $rows = DB::table(self::TABLE)
                ->where('created_at', '>=', $since)
                ->get();

            $last = DB::table(self::TABLE)->max('created_at');
        } catch (\Throwable $exception) {
            report($exception);

            return $empty;
        }

        $pairs = [];

        foreach ($rows as $row) {
            $key = ($row->provider ?? '').'|'.($row->from_model ?? '').'|'.($row->to_model ?? '');

            if (! isset($pairs[$key])) {
                $pairs[$key] = [
                    'provider' => (string) $row->provider,
                    'from' => (string) $row->from_model,
                    'to' => (string) $row->to_model,
                    'total' => 0,
                    'failed' => 0,
                ];
            }

            ++$pairs[$key]['total'];

            if (! $row->succeeded) {
                ++$pairs[$key]['failed'];
            }
        }

        usort($pairs, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $succeeded = 0;

        foreach ($rows as $row) {
            if ($row->succeeded) {
                ++$succeeded;
            }
        }

        return [
            'total' => count($rows),
            'succeeded' => $succeeded,
            'failed' => count($rows) - $succeeded,
            'last_at' => is_string($last) ? $last : null,
            'pairs' => array_slice(array_values($pairs), 0, 5),
        ];
    }
}
