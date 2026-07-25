<?php
/**
 * WordPress Adapter — Performance Monitor.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\PerformanceMonitorInterface;

final class PerformanceMonitor implements PerformanceMonitorInterface
{
    public function record(string $metric, float $value, array $tags = []): void
    {
        if (!\class_exists('WP_MCP_AI_Performance_Monitor_Service')) {
            return;
        }

        try {
            $service = new \WP_MCP_AI_Performance_Monitor_Service();
            if (\method_exists($service, 'record_metric')) {
                $service->record_metric($metric, $value, $tags);
            }
        } catch (\Throwable $e) {
            // Best-effort recording.
        }
    }

    public function getAggregate(string $metric, string $startDate, string $endDate): array
    {
        if (!\class_exists('WP_MCP_AI_Performance_Monitor_Service')) {
            return ['count' => 0, 'avg' => 0.0, 'min' => 0.0, 'max' => 0.0, 'p95' => 0.0, 'p99' => 0.0];
        }

        try {
            $service = new \WP_MCP_AI_Performance_Monitor_Service();
            if (\method_exists($service, 'get_aggregate')) {
                /** @var array $result */
                $result = $service->get_aggregate($metric, $startDate, $endDate);
                return \is_array($result) ? $result : $this->emptyAggregate();
            }
        } catch (\Throwable $e) {
            // Fall through.
        }

        return $this->emptyAggregate();
    }

    public function getReport(string $period = 'day'): array
    {
        if (!\class_exists('WP_MCP_AI_Performance_Reporting_Service')) {
            return ['metrics' => [], 'recommendations' => ['Performance reporting unavailable']];
        }

        try {
            $service = new \WP_MCP_AI_Performance_Reporting_Service();
            if (\method_exists($service, 'generate_report')) {
                /** @var array $result */
                $result = $service->generate_report($period);
                return \is_array($result) ? $result : ['metrics' => [], 'recommendations' => []];
            }
        } catch (\Throwable $e) {
            // Fall through.
        }

        return ['metrics' => [], 'recommendations' => []];
    }

    public function healthCheck(): array
    {
        $result = ['healthy' => true, 'alerts' => []];

        // Check chat latency.
        $latency = $this->getAggregate('chat_latency_ms', \gmdate('Y-m-d', \strtotime('-1 hour')), \gmdate('Y-m-d'));
        if ($latency['p95'] > 30000) {
            $result['healthy'] = false;
            $result['alerts'][] = ['metric' => 'chat_latency_ms', 'value' => $latency['p95'], 'threshold' => 30000.0];
        }

        // Check tool execution latency.
        $toolLatency = $this->getAggregate('tool_execution_ms', \gmdate('Y-m-d', \strtotime('-1 hour')), \gmdate('Y-m-d'));
        if ($toolLatency['p95'] > 60000) {
            $result['healthy'] = false;
            $result['alerts'][] = ['metric' => 'tool_execution_ms', 'value' => $toolLatency['p95'], 'threshold' => 60000.0];
        }

        return $result;
    }

    private function emptyAggregate(): array
    {
        return ['count' => 0, 'avg' => 0.0, 'min' => 0.0, 'max' => 0.0, 'p95' => 0.0, 'p99' => 0.0];
    }
}
