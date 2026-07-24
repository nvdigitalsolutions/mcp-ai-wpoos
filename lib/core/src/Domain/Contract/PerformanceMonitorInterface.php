<?php
/**
 * Performance Monitor — domain contract.
 *
 * Tracks and reports performance metrics for AI operations.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Performance monitoring and reporting.
 *
 * @since 2.0.0
 */
interface PerformanceMonitorInterface
{
    /**
     * Record a performance metric.
     *
     * @param string $metric    Metric name (e.g., 'chat_latency_ms', 'tool_execution_ms').
     * @param float  $value     Metric value.
     * @param array  $tags      Optional tags (provider, model, tool, etc.).
     */
    public function record(string $metric, float $value, array $tags = []): void;

    /**
     * Get aggregated metrics for a time period.
     *
     * @param string $metric    Metric name.
     * @param string $startDate Start date (YYYY-MM-DD).
     * @param string $endDate   End date (YYYY-MM-DD).
     *
     * @return array{count: int, avg: float, min: float, max: float, p95: float, p99: float}
     */
    public function getAggregate(string $metric, string $startDate, string $endDate): array;

    /**
     * Get a performance summary report.
     *
     * @param string $period 'hour', 'day', 'week', or 'month'.
     *
     * @return array{metrics: array, recommendations: array<int, string>}
     */
    public function getReport(string $period = 'day'): array;

    /**
     * Check whether performance is within healthy thresholds.
     *
     * @return array{healthy: bool, alerts: array<int, array{metric: string, value: float, threshold: float}>}
     */
    public function healthCheck(): array;
}
