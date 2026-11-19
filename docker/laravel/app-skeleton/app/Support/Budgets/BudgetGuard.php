<?php

namespace App\Support\Budgets;

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
            // One verdict, computed by the store, so this path and the
            // operator page's badge can never disagree about the state.
            $verdict = $this->store->verdict($budget);

            if (BudgetStore::STATE_EXHAUSTED === $verdict['state']) {
                throw new BudgetExceededException(
                    $verdict['window'],
                    $verdict['spend'],
                    $verdict['limit']
                );
            }

            if (BudgetStore::STATE_WARN === $verdict['state']) {
                Log::warning('budget.warn', array(
                    'tenant_id' => $tenantId,
                    'window' => $verdict['window'],
                    'spend' => round($verdict['spend'], 6),
                    'limit' => $verdict['limit'],
                    'percent' => round((float) $verdict['percent'], 1),
                ));
            }
        }
    }
}
