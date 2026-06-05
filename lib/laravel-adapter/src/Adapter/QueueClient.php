<?php
/**
 * Laravel adapter: QueueClientInterface implementation.
 *
 * Wraps Laravel's Queue system (Redis, SQS, database, sync, beanstalkd)
 * behind the framework-agnostic QueueClientInterface. Dispatches
 * generic jobs that the oOS core can track via the database queue
 * driver.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Entity\JobStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueClient implements QueueClientInterface {

	/**
	 * Laravel queue connection name (e.g., 'redis', 'sqs', 'database').
	 */
	private string $connection;

	/**
	 * @param string $connection  Queue connection name. Defaults to NVOOS_QUEUE_CONNECTION env or 'database'.
	 */
	public function __construct( string $connection = '' ) {
		$this->connection = '' !== $connection
			? $connection
			: ( env( 'NVOOS_QUEUE_CONNECTION', 'database' ) ?: 'database' );
	}

	/**
	 * Enqueue a job for asynchronous execution.
	 *
	 * Dispatches a generic job carrying the handler class and payload.
	 * For 'sync' connections, the job executes immediately but still
	 * returns a tracking ID.
	 *
	 * @param string               $handler  Fully-qualified class name or registered handler ID.
	 * @param array<string, mixed> $payload  Serializable payload passed to the handler.
	 * @param array<string, mixed> $options  Optional: group, delay_seconds, unique, priority.
	 *
	 * @return string  Job ID for tracking.
	 */
	public function enqueue( string $handler, array $payload, array $options = array() ): string {
		$queue = $options['queue'] ?? 'oos';
		$delay = isset( $options['delay_seconds'] ) ? (int) $options['delay_seconds'] : 0;

		$job = new \Nvoos\Laravel\Jobs\NvoosToolJob(
			handler: $handler,
			payload: $payload,
		);

		if ( $delay > 0 ) {
			Queue::connection( $this->connection )->later(
				now()->addSeconds( $delay ),
				$job,
				null,
				$queue,
			);
		} else {
			Queue::connection( $this->connection )->push(
				$job,
				null,
				$queue,
			);
		}

		// For database driver, the job ID is available from the jobs table.
		// For other drivers, return a composite identifier.
		$jobId = $this->getLastJobId( $queue );

		return $jobId ?? 'job_' . uniqid( '', true );
	}

	/**
	 * Get the current status of a queued job.
	 *
	 * Queries the database jobs table for status. For non-database
	 * drivers, job tracking is best-effort.
	 *
	 * @param string $jobId  Job identifier.
	 * @return JobStatus
	 */
	public function getStatus( string $jobId ): JobStatus {
		if ( ! Schema::hasTable( 'jobs' ) ) {
			return new JobStatus(
				jobId: $jobId,
				status: 'completed',
			);
		}

		$row = DB::table( 'jobs' )->where( 'id', $jobId )->first();

		if ( null === $row ) {
			// Job not in the queue — check failed_jobs table.
			if ( Schema::hasTable( 'failed_jobs' ) ) {
				$failed = DB::table( 'failed_jobs' )->where( 'uuid', $jobId )->first();
				if ( null !== $failed ) {
					return new JobStatus(
						jobId: $jobId,
						status: 'failed',
						error: $failed->exception ?? 'Job failed.',
						attempts: $failed->attempts ?? 0,
					);
				}
			}

			return new JobStatus(
				jobId: $jobId,
				status: 'completed',
			);
		}

		$attempts = $row->attempts ?? 0;

		// If reserved_at is set, the job is currently running.
		if ( ! empty( $row->reserved_at ) ) {
			return new JobStatus(
				jobId: $jobId,
				status: 'running',
				attempts: $attempts,
			);
		}

		return new JobStatus(
			jobId: $jobId,
			status: 'queued',
			attempts: $attempts,
		);
	}

	/**
	 * Cancel a queued but not-yet-running job.
	 *
	 * Only works for database driver. Other drivers return false.
	 *
	 * @param string $jobId  Job identifier.
	 * @return bool
	 */
	public function cancel( string $jobId ): bool {
		if ( ! Schema::hasTable( 'jobs' ) ) {
			return false;
		}

		$deleted = DB::table( 'jobs' )->where( 'id', $jobId )->delete();

		return $deleted > 0;
	}

	/**
	 * Schedule a recurring job.
	 *
	 * Stores the schedule definition in the database so it can be picked
	 * up by a Laravel console kernel schedule() call.
	 *
	 * @param string               $handler        Handler class name.
	 * @param array<string, mixed> $payload        Serializable payload.
	 * @param string               $cronExpression Cron expression or interval string.
	 *
	 * @return string  Schedule ID for later unscheduling.
	 */
	public function schedule( string $handler, array $payload, string $cronExpression ): string {
		$scheduleId = 'schedule_' . uniqid( '', true );

		$this->ensureSchedulesTable();

		DB::table( 'nvoos_schedules' )->insert( array(
			'id'              => $scheduleId,
			'handler'         => $handler,
			'payload'         => json_encode( $payload, JSON_UNESCAPED_SLASHES ),
			'cron_expression' => $cronExpression,
			'created_at'      => now(),
			'updated_at'      => now(),
		) );

		return $scheduleId;
	}

	/**
	 * Unschedule a previously registered recurring job.
	 *
	 * @param string $scheduleId  Schedule identifier.
	 */
	public function unschedule( string $scheduleId ): void {
		if ( Schema::hasTable( 'nvoos_schedules' ) ) {
			DB::table( 'nvoos_schedules' )->where( 'id', $scheduleId )->delete();
		}
	}

	/**
	 * List jobs filtered by status and optional constraints.
	 *
	 * Queries the database jobs table for queued jobs. Non-database
	 * drivers return an empty array.
	 *
	 * @param array<string, mixed> $filters  Optional: status, queue, limit.
	 * @param int                  $limit    Maximum results (1–100).
	 * @return JobStatus[]
	 */
	public function listJobs( array $filters = array(), int $limit = 50 ): array {
		if ( ! Schema::hasTable( 'jobs' ) ) {
			return array();
		}

		$query = DB::table( 'jobs' );

		if ( ! empty( $filters['queue'] ) ) {
			$query->where( 'queue', $filters['queue'] );
		}

		$limit = min( 100, max( 1, $limit ) );
		$rows  = $query->orderBy( 'id', 'desc' )->limit( $limit )->get();

		$jobs = array();
		foreach ( $rows as $row ) {
			$status  = ! empty( $row->reserved_at ) ? 'running' : 'queued';
			$jobs[]  = new JobStatus(
				jobId: (string) $row->id,
				status: $status,
				attempts: $row->attempts ?? 0,
			);
		}

		return $jobs;
	}

	// ─── Private helpers ──────────────────────────────────────────────

	/**
	 * Get the ID of the most recently inserted job for a given queue.
	 */
	private function getLastJobId( string $queue ): ?string {
		if ( ! Schema::hasTable( 'jobs' ) ) {
			return null;
		}

		$row = DB::table( 'jobs' )
			->where( 'queue', $queue )
			->orderBy( 'id', 'desc' )
			->first();

		return $row ? (string) $row->id : null;
	}

	/**
	 * Create the schedules table if it doesn't exist.
	 */
	private function ensureSchedulesTable(): void {
		if ( Schema::hasTable( 'nvoos_schedules' ) ) {
			return;
		}

		Schema::create( 'nvoos_schedules', function ( $table ) {
			$table->string( 'id' )->primary();
			$table->string( 'handler' );
			$table->text( 'payload' )->nullable();
			$table->string( 'cron_expression' );
			$table->timestamps();
		} );
	}
}
