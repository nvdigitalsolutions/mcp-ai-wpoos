<?php

namespace App\Support\Reports;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Token usage aggregates for the console — Proposal 149 §82.
 *
 * The WordPress token manager's `per_user` view, reduced to what this runtime
 * can actually evidence. Three deliberate differences:
 *
 *  - **no "Tier" column.** Tiers are a WordPress plan concept; there is no
 *    equivalent here, and inventing one from the entitlement would be a guess.
 *  - **no "Cached Tokens".** The provider response shape does not carry cached
 *    tokens, so the column would always be zero.
 *  - **"Anonymous" instead of a broken user link.** A turn can run without an
 *    authenticated user (`user_id = 0`); it is counted, and labelled.
 *
 * The window is whole days, like every other retention boundary in this series
 * (`startOfDay()`), so "7 days" means seven complete days plus today.
 */
class TokenUsageReport
{
    public const TABLE = 'nvoos_token_usage';

    /** The periods the page offers; anything else falls back to 30. */
    public const PERIODS = [7, 30, 90];

    public const DEFAULT_PERIOD = 30;

    /**
     * Per-user aggregates over the window, highest spend first.
     *
     * `$tenantId` is required rather than defaulted (Proposal 152 §3 D5): `null`
     * means every estate, and only an operator surface may ask for that. The
     * operator page states it explicitly so a future per-estate filter is a
     * one-line change rather than a silent semantic shift.
     *
     * @return list<array{user_id: int, name: string, events: int, prompt_tokens: int, completion_tokens: int, total_tokens: int, cost: float}>
     */
    public function byUser(int $days, ?string $tenantId): array
    {
        $days = $this->normalisePeriod($days);

        if (! Schema::hasTable(self::TABLE)) {
            return array();
        }

        $rows = DB::table(self::TABLE)
            ->where('created_at', '>=', $this->windowStart($days))
            ->when(null !== $tenantId, static fn ($query) => $query->where('tenant_id', $tenantId))
            ->groupBy('user_id')
            ->selectRaw(
                'user_id, COUNT(*) as events,'
                .' SUM(prompt_tokens) as prompt_tokens,'
                .' SUM(completion_tokens) as completion_tokens,'
                .' SUM(total_tokens) as total_tokens,'
                .' SUM(cost) as cost'
            )
            ->orderByDesc('cost')
            ->get();

        $names = $this->names($rows->pluck('user_id')->map(static fn ($id): int => (int) $id)->all());

        $out = array();

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;

            $out[] = array(
                'user_id' => $userId,
                'name' => $names[$userId]
                    ?? (0 === $userId ? 'Anonymous' : 'User #'.$userId),
                'events' => (int) $row->events,
                'prompt_tokens' => (int) $row->prompt_tokens,
                'completion_tokens' => (int) $row->completion_tokens,
                'total_tokens' => (int) $row->total_tokens,
                'cost' => (float) $row->cost,
            );
        }

