<?php
/**
 * Immutable job status entity.
 *
 * Returned by QueueClientInterface to represent the current state
 * of an async job (tool execution, cron task, batch operation).
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

final readonly class JobStatus implements \JsonSerializable {

	/**
	 * @param string                  $jobId
	 * @param string                  $status          'queued', 'running', 'completed', 'failed', 'cancelled'
	 * @param array|null              $result          Serialized result payload (when completed).
	 * @param string|null             $error           Error message (when failed).
	 * @param \DateTimeImmutable|null $queuedAt
	 * @param \DateTimeImmutable|null $startedAt
	 * @param \DateTimeImmutable|null $completedAt
	 * @param int                     $attempts        Number of execution attempts so far.
	 */
	public function __construct(
		public string $jobId,
		public string $status,
		public ?array $result = null,
		public ?string $error = null,
		public ?\DateTimeImmutable $queuedAt = null,
		public ?\DateTimeImmutable $startedAt = null,
		public ?\DateTimeImmutable $completedAt = null,
		public int $attempts = 0,
	) {}

	/**
	 * Whether the job has reached a terminal state.
	 */
	public function isTerminal(): bool {
		return in_array( $this->status, array( 'completed', 'failed', 'cancelled' ), true );
	}

	/**
	 * Whether the job is currently being processed.
	 */
	public function isRunning(): bool {
		return 'running' === $this->status;
	}

	/**
	 * Whether the job completed successfully.
	 */
	public function isSuccessful(): bool {
		return 'completed' === $this->status;
	}

	public function jsonSerialize(): array {
		return array(
			'job_id'       => $this->jobId,
			'status'       => $this->status,
			'result'       => $this->result,
			'error'        => $this->error,
			'queued_at'    => $this->queuedAt?->format( 'c' ),
			'started_at'   => $this->startedAt?->format( 'c' ),
			'completed_at' => $this->completedAt?->format( 'c' ),
			'attempts'     => $this->attempts,
		);
	}
}
