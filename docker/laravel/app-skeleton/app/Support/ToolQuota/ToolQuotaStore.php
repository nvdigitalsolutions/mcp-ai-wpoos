<?php

namespace App\Support\ToolQuota;

use App\Models\ToolQuota;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tool daily quotas — Proposal 149 §176.
 *
 * The WordPress counterpart keeps the limit in one option and the running usage
 * in per-user meta, then estimates a tool's cost from the size of its **result**
 * (`strlen / 4`). That proxy is kept here because it is the honest one: a tool
 * body does not call a provider, so there are no provider tokens to attribute to
 * it. What a tool costs the loop is the size of the payload it puts back into
 * the context on the next turn.
 *
 * **Policy and usage are separate tables.** Merging them would make the counter
 * a second truth about the limit, which is the failure `BudgetStore` avoided by
 * computing spend from `nvoos_token_usage`.
 *
 * **A zero limit reads as unlimited.** The guard's floor: a control nobody
 * configured must not refuse anybody. Absence of a row and a disabled row mean
 * the same thing.
 *
 * **No multipliers.** WordPress applies a per-tool multiplier to a per-user tier
 * base (`low` / `medium` / `high` tier max tokens). This runtime has no claim
 * tiers, so a multiplier would multiply nothing — a field that renders and
 * changes no behaviour, which the contract calls `inert` and refuses to count as
 * parity. It is omitted rather than faked; a multiplier can arrive with the tier
 * model that would give it meaning.
 */
class ToolQuotaStore
{
    public const QUOTAS_TABLE = 'nvoos_tool_quotas';

    public const USAGE_TABLE = 'nvoos_tool_quota_usage';

    /** The row is switched off; it is not consulted and refuses nothing. */
    public const STATE_DISABLED = 'disabled';

    /** A zero limit: read as "no limit", the same convention budgets use. */
    public const STATE_UNLIMITED = 'unlimited';

    /** At or over the limit; the guard refuses the tool. */
    public const STATE_EXHAUSTED = 'exhausted';

    /** At or over the warn band but under the limit; the guard allows and logs. */
    public const STATE_WARN = 'warn';

    /** Under both bands. */
    public const STATE_OK = 'ok';

    /** The warn band, as a fraction of the limit. */
    public const WARN_AT = 0.8;

    /**
     * Whether quotas are a live control on this deployment.
     *
     * Off means no check **and no recording**, so an operator can stop the
     * per-call upsert without redeploying the guard.
     */
    public function enabled(): bool
    {
        return (bool) config('oos.tool_quotas.enabled', true);
    }

    /**
     * Every quota row of the current estate, for the page.
     *
     * `ToolQuota` carries the tenant scope, so this needs no argument and cannot
     * be asked for another estate's limits by mistake.
     *
     * @return list<ToolQuota>
     */
    public function forCurrentEstate(): array
    {
        if (! Schema::hasTable(self::QUOTAS_TABLE)) {
            return array();
        }

        return ToolQuota::query()->orderBy('tool_slug')->get()->all();
    }

    /**
     * The quota row for one tool, enabled or not.
     */
    public function forTool(string $slug): ?ToolQuota
    {
        if (! Schema::hasTable(self::QUOTAS_TABLE)) {
            return null;
        }

        return ToolQuota::query()->where('tool_slug', $slug)->first();
    }

    /**
     * The limit that applies to one user's call right now, or `null` if
     * unmetered.
     *
     * Returning `null` rather than `0` keeps "no limit" and "zero limit"
     * distinguishable at the call site even though both refuse nothing.
     */
    public function limitFor(string $slug): ?int
    {
        $quota = $this->forTool($slug);

        if (null === $quota || ! $quota->enabled) {
            return null;
        }

        $limit = (int) $quota->daily_tokens;

        return $limit > 0 ? $limit : null;
    }

