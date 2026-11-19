<?php

namespace App\Support\Budgets;

use App\Models\Budget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estate budgets — Proposal 154.
 *
 * Reads the configured limits and the spend they apply to. Spend is not stored:
 * it is `SUM(cost)` over `nvoos_token_usage`, which is the record the cost pages
 * already aggregate, so a budget can never disagree with a report.
 */
class BudgetStore
{
    public const BUDGETS_TABLE = 'nvoos_budgets';

    public const SPEND_TABLE = 'nvoos_token_usage';

    /**
     * The enabled budgets of the current estate.
     *
     * `Budget` carries the tenant scope, so this needs no argument — and cannot
     * be asked for another estate's limits by mistake.
     *
     * @return list<Budget>
     */
    public function forCurrentEstate(): array
    {
        if (! Schema::hasTable(self::BUDGETS_TABLE)) {
            return array();
        }

        return Budget::query()
            ->where('enabled', true)
            ->orderBy('window')
            ->get()
            ->all();
    }

    /**
     * Spend for one estate over one window.
     *
     * A missing spend table is `0.0`, not an error: a deployment that has not
     * migrated the token table has no spend to measure against.
     */
    public function spend(string $tenantId, string $window): float
    {
        if (! Schema::hasTable(self::SPEND_TABLE)) {
            return 0.0;
        }

        return (float) DB::table(self::SPEND_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $this->windowStart($window))
            ->sum('cost');
    }

    /**
     * The first moment of the window, in whole days.
     *
     * Deliberately `startOfDay()`/`startOfMonth()`, the same boundary the
     * retention and cost pages use: "daily" means today, not the last 24 hours,
     * so a budget cannot disagree with the chart beside it.
     */
    public function windowStart(string $window): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        return Budget::MONTHLY === $window
            ? $now->startOfMonth()
            : $now->startOfDay();
    }
}
