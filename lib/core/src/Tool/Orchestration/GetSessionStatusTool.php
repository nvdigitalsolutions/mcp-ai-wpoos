<?php

/**
 * Real-time status of one autonomous session.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Session_Status` (Proposal 164 section 164.78).
 * Everything is derived from the stored session; nothing here writes state.
 *
 * `include_plan` is accepted but cannot be honoured yet: the task-plan store is
 * the next slice's subject. WordPress omits `task_plan` when its plan tool is
 * unavailable, and so does this port — an absent key says "no plan reader",
 * while an empty object would read as "the plan is empty".
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

class GetSessionStatusTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly AutonomousSessionStoreInterface $sessions,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_session_status';
    }

    public function getName(): string
    {
        return 'Get Session Status';
    }

    public function getDescription(): string
    {
        return 'Get real-time status of an autonomous orchestration session including health metrics, progress, and resource usage.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'session_id'   => array(
                    'type'        => 'string',
                    'description' => 'Session ID to retrieve.',
                ),
                'include_plan' => array(
                    'type'        => 'boolean',
                    'description' => 'Include task plan details (default: false). Omitted when the host has no task-plan reader.',
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
            return $this->errors->create(
                'missing_session_id',
                'Missing required argument: session_id.'
            );
        }

        $session = $this->sessions->find($sessionId);

        if (null === $session) {
            return $this->errors->create(
                'session_not_found',
                sprintf('Session %s not found', $sessionId)
            );
        }

        $metrics = $this->metrics($session);

        return $this->success(
            sprintf('Session %s is %s.', substr($sessionId, 0, 8), (string) $session['status']),
            array(
                'session_id' => $sessionId,
                'status'     => $session['status'],
                'health'     => array(
                    'status'          => $session['health_status'],
                    'circuit_breaker' => $session['circuit_breaker'],
                    'success_rate'    => $session['success_rate'],
                    'error_count'     => $session['error_count'],
                ),
                'progress'   => array(
                    'iteration_count'      => $session['iteration_count'],
                    'max_iterations'       => $session['max_iterations'],
                    'iterations_remaining' => $metrics['iterations_remaining'],
                    'progress_percent'     => $metrics['iteration_progress'],
                    'completion_score'     => $session['completion_score'],
                ),
                'resources'  => array(
                    'token_usage'      => $session['token_usage'],
                    'token_budget'     => $session['token_budget'],
                    'tokens_remaining' => $metrics['tokens_remaining'],
                    'budget_percent'   => $metrics['token_progress'],
                ),
                'timing'     => array(
                    'started_at'     => $session['started_at'],
                    'last_activity'  => $session['last_activity'],
                    'expires_at'     => $session['expires_at'],
                    'elapsed'        => $metrics['elapsed_time'],
                    'time_remaining' => $metrics['time_remaining'],
                ),
                'metadata'   => array(
                    'plan_id'      => $session['plan_id'],
                    'assistant_id' => $session['assistant_id'],
                    'user_id'      => $session['user_id'],
                    'last_tool'    => '' !== (string) $session['last_tool'] ? $session['last_tool'] : null,
                    'last_error'   => '' !== (string) $session['last_error'] ? $session['last_error'] : null,
                ),
                'indicators' => array(
                    'exit_signal'     => (bool) $session['exit_signal'],
                    'should_continue' => $this->shouldContinue($session, $metrics),
                    'status_summary'  => $this->summary($session, $metrics),
                ),
            )
        );
    }

    /**
     * @param array<string, mixed> $session Session.
     *
     * @return array<string, int|float>
     */
    private function metrics(array $session): array
    {
        $remainingIterations = max(0, (int) $session['max_iterations'] - (int) $session['iteration_count']);
        $remainingTokens     = max(0, (int) $session['token_budget'] - (int) $session['token_usage']);
        $iterationProgress   = (int) $session['max_iterations'] > 0
            ? ( (int) $session['iteration_count'] / (int) $session['max_iterations'] ) * 100
            : 0;
        $tokenProgress       = (int) $session['token_budget'] > 0
            ? ( (int) $session['token_usage'] / (int) $session['token_budget'] ) * 100
            : 0;

        $started = strtotime((string) $session['started_at']);
        $expires = strtotime((string) $session['expires_at']);
        $now     = time();

        return array(
            'iterations_remaining' => $remainingIterations,
            'tokens_remaining'     => $remainingTokens,
            'iteration_progress'   => round($iterationProgress, 1),
            'token_progress'       => round($tokenProgress, 1),
            'elapsed_time'         => false === $started ? 0 : $now - $started,
            'time_remaining'       => false === $expires ? 0 : max(0, $expires - $now),
        );
    }

    /**
     * @param array<string, mixed>     $session Session.
     * @param array<string, int|float> $metrics Derived metrics.
     */
    private function shouldContinue(array $session, array $metrics): bool
    {
        if (AutonomousSessionStoreInterface::STATUS_ACTIVE !== $session['status']) {
            return false;
        }

        if ('open' === $session['circuit_breaker']) {
            return false;
        }

        if ($metrics['iterations_remaining'] <= 0 || $metrics['tokens_remaining'] <= 0) {
            return false;
        }

        return $metrics['time_remaining'] > 0;
    }

    /**
     * @param array<string, mixed>     $session Session.
     * @param array<string, int|float> $metrics Derived metrics.
     */
    private function summary(array $session, array $metrics): string
    {
        if (AutonomousSessionStoreInterface::STATUS_COMPLETED === $session['status']) {
            return '✅ Session completed successfully';
        }

        if (AutonomousSessionStoreInterface::STATUS_PAUSED === $session['status']) {
            return '⏸️ Session paused';
        }

        if (AutonomousSessionStoreInterface::STATUS_FAILED === $session['status']) {
            return '❌ Session failed';
        }

        if (AutonomousSessionStoreInterface::HEALTH_CRITICAL === $session['health_status']) {
            return '🚨 Critical health issues detected';
        }

        if ('open' === $session['circuit_breaker']) {
            return '🔴 Circuit breaker opened - session paused';
        }

        if ($metrics['iterations_remaining'] <= 0) {
            return '⚠️ Maximum iterations reached';
        }

        if ($metrics['tokens_remaining'] <= 0) {
            return '⚠️ Token budget exhausted';
        }

        if ($metrics['time_remaining'] <= 0) {
            return '⏰ Session expired';
        }

        if ($metrics['iterations_remaining'] < 5) {
            return sprintf('⚠️ Running low on iterations (%d remaining)', $metrics['iterations_remaining']);
        }

        if ($metrics['token_progress'] > 80) {
            return sprintf('⚠️ Token budget %.1f%% used', $metrics['token_progress']);
        }

        if (AutonomousSessionStoreInterface::HEALTH_WARNING === $session['health_status']) {
            return '⚠️ Health warnings detected';
        }

        return sprintf(
            '✅ Active and healthy - %d iterations, %.1f%% tokens used',
            $session['iteration_count'],
            $metrics['token_progress']
        );
    }
}