    /**
     * One user's estimated tokens for one tool today.
     */
    public function usageToday(string $slug, int $userId, ?string $day = null): int
    {
        if (! Schema::hasTable(self::USAGE_TABLE)) {
            return 0;
        }

        $row = DB::table(self::USAGE_TABLE)
            ->where('tenant_id', TenantContext::current())
            ->where('user_id', max(0, $userId))
            ->where('tool_slug', $slug)
            ->where('day', $day ?? self::today())
            ->first();

        return null === $row ? 0 : (int) $row->tokens;
    }

    /**
     * Today's consumption per user, for the page.
     *
     * The limit is enforced per user, so an estate-wide total would be the wrong
     * number to badge: one heavy user at their ceiling is a different state from
     * twenty light ones sharing the same total. The page reads the busiest user
     * against the limit and shows the total beside it.
     *
     * @return array<int, int> user id => tokens
     */
    public function usageByUser(string $slug, ?string $day = null): array
    {
        if (! Schema::hasTable(self::USAGE_TABLE)) {
            return array();
        }

        $rows = DB::table(self::USAGE_TABLE)
            ->select('user_id', DB::raw('SUM(tokens) as tokens'))
            ->where('tenant_id', TenantContext::current())
            ->where('tool_slug', $slug)
            ->where('day', $day ?? self::today())
            ->groupBy('user_id')
            ->get();

        $usage = array();

        foreach ($rows as $row) {
            $usage[ (int) $row->user_id ] = (int) $row->tokens;
        }

        return $usage;
    }

    /**
     * The verdict for one row, from the busiest user's usage.
     *
     * @return array{state: string, limit: int, busiest: int, total: int, users: int, percent: float|null}
     */
    public function verdict(ToolQuota $quota): array
    {
        $limit = $quota->enabled ? (int) $quota->daily_tokens : 0;
        $usage = $this->usageByUser($quota->tool_slug);

        $total = array_sum($usage);
        $busiest = array() === $usage ? 0 : max($usage);

        if (! $quota->enabled) {
            $state = self::STATE_DISABLED;
        } elseif ($limit <= 0) {
            $state = self::STATE_UNLIMITED;
        } elseif ($busiest >= $limit) {
            $state = self::STATE_EXHAUSTED;
        } elseif ($busiest >= (int) ceil($limit * self::WARN_AT)) {
            $state = self::STATE_WARN;
        } else {
            $state = self::STATE_OK;
        }

        return array(
            'state' => $state,
            'limit' => $limit,
            'busiest' => $busiest,
            'total' => $total,
            'users' => count($usage),
            'percent' => $limit > 0 ? ($busiest / $limit) * 100 : null,
        );
    }

    /**
     * Write or update a quota, then return the stored row.
     */
    public function put(string $slug, int $dailyTokens, bool $enabled = true): ToolQuota
    {
        $slug = trim($slug);

        if ('' === $slug) {
            throw new \InvalidArgumentException('A tool quota needs a tool slug.');
        }

        $quota = $this->forTool($slug);

        if (null === $quota) {
            $quota = new ToolQuota();
            $quota->tool_slug = $slug;
        }

        $quota->daily_tokens = max(0, $dailyTokens);
        $quota->enabled = $enabled;
        $quota->save();

        return $quota;
    }

    /**
     * Remove a quota row.
     *
     * Usage is deliberately left alone: a row deleted by mistake must not take
     * the day's counter with it, and an unmetered tool is unmetered whether or
     * not last week's totals exist.
     */
    public function remove(int $id): bool
    {
        if (! Schema::hasTable(self::QUOTAS_TABLE)) {
            return false;
        }

        return (bool) ToolQuota::query()->whereKey($id)->delete();
    }

