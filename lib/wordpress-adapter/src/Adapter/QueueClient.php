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

		// Prefer Action Scheduler when available.
		if ( \function_exists( 'as_enqueue_async_action' ) ) {
			if ( $unique ) {
				$existingId = \as_has_scheduled_action( $handler, $payload, $groupId );
				if ( $existingId ) {
					return (string) $existingId;
				}
			}

			$actionId = \as_enqueue_async_action(
				$handler,
				$payload,
				$groupId,
				$unique,
				$options['priority'] ?? 10,
			);

			return (string) $actionId;
		}

		// Fallback: WP-Cron single event.
		$jobId = 'cron_' . \wp_generate_uuid4();

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
		if ( \function_exists( 'as_get_scheduled_actions' ) ) {
			return $this->getActionSchedulerStatus( $jobId );
		}

		return $this->getTransientStatus( $jobId );
	}

	public function cancel( string $jobId ): bool {
		if ( \function_exists( 'as_unschedule_action' ) ) {
			\as_unschedule_action( '', array(), '', $jobId );
			return true;
		}

		// Fallback: clear the transient tracking.
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
