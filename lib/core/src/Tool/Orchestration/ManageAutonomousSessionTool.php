<?php

/**
 * Start, pause, resume, stop or update an autonomous session.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Manage_Autonomous_Session` (Proposal 164 section
 * 164.78). The session object it writes is the subject of the other seven loop
 * tools, and `AutonomousSessionStoreInterface` documents its shape.
 *
 * ## What is pinned
 *
 * - **Transitions are permissive.** `pause` and `stop` accept any state, and
 *   `resume` turns even a completed session back into `active`; only an expired
 *   session is refused. That is the WordPress behaviour. The contract keeps it
 *   for parity and names the gap: a strict machine would refuse `resume` unless
 *   the session is paused, and the follow-up is a state-machine guard in the
 *   store rather than a silent behaviour change here.
 * - **`update` writes only the six known metrics.** An unknown key in `metrics`
 *   is ignored rather than stored, so a caller cannot invent session fields.
 * - **`last_activity` is always part of an update**, even when nothing else
 *   changed, which is what keeps a running session out of the expiry sweep.
 *
 * One adaptation: WordPress logs every execution to a history CCT from inside
 * this tool. Here the history is a separate optional contract that the loop
 * runner writes; a management tool that records itself would double-count the
 * calls the metrics tool is trying to measure.
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
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class ManageAutonomousSessionTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly AutonomousSessionStoreInterface $sessions,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'manage_autonomous_session';
    }

    public function getName(): string
    {
        return 'Manage Autonomous Session';
    }

    public function getDescription(): string
    {
        return 'Manage autonomous orchestration session lifecycle. Use this to start, pause, resume, or stop autonomous workflows with session state tracking.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'action'     => array(
                    'type'        => 'string',
                    'enum'        => array('start', 'pause', 'resume', 'stop', 'update'),
                    'description' => 'Action to perform on the session.',
                ),
                'plan_id'    => array(
                    'type'        => 'integer',
                    'description' => 'Task plan ID to start a session for (required for "start").',
                ),
                'session_id' => array(
                    'type'        => 'string',
                    'description' => 'Session ID (required for pause/resume/stop/update).',
                ),
                'config'     => array(
                    'type'        => 'object',
                    'description' => 'Session configuration for "start".',
                    'properties'  => array(
                        'max_iterations' => array(
                            'type'        => 'integer',
                            'description' => 'Maximum iterations allowed (default: 25).',
                            'default'     => AutonomousSessionStoreInterface::DEFAULT_MAX_ITERATIONS,
                        ),
                        'token_budget'   => array(
                            'type'        => 'integer',
                            'description' => 'Token budget for the session (default: 10000).',
                            'default'     => AutonomousSessionStoreInterface::DEFAULT_TOKEN_BUDGET,
                        ),
                        'assistant_id'   => array(
                            'type'        => 'integer',
                            'description' => 'Assistant ID to use.',
                        ),
                    ),
                ),
                'metrics'    => array(
                    'type'        => 'object',
                    'description' => 'Session metrics to update (for "update"). Unknown keys are ignored.',
                    'properties'  => array(
                        'iteration_count' => array(
                            'type'        => 'integer',
                            'description' => 'Current iteration number.',
                        ),
                        'token_usage'     => array(
                            'type'        => 'integer',
                            'description' => 'Total tokens used.',
                        ),
                        'success_rate'    => array(
                            'type'        => 'number',
                            'description' => 'Success rate percentage (0–100).',
                        ),
                        'error_count'     => array(
                            'type'        => 'integer',
                            'description' => 'Number of errors encountered.',
                        ),
                        'last_tool'       => array(
                            'type'        => 'string',
                            'description' => 'Last tool executed.',
                        ),
                        'last_error'      => array(
                            'type'        => 'string',
                            'description' => 'Last error message.',
                        ),
                    ),
                ),
            ),
            'required'             => array('action'),
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

        $action = $this->stringParam($arguments, 'action');

        return match ($action) {
            'start'  => $this->start($arguments, $context),
            'pause'  => $this->transition($arguments, AutonomousSessionStoreInterface::STATUS_PAUSED, 'paused'),
            'resume' => $this->resume($arguments),
            'stop'   => $this->stop($arguments),
            'update' => $this->update($arguments),
            default  => $this->errors->create(
                'unknown_action',
                sprintf('Unknown action: %s', $action)
            ),
        };
    }

    /**
     * @param array<string, mixed> $arguments Arguments.
     * @param array<string, mixed> $context   Execution context.
     */
    private function start(array $arguments, array $context): mixed
    {
        $planId = abs($this->intParam($arguments, 'plan_id', 0));

        if (0 === $planId) {
            return $this->errors->create('missing_plan_id', 'Missing required argument: plan_id.');
        }

        $config      = isset($arguments['config']) && is_array($arguments['config']) ? $arguments['config'] : array();
        $maxIter     = abs((int) ( $config['max_iterations'] ?? 0 ));
        $tokenBudget = abs((int) ( $config['token_budget'] ?? 0 ));
        $assistant   = abs((int) ( $config['assistant_id'] ?? 0 ));

        $maxIter     = $maxIter > 0 ? $maxIter : AutonomousSessionStoreInterface::DEFAULT_MAX_ITERATIONS;
        $tokenBudget = $tokenBudget > 0 ? $tokenBudget : AutonomousSessionStoreInterface::DEFAULT_TOKEN_BUDGET;

        try {
            $session = $this->sessions->create(
                array(
                    'plan_id'         => $planId,
                    'assistant_id'    => $assistant,
                    'user_id'         => (int) ($context['user_id'] ?? 0),
                    'status'          => AutonomousSessionStoreInterface::STATUS_ACTIVE,
                    'max_iterations'  => $maxIter,
                    'token_budget'    => $tokenBudget,
                    'health_status'   => AutonomousSessionStoreInterface::HEALTH_HEALTHY,
                    'circuit_breaker' => 'closed',
                    'success_rate'    => 100.0,
                )
            );
        } catch (\Throwable $error) {
            return $this->errors->create('session_store_failed', $error->getMessage());
        }

        return $this->success(
            sprintf(
                'Started autonomous session %s for task plan #%d',
                substr((string) $session['session_id'], 0, 8),
                $planId
            ),
            array(
                'session_id'     => $session['session_id'],
                'plan_id'        => $session['plan_id'],
                'status'         => $session['status'],
                'max_iterations' => $session['max_iterations'],
                'token_budget'   => $session['token_budget'],
                'expires_at'     => $session['expires_at'],
            )
        );
    }

    /**
     * @param array<string, mixed> $arguments Arguments.
     */
    private function transition(array $arguments, string $status, string $label): mixed
    {
        $sessionId = $this->stringParam($arguments, 'session_id');

        if ('' === $sessionId) {
            return $this->errors->create('missing_session_id', 'Missing required argument: session_id.');
        }

        $session = $this->sessions->find($sessionId);

        if (null === $session) {
            return $this->sessionNotFound($sessionId);
        }

        $updated = $this->sessions->update($sessionId, array('status' => $status));

        if (null === $updated) {
            return $this->errors->create('session_' . $label . '_failed', 'Failed to ' . $label . ' session.');
        }

        return $this->success(
            sprintf('%s session %s', ucfirst($label), substr($sessionId, 0, 8)),
            array('session_id' => $sessionId, 'status' => $updated['status'])
        );
    }

    /**
     * @param array<string, mixed> $arguments Arguments.
     */
    private function resume(array $arguments): mixed
    {
        $sessionId = $this->stringParam($arguments, 'session_id');

        if ('' === $sessionId) {
            return $this->errors->create('missing_session_id', 'Missing required argument: session_id.');
        }

        $session = $this->sessions->find($sessionId);

        if (null === $session) {
            return $this->sessionNotFound($sessionId);
        }

        $expires = strtotime((string) $session['expires_at']);

        if (false !== $expires && $expires < time()) {
            return $this->errors->create('session_expired', 'Session has expired.');
        }

        $updated = $this->sessions->update($sessionId, array('status' => AutonomousSessionStoreInterface::STATUS_ACTIVE));

        if (null === $updated) {
            return $this->errors->create('session_resume_failed', 'Failed to resume session.');
        }

        return $this->success(
            sprintf('Resumed session %s', substr($sessionId, 0, 8)),
            array('session_id' => $sessionId, 'status' => $updated['status'])
        );
    }

    /**
     * @param array<string, mixed> $arguments Arguments.
     */
    private function stop(array $arguments): mixed
    {
        $sessionId = $this->stringParam($arguments, 'session_id');

        if ('' === $sessionId) {
            return $this->errors->create('missing_session_id', 'Missing required argument: session_id.');
        }

        if (null === $this->sessions->find($sessionId)) {
            return $this->sessionNotFound($sessionId);
        }

        $updated = $this->sessions->update(
            $sessionId,
            array(
                'status'       => AutonomousSessionStoreInterface::STATUS_COMPLETED,
                'completed_at' => gmdate('c'),
            )
        );

        if (null === $updated) {
            return $this->errors->create('session_stop_failed', 'Failed to stop session.');
        }

        return $this->success(
            sprintf('Stopped session %s', substr($sessionId, 0, 8)),
            array('session_id' => $sessionId, 'status' => $updated['status'])
        );
    }

    /**
     * @param array<string, mixed> $arguments Arguments.
     */
    private function update(array $arguments): mixed
    {
        $sessionId = $this->stringParam($arguments, 'session_id');

        if ('' === $sessionId) {
            return $this->errors->create('missing_session_id', 'Missing required argument: session_id.');
        }

        $metrics = isset($arguments['metrics']) && is_array($arguments['metrics']) ? $arguments['metrics'] : array();

        if (array() === $metrics) {
            return $this->errors->create('missing_metrics', 'Missing required argument: metrics.');
        }

        if (null === $this->sessions->find($sessionId)) {
            return $this->sessionNotFound($sessionId);
        }

        $changes = array();

        if (isset($metrics['iteration_count'])) {
            $changes['iteration_count'] = abs((int) $metrics['iteration_count']);
        }

        if (isset($metrics['token_usage'])) {
            $changes['token_usage'] = abs((int) $metrics['token_usage']);
        }

        if (isset($metrics['success_rate'])) {
            $changes['success_rate'] = (float) $metrics['success_rate'];
        }

        if (isset($metrics['error_count'])) {
            $changes['error_count'] = abs((int) $metrics['error_count']);
        }

        if (isset($metrics['last_tool'])) {
            $changes['last_tool'] = Text::singleLine((string) $metrics['last_tool']);
        }

        if (isset($metrics['last_error'])) {
            $changes['last_error'] = Text::multiline((string) $metrics['last_error']);
        }

        $updated = $this->sessions->update($sessionId, $changes);

        if (null === $updated) {
            return $this->errors->create('session_update_failed', 'Failed to update session.');
        }

        return $this->success(
            sprintf('Updated session %s metrics', substr($sessionId, 0, 8)),
            array('session_id' => $sessionId, 'updated' => array_keys($changes))
        );
    }

    private function sessionNotFound(string $sessionId): mixed
    {
        return $this->errors->create(
            'session_not_found',
            sprintf('Session %s not found', $sessionId)
        );
    }
}