    /**
     * Add one call's estimated tokens to a user's bucket.
     *
     * Increment-then-insert rather than `upsert`, because the counter has to be
     * incremented and an `upsert` that replaced the row would reset it. The
     * insert is only attempted when no row matched, and a lost race is retried as
     * an increment, so the unique key makes a double-insert impossible rather
     * than merely unlikely.
     */
    public function record(string $slug, int $userId, int $tokens): void
    {
        if (! Schema::hasTable(self::USAGE_TABLE) || $tokens <= 0) {
            return;
        }

        $tenant = TenantContext::current();
        $userId = max(0, $userId);
        $day = self::today();
        $now = CarbonImmutable::now('UTC');

        $bucket = static function () use ($tenant, $userId, $slug, $day) {
            return DB::table(self::USAGE_TABLE)
                ->where('tenant_id', $tenant)
                ->where('user_id', $userId)
                ->where('tool_slug', $slug)
                ->where('day', $day);
        };

        $incremented = $bucket()->increment('tokens', $tokens, array(
            'requests' => DB::raw('requests + 1'),
            'updated_at' => $now,
        ));

        if ($incremented > 0) {
            return;
        }

        try {
            DB::table(self::USAGE_TABLE)->insert(array(
                'tenant_id' => $tenant,
                'user_id' => $userId,
                'tool_slug' => $slug,
                'day' => $day,
                'tokens' => $tokens,
                'requests' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ));
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Another request created the bucket between the two statements.
            // The row exists now, so the original increment applies to it.
            $bucket()->increment('tokens', $tokens, array(
                'requests' => DB::raw('requests + 1'),
                'updated_at' => $now,
            ));
        }
    }

    /**
     * Drop usage older than the retention window, for the current estate.
     *
     * Nothing reads yesterday's counters — a daily quota resets at midnight — so
     * they are kept only long enough to answer "what did this cost last week".
     */
    public function prune(?int $days = null): int
    {
        if (! Schema::hasTable(self::USAGE_TABLE)) {
            return 0;
        }

        $cutoff = $this->cutoff($days);

        return DB::table(self::USAGE_TABLE)
            ->where('tenant_id', TenantContext::current())
            ->where('day', '<', $cutoff)
            ->delete();
    }

    /**
     * Drop usage older than the retention window, for **every** estate.
     *
     * The scheduled prune uses this rather than {@see prune()}: retention is
     * deployment-wide maintenance, and an estate-scoped delete would quietly
     * leave every other estate's counters to grow forever. Reading across estates
     * is the documented opt-out of the tenant scope (Proposal 153), and it is only
     * ever a delete by date — never a read of another estate's traffic.
     */
    public function pruneAll(?int $days = null): int
    {
        if (! Schema::hasTable(self::USAGE_TABLE)) {
            return 0;
        }

        return DB::table(self::USAGE_TABLE)
            ->where('day', '<', $this->cutoff($days))
            ->delete();
    }

    /**
     * The oldest day that survives retention.
     */
    private function cutoff(?int $days): string
    {
        $days = $days ?? (int) config('oos.tool_quotas.retention_days', 30);

        return CarbonImmutable::now('UTC')->subDays(max(1, $days))->toDateString();
    }

    /**
     * The UTC day the quota is counted in.
     *
     * UTC rather than the estate's timezone on purpose: the same rule
     * `record_tool_usage` uses in WordPress, and a quota whose reset moves with
     * a timezone setting is a quota that can be reset twice.
     */
    public static function today(): string
    {
        return CarbonImmutable::now('UTC')->toDateString();
    }

    /**
     * When the current window ends, for the refusal message.
     */
    public static function resetsAt(): string
    {
        return CarbonImmutable::now('UTC')->endOfDay()->toIso8601String();
    }

    /**
     * The limiter's estimate of what a tool result costs the next turn.
     *
     * `strlen / 4` is the same ratio WordPress's `estimate_tokens()` uses, kept
     * so an operator moving between runtimes reads comparable numbers. A string
     * or an encoded structure is measured; anything else counts as one token
     * rather than zero, because a call that returned something did cost
     * something.
     */
    public static function estimate(mixed $result): int
    {
        if (\is_string($result)) {
            return max(1, (int) (\strlen($result) / 4));
        }

        if (\is_array($result) || \is_object($result)) {
            $encoded = \json_encode($result);

            return false === $encoded ? 1 : max(1, (int) (\strlen($encoded) / 4));
        }

        return 1;
    }
}
