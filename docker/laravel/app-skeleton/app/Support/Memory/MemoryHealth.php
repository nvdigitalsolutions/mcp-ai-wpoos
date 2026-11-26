<?php

namespace App\Support\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent-memory health for one estate — Proposal 149 §182.
 *
 * **What this measures, and what WordPress actually measures.** The rendered
 * WordPress `orchestration` → `memory_health` view reports **PHP process memory
 * pressure**: `memory_get_usage()` against `memory_warning_threshold` /
 * `memory_critical_threshold` of `memory_limit`. That number is meaningful on a
 * request that loads a whole plugin stack on every hit; it is not meaningful here,
 * where an FPM request measures the page rendering itself and no cross-request
 * gauge exists. The portable half is the **Pro retention API**
 * (`WP_MCP_AI_Memory_Retention::get_memory_health_stats()`): total, dormant,
 * oldest, and the dormancy window. This class is that half, over `nvoos_memories`.
 *
 * **Expired rows are invisible garbage.** `MemoryStore::applyExpiry()` filters
 * `expires_at IS NULL OR expires_at > now()` on every read, so an expired row can
 * never be recalled — but nothing sweeps them, so they accumulate. The count is
 * therefore a hygiene signal, and it is reported rather than hidden.
 *
 * **No data is not health.** An estate with no memories has nothing to grade; the
 * verdict is `empty`, not `ok`, and the page says so. The media library made the
 * same call about WordPress's "100%" for an empty alt-coverage figure.
 *
 * **The bands are judgements, not SLOs.** Nothing here has a target; the numbers
 * are chosen to answer "is this store mostly dead weight?" and are documented
 * rather than tuned:
 *
 *  - expired share ≥ 50% → critical (most of the store is unreachable garbage);
 *  - dormant share ≥ 75% → critical, ≥ 50% → warn (most live rows are untouched);
 *  - any expired row at all → warn (the sweeper question is open).
 *
 * The dormancy **window** is a setting (`memory_dormancy_days`, default 30, the
 * WordPress default); the percentages are not, because a band an operator can
 * widen until it is green is not a band.
 */
final class MemoryHealth
{
    /** Share of live memories that may be dormant before the band changes. */
    public const DORMANT_WARN_PCT = 50;

    public const DORMANT_CRITICAL_PCT = 75;

    /** Share of all memories that may be expired before the band changes. */
    public const EXPIRED_CRITICAL_PCT = 50;

    /** Window for the growth figures, in days. */
    public const GROWTH_DAYS = 30;

    /** The WordPress default for `memory_dormancy_days`. */
    public const DEFAULT_DORMANCY_DAYS = 30;

    /** Memories expiring inside this many days are "soon" and worth acting on. */
    public const EXPIRING_SOON_DAYS = 7;

    /**
     * One estate's agent memory, summarised.
     *
     * A missing table is a measured zero, not an error: the runtime tables come
     * from guarded migrations, and a load order that has not run them yet must not
     * blank the page (the same reading `MediaLibrary` takes).
     *
     * @return array{
     *     total: int, live: int, expired: int, dormant: int, expiring_soon: int,
     *     bytes: int, oldest: string, created_recent: int, created_previous: int,
     *     dormant_pct: int, expired_pct: int, growth_pct: int|null,
     *     agents: list<array{agent_id: string, total: int}>
     * }
     */
    public function summary(string $tenantId, int $dormancyDays = self::DEFAULT_DORMANCY_DAYS): array
    {
        $empty = array(
            'total' => 0,
            'live' => 0,
            'expired' => 0,
            'dormant' => 0,
            'expiring_soon' => 0,
            'bytes' => 0,
            'oldest' => '',
            'created_recent' => 0,
            'created_previous' => 0,
            'dormant_pct' => 0,
            'expired_pct' => 0,
            'growth_pct' => null,
            'agents' => array(),
        );

        if (! Schema::hasTable('nvoos_memories')) {
            return $empty;
        }

        $now = now();
        $dormancyDays = max(1, $dormancyDays);

        /** A fresh builder per call: every figure below is a different predicate. */
        $base = static fn () => DB::table('nvoos_memories')->where('tenant_id', $tenantId);

        $total = (int) $base()->count();

        if ($total < 1) {
            return $empty;
        }

        $expired = (int) $base()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->count();

        $live = $total - $expired;

        $dormant = (int) $base()
            ->where(static function ($query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->where('updated_at', '<', $now->copy()->subDays($dormancyDays))
            ->count();

        $expiringSoon = (int) $base()
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $now)
            ->where('expires_at', '<=', $now->copy()->addDays(self::EXPIRING_SOON_DAYS))
            ->count();

        $createdRecent = (int) $base()
            ->where('created_at', '>=', $now->copy()->subDays(self::GROWTH_DAYS))
            ->count();

        $createdPrevious = (int) $base()
            ->where('created_at', '>=', $now->copy()->subDays(self::GROWTH_DAYS * 2))
            ->where('created_at', '<', $now->copy()->subDays(self::GROWTH_DAYS))
            ->count();

        $oldest = $base()->min('created_at');

        $bytes = (int) $base()
            ->selectRaw('COALESCE(SUM(LENGTH(content)), 0) AS bytes')
            ->value('bytes');

        $agents = $base()
            ->select('agent_id', DB::raw('COUNT(*) AS total'))
            ->groupBy('agent_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(static fn (object $row): array => array(
                'agent_id' => (string) $row->agent_id,
                'total' => (int) $row->total,
            ))
            ->all();

        return array(
            'total' => $total,
            'live' => $live,
            'expired' => $expired,
            'dormant' => $dormant,
            'expiring_soon' => $expiringSoon,
            'bytes' => $bytes,
            'oldest' => (string) ($oldest ?? ''),
            'created_recent' => $createdRecent,
            'created_previous' => $createdPrevious,
            'dormant_pct' => $live > 0 ? (int) round($dormant / $live * 100) : 0,
            'expired_pct' => (int) round($expired / $total * 100),
            'growth_pct' => $createdPrevious > 0
                ? (int) round(($createdRecent - $createdPrevious) / $createdPrevious * 100)
                : null,
            'agents' => $agents,
        );
    }

    /**
     * `empty`, `ok`, `warn` or `critical`.
     *
     * @param array<string, mixed> $summary
     */
    public function verdict(array $summary): string
    {
        if ((int) ($summary['total'] ?? 0) < 1) {
            return 'empty';
        }

        $expiredPct = (int) ($summary['expired_pct'] ?? 0);
        $dormantPct = (int) ($summary['dormant_pct'] ?? 0);
        $expired = (int) ($summary['expired'] ?? 0);

        if ($expiredPct >= self::EXPIRED_CRITICAL_PCT || $dormantPct >= self::DORMANT_CRITICAL_PCT) {
            return 'critical';
        }

        if ($expired > 0 || $dormantPct >= self::DORMANT_WARN_PCT) {
            return 'warn';
        }

        return 'ok';
    }
}
