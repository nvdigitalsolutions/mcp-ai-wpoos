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
 * **Two scopes.** A whole-estate limit applies to every turn; an
 * assistant-scoped limit applies only to that assistant's turns. A turn is
 * therefore judged against at most two configured limits, and the tighter one
 * wins — there is no "borrow from the estate" behaviour, for the same reason
 * proposal 154 §5 refuses to let a monthly limit relax a daily one.
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
     * @param int $assistantId the assistant whose turn is being checked; `0`
     *                         when the caller cannot name one, which judges the
     *                         turn against whole-estate limits only
     *
     * @throws BudgetExceededException     the estate or the assistant is at or over a limit
     * @throws BudgetUnverifiableException spend could not be read
     */
    public function assertWithinBudget(int $assistantId = 0): void
    {
        try {
            $this->check(max(0, $assistantId));
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

    private function check(int $assistantId): void
    {
        $tenantId = TenantContext::current();

        foreach ($this->appliesTo($assistantId) as $budget) {
            // One verdict, computed by the store, so this path and the
            // operator page's badge can never disagree about the state.
            $verdict = $this->store->verdict($budget);

            if (BudgetStore::STATE_EXHAUSTED === $verdict['state']) {
                throw new BudgetExceededException(
                    $verdict['window'],
                    $verdict['spend'],
                    $verdict['limit'],
                    $verdict['assistant_id']
                );
            }

            if (BudgetStore::STATE_WARN === $verdict['state']) {
                Log::warning('budget.warn', array(
                    'tenant_id' => $tenantId,
                    'window' => $verdict['window'],
                    'assistant_id' => $verdict['assistant_id'],
                    'spend' => round($verdict['spend'], 6),
                    'limit' => $verdict['limit'],
                    'percent' => round((float) $verdict['percent'], 1),
                ));
            }
        }
    }

    /**
     * The budgets that judge this turn, tightest scope first.
     *
     * An assistant limit is evaluated before the estate limit because it names
     * the more specific cause: "assistant #12 is over" is actionable on the
     * assistant, while "the estate is over" is actionable on the estate. When
     * both are exhausted the operator should hear the one they can fix without
     * touching everyone else.
     *
     * @return list<\App\Models\Budget>
     */
    private function appliesTo(int $assistantId): array
    {
        $applicable = array();

        foreach ($this->store->forCurrentEstate() as $budget) {
            $scope = (int) $budget->assistant_id;

            // `0` is the whole estate and applies to every turn; a non-zero id
            // applies only to its own assistant. Without this an assistant
            // limit for somebody else's agent would refuse every turn.
            if ($scope > 0 && $scope !== $assistantId) {
                continue;
            }

            $applicable[] = $budget;
        }

        usort(
            $applicable,
            static fn ($a, $b): int => (int) $b->assistant_id <=> (int) $a->assistant_id
        );

        return $applicable;
    }
}
