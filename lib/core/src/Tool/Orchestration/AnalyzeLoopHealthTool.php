<?php

/**
 * Diagnose one autonomous loop for runaway behaviour.
 *
 * Port of `WP_MCP_AI_Tool_Analyze_Loop_Health` (Proposal 164 section 164.78).
 * The tool is the second detector in the loop group: where
 * {@see ConfigureCircuitBreakerTool} tunes the breaker and
 * {@see AutonomousCircuitBreaker} trips it on measured failure counts, this tool
 * reads the *shape* of the recent work — repetition, cascades, stuck tools,
 * severe errors, resource pressure and velocity — and can trip the same breaker
 * on a conclusive verdict.
 *
 * ## The rules, as WordPress applies them
 *
 * - **Repeated actions**: in the last 3 actions, at most 2 distinct tools.
 * - **Error cascade**: 3 or more failures in the last 5 actions.
 * - **Stuck pattern**: one tool 3+ times in the last 5, or one identical error
 *   twice in the last 5.
 * - **Error severity**: a critical pattern (fatal, out of memory, segmentation
 *   fault, access/authentication denied, permission denied) in the current error.
 * - **Resource pressure**: more than 80% of either the iteration cap or the
 *   token budget consumed.
 * - **Velocity**: success rate over 70% is good, over 40% moderate, else poor.
 *
 * Health is `critical` when severity is critical, the cascade has 4+ errors, or
 * the loop is stuck; `warning` on any other detection; otherwise `healthy`. The
 * breaker opens on that critical verdict. WordPress also spells out a second
 * rule — a stuck pattern once the session has recorded 5 errors — but a stuck
 * pattern already makes health critical, so the second test can never change the
 * outcome. It is kept for parity and named here rather than quietly dropped.
 *
 * ## Pinned, and one correction
 *
 * - **No actions means a poor velocity band.** A first health check with no
 *   `last_actions` reports `warning`, because the velocity rule reads 0% as
 *   poor. This is the WordPress arithmetic; nothing acts on it (`should_pause`
 *   stays false and the recommendation still says the loop is good).
 * - **`error_rate` is always present.** WordPress omits it from the early return
 *   for fewer than 3 actions. The key is now always emitted, so the shape does
 *   not change with the input; the value is 0 when there is no evidence.
 * - **Unnamed actions are ignored.** WordPress indexes `tool` unconditionally
 *   and would warn on a malformed entry; here an action without a tool name
 *   contributes nothing to the repetition or stuck tests.
 * - **A zero cap cannot fatal.** WordPress divides by `max_iterations` and
 *   `token_budget` directly. A stored 0 would be a `DivisionByZeroError`; here
 *   it reads as 0% pressure when nothing was used and 100% when something was.
 * - **Opening the breaker goes through the breaker.** WordPress writes the bare
 *   string onto the session, leaving its own `circuit_breaker_open` flag and
 *   cooldown stamp stale. `forceOpen()` writes the state, the flag and
 *   `circuit_opened_at` together, so the next `allowExecution()` honours the
 *   cooldown instead of half-opening immediately.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Application\Orchestration\AutonomousCircuitBreaker;
use Nvoos\Core\Domain\Contract\AutonomousSessionStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class AnalyzeLoopHealthTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly AutonomousSessionStoreInterface $sessions,
        private readonly SettingsStoreInterface $settings,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'analyze_loop_health';
    }

    public function getName(): string
    {
        return 'Analyze Loop Health';
    }

    public function getDescription(): string
    {
        return 'Analyze autonomous loop health to detect runaway behaviors, repeated failures, and circuit breaker conditions. Returns health status and recommendations.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'session_id'    => array(
                    'type'        => 'string',
                    'description' => 'Session ID to analyze.',
                ),
                'last_actions'  => array(
                    'type'        => 'array',
                    'description' => 'Recent actions/tool calls for pattern detection.',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'tool'    => array(
                                'type'        => 'string',
                                'description' => 'Tool name.',
                            ),
                            'success' => array(
                                'type'        => 'boolean',
                                'description' => 'Whether the action succeeded.',
                            ),
                            'error'   => array(
                                'type'        => 'string',
                                'description' => 'Error message if it failed.',
                            ),
                        ),
                    ),
                ),
                'current_error' => array(
                    'type'        => 'string',
                    'description' => 'Current error message to analyze.',
                ),
            ),
            'required'             => array('session_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $sessionId = $this->stringParam($arguments, 'session_id');

        if ('' === $sessionId) {
            return $this->errors->create('missing_session_id', 'Missing required argument: session_id.');
        }

        $session = $this->sessions->find($sessionId);

        if (null === $session) {
            return $this->errors->create(
                'session_not_found',
                sprintf('Session %s not found', $sessionId)
            );
        }

        $actions = $this->actions($arguments);
        $currentError = isset($arguments['current_error']) && is_scalar($arguments['current_error'])
            ? Text::multiline((string) $arguments['current_error'])
            : '';

        $analysis = array(
            'repeated_actions'  => $this->detectRepeatedActions($actions),
            'error_cascade'     => $this->detectErrorCascade($actions),
            'stuck_pattern'     => $this->detectStuckPattern($actions),
            'error_severity'    => $this->analyzeErrorSeverity($currentError),
            'resource_pressure' => $this->checkResourcePressure($session),
            'velocity'          => $this->calculateVelocity($actions),
        );

        $healthStatus = $this->determineHealthStatus($analysis);
        $shouldOpen   = $this->shouldOpen($analysis, $session);
        $breaker      = new AutonomousCircuitBreaker($sessionId, $this->sessions, $this->settings);

        if ($shouldOpen) {
            $breaker->forceOpen();
        }

        if ($healthStatus !== (string) $session['health_status']) {
            $this->sessions->update($sessionId, array('health_status' => $healthStatus));
        }

        return $this->success(
            sprintf('Loop health for session %s: %s.', substr($sessionId, 0, 8), $healthStatus),
            array(
                'session_id'      => $sessionId,
                'health_status'   => $healthStatus,
                'circuit_breaker' => $breaker->state(),
                'analysis'        => $analysis,
                'warnings'        => $this->warnings($analysis),
                'recommendations' => $this->recommendations($analysis, $healthStatus),
                'should_pause'    => $shouldOpen,
            )
        );
    }

    /**
     * Normalise the caller's action log.
     *
     * @param array<string, mixed> $arguments Arguments.
     *
     * @return list<array{tool: string, success: bool, error: string}>
     */
    private function actions(array $arguments): array
    {
        $raw = isset($arguments['last_actions']) && is_array($arguments['last_actions'])
            ? $arguments['last_actions']
            : array();

        $actions = array();

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $actions[] = array(
                'tool'    => isset($entry['tool']) && is_scalar($entry['tool'])
                    ? Text::singleLine((string) $entry['tool'])
                    : '',
                'success' => ! empty($entry['success']),
                'error'   => isset($entry['error']) && is_scalar($entry['error'])
                    ? Text::multiline((string) $entry['error'])
                    : '',
            );
        }

        return $actions;
    }

    /**
     * @param list<array{tool: string, success: bool, error: string}> $actions Normalised actions.
     *
     * @return array{detected: bool, pattern: string|null, count: int}
     */
    private function detectRepeatedActions(array $actions): array
    {
        if (count($actions) < 3) {
            return array('detected' => false, 'pattern' => null, 'count' => 0);
        }

        $tools = array();

        foreach (array_slice($actions, -3) as $action) {
            if ('' !== $action['tool']) {
                $tools[] = $action['tool'];
            }
        }

        if (count($tools) < 3 || count(array_unique($tools)) > 2) {
            return array('detected' => false, 'pattern' => null, 'count' => 0);
        }

        $counts = array_count_values($tools);
        arsort($counts);

        $pattern = (string) array_key_first($counts);

        return array(
            'detected' => true,
            'pattern'  => $pattern,
            'count'    => (int) $counts[ $pattern ],
        );
    }

    /**
     * @param list<array{tool: string, success: bool, error: string}> $actions Normalised actions.
     *
     * @return array{detected: bool, error_count: int, error_rate: float}
     */
    private function detectErrorCascade(array $actions): array
    {
        if (count($actions) < 3) {
            return array('detected' => false, 'error_count' => 0, 'error_rate' => 0.0);
        }

        $recent = array_slice($actions, -5);
        $errors = 0;

        foreach ($recent as $action) {
            if (! $action['success']) {
                ++$errors;
            }
        }

        $total = count($recent);

        return array(
            'detected'    => $errors >= 3,
            'error_count' => $errors,
            'error_rate'  => $total > 0 ? ( $errors / $total ) * 100 : 0.0,
        );
    }

    /**
     * @param list<array{tool: string, success: bool, error: string}> $actions Normalised actions.
     *
     * @return array{detected: bool, reason: string|null}
     */
    private function detectStuckPattern(array $actions): array
    {
        if (count($actions) < 5) {
            return array('detected' => false, 'reason' => null);
        }

        $tools  = array();
        $errors = array();

        foreach (array_slice($actions, -5) as $action) {
            if ('' !== $action['tool']) {
                $tools[] = $action['tool'];
            }

            if ('' !== $action['error']) {
                $errors[] = $action['error'];
            }
        }

        foreach (array_count_values($tools) as $tool => $count) {
            if ($count >= 3) {
                return array(
                    'detected' => true,
                    'reason'   => sprintf('Tool "%s" called %d times in last 5 actions', $tool, $count),
                );
            }
        }

        foreach (array_count_values($errors) as $error => $count) {
            if ($count >= 2) {
                return array(
                    'detected' => true,
                    'reason'   => 'Same error repeated: ' . $this->truncate((string) $error, 50),
                );
            }
        }

        return array('detected' => false, 'reason' => null);
    }

    /**
     * @return array{severity: string, critical: bool, message?: string}
     */
    private function analyzeErrorSeverity(string $error): array
    {
        if ('' === $error) {
            return array('severity' => 'none', 'critical' => false);
        }

        $patterns = array(
            '/fatal error/i',
            '/out of memory/i',
            '/segmentation fault/i',
            '/access denied/i',
            '/permission denied/i',
            '/authentication failed/i',
        );

        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $error)) {
                return array(
                    'severity' => 'critical',
                    'critical' => true,
                    'message'  => 'Critical error detected',
                );
            }
        }

        return array('severity' => 'normal', 'critical' => false);
    }

    /**
     * @param array<string, mixed> $session Session.
     *
     * @return array{iteration_pressure: float, token_pressure: float, high_pressure: bool}
     */
    private function checkResourcePressure(array $session): array
    {
        $iterations = max(0, (int) $session['iteration_count']);
        $maxIter    = (int) $session['max_iterations'];
        $tokens     = max(0, (int) $session['token_usage']);
        $budget     = (int) $session['token_budget'];

        $iterationPressure = $maxIter > 0
            ? ( $iterations / $maxIter ) * 100
            : ( $iterations > 0 ? 100.0 : 0.0 );
        $tokenPressure     = $budget > 0
            ? ( $tokens / $budget ) * 100
            : ( $tokens > 0 ? 100.0 : 0.0 );

        return array(
            'iteration_pressure' => $iterationPressure,
            'token_pressure'     => $tokenPressure,
            'high_pressure'      => $iterationPressure > 80 || $tokenPressure > 80,
        );
    }

    /**
     * @param list<array{tool: string, success: bool, error: string}> $actions Normalised actions.
     *
     * @return array{success_rate: float, velocity: string}
     */
    private function calculateVelocity(array $actions): array
    {
        $total = count($actions);

        if (0 === $total) {
            return array('success_rate' => 0.0, 'velocity' => 'poor');
        }

        $successes = 0;

        foreach ($actions as $action) {
            if ($action['success']) {
                ++$successes;
            }
        }

        $rate = ( $successes / $total ) * 100;

        return array(
            'success_rate' => $rate,
            'velocity'     => $rate > 70 ? 'good' : ( $rate > 40 ? 'moderate' : 'poor' ),
        );
    }

    /**
     * @param array<string, mixed> $analysis Analysis results.
     */
    private function determineHealthStatus(array $analysis): string
    {
        if (
            $analysis['error_severity']['critical']
            || $analysis['error_cascade']['error_count'] >= 4
            || $analysis['stuck_pattern']['detected']
        ) {
            return AutonomousSessionStoreInterface::HEALTH_CRITICAL;
        }

        if (
            $analysis['error_cascade']['detected']
            || $analysis['repeated_actions']['detected']
            || $analysis['resource_pressure']['high_pressure']
            || 'poor' === $analysis['velocity']['velocity']
        ) {
            return AutonomousSessionStoreInterface::HEALTH_WARNING;
        }

        return AutonomousSessionStoreInterface::HEALTH_HEALTHY;
    }

    /**
     * @param array<string, mixed> $analysis Analysis results.
     * @param array<string, mixed> $session  Session.
     */
    private function shouldOpen(array $analysis, array $session): bool
    {
        if (AutonomousSessionStoreInterface::HEALTH_CRITICAL === $this->determineHealthStatus($analysis)) {
            return true;
        }

        return $analysis['stuck_pattern']['detected'] && (int) $session['error_count'] >= 5;
    }

    /**
     * @param array<string, mixed> $analysis Analysis results.
     *
     * @return list<string>
     */
    private function warnings(array $analysis): array
    {
        $warnings = array();

        if ($analysis['repeated_actions']['detected']) {
            $warnings[] = sprintf(
                'Repetitive pattern: Tool "%s" called %d times',
                $analysis['repeated_actions']['pattern'],
                $analysis['repeated_actions']['count']
            );
        }

        if ($analysis['error_cascade']['detected']) {
            $warnings[] = sprintf(
                'Error cascade: %d errors in last 5 actions (%.1f%% error rate)',
                $analysis['error_cascade']['error_count'],
                $analysis['error_cascade']['error_rate']
            );
        }

        if ($analysis['stuck_pattern']['detected']) {
            $warnings[] = 'Stuck pattern: ' . $analysis['stuck_pattern']['reason'];
        }

        if ($analysis['resource_pressure']['high_pressure']) {
            $warnings[] = sprintf(
                'Resource pressure: %.1f%% iterations, %.1f%% tokens used',
                $analysis['resource_pressure']['iteration_pressure'],
                $analysis['resource_pressure']['token_pressure']
            );
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $analysis     Analysis results.
     * @param string               $healthStatus Health verdict.
     *
     * @return list<string>
     */
    private function recommendations(array $analysis, string $healthStatus): array
    {
        $recommendations = array();

        if (AutonomousSessionStoreInterface::HEALTH_CRITICAL === $healthStatus) {
            $recommendations[] = 'STOP: Critical issues detected. Review errors and restart session.';
        }

        if ($analysis['stuck_pattern']['detected']) {
            $recommendations[] = 'Try a different approach or tool to break the stuck pattern.';
        }

        if ($analysis['error_cascade']['detected']) {
            $recommendations[] = 'Pause and investigate root cause of repeated errors.';
        }

        if ($analysis['repeated_actions']['detected']) {
            $recommendations[] = 'Vary your approach to avoid repetitive loops.';
        }

        if ($analysis['resource_pressure']['high_pressure']) {
            $recommendations[] = 'Consider wrapping up current iteration - resources running low.';
        }

        if (array() === $recommendations) {
            $recommendations[] = 'Loop health is good. Continue working.';
        }

        return $recommendations;
    }

    /**
     * Truncate without splitting a multibyte character.
     */
    private function truncate(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        return substr($value, 0, $length);
    }
}
