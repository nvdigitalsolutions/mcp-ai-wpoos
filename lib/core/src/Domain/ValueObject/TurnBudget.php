<?php

/**
 * TurnBudget — the two token ceilings that bound one agentic turn.
 *
 * A budget is not a rate limit. A rate limit caps requests per unit of time; a
 * budget caps **input plus output tokens**, which is the quantity a runaway loop
 * actually spends. The distinction is the one every gateway draws, and it is why
 * this is separate from `RateLimiter`.
 *
 * Two dimensions, because they fail differently:
 *
 * - **per call** — one provider request. A single oversized prompt is a
 *   configuration problem (too much history, too many tools) and is worth
 *   naming as such.
 * - **per turn** — everything one `handleChat` invocation spends, including
 *   every loop iteration and every tool round-trip. This is the runaway bound:
 *   a model that keeps calling tools can otherwise spend `maxIterations ×
 *   perCall` before anything stops it.
 *
 * The per-call cap is reported first because it names the more specific cause, the
 * same ordering `BudgetGuard` uses for an assistant limit before an estate one.
 *
 * **Zero means off**, for both dimensions, matching `ToolQuotaStore::limitFor()`
 * and `BudgetStore::STATE_UNLIMITED`: a ceiling nobody configured must not
 * refuse anybody.
 *
 * @package Nvoos\Core
 * @since   2.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

final class TurnBudget
{
    /** Under every configured ceiling, and under the warn band. */
    public const STATE_OK = 'ok';

    /** Under every ceiling but at or over the warn band. */
    public const STATE_WARN = 'warn';

    /** Over a ceiling; the call must not be made. */
    public const STATE_EXHAUSTED = 'exhausted';

    /** The dimension that decided the verdict. */
    public const DIMENSION_CALL = 'call';

    public const DIMENSION_TURN = 'turn';

    /**
     * @param int   $perCallTokens Ceiling for one provider call; 0 disables it.
     * @param int   $perTurnTokens Ceiling for the whole turn; 0 disables it.
     * @param float $warnAt        Fraction of a ceiling at which to warn (0-1).
     */
    public function __construct(
        public readonly int $perCallTokens = 0,
        public readonly int $perTurnTokens = 0,
        public readonly float $warnAt = 0.8,
    ) {
    }

    /**
     * Build from a config/settings array.
     *
     * A warn band outside `(0, 1]` falls back to 0.8 rather than being clamped to
     * a meaningless edge: a band at 0 would warn on every call, and one above 1
     * would never warn.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $warn = (float) ( $config['warn_at'] ?? 0.8 );

        if ($warn <= 0.0 || $warn > 1.0) {
            $warn = 0.8;
        }

        return new self(
            \max(0, (int) ( $config['per_call_tokens'] ?? 0 )),
            \max(0, (int) ( $config['per_turn_tokens'] ?? 0 )),
            $warn,
        );
    }

    /**
     * Whether either ceiling is configured.
     */
    public function enabled(): bool
    {
        return $this->perCallTokens > 0 || $this->perTurnTokens > 0;
    }

    /**
     * Judge the next provider call.
     *
     * Called **before** the request leaves, on the estimate; the exact count is
     * only known from the response, so one call can still overshoot by its own
     * estimation error. That is the allowance every gateway makes, and the
     * alternative — charging after the fact — is a report rather than a ceiling.
     *
     * @param int $spentTokens           Tokens already spent in this turn.
     * @param int $estimatedPromptTokens Estimated size of the outgoing prompt.
     * @param int $requestedOutputTokens `max_tokens` for the call, if known.
     *
     * @return array{state: string, dimension: string, limit: int, projected: int, spent: int, percent: float|null}|null
     *         Null when neither ceiling is configured.
     */
    public function assess(
        int $spentTokens,
        int $estimatedPromptTokens,
        int $requestedOutputTokens = 0,
    ): ?array {
        if (! $this->enabled()) {
            return null;
        }

        $callProjected = \max(0, $estimatedPromptTokens) + \max(0, $requestedOutputTokens);
        $turnProjected = \max(0, $spentTokens) + $callProjected;

        if ($this->perCallTokens > 0 && $callProjected > $this->perCallTokens) {
            return $this->verdict(self::STATE_EXHAUSTED, self::DIMENSION_CALL, $this->perCallTokens, $callProjected, $spentTokens);
        }

        if ($this->perTurnTokens > 0 && $turnProjected > $this->perTurnTokens) {
            return $this->verdict(self::STATE_EXHAUSTED, self::DIMENSION_TURN, $this->perTurnTokens, $turnProjected, $spentTokens);
        }

        // Under every ceiling. Warn against whichever is closest to its edge, so
        // a turn that is about to hit the tighter bound says so first.
        $tightest = null;

        if ($this->perCallTokens > 0) {
            $tightest = $this->ratio($callProjected, $this->perCallTokens, self::DIMENSION_CALL, $spentTokens);
        }

        if ($this->perTurnTokens > 0) {
            $turnRatio = $this->ratio($turnProjected, $this->perTurnTokens, self::DIMENSION_TURN, $spentTokens);

            if (null === $tightest || ( $turnRatio['percent'] ?? 0.0 ) > ( $tightest['percent'] ?? 0.0 )) {
                $tightest = $turnRatio;
            }
        }

        if (null !== $tightest && ( $tightest['percent'] ?? 0.0 ) >= ( $this->warnAt * 100 )) {
            return $this->verdict(self::STATE_WARN, $tightest['dimension'], $tightest['limit'], $tightest['projected'], $spentTokens);
        }

        if (null === $tightest) {
            // Unreachable while `enabled()` is true, but a total function is
            // cheaper to reason about than a provably-dead branch.
            return $this->verdict(self::STATE_OK, self::DIMENSION_TURN, 0, 0, $spentTokens);
        }

        return $this->verdict(self::STATE_OK, $tightest['dimension'], $tightest['limit'], $tightest['projected'], $spentTokens);
    }

    /**
     * @return array{state: string, dimension: string, limit: int, projected: int, spent: int, percent: float|null}
     */
    private function ratio(int $projected, int $limit, string $dimension, int $spent): array
    {
        return $this->verdict(
            $projected > $limit ? self::STATE_EXHAUSTED : self::STATE_OK,
            $dimension,
            $limit,
            $projected,
            $spent,
        );
    }

    /**
     * @return array{state: string, dimension: string, limit: int, projected: int, spent: int, percent: float|null}
     */
    private function verdict(string $state, string $dimension, int $limit, int $projected, int $spent): array
    {
        return array(
            'state' => $state,
            'dimension' => $dimension,
            'limit' => $limit,
            'projected' => $projected,
            'spent' => $spent,
            'percent' => $limit > 0 ? \round(( $projected / $limit ) * 100, 1) : null,
        );
    }
}
