<?php

namespace App\Support\Reports;

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
}
