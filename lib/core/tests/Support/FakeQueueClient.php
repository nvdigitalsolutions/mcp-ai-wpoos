<?php

/**
 * A recording double for the queue port.
 *
 * Async export has exactly two questions that do not need a worker: was the job
 * enqueued with the right handler and payload, and what does the depth count see?
 * Both are answered by recording rather than executing.
 *
 * @package Nvoos\Core\Tests
 * @since   2.7.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Entity\JobStatus;

final class FakeQueueClient implements QueueClientInterface
{
    /**
     * Every enqueue call, as `[handler, payload, options]`.
     *
     * @var list<array{handler: string, payload: array<string, mixed>, options: array<string, mixed>}>
     */
    public array $enqueued = array();

    /**
     * What `listJobs()` returns.
     *
     * @var list<JobStatus>
     */
    public array $jobs = array();

    /**
     * What `getStatus()` returns; a queued job when unset.
     */
    public ?JobStatus $status = null;

    /**
     * Job id to hand back from `enqueue()`.
     */
    public string $jobId = 'job_test';

    public function enqueue(string $handler, array $payload, array $options = array()): string
    {
        $this->enqueued[] = array(
            'handler' => $handler,
            'payload' => $payload,
            'options' => $options,
        );

        return $this->jobId;
    }

    public function getStatus(string $jobId): JobStatus
    {
        return $this->status ?? new JobStatus(jobId: $jobId, status: 'queued');
    }

    public function cancel(string $jobId): bool
    {
        return true;
    }

    public function schedule(string $handler, array $payload, string $cronExpression): string
    {
        return 'schedule_test';
    }

    public function unschedule(string $scheduleId): void
    {
    }

    /**
     * @return list<JobStatus>
     */
    public function listJobs(array $filters = array(), int $limit = 50): array
    {
        return $this->jobs;
    }
}
