<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\ToolExecutionInterface;

final class ToolExecution implements ToolExecutionInterface
{
    private $orchestrator;
    private $asyncExecutor;
    private $loadMonitor;

    public function __construct() {
        if (\class_exists('WP_MCP_AI_Tool_Execution_Orchestrator')) {
            $this->orchestrator = new \WP_MCP_AI_Tool_Execution_Orchestrator();
        }
        if (\class_exists('WP_MCP_AI_Tool_Async_Executor')) {
            $this->asyncExecutor = new \WP_MCP_AI_Tool_Async_Executor();
        }
        if (\class_exists('WP_MCP_AI_Tool_Load_Monitor')) {
            $this->loadMonitor = new \WP_MCP_AI_Tool_Load_Monitor();
        }
    }

    public function executeSync(string $toolSlug, array $arguments, array $context = []): array {
        if (!$this->orchestrator || !\method_exists($this->orchestrator, 'execute_tool')) {
            return ['success' => false, 'error' => 'Tool execution unavailable'];
        }
        try {
            $start = \microtime(true);
            $result = $this->orchestrator->execute_tool($toolSlug, $arguments, $context);
            $duration = \round((\microtime(true) - $start) * 1000, 2);
            if (\is_wp_error($result)) {
                return ['success' => false, 'error' => $result->get_error_message(), 'duration_ms' => $duration];
            }
            return ['success' => true, 'result' => $result, 'duration_ms' => $duration];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function executeAsync(string $toolSlug, array $arguments, array $context = []): array {
        if (!$this->asyncExecutor || !\method_exists($this->asyncExecutor, 'enqueue')) {
            return ['success' => false, 'error' => 'Async execution unavailable'];
        }
        try {
            $jobId = $this->asyncExecutor->enqueue($toolSlug, $arguments, $context);
            return ['success' => true, 'job_id' => (string) $jobId];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getAsyncStatus(string $jobId): array {
        if (!$this->asyncExecutor || !\method_exists($this->asyncExecutor, 'get_status')) {
            return ['status' => 'unknown'];
        }
        try {
            $status = $this->asyncExecutor->get_status($jobId);
            return \is_array($status) ? $status : ['status' => (string) $status];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    public function getRecommendedMode(string $toolSlug): string {
        if ($this->orchestrator && \method_exists($this->orchestrator, 'get_recommended_mode')) {
            return $this->orchestrator->get_recommended_mode($toolSlug);
        }
        return 'sync';
    }

    public function getCapacity(): int {
        if ($this->loadMonitor && \method_exists($this->loadMonitor, 'get_capacity')) {
            return (int) $this->loadMonitor->get_capacity();
        }
        return 100;
    }
}
