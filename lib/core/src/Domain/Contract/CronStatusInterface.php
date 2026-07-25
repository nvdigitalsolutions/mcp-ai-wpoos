<?php
/**
 * Cron Status Service — domain contract.
 *
 * Provides job status information for chat interfaces and admin dashboards.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Lightweight job status querying for UI consumption.
 *
 * @since 2.0.0
 */
interface CronStatusInterface
{
    /**
     * Get a summary of active and recently completed jobs.
     *
     * @param int         $userId      User ID to filter by (0 = all if admin).
     * @param int         $limit       Maximum jobs to return.
     * @param int|string|null $assistantId Optional assistant ID filter.
     *
     * @return array{active: int, pending: int, completed: int, failed: int, jobs: array<int, array>}
     */
    public function getSummary(int $userId = 0, int $limit = 10, $assistantId = null): array;

    /**
     * Get details for a specific job.
     *
     * @param string $jobId Job identifier.
     *
     * @return array{found: bool, job?: array}
     */
    public function getJob(string $jobId): array;

    /**
     * Get the count of jobs by status.
     *
     * @param int|string|null $assistantId Optional assistant ID filter.
     *
     * @return array{active: int, pending: int, completed: int, failed: int, total: int}
     */
    public function getCounts($assistantId = null): array;
}
