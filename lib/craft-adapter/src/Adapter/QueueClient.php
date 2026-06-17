<?php
/**
 * Craft adapter: QueueClientInterface implementation.
 *
 * Wraps Craft's Yii Queue component behind the framework-agnostic
 * QueueClientInterface. Supports any Yii Queue driver (Redis, DB,
 * Beanstalk, SQS). Jobs are dispatched via `Craft::$app->queue->push()`.
 *
 * @package Nvoos\Craft
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Craft\Adapter;

use Craft;
use craft\queue\BaseJob;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Entity\JobStatus;

class QueueClient implements QueueClientInterface {

	/**
	 * Default TTR (time to reserve) in seconds for queue jobs.
	 */
	private int $defaultTtr;

	/**
	 * @param int $defaultTtr  Time-to-reserve in seconds (default 300 = 5 min).
	 */
	public function __construct( int $defaultTtr = 300 ) {
		$this->defaultTtr = $defaultTtr;
	}

	/**
	 * Enqueue a job for asynchronous execution.
	 *
	 * Pushes a Craft queue job wrapping the oOS handler and payload.
	 *
	 * @param string               $handler  Fully-qualified class name or registered handler ID.
	 * @param array<string, mixed> $payload  Serializable payload passed to the handler.
	 * @param array<string, mixed> $options  Optional: delay_seconds, priority, ttr.
	 *
	 * @return string  Job ID for tracking.
	 */
	public function enqueue( string $handler, array $payload, array $options = array() ): string {
		$priority = $options['priority'] ?? 1024;
		$delay    = isset( $options['delay_seconds'] ) ? (int) $options['delay_seconds'] : 0;
		$ttr      = $options['ttr'] ?? $this->defaultTtr;

		$job = new \Nvoos\Craft\Jobs\NvoosToolJob( array(
			'handler' => $handler,
			'payload' => $payload,
		) );

		$jobId = Craft::$app->queue
			->ttr( $ttr )
			->priority( $priority )
			->delay( $delay )
			->push( $job );

		return (string) ( $jobId ?? uniqid( 'job_', true ) );
	}

	/**
	 * Get the current status of a queued job.
	 *
	 * Yii Queue does not provide a uniform status API across drivers.
	 * For the DB driver, queries the queue table. For other drivers,
	 * returns 'completed' as a best-effort default.
	 *
	 * @param string $jobId  Job identifier.
	 * @return JobStatus
	 */
	public function getStatus( string $jobId ): JobStatus {
		// Try the DB driver approach.
		if ( Craft::$app->getDb()->tableExists( '{{%queue}}' ) ) {
			$row = ( new \craft\db\Query() )
				->from( '{{%queue}}' )
				->where( array( 'id' => $jobId ) )
				->one();

			if ( ! empty( $row ) ) {
				$attempts = (int) ( $row['attempt'] ?? 0 );
				$status   = ! empty( $row['timeUpdated'] ) && ! empty( $row['dateReserved'] )
					? 'running'
					: 'queued';

				return new JobStatus(
					jobId: $jobId,
					status: $status,
					attempts: $attempts,
				);
			}

			// Check failed jobs.
			if ( Craft::$app->getDb()->tableExists( '{{%queue_failed}}' ) ) {
				$failed = ( new \craft\db\Query() )
					->from( '{{%queue_failed}}' )
					->where( array( 'id' => $jobId ) )
					->one();

				if ( ! empty( $failed ) ) {
					return new JobStatus(
						jobId: $jobId,
						status: 'failed',
						error: $failed['error'] ?? 'Job failed.',
					);
				}
			}
		}

		// Job not found — assume completed.
		return new JobStatus(
			jobId: $jobId,
			status: 'completed',
		);
	}

	/**
	 * Cancel a queued but not-yet-running job.
	 *
	 * Only works for DB driver. Other drivers return false.
	 *
	 * @param string $jobId  Job identifier.
	 * @return bool
	 */
	public function cancel( string $jobId ): bool {
		if ( ! Craft::$app->getDb()->tableExists( '{{%queue}}' ) ) {
			return false;
		}

		$deleted = Craft::$app->getDb()->createCommand()
			->delete( '{{%queue}}', array( 'id' => $jobId ) )
			->execute();

		return $deleted > 0;
	}

	/**
	 * Schedule a recurring job.
	 *
	 * Stores the schedule definition in a database table. A Craft
	 * console command or cron job should poll this table and push
	 * jobs on schedule.
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

		Craft::$app->getDb()->createCommand()
			->insert( '{{%nvoos_schedules}}', array(
				'id'              => $scheduleId,
				'handler'         => $handler,
				'payload'         => json_encode( $payload, JSON_UNESCAPED_SLASHES ),
				'cron_expression' => $cronExpression,
				'dateCreated'     => gmdate( 'Y-m-d H:i:s' ),
				'dateUpdated'     => gmdate( 'Y-m-d H:i:s' ),
				'uid'             => \Craft::$app->security->generateRandomString( 32 ),
			) )
			->execute();

		return $scheduleId;
	}

	/**
	 * Unschedule a previously registered recurring job.
	 *
	 * @param string $scheduleId  Schedule identifier.
	 */
	public function unschedule( string $scheduleId ): void {
		if ( Craft::$app->getDb()->tableExists( '{{%nvoos_schedules}}' ) ) {
			Craft::$app->getDb()->createCommand()
				->delete( '{{%nvoos_schedules}}', array( 'id' => $scheduleId ) )
				->execute();
		}
	}

	/**
	 * List jobs filtered by status and optional constraints.
	 *
	 * Queries the queue table for pending/running jobs.
	 *
	 * @param array<string, mixed> $filters  Optional: status, queue, limit.
	 * @param int                  $limit    Maximum results (1–100).
	 * @return JobStatus[]
	 */
	public function listJobs( array $filters = array(), int $limit = 50 ): array {
		if ( ! Craft::$app->getDb()->tableExists( '{{%queue}}' ) ) {
			return array();
		}

		$query = ( new \craft\db\Query() )
			->from( '{{%queue}}' )
			->orderBy( array( 'id' => SORT_DESC ) )
			->limit( min( 100, max( 1, $limit ) ) );

		if ( ! empty( $filters['channel'] ) ) {
			$query->andWhere( array( 'channel' => $filters['channel'] ) );
		}

		$rows = $query->all();
		$jobs = array();

		foreach ( $rows as $row ) {
			$status = ! empty( $row['dateReserved'] ) ? 'running' : 'queued';
			$jobs[] = new JobStatus(
				jobId: (string) $row['id'],
				status: $status,
				attempts: (int) ( $row['attempt'] ?? 0 ),
			);
		}

		return $jobs;
	}

	// ─── Private helpers ──────────────────────────────────────────────

	private function ensureSchedulesTable(): void {
		if ( Craft::$app->getDb()->tableExists( '{{%nvoos_schedules}}' ) ) {
			return;
		}

		Craft::$app->getDb()->createCommand()
			->createTable( '{{%nvoos_schedules}}', array(
				'id'              => Craft::$app->getDb()->getSchema()->createColumnSchemaBuilder( 'string', 64 )->notNull(),
				'handler'         => Craft::$app->getDb()->getSchema()->createColumnSchemaBuilder( 'string', 255 )->notNull(),
				'payload'         => Craft::$app->getDb()->getSchema()->createColumnSchemaBuilder( 'text' )->null(),
				'cron_expression' => Craft::$app->getDb()->getSchema()->createColumnSchemaBuilder( 'string', 64 )->notNull(),
				'dateCreated'     => Craft::$app->getDb()->getSchema()->createColumnSchemaBuilder( 'datetime' )->notNull(),
				'dateUpdated'     => Craft::$app->getDb()->getSchema()->createColumnSchemaBuilder( 'datetime' )->notNull(),
				'uid'             => Craft::$app->getDb()->getSchema()->createColumnSchemaBuilder( 'char', 36 )->notNull(),
				'PRIMARY KEY (id)',
			) )
			->execute();
	}
}
