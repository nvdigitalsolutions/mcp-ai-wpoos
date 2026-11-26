<?php

/**
 * The dual-condition exit gate for an autonomous loop.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Check_Exit_Conditions` (Proposal 164 section
 * 164.78). The gate exists because a semantic "I'm done" is not evidence: exit
 * requires **both** the completion indicators at threshold **and** an explicit
 * `exit_signal`, with hard safety limits (iterations, tokens, expiry, breaker)
 * able to force a guarded exit regardless.
 *
 * That two-key rule matches the current guidance on agent termination: an
 * independent signal plus a hard cap, never a model's self-report alone.
 *
 * ## Pinned
 *
 * - **`force_check` is inert.** WordPress reads it and never uses it — the
 *   checks run unconditionally. The port accepts it and says so in the schema
 *   rather than silently dropping a parameter callers send.
 * - **The safety limits are OR-ed into `should_exit`,** so an expired or
 *   exhausted session exits even when the dual gate never passed; the
 *   recommendation distinguishes the clean exit from the guarded ones.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Domain\Contract\AutonomousSessionStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Tool\AbstractTool;

class CheckExitConditionsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly AutonomousSessionStoreInterface $sessions,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'check_exit_conditions';
    }

    public function getName(): string
    {
        return 'Check Exit Conditions';
    }

    public function getDescription(): string
    {
        return 'Check the dual-condition exit gate for autonomous loops. Returns true ONLY when BOTH completion indicators AND an explicit EXIT_SIGNAL are present. This prevents premature loop exits; hard limits force a guarded exit regardless.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'session_id'       => array(
                    'type'        => 'string',
                    'description' => 'Session ID to check.',
                ),
                'completion_score' => array(
                    'type'        => 'integer',
                    'description' => 'Number of completion indicators detected (from detect_completion_indicators).',
                ),
                'exit_signal'      => array(
                    'type'        => 'boolean',
                    'description' => 'Explicit EXIT_SIGNAL flag (set to true when work is verified complete).',
                ),
                'threshold'        => array(
                    'type'        => 'integer',
                    'description' => 'Minimum completion score required (default: 2).',
                    'default'     => 2,
                ),
                'force_check'      => array(
                    'type'        => 'boolean',
                    'description' => 'Accepted for compatibility; the WordPress tool reads it but never acts on it, and the checks always run.',
                    'default'     => false,
                ),
            ),
            'required'             => array('session_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'idempotent');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $sessionId = $this->stringParam($arguments, 'session_id');

        if ('' === $sessionId) {
            return $this->errors->create('missing_session_id', 'Missing required argument: session_id.');
        }

        $score    = $this->intParam($arguments, 'completion_score', 0);
        $signal   = $this->boolParam($arguments, 'exit_signal', false);
        $limit    = $this->intParam($arguments, 'threshold', 2);
        $limit    = $limit > 0 ? $limit : 2;
        $session  = $this->sessions->find($sessionId);

        if (null === $session) {
            return $this->errors->create(
                'session_not_found',
                sprintf('Session %s not found', $sessionId)
            );
        }

        $expires = strtotime((string) $session['expires_at']);
        $checks  = array(
            'completion_indicators_met' => $score >= $limit,
            'exit_signal_set'           => $signal,
            'max_iterations_reached'    => (int) $session['iteration_count'] >= (int) $session['max_iterations'],
            'token_budget_exceeded'     => (int) $session['token_usage'] >= (int) $session['token_budget'],
            'session_expired'           => false !== $expires && $expires < time(),
            'circuit_breaker_open'      => 'open' === $session['circuit_breaker'],
        );

        $dualGate = $checks['completion_indicators_met'] && $checks['exit_signal_set'];
        $shouldExit = $dualGate
            || $checks['max_iterations_reached']
            || $checks['token_budget_exceeded']
            || $checks['session_expired']
            || $checks['circuit_breaker_open'];

        $metrics = array(
            'iteration_count'      => $session['iteration_count'],
            'max_iterations'       => $session['max_iterations'],
            'iterations_remaining' => max(0, (int) $session['max_iterations'] - (int) $session['iteration_count']),
            'token_usage'          => $session['token_usage'],
            'token_budget'         => $session['token_budget'],
            'tokens_remaining'     => max(0, (int) $session['token_budget'] - (int) $session['token_usage']),
            'completion_score'     => $score,
            'completion_threshold' => $limit,
            'health_status'        => $session['health_status'],
            'circuit_breaker'      => $session['circuit_breaker'],
        );

        return $this->success(
            $shouldExit ? 'Exit conditions met.' : 'No exit conditions met; continue.',
            array(
                'session_id'       => $sessionId,
                'should_exit'      => $shouldExit,
                'exit_reason'      => $this->exitReason($checks, $dualGate),
                'dual_gate_passed' => $dualGate,
                'checks'           => $checks,
                'metrics'          => $metrics,
                'recommendation'   => $this->recommendation($shouldExit, $dualGate, $checks, $metrics),
            )
        );
    }

    /**
     * @param array<string, bool> $checks   Check results.
     * @param bool                $dualGate Whether both keys were present.
     */
    private function exitReason(array $checks, bool $dualGate): string
    {
        if ($dualGate) {
            return 'COMPLETED: Dual-condition gate passed (completion indicators + EXIT_SIGNAL)';
        }

        if ($checks['max_iterations_reached']) {
            return 'MAX_ITERATIONS: Maximum iteration limit reached';
        }

        if ($checks['token_budget_exceeded']) {
            return 'TOKEN_BUDGET: Token budget exhausted';
        }

        if ($checks['session_expired']) {
            return 'EXPIRED: Session timeout (24 hours)';
        }

        if ($checks['circuit_breaker_open']) {
            return 'CIRCUIT_BREAKER: Circuit breaker opened due to repeated failures';
        }

        return 'CONTINUE: No exit conditions met';
    }

    /**
     * @param array<string, bool>      $checks  Check results.
     * @param array<string, int|float> $metrics Derived metrics.
     */
    private function recommendation(bool $shouldExit, bool $dualGate, array $checks, array $metrics): string
    {
        if (! $shouldExit) {
            $warnings = array();

            if ($metrics['iterations_remaining'] < 5) {
                $warnings[] = sprintf('Only %d iterations remaining', $metrics['iterations_remaining']);
            }

            if ($metrics['tokens_remaining'] < 1000) {
                $warnings[] = sprintf('Only %d tokens remaining', $metrics['tokens_remaining']);
            }

            if ($checks['completion_indicators_met'] && ! $checks['exit_signal_set']) {
                $warnings[] = 'Completion indicators met but EXIT_SIGNAL not set. Verify work is complete.';
            }

            return array() !== $warnings
                ? 'CONTINUE_WITH_CAUTION: ' . implode('. ', $warnings)
                : 'CONTINUE: Session healthy, continue working.';
        }

        if ($dualGate) {
            return 'EXIT_CLEANLY: Work completed successfully. Both completion criteria met.';
        }

        if ($checks['max_iterations_reached']) {
            return 'EXIT_INCOMPLETE: Maximum iterations reached. Work may be incomplete.';
        }

        if ($checks['token_budget_exceeded']) {
            return 'EXIT_BUDGET: Token budget exhausted. Work may be incomplete.';
        }

        if ($checks['session_expired']) {
            return 'EXIT_TIMEOUT: Session expired after 24 hours. Resume with new session if needed.';
        }

        if ($checks['circuit_breaker_open']) {
            return 'EXIT_ERROR: Circuit breaker opened due to errors. Review logs and retry.';
        }

        return 'EXIT_UNKNOWN: Exit triggered for unknown reason.';
    }
}
