<?php
/**
 * WordPress adapter: QueueClientInterface implementation.
 *
 * Wraps WordPress Action Scheduler and WP-Cron behind the framework-agnostic
 * QueueClientInterface. Uses Action Scheduler when available (WooCommerce
 * or standalone), falling back to WP-Cron for scheduling and a simple
 * database table for status tracking.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Entity\JobStatus;

class QueueClient implements QueueClientInterface {

	public function enqueue( string $handler, array $payload, array $options = array() ): string {
		$groupId = $options['group'] ?? 'wp_mcp_ai';
		$unique  = $options['unique'] ?? false;
		$jobId   = 'job_' . \wp_generate_uuid4();

		// 1. Persist to the job store first (source of truth).
		if ( \class_exists( 'WP_MCP_AI_Job_Store' ) ) {
			\WP_MCP_AI_Job_Store::insert( array(
				'job_id'  => $jobId,
				'handler' => $handler,
				'payload' => \wp_json_encode( $payload ),
				'status'  => 'queued',
				'user_id' => \get_current_user_id(),
			) );
		}

		// 2. If RabbitMQ is available, publish to broker for distributed processing.
		// The job store remains the canonical source of truth for status tracking.
		if ( $this->isRabbitMqAvailable() ) {
			\WP_MCP_AI_RabbitMQ_Client::get_instance()->publish(
				'tools',
				'execute.normal',
				array(
					'job_id'   => $jobId,
					'handler'  => $handler,
					'payload'  => $payload,
					'user_id'  => \get_current_user_id(),
				)
			);

			// Only enqueue to Action Scheduler as a fallback when no dedicated
			// queue worker is configured to consume from RabbitMQ. This prevents
			// write amplification on the wp_actionscheduler_* tables.
			if ( ! $this->isDedicatedQueueWorkerActive() && \function_exists( 'as_enqueue_async_action' ) ) {
				\as_enqueue_async_action(
					$handler,
					\array_merge(
						$payload,
						array( '_job_id' => $jobId )
					),
					$groupId,
					$unique,
					$options['priority'] ?? 10,
				);
			}

			return $jobId;
		}

		// 3. Prefer Action Scheduler when available.
		if ( \function_exists( 'as_enqueue_async_action' ) ) {
			if ( $unique ) {
				$existingId = \as_has_scheduled_action( $handler, $payload, $groupId );
				if ( $existingId ) {
					return (string) $existingId;
				}
			}

			$actionId = \as_enqueue_async_action(
				$handler,
				\array_merge(
					$payload,
					array( '_job_id' => $jobId )
				),
				$groupId,
				$unique,
				$options['priority'] ?? 10,
			);

			return $jobId;
		}

		// 4. Fallback: WP-Cron single event.
		\wp_schedule_single_event(
			\time(),
			'wp_mcp_ai_handle_async_job',
			array(
				\array_merge(
					$payload,
					array(
						'_job_id'  => $jobId,
						'_handler' => $handler,
					)
				),
			),
		);

		return $jobId;
	}

	public function getStatus( string $jobId ): JobStatus {
		// 1. Check persistent store first.
		if ( \class_exists( 'WP_MCP_AI_Job_Store' ) ) {
			$row = \WP_MCP_AI_Job_Store::get( $jobId );
			if ( $row ) {
				return new JobStatus(
					jobId: $jobId,
					status: $row['status'],
					result: $row['result'] ? \json_decode( $row['result'], true ) : null,
					error: $row['error'],
					queuedAt: $row['created_at'] ? new \DateTimeImmutable( $row['created_at'] ) : null,
					startedAt: $row['started_at'] ? new \DateTimeImmutable( $row['started_at'] ) : null,
					completedAt: $row['completed_at'] ? new \DateTimeImmutable( $row['completed_at'] ) : null,
					attempts: (int) $row['attempts'],
				);
			}
		}

		// 2. Fall back to Action Scheduler.
		if ( \function_exists( 'as_get_scheduled_actions' ) ) {
			return $this->getActionSchedulerStatus( $jobId );
		}

		// 3. Fall back to transient.
		return $this->getTransientStatus( $jobId );
	}

	public function cancel( string $jobId ): bool {
		// Capability gate: users can cancel their own jobs; admins can cancel any.
		if ( \class_exists( 'WP_MCP_AI_Job_Store' ) ) {
			$job = \WP_MCP_AI_Job_Store::get( $jobId );
			if ( $job ) {
				$userId = \get_current_user_id();
				$jobUserId = isset( $job['user_id'] ) ? (int) $job['user_id'] : 0;
				if ( $jobUserId && $jobUserId !== $userId && ! \current_user_can( 'manage_options' ) ) {
					return false;
				}
			}
		}

		// 1. Cancel in Action Scheduler — search for actions carrying this
		// _job_id in their args (the job_id UUID is not an AS action ID).
		if ( \function_exists( 'as_get_scheduled_actions' ) ) {
			$as_job = null;
			if ( \class_exists( 'WP_MCP_AI_Job_Store' ) ) {
				$as_job = \WP_MCP_AI_Job_Store::get( $jobId );
			}
			$search_hook = ( $as_job && ! empty( $as_job['handler'] ) )
				? $as_job['handler']
				: '';

			$actions = \as_get_scheduled_actions( array(
				'hook'   => $search_hook,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			) );

			foreach ( $actions as $action ) {
				$args = $action->get_args();
				// phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- get_args() may return objects.
				if ( \is_array( $args ) && isset( $args['_job_id'] ) && $args['_job_id'] === $jobId ) {
					\ActionScheduler::store()->cancel_action( $action->get_id() );
					break;
				}
			}
		}

		// 2. Cancel WP-Cron fallback event (matched by _job_id in args).
		$next = \wp_next_scheduled( 'wp_mcp_ai_handle_async_job' );
		if ( $next ) {
			$events = \_get_cron_array();
			if ( is_array( $events ) ) {
				foreach ( $events as $timestamp => $hooks ) {
					if ( isset( $hooks['wp_mcp_ai_handle_async_job'] ) ) {
						foreach ( $hooks['wp_mcp_ai_handle_async_job'] as $key => $event ) {
							$eventArgs = isset( $event['args'] ) ? $event['args'] : array();
							$match     = false;
							if ( is_array( $eventArgs ) && ! empty( $eventArgs ) ) {
								$firstArg = reset( $eventArgs );
								if ( is_array( $firstArg ) && isset( $firstArg['_job_id'] ) && $firstArg['_job_id'] === $jobId ) {
									$match = true;
								}
							}
							if ( $match ) {
								\wp_unschedule_event( $timestamp, 'wp_mcp_ai_handle_async_job', $eventArgs );
							}
						}
					}
				}
			}
		}

		// 3. Update job store status.
		if ( \class_exists( 'WP_MCP_AI_Job_Store' ) ) {
			\WP_MCP_AI_Job_Store::update_status( $jobId, 'cancelled' );
		}

		// 4. Clear transient tracking.
		\delete_transient( 'wp_mcp_ai_job_' . $jobId );

		return true;
	}

	public function schedule( string $handler, array $payload, string $cronExpression ): string {
		$scheduleId = 'schedule_' . \wp_generate_uuid4();

		if ( \function_exists( 'as_schedule_cron_action' ) ) {
			\as_schedule_cron_action(
				\time(),
				$cronExpression,
				$handler,
				$payload,
				'wp_mcp_ai_recurring',
			);
		} else {
			// Map cron expression to a WordPress interval.
			$interval = $this->cronExpressionToInterval( $cronExpression );
			\wp_schedule_event( \time(), $interval, 'wp_mcp_ai_recurring_job', $payload );
		}

		\update_option(
			'wp_mcp_ai_schedule_' . $scheduleId,
			array(
				'handler'         => $handler,
				'payload'         => $payload,
				'cron_expression' => $cronExpression,
			),
			false
		);

		return $scheduleId;
	}

	public function unschedule( string $scheduleId ): void {
		$info = \get_option( 'wp_mcp_ai_schedule_' . $scheduleId );
		if ( is_array( $info ) && ! empty( $info['handler'] ) ) {
			$timestamp = \wp_next_scheduled( 'wp_mcp_ai_recurring_job', $info['payload'] );
			if ( $timestamp ) {
				\wp_unschedule_event( $timestamp, 'wp_mcp_ai_recurring_job', $info['payload'] );
			}
		}

		\delete_option( 'wp_mcp_ai_schedule_' . $scheduleId );
	}

	public function listJobs( array $filters = array(), int $limit = 50 ): array {
		if ( \function_exists( 'as_get_scheduled_actions' ) ) {
			return $this->listActionSchedulerJobs( $filters, $limit );
		}

		return array();
	}

	// ─── Action Scheduler helpers ──────────────────────────────────────

	private function getActionSchedulerStatus( string $jobId ): JobStatus {
		$store  = \ActionScheduler::store();
		$action = $store->fetch_action( $jobId );

		if ( ! $action ) {
			return new JobStatus(
				jobId: $jobId,
				status: 'cancelled',
				error: 'Job not found in Action Scheduler store.',
			);
		}

		$status = $action->get_status();

		return new JobStatus(
			jobId: $jobId,
			status: $this->mapAsStatus( $status ),
			attempts: $action->get_attempt_count(),
		);
	}

	/**
	 * @return JobStatus[]
	 */
	private function listActionSchedulerJobs( array $filters, int $limit ): array {
		$args = array(
			'per_page' => \min( 100, \max( 1, $limit ) ),
			'group'    => $filters['group'] ?? '',
			'status'   => $filters['status'] ?? '',
			'claimed'  => $filters['claimed'] ?? null,
		);

		if ( ! empty( $filters['hook'] ) ) {
			$args['hook'] = $filters['hook'];
		}

		$actions = \as_get_scheduled_actions( $args );

		$jobs = array();
		foreach ( $actions as $action ) {
			$jobs[] = new JobStatus(
				jobId: (string) $action->get_id(),
				status: $this->mapAsStatus( $action->get_status() ),
				attempts: $action->get_attempt_count(),
			);
		}

		return $jobs;
	}

	// ─── Transient-based fallback ─────────────────────────────────────

	private function getTransientStatus( string $jobId ): JobStatus {
		$data = \get_transient( 'wp_mcp_ai_job_' . $jobId );

		if ( false === $data || ! is_array( $data ) ) {
			return new JobStatus(
				jobId: $jobId,
				status: 'cancelled',
				error: 'Job tracking data not found.',
			);
		}

		return new JobStatus(
			jobId: $jobId,
			status: $data['status'] ?? 'unknown',
			result: $data['result'] ?? null,
			error: $data['error'] ?? null,
			attempts: $data['attempts'] ?? 0,
		);
	}

	// ─── RabbitMQ helper ──────────────────────────────────────────────

	/**
	 * Check whether RabbitMQ integration is available and enabled.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True when RabbitMQ can be used for job distribution.
	 */
	private function isRabbitMqAvailable(): bool {
		if ( ! \class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			return false;
		}

		try {
			return \WP_MCP_AI_RabbitMQ_Client::get_instance()->is_available();
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Check whether a dedicated queue worker is configured to consume
	 * jobs from RabbitMQ, making the Action Scheduler fallback enqueue
	 * unnecessary.
	 *
	 * When a dedicated queue worker (binary or daemon) is deployed,
	 * jobs published to RabbitMQ are consumed directly. Enqueuing the
	 * same job to Action Scheduler creates orphaned AS records that
	 * bloat the wp_actionscheduler_* tables.
	 *
	 * @since 1.2.1
	 *
	 * @return bool True when AS fallback enqueue should be suppressed.
	 */
	private function isDedicatedQueueWorkerActive(): bool {
		// A dedicated worker must be explicitly opted into by the site
		// operator when they deploy bin/queue-worker.php via cron,
		// systemd, or Kubernetes.
		$dedicated = \get_option( 'wp_mcp_ai_queue_worker_dedicated', false );

		/**
		 * Filter whether a dedicated queue worker daemon is active.
		 *
		 * Allows programmatic control (e.g., via wp-config.php constant)
		 * for sites that deploy the worker through infrastructure-as-code.
		 *
		 * @since 1.2.1
		 *
		 * @param bool $dedicated Whether a dedicated worker is active.
		 */
		return (bool) \apply_filters( 'wp_mcp_ai_queue_worker_dedicated', $dedicated );
	}

	// ─── Utilities ─────────────────────────────────────────────────────

	private function mapAsStatus( string $asStatus ): string {
		return match ( $asStatus ) {
			\ActionScheduler_Store::STATUS_PENDING    => 'queued',
			\ActionScheduler_Store::STATUS_RUNNING    => 'running',
			\ActionScheduler_Store::STATUS_COMPLETE   => 'completed',
			\ActionScheduler_Store::STATUS_FAILED     => 'failed',
			\ActionScheduler_Store::STATUS_CANCELED   => 'cancelled',
			default => $asStatus,
		};
	}

	private function cronExpressionToInterval( string $expression ): string {
		// Map common cron expressions to WordPress interval slugs.
		$map = array(
			'* * * * *'    => 'every_minute',
			'*/5 * * * *'  => 'five_minutes',
			'*/15 * * * *' => 'fifteen_minutes',
			'0 * * * *'    => 'hourly',
			'0 */6 * * *'  => 'six_hours',
			'0 */12 * * *' => 'twicedaily',
			'0 0 * * *'    => 'daily',
			'0 0 * * 0'    => 'weekly',
		);

		if ( isset( $map[ $expression ] ) ) {
			return $map[ $expression ];
		}

		// Fall back to interval strings.
		$intervalMap = array(
			'hourly'     => 'hourly',
			'daily'      => 'daily',
			'twicedaily' => 'twicedaily',
			'weekly'     => 'weekly',
		);

		return $intervalMap[ $expression ] ?? 'daily';
	}
}
