<?php

namespace App\Support\Diagnostics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Domain\Contract\RoutingMetricsInterface;

/**
 * Durable counters for routing deviations — Proposal 149 §71-§72.
 *
 * Two kinds share one table because they are one story — "the answer did not
 * come straight from what was asked" — and an operator reads them together:
 *
 *  - `model_fallback` — the model failed, a sibling on the same provider served
 *    the request (`from_model` → `to_model`);
 *  - `provider_hop` — the provider failed, another provider served it
 *    (`from_provider` → `provider`).
 *
 * Three rules:
 *
 *  1. **A write never throws.** The router does not wrap the call, and a
 *     metrics row is not worth failing a chat over. Every path catches and
 *     reports.
 *  2. **Missing schema is not an error.** A deployment mid-migration, or a
 *     suite that runs without migrations, records nothing rather than
 *     exploding.
 *  3. **One row per attempt.** Reads aggregate; nothing is pre-computed, so a
 *     rule change never needs a backfill.
 */
final class RoutingMetricStore implements RoutingMetricsInterface
{
    private const TABLE = 'nvoos_routing_metrics';

    private const MODEL_FALLBACK = 'model_fallback';

    private const PROVIDER_HOP = 'provider_hop';

    public function recordModelFallback(
        string $provider,
        string $fromModel,
        string $toModel,
        bool $succeeded
    ): void {
        $this->insert([
            'kind' => self::MODEL_FALLBACK,
            'provider' => $provider,
            'from_provider' => null,
            'from_model' => $fromModel,
            'to_model' => $toModel,
            'succeeded' => $succeeded,
        ]);
    }

    public function recordProviderHop(
        string $fromProvider,
        string $toProvider,
        bool $succeeded
    ): void {
        $this->insert([
            'kind' => self::PROVIDER_HOP,
            'provider' => $toProvider,
            'from_provider' => $fromProvider,
            'from_model' => '',
            'to_model' => '',
            'succeeded' => $succeeded,
        ]);
    }

    /**
     * Routing counts over the last N days, for the diagnostics page.
     *
     * @return array{
     *     total: int,
     *     succeeded: int,
     *     failed: int,
     *     last_at: string|null,
     *     model_fallbacks: int,
     *     provider_hops: int,
     *     pairs: list<array{provider: string, from: string, to: string, total: int, failed: int}>,
     *     hops: list<array{from: string, to: string, total: int, failed: int}>
     * }
     */
    public function summary(int $days = 30): array
    {
        $empty = [
            'total' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'last_at' => null,
            'model_fallbacks' => 0,
            'provider_hops' => 0,
            'pairs' => [],
            'hops' => [],
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
        $hops = [];
        $succeeded = 0;
        $modelFallbacks = 0;
        $providerHops = 0;

        foreach ($rows as $row) {
            $ok = (bool) $row->succeeded;

            if ($ok) {
                ++$succeeded;
            }

            if (self::PROVIDER_HOP === ($row->kind ?? self::MODEL_FALLBACK)) {
                ++$providerHops;

                $key = ((string) ($row->from_provider ?? '')).'|'.((string) $row->provider);

                if (! isset($hops[$key])) {
                    $hops[$key] = [
                        'from' => (string) ($row->from_provider ?? ''),
                        'to' => (string) $row->provider,
                        'total' => 0,
                        'failed' => 0,
                    ];
                }

                ++$hops[$key]['total'];

                if (! $ok) {
                    ++$hops[$key]['failed'];
                }

                continue;
            }

            ++$modelFallbacks;

            $key = ((string) $row->provider).'|'.((string) $row->from_model).'|'.((string) $row->to_model);

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

            if (! $ok) {
                ++$pairs[$key]['failed'];
            }
        }

        usort($pairs, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        usort($hops, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'total' => count($rows),
            'succeeded' => $succeeded,
            'failed' => count($rows) - $succeeded,
            'last_at' => is_string($last) ? $last : null,
            'model_fallbacks' => $modelFallbacks,
            'provider_hops' => $providerHops,
            'pairs' => array_slice(array_values($pairs), 0, 5),
            'hops' => array_slice(array_values($hops), 0, 5),
        ];
    }

    /**
     * Insert one counted attempt, or nothing when the table is unavailable.
     *
     * @param array<string, mixed> $row Row values.
     */
    private function insert(array $row): void
    {
        try {
            if (! Schema::hasTable(self::TABLE)) {
                return;
            }

            DB::table(self::TABLE)->insert([
                'kind' => (string) $row['kind'],
                'provider' => mb_substr((string) $row['provider'], 0, 64),
                'from_provider' => null === $row['from_provider']
                    ? null
                    : mb_substr((string) $row['from_provider'], 0, 64),
                'from_model' => mb_substr((string) $row['from_model'], 0, 191),
                'to_model' => mb_substr((string) $row['to_model'], 0, 191),
                'succeeded' => (bool) $row['succeeded'],
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
