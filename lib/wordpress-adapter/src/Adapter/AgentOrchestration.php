<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\AgentOrchestrationInterface;

final class AgentOrchestration implements AgentOrchestrationInterface
{
    private $orchestrator;

    public function __construct() {
        if (\class_exists('WP_MCP_AI_Agent_Team_Orchestrator')) {
            $this->orchestrator = new \WP_MCP_AI_Agent_Team_Orchestrator();
        }
    }

    public function composeTeam(array $taskRequirements): array {
        if (!$this->orchestrator) {
            return ['success' => false, 'error' => 'Agent orchestration unavailable'];
        }
        try {
            $result = $this->orchestrator->compose_team($taskRequirements);
            if (\is_wp_error($result)) {
                return ['success' => false, 'error' => $result->get_error_message()];
            }
            return ['success' => true, 'team' => \is_array($result) ? $result : []];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function executeWorkflow(string $teamId, array $workflow, array $context = []): array {
        if (!$this->orchestrator || !\method_exists($this->orchestrator, 'execute_workflow')) {
            return ['success' => false, 'error' => 'Workflow execution unavailable'];
        }
        try {
            $result = $this->orchestrator->execute_workflow($teamId, $workflow, $context);
            if (\is_wp_error($result)) {
                return ['success' => false, 'error' => $result->get_error_message()];
            }
            return ['success' => true, 'results' => \is_array($result) ? $result : []];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getTeamStatus(string $teamId): array {
        if (!$this->orchestrator || !\method_exists($this->orchestrator, 'get_team_status')) {
            return ['found' => false];
        }
        try {
            $status = $this->orchestrator->get_team_status($teamId);
            if ($status === false || $status === null) { return ['found' => false]; }
            return ['found' => true, 'team' => \is_array($status) ? $status : []];
        } catch (\Throwable $e) {
            return ['found' => false];
        }
    }

    public function delegateToAgent(string $teamId, string $agentId, array $task, array $context = []): array {
        if (!$this->orchestrator || !\method_exists($this->orchestrator, 'delegate_to_agent')) {
            return ['success' => false, 'error' => 'Agent delegation unavailable'];
        }
        try {
            $result = $this->orchestrator->delegate_to_agent($teamId, $agentId, $task, $context);
            if (\is_wp_error($result)) {
                return ['success' => false, 'error' => $result->get_error_message()];
            }
            return ['success' => true, 'result' => $result];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