        return $out;
    }

    /**
     * Column totals for the rendered window.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{events: int, prompt_tokens: int, completion_tokens: int, total_tokens: int, cost: float}
     */
    public function totals(array $rows): array
    {
        $totals = array(
            'events' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'cost' => 0.0,
        );

        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key] ?? 0;
            }
        }

        $totals['cost'] = round((float) $totals['cost'], 6);

        return $totals;
    }

    /**
     * Spend per estate over the window, highest first.
     *
     * Deliberately unscoped, and that is not an exception to Proposal 152 §3 D5.
     * D5 exists so a reader cannot be called in the wrong scope by accident;
     * enumerating the estates **is** this reader's only query. It is reachable
     * only from the operator page — the estate-facing cost endpoints go through
     * `TokenUsage::summary()` with the caller's estate (§86).
     *
     * Only estates with usage in the window appear. A tenant with no rows is not
     * a row of zeros here: the table is the overview you navigate from, and an
     * estate that spent nothing is not something to navigate to.
     *
     * `users` counts distinct **authenticated** users. An anonymous turn
     * (`user_id = 0`) is spend without a person, so counting it as one would
     * overstate the estate by a user who does not exist.
     *
     * `share` is the share of **tokens**, not of cost: cost is zero for a window
     * with no priced model, and a column of 0.0% would read as a broken query
     * rather than as a pricing gap. Tokens are recorded on every turn.
     *
     * @return list<array{tenant_id: string, name: string, users: int, events: int, prompt_tokens: int, completion_tokens: int, total_tokens: int, cost: float, share: float}>
     */
    public function byEstate(int $days): array
    {
        $days = $this->normalisePeriod($days);

        if (! Schema::hasTable(self::TABLE)) {
            return array();
        }

        $rows = DB::table(self::TABLE)
            ->where('created_at', '>=', $this->windowStart($days))
            ->groupBy('tenant_id')
            ->selectRaw(
                'tenant_id, COUNT(*) as events,'
                .' COUNT(DISTINCT CASE WHEN user_id > 0 THEN user_id END) as users,'
                .' SUM(prompt_tokens) as prompt_tokens,'
                .' SUM(completion_tokens) as completion_tokens,'
                .' SUM(total_tokens) as total_tokens,'
                .' SUM(cost) as cost'
            )
            // Ordered by token volume, the same quantity `share` is computed
            // from, so the column descends as the table is read. Cost is the
            // tie-break for a window where pricing is uniform.
            ->orderByDesc('total_tokens')
            ->orderByDesc('cost')
            ->orderBy('tenant_id')
            ->get();

        $totalTokens = 0;

        foreach ($rows as $row) {
            $totalTokens += (int) $row->total_tokens;
        }

        $names = $this->estateNames(
            $rows->pluck('tenant_id')->map(static fn ($id): string => (string) $id)->all()
        );

        $out = array();

        foreach ($rows as $row) {
            $tenantId = $this->normaliseEstateId($row->tenant_id);
            $tokens = (int) $row->total_tokens;

            $out[] = array(
                'tenant_id' => $tenantId,
                'name' => $names[$tenantId] ?? $tenantId,
                'users' => (int) $row->users,
                'events' => (int) $row->events,
                'prompt_tokens' => (int) $row->prompt_tokens,
                'completion_tokens' => (int) $row->completion_tokens,
                'total_tokens' => $tokens,
                'cost' => (float) $row->cost,
                'share' => $totalTokens > 0 ? round($tokens / $totalTokens * 100, 1) : 0.0,
            );
        }

        return $out;
    }

    /**
     * The display name for one estate, or its id when the tenants table has no
     * row for it — the same fallback discipline as `User #id` on the user table.
     */
    public function estateName(string $tenantId): string
    {
        $tenantId = $this->normaliseEstateId($tenantId);
        $names = $this->estateNames(array($tenantId));

        return $names[$tenantId] ?? $tenantId;
    }

    /**
     * Spend per model over the window, largest token volume first.
     *
     * `$tenantId` follows the reader contract (Proposal 152 §3 D5); the page
     * calls it only for a selected estate, because a platform-wide by-model
     * table says nothing a per-estate one does not.
     *
     * Grouped in SQL on `model_id`. **Provider is deliberately absent**: it
     * lives in the metadata blob, so a per-provider breakdown is a row scan
     * rather than an aggregate, and `/api/v1/cost/by-provider` already answers
     * it for one estate.
     *
     * @return list<array{model_id: string, events: int, prompt_tokens: int, completion_tokens: int, total_tokens: int, cost: float}>
     */
    public function byModel(int $days, ?string $tenantId): array
    {
        $days = $this->normalisePeriod($days);

        if (! Schema::hasTable(self::TABLE)) {
            return array();
        }

        $rows = DB::table(self::TABLE)
            ->where('created_at', '>=', $this->windowStart($days))
            ->when(null !== $tenantId, static fn ($query) => $query->where('tenant_id', $tenantId))
            ->groupBy('model_id')
            ->selectRaw(
                'model_id, COUNT(*) as events,'
                .' SUM(prompt_tokens) as prompt_tokens,'
                .' SUM(completion_tokens) as completion_tokens,'
                .' SUM(total_tokens) as total_tokens,'
                .' SUM(cost) as cost'
            )
            ->orderByDesc('total_tokens')
            ->orderBy('model_id')
            ->get();

        $out = array();

        foreach ($rows as $row) {
            $out[] = array(
                'model_id' => (string) $row->model_id,
                'events' => (int) $row->events,
                'prompt_tokens' => (int) $row->prompt_tokens,
                'completion_tokens' => (int) $row->completion_tokens,
                'total_tokens' => (int) $row->total_tokens,
                'cost' => (float) $row->cost,
            );
        }

        return $out;
    }

    public function windowStart(int $days = self::DEFAULT_PERIOD): CarbonImmutable
    {
        return CarbonImmutable::now()
            ->subDays($this->normalisePeriod($days) - 1)
            ->startOfDay();
    }

    public function normalisePeriod(int $days): int
    {
        return in_array($days, self::PERIODS, true) ? $days : self::DEFAULT_PERIOD;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    private function names(array $ids): array
    {
        if (array() === $ids) {
            return array();
        }

        $model = (string) config('oos.user_model', 'App\\Models\\User');

        if (! class_exists($model)) {
            return array();
        }

        try {
            return $model::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
        } catch (\Throwable $exception) {
            report($exception);

            return array();
        }
    }

    /**
     * Estate ids resolved to display names.
     *
     * A missing row is not an error and not a blank cell: the caller falls back
     * to the id, which is what the usage row itself recorded.
     *
     * @param array<int, string> $ids
     * @return array<string, string>
     */
    private function estateNames(array $ids): array
    {
        if (array() === $ids) {
            return array();
        }

        try {
            return Tenant::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
        } catch (\Throwable $exception) {
            report($exception);

            return array();
        }
    }

    /**
     * The estate a row belongs to.
     *
     * The column is NOT NULL with a default (§86), so an empty value can only
     * come from a row written before the migration ran against a table altered
     * without one. Treating that as `default` matches the adapter's own
     * attribution rather than inventing a second rule.
     */
    private function normaliseEstateId(mixed $value): string
    {
        $tenant = trim((string) $value);

        return '' !== $tenant ? $tenant : Tenant::DEFAULT_ID;
    }
}
