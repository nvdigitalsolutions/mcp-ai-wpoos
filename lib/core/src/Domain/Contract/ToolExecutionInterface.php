<?php
/**
 * Tool Execution — domain contract.
 *
 * Executes tools synchronously or asynchronously with load balancing,
 * speculative execution, and depth scheduling.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ToolExecutionInterface
{
    /**
     * Execute a tool synchronously.
     *
     * @return array{success: bool, result?: mixed, error?: string, duration_ms?: float}
     */
    public function executeSync(string $toolSlug, array $arguments, array $context = []): array;

    /**
     * Enqueue a tool for asynchronous execution.
     *
     * @return array{success: bool, job_id?: string, error?: string}
     */
    public function executeAsync(string $toolSlug, array $arguments, array $context = []): array;

    /**
     * Check the status of an async tool execution.
     *
     * @return array{status: string, result?: mixed, progress?: float, error?: string}
     */
    public function getAsyncStatus(string $jobId): array;

    /**
     * Get the recommended execution mode (sync/async) for a tool.
     *
     * @return string 'sync' or 'async'
     */
    public function getRecommendedMode(string $toolSlug): string;

    /**
     * Check current system capacity (0-100%).
     *
     * @return int Capacity percentage.
     */
    public function getCapacity(): int;
}
