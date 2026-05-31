<?php
/**
 * Queue client contract for the oOS AI orchestration core.
 *
 * Abstracts asynchronous job execution so that tools, the agentic loop,
 * and cron-like scheduling never depend on WordPress Action Scheduler,
 * WP-Cron, or any other framework's queue system.
 *
 *  - WordPress: wraps Action Scheduler or WP-Cron
 *  - Laravel:   wraps Laravel Queues (Redis, SQS, database)
 *  - Symfony:   wraps Symfony Messenger
 *  - Standalone: wraps a simple database-backed queue
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Contract;

use Oos\Core\Domain\Entity\JobStatus;

interface QueueClientInterface
{
    /**
     * Enqueue a job for asynchronous execution.
     *
     * @param string $handler  Fully-qualified class name or registered handler ID.
     * @param array  $payload  Serializable payload passed to the handler.
     * @param array  $options  Optional: group, priority, delay_seconds, unique, etc.
     *
     * @return string  Job ID for tracking.
     */
    public function enqueue(string $handler, array $payload, array $options = []): string;

    /**
     * Get the current status of a queued job.
     */
    public function getStatus(string $jobId): JobStatus;

    /**
     * Cancel a queued but not-yet-running job.
     *
     * @return bool  True if cancellation succeeded, false if job was already running/completed.
     */
    public function cancel(string $jobId): bool;

    /**
     * Schedule a recurring job.
     *
     * @param string $handler         Handler class name or ID.
     * @param array  $payload         Serializable payload.
     * @param string $cronExpression  Cron expression (e.g., '*/5 * * * *')
     *                                or interval string ('hourly', 'daily', 'twicedaily').
     *
     * @return string  Schedule ID for later unscheduling.
     */
    public function schedule(string $handler, array $payload, string $cronExpression): string;

    /**
     * Unschedule a previously registered recurring job.
     */
    public function unschedule(string $scheduleId): void;

    /**
     * List jobs filtered by status and optional constraints.
     *
     * @param array $filters  Optional: status, handler, group, user_id, assistant_id.
     * @param int   $limit    Maximum results (1–100).
     *
     * @return JobStatus[]
     */
    public function listJobs(array $filters = [], int $limit = 50): array;
}
