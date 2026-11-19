<?php

namespace App\Support\Budgets;

use App\Models\Assistant;
use App\Models\Budget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Estate budgets — Proposal 154.
 *
 * Reads the configured limits and the spend they apply to. Spend is not stored:
 * it is `SUM(cost)` over `nvoos_token_usage`, which is the record the cost pages
 * already aggregate, so a budget can never disagree with a report.
 *
 * It is also the writer, because a second class that inserts rows would be a
 * second place that has to know the window vocabulary. `put()` refuses a window
 * it does not know, so the closed vocabulary is enforced by the store rather than
 * only by the form that happens to call it.
 */
class BudgetStore
{
    public const BUDGETS_TABLE = 'nvoos_budgets';

    public const SPEND_TABLE = 'nvoos_token_usage';

    /** The row is turned off; it is not consulted and it refuses nothing. */
    public const STATE_DISABLED = 'disabled';

    /** A limit of zero: a misconfiguration, read as "no limit". */
    public const STATE_UNLIMITED = 'unlimited';

    /** At or over the limit; the guard refuses the turn. */
    public const STATE_EXHAUSTED = 'exhausted';

    /** At or over `warn_at` but under the limit; the guard allows and logs. */
    public const STATE_WARN = 'warn';

    /** Under both bands. */
    public const STATE_OK = 'ok';

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
     * Every estate's budgets, for the operator surface.
     *
     * `withoutGlobalScopes()` is the documented opt-out (Proposal 153): `/ops` is
     * the vendor's view of every estate, and the page renders the estate column
     * that makes the breadth visible. The guard must never call this — it asks
     * for `forCurrentEstate()` so a turn cannot be judged against a limit that
     * belongs to somebody else.
     *
     * @return list<Budget>
     */
    public function all(): array
    {
        if (! Schema::hasTable(self::BUDGETS_TABLE)) {
            return array();
        }

        return Budget::query()
            ->withoutGlobalScopes()
            ->with('tenant')
            ->orderBy('tenant_id')
            ->orderBy('window')
            ->get()
            ->all();
    }

    /**
     * The state of a budget, its spend and its ratio — one query, one answer.
     *
     * The page and the guard both read this, which is the point: a badge that
     * says "exhausted" and an enforcement path that allows the turn would be two
     * implementations of one rule, and they would drift.
     *
     * Spend is filtered by the budget's own `assistant_id`: an estate-wide limit
     * (`0`) sees every attributed row, an assistant limit sees only its own
     * turns. That asymmetry is the whole semantics of the two scopes, so it lives
     * in one place.
     *
     * @return array{state: string, window: string, assistant_id: int, limit: float, spend: float, percent: float|null}
     */
    public function verdict(Budget $budget): array
    {
        $window = (string) $budget->window;
        $assistantId = (int) $budget->assistant_id;
        $limit = (float) $budget->limit_usd;

        // The row's own estate, not the request's: this method is also called
        // for another estate's row by the operator page.
        $spend = $this->spend((string) $budget->tenant_id, $window, $assistantId);

        // `warn_at` is a fraction of the limit; a zero or unset value means the
        // deployment only wants the hard band, not a warning on every request.
        $warnAt = min(1.0, max(0.0, (float) $budget->warn_at));

        $state = match (true) {
            ! $budget->enabled => self::STATE_DISABLED,
            $limit <= 0.0 => self::STATE_UNLIMITED,
            $spend >= $limit => self::STATE_EXHAUSTED,
            $warnAt > 0.0 && $spend >= $limit * $warnAt => self::STATE_WARN,
            default => self::STATE_OK,
        };

        return array(
            'state' => $state,
            'window' => $window,
            'assistant_id' => $assistantId,
            'limit' => $limit,
            'spend' => $spend,
            // A disabled or unlimited row has no meaningful ratio; `null` keeps
            // the page from printing "0%" over a real spend.
            'percent' => $limit > 0.0 ? $spend / $limit * 100.0 : null,
        );
    }

    /**
     * The state alone, for callers that do not need the numbers.
     */
    public function state(Budget $budget): string
    {
        return $this->verdict($budget)['state'];
    }

    /**
     * Spend for one estate over one window, optionally for one assistant.
     *
     * `$assistantId = 0` sums the whole estate, which is what an estate-wide
     * limit means. A non-zero id narrows to rows attributed to that assistant;
     * rows written before the column existed carry `0` and are therefore counted
     * by the estate limit and by no assistant limit — the honest reading, not a
     * guess.
     *
     * A missing spend table is `0.0`, not an error: a deployment that has not
     * migrated the token table has no spend to measure against.
     */
    public function spend(string $tenantId, string $window, int $assistantId = 0): float
    {
        if (! Schema::hasTable(self::SPEND_TABLE)) {
            return 0.0;
        }

        $query = DB::table(self::SPEND_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $this->windowStart($window));

        if ($assistantId > 0) {
            $query->where('assistant_id', $assistantId);
        }

        return (float) $query->sum('cost');
    }

    /**
     * Create or replace the limit for one estate, one scope and one window.
     *
     * Upsert rather than insert because `(tenant_id, assistant_id, window)` is
     * unique: a second row for the same triple is a configuration mistake, and
     * the operator editing a limit means the same thing as the operator setting
     * it.
     *
     * `$assistantId = 0` is a whole-estate limit. A non-zero id is validated
     * against the estate: a limit naming an assistant from another estate would
     * never match a turn (the guard filters by the request's estate *and* the
     * turn's assistant) while still looking configured on the page.
     *
     * `tenant_id` and `assistant_id` are assigned here explicitly. The model's
     * `creating` hook is a default for the guard's own writes, not a rule that a
     * privileged surface cannot name an estate — `/ops` exists precisely to act
     * on another estate.
     */
    public function put(
        string $tenantId,
        string $window,
        float $limitUsd,
        float $warnAt,
        bool $enabled,
        int $assistantId = 0,
    ): Budget {
        if (! in_array($window, Budget::WINDOWS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown budget window "%s". Known windows: %s.',
                $window,
                implode(', ', Budget::WINDOWS)
            ));
        }

        $assistantId = max(0, $assistantId);

        if ($assistantId > 0 && ! $this->assistantBelongsTo($tenantId, $assistantId)) {
            throw new InvalidArgumentException(sprintf(
                'Assistant %d does not belong to estate "%s".',
                $assistantId,
                $tenantId
            ));
        }

        $budget = Budget::query()
            ->withoutGlobalScopes()
            ->firstOrNew(array(
                'tenant_id' => $tenantId,
                'assistant_id' => $assistantId,
                'window' => $window,
            ));

        $budget->tenant_id = $tenantId;
        $budget->assistant_id = $assistantId;
        $budget->window = $window;
        $budget->limit_usd = $limitUsd;
        $budget->warn_at = min(1.0, max(0.0, $warnAt));
        $budget->enabled = $enabled;
        $budget->save();

        return $budget;
    }

    /**
     * Whether the assistant exists in that estate.
     *
     * `withoutGlobalScopes()` because the id is scoped by the argument, not by
     * the request: on `/ops` the ambient estate is `default` and every other
     * estate's assistants would otherwise read as missing.
     */
    private function assistantBelongsTo(string $tenantId, int $assistantId): bool
    {
        return Assistant::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($assistantId)
            ->exists();
    }

    /**
     * Delete a limit.
     *
     * Deleting rather than disabling is a real distinction: a disabled row keeps
     * its numbers and its history, a deleted one stops appearing anywhere. The
     * page offers both, and the confirmation says which is which.
     */
    public function remove(Budget $budget): void
    {
        $budget->delete();
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
