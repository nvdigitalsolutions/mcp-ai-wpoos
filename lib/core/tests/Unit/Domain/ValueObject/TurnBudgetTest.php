<?php

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\ValueObject;

use Nvoos\Core\Domain\ValueObject\TurnBudget;
use PHPUnit\Framework\TestCase;

/**
 * TurnBudget — the two ceilings that bound one turn (Proposal 149 §179).
 *
 * The load-bearing cases are the ordering and the off switch: a call over the
 * per-call cap names the more specific cause, and a zero ceiling must never
 * refuse anything — a control nobody configured has to be invisible.
 */
final class TurnBudgetTest extends TestCase
{
    public function test_no_ceiling_assesses_to_null(): void
    {
        $this->assertFalse((new TurnBudget())->enabled());
        $this->assertNull((new TurnBudget())->assess(999999, 999999, 999999));
    }

    public function test_a_call_over_the_per_call_ceiling_is_exhausted(): void
    {
        $verdict = (new TurnBudget(perCallTokens: 1000))->assess(0, 900, 200);

        $this->assertSame(TurnBudget::STATE_EXHAUSTED, $verdict['state']);
        $this->assertSame(TurnBudget::DIMENSION_CALL, $verdict['dimension']);
        $this->assertSame(1100, $verdict['projected']);
        $this->assertSame(1000, $verdict['limit']);
    }

    public function test_the_call_ceiling_is_named_before_the_turn_ceiling(): void
    {
        // Both are exceeded; the call is the more specific cause, the same
        // ordering BudgetGuard uses for an assistant limit before an estate one.
        $verdict = (new TurnBudget(perCallTokens: 100, perTurnTokens: 150))->assess(0, 200, 0);

        $this->assertSame(TurnBudget::DIMENSION_CALL, $verdict['dimension']);
    }

    public function test_a_turn_over_the_per_turn_ceiling_is_exhausted(): void
    {
        $verdict = (new TurnBudget(perTurnTokens: 1000))->assess(900, 50, 100);

        $this->assertSame(TurnBudget::STATE_EXHAUSTED, $verdict['state']);
        $this->assertSame(TurnBudget::DIMENSION_TURN, $verdict['dimension']);
        $this->assertSame(1050, $verdict['projected']);
        $this->assertSame(900, $verdict['spent']);
    }

    public function test_the_spend_so_far_counts_towards_the_next_call(): void
    {
        $budget = new TurnBudget(perTurnTokens: 200);

        $this->assertSame(
            TurnBudget::STATE_OK,
            $budget->assess(0, 100, 0)['state'],
            'An empty turn with a small prompt is fine.'
        );

        $this->assertSame(
            TurnBudget::STATE_EXHAUSTED,
            $budget->assess(150, 100, 0)['state'],
            'The same prompt after spending 150 must not be allowed.'
        );
    }

    public function test_the_warn_band_reports_without_refusing(): void
    {
        $verdict = (new TurnBudget(perTurnTokens: 1000, warnAt: 0.8))->assess(750, 100, 0);

        $this->assertSame(TurnBudget::STATE_WARN, $verdict['state']);
        $this->assertSame(85.0, $verdict['percent']);
    }

    public function test_under_every_ceiling_is_ok(): void
    {
        $verdict = (new TurnBudget(perCallTokens: 1000, perTurnTokens: 10000))->assess(100, 200, 100);

        $this->assertSame(TurnBudget::STATE_OK, $verdict['state']);
        $this->assertNotNull($verdict['percent']);
    }

    public function test_the_tighter_dimension_decides_the_warn_band(): void
    {
        // The call is at 60%, the turn at 96% — the turn is what is about to bite.
        $verdict = (new TurnBudget(perCallTokens: 1000, perTurnTokens: 1000))->assess(900, 50, 10);

        $this->assertSame(TurnBudget::STATE_WARN, $verdict['state']);
        $this->assertSame(TurnBudget::DIMENSION_TURN, $verdict['dimension']);
    }

    public function test_a_warn_band_outside_the_unit_interval_falls_back(): void
    {
        $this->assertSame(0.8, (TurnBudget::fromArray(array('warn_at' => 0)))->warnAt);
        $this->assertSame(0.8, (TurnBudget::fromArray(array('warn_at' => 2.0)))->warnAt);
        $this->assertSame(0.5, (TurnBudget::fromArray(array('warn_at' => 0.5)))->warnAt);
    }

    public function test_negative_ceilings_are_zero_not_unlimited_negatives(): void
    {
        $budget = TurnBudget::fromArray(array('per_call_tokens' => -5, 'per_turn_tokens' => -1));

        $this->assertFalse($budget->enabled());
    }
}
