<?php
/**
 * Laravel adapter: durable job status store.
 *
 * Laravel's database queue exposes job state only through the `jobs`
 * table. Redis/SQS drivers do not, so the adapter records its own
 * lifecycle rows in `nvoos_jobs`. The job itself writes the transitions.
 *
 * @package Nvoos\Laravel
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Domain\Entity\JobStatus;

class JobStatusStore {

	/**
	 * Status table name.
	 */
	private const TABLE = 'nvoos_jobs';

	/**
	 * Whether the status table exists.
	 */
	public function isAvailable(): bool {
		return Schema::hasTable( self::TABLE );
	}

	/**
	 * Count jobs by status.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		if ( ! $this->isAvailable() ) {
			return array();
		}

		return DB::table( self::TABLE )
			->select( 'status', DB::raw( 'count(*) as total' ) )
			->groupBy( 'status' )
			->pluck( 'total', 'status' )
			->map( static fn ( $total ) => (int) $total )
			->all();
	}

	/**
	 * Record a newly queued job.
	 *
	 * @param array<string, mixed> $payload Job payload.
	 */
	public function queue( string $jobId, string $handler, array $payload ): void {
		if ( ! $this->isAvailable() ) {
			return;
		}

		DB::table( self::TABLE )->insert( array(
			'id'         => $jobId,
			'handler'    => $handler,
			'payload'    => json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'status'     => 'queued',
			'attempts'   => 0,
			'queued_at'  => now(),
		) );
	}

	/**
	 * Mark a job as running.
	 */
	public function markRunning( string $jobId, int $attempts = 1 ): void {
		if ( ! $this->isAvailable() ) {
			return;
		}

		DB::table( self::TABLE )->where( 'id', $jobId )->update( array(
			'status'     => 'running',
			'attempts'   => $attempts,
			'started_at' => now(),
		) );
	}

	/**
	 * Mark a job as completed.
	 *
	 * @param array<string, mixed>|null $result Optional serializable result.
	 */
	public function markCompleted( string $jobId, ?array $result = null ): void {
		if ( ! $this->isAvailable() ) {
			return;
		}

		DB::table( self::TABLE )->where( 'id', $jobId )->update( array(
			'status'       => 'completed',
			'result'       => null !== $result ? json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : null,
			'completed_at' => now(),
		) );
	}

	/**
	 * Mark a job as failed.
	 */
	public function markFailed( string $jobId, \Throwable $error ): void {
		if ( ! $this->isAvailable() ) {
			return;
		}

		DB::table( self::TABLE )->where( 'id', $jobId )->update( array(
			'status'       => 'failed',
			'error'        => $error->getMessage(),
			'completed_at' => now(),
		) );
	}

	/**
	 * Mark a job as cancelled.
	 *
	 * @return bool True when a queued/running row was updated.
	 */
	public function markCancelled( string $jobId ): bool {
		if ( ! $this->isAvailable() ) {
			return false;
		}

		$updated = DB::table( self::TABLE )
			->where( 'id', $jobId )
			->whereIn( 'status', array( 'queued', 'running' ) )
			->update( array(
				'status'       => 'cancelled',
				'completed_at' => now(),
			) );

		return $updated > 0;
	}

	/**
	 * Read a single job status.
	 */
	public function get( string $jobId ): ?JobStatus {
		if ( ! $this->isAvailable() ) {
			return null;
		}

		$row = DB::table( self::TABLE )->where( 'id', $jobId )->first();
		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * List jobs, newest first.
	 *
	 * @param array<string, mixed> $filters Optional status/handler filters.
	 * @return JobStatus[]
	 */
	public function listJobs( array $filters = array(), int $limit = 50 ): array {
		if ( ! $this->isAvailable() ) {
			return array();
		}

		$query = DB::table( self::TABLE );

		if ( ! empty( $filters['status'] ) ) {
			$query->where( 'status', (string) $filters['status'] );
		}

		if ( ! empty( $filters['handler'] ) ) {
			$query->where( 'handler', (string) $filters['handler'] );
		}

		$limit = min( 100, max( 1, $limit ) );
		$rows  = $query->orderBy( 'queued_at', 'desc' )->limit( $limit )->get();

		$jobs = array();
		foreach ( $rows as $row ) {
			$jobs[] = $this->hydrate( $row );
		}

		return $jobs;
	}

	/**
	 * Read a single raw job row (handler/payload for diagnostics and retry).
	 */
	public function row( string $jobId ): ?object {
		if ( ! $this->isAvailable() ) {
			return null;
		}

		return DB::table( self::TABLE )->where( 'id', $jobId )->first();
	}

	/**
	 * List raw job rows, newest first.
	 *
	 * @param array<string, mixed> $filters Optional status/handler filters.
	 * @return array<int, object>
	 */
	public function rows( array $filters = array(), int $limit = 50 ): array {
		if ( ! $this->isAvailable() ) {
			return array();
		}

		$query = DB::table( self::TABLE );

		if ( ! empty( $filters['status'] ) ) {
			$query->where( 'status', (string) $filters['status'] );
		}

		if ( ! empty( $filters['handler'] ) ) {
			$query->where( 'handler', (string) $filters['handler'] );
		}

		$limit = min( 100, max( 1, $limit ) );

		return $query->orderBy( 'queued_at', 'desc' )->limit( $limit )->get()->all();
	}

	/**
	 * Reset a failed job to queued so it can be re-dispatched.
	 */
	public function markQueued( string $jobId ): bool {
		if ( ! $this->isAvailable() ) {
			return false;
		}

		return DB::table( self::TABLE )
			->where( 'id', $jobId )
			->update( array(
				'status'       => 'queued',
				'error'        => null,
				'started_at'   => null,
				'completed_at' => null,
			) ) > 0;
	}

	/**
	 * Dismiss a failed dead-letter row by moving it to cancelled.
	 */
	public function dismiss( string $jobId ): bool {
		if ( ! $this->isAvailable() ) {
			return false;
		}

		return DB::table( self::TABLE )
			->where( 'id', $jobId )
			->where( 'status', 'failed' )
			->update( array(
				'status'       => 'cancelled',
				'completed_at' => now(),
			) ) > 0;
	}

	/**
	 * Convert a table row to the domain entity.
	 */
	private function hydrate( object $row ): JobStatus {
		$result = null;
		if ( ! empty( $row->result ) ) {
			$decoded = json_decode( (string) $row->result, true );
			$result  = is_array( $decoded ) ? $decoded : null;
		}

		return new JobStatus(
			jobId: (string) $row->id,
			status: (string) $row->status,
			result: $result,
			error: $row->error ?? null,
			queuedAt: $this->date( $row->queued_at ?? null ),
			startedAt: $this->date( $row->started_at ?? null ),
			completedAt: $this->date( $row->completed_at ?? null ),
			attempts: (int) ( $row->attempts ?? 0 ),
		);
	}

	/**
	 * Convert a database timestamp to an immutable date.
	 */
	private function date( mixed $value ): ?\DateTimeImmutable {
		if ( empty( $value ) ) {
			return null;
		}

		try {
			return new \DateTimeImmutable( (string) $value );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
