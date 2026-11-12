<?php
/**
 * Laravel adapter: QueueClientInterface implementation.
 *
 * Wraps Laravel's Queue system (Redis, SQS, database, sync, beanstalkd)
 * behind the framework-agnostic QueueClientInterface. Every job gets a
 * UUID and a durable `nvoos_jobs` status row so tracking works on
 * drivers that do not expose a `jobs` table (Redis, SQS).
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Entity\JobStatus;
use Nvoos\Laravel\Jobs\NvoosToolJob;

class QueueClient implements QueueClientInterface {

	/**
	 * Laravel queue connection name (e.g., 'redis', 'sqs', 'database').
	 */
	private string $connection;

	/**
	 * Durable job status store.
	 */
	private JobStatusStore $statuses;

	/**
	 * @param string                $connection  Queue connection name. Defaults to the oOS config or the app default.
	 * @param JobStatusStore|null   $statuses    Optional status store (tests).
	 */
	public function __construct( string $connection = '', ?JobStatusStore $statuses = null ) {
		$this->connection = '' !== $connection
			? $connection
			: ( (string) config( 'oos.queue_connection', config( 'queue.default', 'database' ) ) ?: 'database' );
		$this->statuses   = $statuses ?? new JobStatusStore();
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
		$jobId = (string) Str::uuid();

		// Record the row before dispatch: a sync driver runs the job inside
		// push(), so the job lifecycle writes must find the row.
		$this->statuses->queue( $jobId, $handler, $payload );

		$job = new NvoosToolJob(
			jobId: $jobId,
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

		return $jobId;
	}

	/**
	 * Get the current status of a queued job.
	 *
	 * Reads the durable status store first. Falls back to Laravel's
	 * database queue table for legacy jobs when the store has no row.
	 *
	 * @param string $jobId  Job identifier.
	 * @return JobStatus
	 */
	public function getStatus( string $jobId ): JobStatus {
		$status = $this->statuses->get( $jobId );
		if ( null !== $status ) {
			return $status;
		}

		if ( Schema::hasTable( 'jobs' ) ) {
			$row = DB::table( 'jobs' )->where( 'id', $jobId )->first();

			if ( null !== $row ) {
				$attempts = $row->attempts ?? 0;

				return new JobStatus(
					jobId: $jobId,
					status: ! empty( $row->reserved_at ) ? 'running' : 'queued',
					attempts: $attempts,
				);
			}

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
		}

		return new JobStatus(
			jobId: $jobId,
			status: 'unknown',
		);
	}

	/**
	 * Cancel a queued but not-yet-running job.
	 *
	 * Marks the durable status row cancelled and, for the database
	 * driver, removes a still-queued row.
	 *
	 * @param string $jobId  Job identifier.
	 * @return bool
	 */
	public function cancel( string $jobId ): bool {
		$cancelled = $this->statuses->markCancelled( $jobId );

		if ( Schema::hasTable( 'jobs' ) ) {
			$deleted = DB::table( 'jobs' )
				->where( 'id', $jobId )
				->whereNull( 'reserved_at' )
				->delete();

			$cancelled = $cancelled || $deleted > 0;
		}

		return $cancelled;
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
	 * @param array<string, mixed> $filters  Optional: status, handler, queue, limit.
	 * @param int                  $limit    Maximum results (1–100).
	 * @return JobStatus[]
	 */
	public function listJobs( array $filters = array(), int $limit = 50 ): array {
		if ( $this->statuses->isAvailable() ) {
			return $this->statuses->listJobs( $filters, $limit );
		}

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
			$status = ! empty( $row->reserved_at ) ? 'running' : 'queued';
			$jobs[] = new JobStatus(
				jobId: (string) $row->id,
				status: $status,
				attempts: $row->attempts ?? 0,
			);
		}

		return $jobs;
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
