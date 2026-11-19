<?php

namespace App\Support\Budgets;

use App\Models\Budget;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * The estate budget check — Proposal 154.
 *
 * Runs **before** the provider is called, because a limit applied after the call
 * is a report rather than a budget. Entry-only means one turn can overshoot by
 * that turn's cost, which is the allowance every gateway makes; per-provider-call
 * enforcement needs a `lib/core` contract and is slice A.2.
 *
 * **Fails closed.** If spend cannot be read the request is refused rather than
 * allowed: an unreadable counter under-reports, and under-reporting is
 * over-spending — the one error a budget exists to prevent.
 */
final class BudgetGuard
{
    public function __construct(private readonly BudgetStore $store)
    {
    }

    /**
     * @throws BudgetExceededException     the estate is at or over a limit
     * @throws BudgetUnverifiableException spend could not be read
     */
    public function assertWithinBudget(): void
    {
        try {
            $this->check();
        } catch (BudgetExceededException | BudgetUnverifiableException $error) {
            throw $error;
        } catch (\Throwable $error) {
            report($error);

            throw new BudgetUnverifiableException(
                'The estate budget could not be verified.',
                0,
                $error
            );
        }
    }

    private function check(): void
    {
        $tenantId = TenantContext::current();

        foreach ($this->store->forCurrentEstate() as $budget) {
            $limit = (float) $budget->limit_usd;

            // A limit of zero is "no limit", not "block everything". A row that
            // stops every turn is a misconfiguration nobody would intend, and
            // failing it closed would take an estate offline over a typo.
            if ($limit <= 0.0) {
                continue;
            }

            $window = (string) $budget->window;
            $spend = $this->store->spend($tenantId, $window);

            if ($spend >= $limit) {
                throw new BudgetExceededException($window, $spend, $limit);
            }

            // `warn_at` is a fraction of the limit; a zero or unset value means
            // the deployment only wants the hard band, not a warning on every
            // single request.
            $warnAt = min(1.0, max(0.0, (float) $budget->warn_at));

            if ($warnAt > 0.0 && $spend >= $limit * $warnAt) {
                Log::warning('budget.warn', array(
                    'tenant_id' => $tenantId,
                    'window' => $window,
                    'spend' => round($spend, 6),
                    'limit' => $limit,
                    'percent' => round($spend / $limit * 100, 1),
                ));
            }
        }
    }

    /**
     * The windows this guard knows, for callers that build a message.
     *
     * @return list<string>
     */
    public static function windows(): array
    {
        return Budget::WINDOWS;
    }
}
