<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\OrchestrationControlInterface;

final class OrchestrationControl implements OrchestrationControlInterface
{
    private int $maxDepth = 15;

    public function isBudgetEnabled(): bool {
        if (\class_exists('WP_MCP_AI_Orchestration_Budget_Enforcement_Service')) {
            if (\class_exists('WP_MCP_AI_Settings_Registry') && \method_exists('WP_MCP_AI_Settings_Registry', 'get_setting')) {
                return (bool) \WP_MCP_AI_Settings_Registry::get_setting('enable_budget_management', true);
            }
        }
        return true;
    }

    public function getPreset(string $name): array {
        if (\class_exists('WP_MCP_AI_Orchestration_Presets')) {
            try {
                $presets = new \WP_MCP_AI_Orchestration_Presets();
                if (\method_exists($presets, 'get')) {
                    $result = $presets->get($name);
                    if ($result === null) { return ['found' => false]; }
                    return ['found' => true, 'preset' => \is_array($result) ? $result : []];
                }
            } catch (\Throwable $e) {}
        }
        return ['found' => false];
    }

    public function savePreset(string $name, array $config): array {
        if (\class_exists('WP_MCP_AI_Orchestration_Preset_Service')) {
            try {
                $service = new \WP_MCP_AI_Orchestration_Preset_Service();
                if (\method_exists($service, 'save')) {
                    $result = $service->save($name, $config);
                    if (\is_wp_error($result)) {
                        return ['success' => false, 'error' => $result->get_error_message()];
                    }
                    return ['success' => true];
                }
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }
        return ['success' => false, 'error' => 'Preset service unavailable'];
    }

    public function listPresets(): array {
        if (\class_exists('WP_MCP_AI_Orchestration_Presets')) {
            try {
                $presets = new \WP_MCP_AI_Orchestration_Presets();
                if (\method_exists($presets, 'list')) {
                    $result = $presets->list();
                    return \is_array($result) ? $result : [];
                }
            } catch (\Throwable $e) {}
        }
        return [];
    }

    public function healthCheck(): array {
        $alerts = [];
        $healthy = true;
        $depth = $this->getDepth();
        $activeJobs = 0;

        if (\class_exists('WP_MCP_AI_Orchestration_Health_Service')) {
            try {
                $service = new \WP_MCP_AI_Orchestration_Health_Service();
                if (\method_exists($service, 'check')) {
                    $result = $service->check();
                    if (\is_array($result)) {
                        $healthy = $result['healthy'] ?? $healthy;
                        $alerts = $result['alerts'] ?? $alerts;
                        $activeJobs = $result['active_jobs'] ?? $activeJobs;
                    }
                }
            } catch (\Throwable $e) {
                $alerts[] = ['message' => $e->getMessage()];
                $healthy = false;
            }
        }

        return ['healthy' => $healthy, 'depth' => $depth, 'active_jobs' => $activeJobs, 'alerts' => $alerts];
    }

    public function getDepth(): int {
        if (\class_exists('WP_MCP_AI_Orchestration_Depth_Scheduler')) {
            try {
                $scheduler = new \WP_MCP_AI_Orchestration_Depth_Scheduler();
                if (\method_exists($scheduler, 'get_current_depth')) {
                    return (int) $scheduler->get_current_depth();
                }
            } catch (\Throwable $e) {}
        }
        return 0;
    }

    public function setMaxDepth(int $maxIterations): void {
        $this->maxDepth = \max(1, \min(50, $maxIterations));
        if (\class_exists('WP_MCP_AI_Orchestration_Depth_Scheduler')) {
            try {
                $scheduler = new \WP_MCP_AI_Orchestration_Depth_Scheduler();
                if (\method_exists($scheduler, 'set_max_depth')) {
                    $scheduler->set_max_depth($this->maxDepth);
                }
            } catch (\Throwable $e) {}
        }
    }
}
