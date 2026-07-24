<?php
/**
 * Agent Orchestration — domain contract.
 *
 * Composes agent teams, coordinates workflows, and tracks execution.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface AgentOrchestrationInterface
{
    /**
     * Compose a team for a task.
     *
     * @return array{success: bool, team?: array, error?: string}
     */
    public function composeTeam(array $taskRequirements): array;

    /**
     * Execute a workflow with an assembled team.
     *
     * @return array{success: bool, results?: array, error?: string}
     */
    public function executeWorkflow(string $teamId, array $workflow, array $context = []): array;

    /**
     * Get the status of an agent team.
     *
     * @return array{found: bool, team?: array}
     */
    public function getTeamStatus(string $teamId): array;

    /**
     * Delegate a subtask to an agent within a team.
     *
     * @return array{success: bool, result?: mixed, error?: string}
     */
    public function delegateToAgent(string $teamId, string $agentId, array $task, array $context = []): array;
}
