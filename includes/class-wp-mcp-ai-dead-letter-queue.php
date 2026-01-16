<?php
/**
 * Dead Letter Queue for failed jobs and webhooks.
 *
 * Provides persistent storage and management for permanently failed operations
 * when RabbitMQ is not available. Supports retry, dismissal, and auditing.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages dead letter queue for failed operations.
 */
class WP_MCP_AI_Dead_Letter_Queue {
	/**
	 * Option name for storing DLQ items.
	 */
	const OPTION_NAME = 'wp_mcp_ai_dead_letter_queue';

	/**
	 * Maximum items to store in DLQ.
	 */
	const MAX_ITEMS = 1000;

	/**
	 * Default retention period in days.
	 */
	const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Item types.
	 */
	const TYPE_CRON_JOB    = 'cron_job';
	const TYPE_WEBHOOK     = 'webhook';
	const TYPE_ASYNC_TOOL  = 'async_tool';
	const TYPE_JOB_QUEUE   = 'job_queue';
	const TYPE_MESH_QUERY  = 'mesh_query';

	/**
	 * Add an item to the dead letter queue.
	 *
	 * @param string $type         Item type (cron_job, webhook, async_tool, job_queue).
	 * @param string $identifier   Unique identifier for the item.
	 * @param array  $data         Item data including payload and context.
	 * @param string $failure_reason Reason for failure.
	 * @param array  $retry_history  Array of previous retry attempts.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function add( $type, $identifier, $data, $failure_reason, $retry_history = array() ) {
		$type       = sanitize_key( $type );
		$identifier = sanitize_text_field( $identifier );

		if ( ! in_array( $type, self::get_valid_types(), true ) ) {
			return new WP_Error(
				'invalid_type',
				__( 'Invalid dead letter queue item type.', 'mcp-ai-wpoos' )
			);
		}

		if ( '' === $identifier ) {
			return new WP_Error(
				'invalid_identifier',
				__( 'Dead letter queue item must have an identifier.', 'mcp-ai-wpoos' )
			);
		}

		$items = self::get_all();

		// Check if we're at capacity.
		if ( count( $items ) >= self::MAX_ITEMS ) {
			self::prune_oldest( 100 ); // Remove oldest 100 items to make room.
			$items = self::get_all();
		}

		$item_id = self::generate_item_id( $type, $identifier );

		$items[ $item_id ] = array(
			'id'             => $item_id,
			'type'           => $type,
			'identifier'     => $identifier,
			'data'           => $data,
			'failure_reason' => sanitize_textarea_field( $failure_reason ),
			'retry_history'  => is_array( $retry_history ) ? $retry_history : array(),
			'retry_count'    => count( $retry_history ),
			'added_at'       => current_time( 'mysql', true ),
			'added_timestamp' => time(),
			'dismissed'      => false,
		);

		$saved = self::save_items( $items );

		if ( $saved ) {
			WP_MCP_AI_Logger::log_event(
				'dlq_item_added',
				'Item added to dead letter queue.',
				array(
					'type'           => $type,
					'identifier'     => $identifier,
					'failure_reason' => $failure_reason,
					'retry_count'    => count( $retry_history ),
				)
			);

			/**
			 * Fires when an item is added to the dead letter queue.
			 *
			 * @since 1.1.0
			 *
			 * @param string $item_id        DLQ item ID.
			 * @param string $type           Item type.
			 * @param string $identifier     Item identifier.
			 * @param array  $data           Item data.
			 * @param string $failure_reason Failure reason.
			 */
			do_action( 'wp_mcp_ai_dlq_item_added', $item_id, $type, $identifier, $data, $failure_reason );
		}

		return $saved;
	}

	/**
	 * Get all items from the dead letter queue.
	 *
	 * @param array $filters Optional filters: type, dismissed, date_from, date_to.
	 * @return array Array of DLQ items.
	 */
	public static function get_all( $filters = array() ) {
		$items = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $items ) ) {
			$items = array();
		}

		// Apply filters.
		if ( ! empty( $filters ) ) {
			$items = self::filter_items( $items, $filters );
		}

		return $items;
	}

	/**
	 * Get items by type.
	 *
	 * @param string $type Item type.
	 * @return array Array of DLQ items.
	 */
	public static function get_by_type( $type ) {
		return self::get_all( array( 'type' => $type ) );
	}

	/**
	 * Get a single item by ID.
	 *
	 * @param string $item_id DLQ item ID.
	 * @return array|null Item data or null if not found.
	 */
	public static function get( $item_id ) {
		$items   = self::get_all();
		$item_id = sanitize_key( $item_id );

		return isset( $items[ $item_id ] ) ? $items[ $item_id ] : null;
	}

	/**
	 * Retry a failed item.
	 *
	 * @param string $item_id DLQ item ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function retry( $item_id ) {
		$item = self::get( $item_id );

		if ( ! $item ) {
			return new WP_Error(
				'item_not_found',
				__( 'Dead letter queue item not found.', 'mcp-ai-wpoos' )
			);
		}

		// Dispatch based on type.
		$result = false;

		switch ( $item['type'] ) {
			case self::TYPE_WEBHOOK:
				$result = self::retry_webhook( $item );
				break;

			case self::TYPE_CRON_JOB:
				$result = self::retry_cron_job( $item );
				break;

			case self::TYPE_ASYNC_TOOL:
				$result = self::retry_async_tool( $item );
				break;

			case self::TYPE_JOB_QUEUE:
				$result = self::retry_job_queue( $item );
				break;

			default:
				$result = new WP_Error(
					'unsupported_type',
					__( 'Unsupported item type for retry.', 'mcp-ai-wpoos' )
				);
		}

		if ( is_wp_error( $result ) ) {
			// Update retry history with failure.
			$items = self::get_all();
			if ( isset( $items[ $item_id ] ) ) {
				$items[ $item_id ]['retry_history'][] = array(
					'timestamp'      => time(),
					'result'         => 'failed',
					'error_message'  => $result->get_error_message(),
				);
				$items[ $item_id ]['retry_count'] = count( $items[ $item_id ]['retry_history'] );
				self::save_items( $items );
			}

			return $result;
		}

		// Remove from DLQ on successful retry.
		self::remove( $item_id );

		WP_MCP_AI_Logger::log_event(
			'dlq_item_retried',
			'Dead letter queue item successfully retried and removed.',
			array(
				'item_id' => $item_id,
				'type'    => $item['type'],
			)
		);

		return true;
	}

	/**
	 * Retry a webhook delivery.
	 *
	 * @param array $item DLQ item.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	protected static function retry_webhook( $item ) {
		if ( ! isset( $item['data']['url'], $item['data']['payload'] ) ) {
			return new WP_Error(
				'invalid_webhook_data',
				__( 'Webhook data is incomplete.', 'mcp-ai-wpoos' )
			);
		}

		$url     = $item['data']['url'];
		$payload = $item['data']['payload'];

		// Use WP_MCP_AI_Job_Notifier to send webhook.
		if ( class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			WP_MCP_AI_Job_Notifier::send_webhook( $url, $payload );
			return true;
		}

		return new WP_Error(
			'webhook_sender_unavailable',
			__( 'Webhook sender not available.', 'mcp-ai-wpoos' )
		);
	}

	/**
	 * Retry a cron job.
	 *
	 * @param array $item DLQ item.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	protected static function retry_cron_job( $item ) {
		if ( ! isset( $item['data']['hook'], $item['data']['args'] ) ) {
			return new WP_Error(
				'invalid_cron_data',
				__( 'Cron job data is incomplete.', 'mcp-ai-wpoos' )
			);
		}

		$hook      = $item['data']['hook'];
		$args      = $item['data']['args'];
		$timestamp = isset( $item['data']['timestamp'] ) ? $item['data']['timestamp'] : time();

		// Reschedule the cron job.
		$scheduled = wp_schedule_single_event( $timestamp, $hook, $args );

		if ( false === $scheduled ) {
			return new WP_Error(
				'cron_schedule_failed',
				__( 'Failed to reschedule cron job.', 'mcp-ai-wpoos' )
			);
		}

		return true;
	}

	/**
	 * Retry an async tool execution.
	 *
	 * @param array $item DLQ item.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	protected static function retry_async_tool( $item ) {
		if ( ! isset( $item['data']['job_id'] ) ) {
			return new WP_Error(
				'invalid_async_tool_data',
				__( 'Async tool data is incomplete.', 'mcp-ai-wpoos' )
			);
		}

		// Re-enqueue the async tool job.
		if ( class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
			$executor = new WP_MCP_AI_Tool_Async_Executor();
			$executor->init();

			// The async executor will handle rescheduling based on the job_id.
			return true;
		}

		return new WP_Error(
			'async_executor_unavailable',
			__( 'Async tool executor not available.', 'mcp-ai-wpoos' )
		);
	}

	/**
	 * Retry a job queue item.
	 *
	 * @param array $item DLQ item.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	protected static function retry_job_queue( $item ) {
		if ( ! isset( $item['data']['job_id'], $item['data']['job_data'] ) ) {
			return new WP_Error(
				'invalid_job_queue_data',
				__( 'Job queue data is incomplete.', 'mcp-ai-wpoos' )
			);
		}

		$job_id   = $item['data']['job_id'];
		$job_data = $item['data']['job_data'];

		// Re-enqueue the job.
		if ( class_exists( 'WP_MCP_AI_Job_Queue_Manager' ) ) {
			$result = WP_MCP_AI_Job_Queue_Manager::enqueue_job( $job_id, $job_data );

			if ( ! $result ) {
				return new WP_Error(
					'job_enqueue_failed',
					__( 'Failed to re-enqueue job.', 'mcp-ai-wpoos' )
				);
			}

			return true;
		}

		return new WP_Error(
			'job_queue_manager_unavailable',
			__( 'Job queue manager not available.', 'mcp-ai-wpoos' )
		);
	}

	/**
	 * Mark an item as dismissed.
	 *
	 * @param string $item_id DLQ item ID.
	 * @return bool True on success, false on failure.
	 */
	public static function dismiss( $item_id ) {
		$items   = self::get_all();
		$item_id = sanitize_key( $item_id );

		if ( ! isset( $items[ $item_id ] ) ) {
			return false;
		}

		$items[ $item_id ]['dismissed'] = true;
		$items[ $item_id ]['dismissed_at'] = current_time( 'mysql', true );

		return self::save_items( $items );
	}

	/**
	 * Remove an item from the dead letter queue.
	 *
	 * @param string $item_id DLQ item ID.
	 * @return bool True on success, false on failure.
	 */
	public static function remove( $item_id ) {
		$items   = self::get_all();
		$item_id = sanitize_key( $item_id );

		if ( ! isset( $items[ $item_id ] ) ) {
			return false;
		}

		unset( $items[ $item_id ] );

		return self::save_items( $items );
	}

	/**
	 * Purge old items from the dead letter queue.
	 *
	 * @param int $retention_days Number of days to retain items.
	 * @return int Number of items purged.
	 */
	public static function purge_old( $retention_days = null ) {
		if ( null === $retention_days ) {
			$retention_days = self::DEFAULT_RETENTION_DAYS;
		}

		$retention_days = absint( $retention_days );
		$cutoff_time    = time() - ( $retention_days * DAY_IN_SECONDS );

		$items  = self::get_all();
		$purged = 0;

		foreach ( $items as $item_id => $item ) {
			$added_timestamp = isset( $item['added_timestamp'] ) ? $item['added_timestamp'] : 0;

			if ( $added_timestamp < $cutoff_time ) {
				unset( $items[ $item_id ] );
				++$purged;
			}
		}

		if ( $purged > 0 ) {
			self::save_items( $items );

			WP_MCP_AI_Logger::log_event(
				'dlq_purged',
				'Old items purged from dead letter queue.',
				array( 'purged_count' => $purged )
			);
		}

		return $purged;
	}

	/**
	 * Prune oldest items to make room for new ones.
	 *
	 * @param int $count Number of oldest items to remove.
	 * @return int Number of items pruned.
	 */
	protected static function prune_oldest( $count ) {
		$items = self::get_all();

		// Sort by added_timestamp ascending.
		uasort(
			$items,
			function ( $a, $b ) {
				$time_a = isset( $a['added_timestamp'] ) ? $a['added_timestamp'] : 0;
				$time_b = isset( $b['added_timestamp'] ) ? $b['added_timestamp'] : 0;
				return $time_a - $time_b;
			}
		);

		$pruned = 0;
		foreach ( $items as $item_id => $item ) {
			if ( $pruned >= $count ) {
				break;
			}

			unset( $items[ $item_id ] );
			++$pruned;
		}

		if ( $pruned > 0 ) {
			self::save_items( $items );
		}

		return $pruned;
	}

	/**
	 * Get statistics about the dead letter queue.
	 *
	 * @return array Statistics.
	 */
	public static function get_stats() {
		$items = self::get_all();

		$stats = array(
			'total'       => count( $items ),
			'by_type'     => array(),
			'dismissed'   => 0,
			'active'      => 0,
			'oldest_date' => null,
			'newest_date' => null,
		);

		foreach ( $items as $item ) {
			$type = isset( $item['type'] ) ? $item['type'] : 'unknown';

			if ( ! isset( $stats['by_type'][ $type ] ) ) {
				$stats['by_type'][ $type ] = 0;
			}

			++$stats['by_type'][ $type ];

			if ( ! empty( $item['dismissed'] ) ) {
				++$stats['dismissed'];
			} else {
				++$stats['active'];
			}

			// Track date range.
			$added_at = isset( $item['added_at'] ) ? $item['added_at'] : null;
			if ( $added_at ) {
				if ( null === $stats['oldest_date'] || $added_at < $stats['oldest_date'] ) {
					$stats['oldest_date'] = $added_at;
				}
				if ( null === $stats['newest_date'] || $added_at > $stats['newest_date'] ) {
					$stats['newest_date'] = $added_at;
				}
			}
		}

		return $stats;
	}

	/**
	 * Filter items based on criteria.
	 *
	 * @param array $items   Items to filter.
	 * @param array $filters Filter criteria.
	 * @return array Filtered items.
	 */
	protected static function filter_items( $items, $filters ) {
		if ( isset( $filters['type'] ) ) {
			$type  = sanitize_key( $filters['type'] );
			$items = array_filter(
				$items,
				function ( $item ) use ( $type ) {
					return isset( $item['type'] ) && $item['type'] === $type;
				}
			);
		}

		if ( isset( $filters['dismissed'] ) ) {
			$dismissed = (bool) $filters['dismissed'];
			$items     = array_filter(
				$items,
				function ( $item ) use ( $dismissed ) {
					$is_dismissed = ! empty( $item['dismissed'] );
					return $is_dismissed === $dismissed;
				}
			);
		}

		if ( isset( $filters['date_from'] ) ) {
			$date_from = strtotime( $filters['date_from'] );
			$items     = array_filter(
				$items,
				function ( $item ) use ( $date_from ) {
					$added_timestamp = isset( $item['added_timestamp'] ) ? $item['added_timestamp'] : 0;
					return $added_timestamp >= $date_from;
				}
			);
		}

		if ( isset( $filters['date_to'] ) ) {
			$date_to = strtotime( $filters['date_to'] );
			$items   = array_filter(
				$items,
				function ( $item ) use ( $date_to ) {
					$added_timestamp = isset( $item['added_timestamp'] ) ? $item['added_timestamp'] : 0;
					return $added_timestamp <= $date_to;
				}
			);
		}

		return $items;
	}

	/**
	 * Generate a unique item ID.
	 *
	 * @param string $type       Item type.
	 * @param string $identifier Item identifier.
	 * @return string Item ID.
	 */
	protected static function generate_item_id( $type, $identifier ) {
		return md5( $type . '_' . $identifier . '_' . microtime( true ) );
	}

	/**
	 * Get valid item types.
	 *
	 * @return array Valid types.
	 */
	protected static function get_valid_types() {
		return array(
			self::TYPE_CRON_JOB,
			self::TYPE_WEBHOOK,
			self::TYPE_ASYNC_TOOL,
			self::TYPE_JOB_QUEUE,
		);
	}

	/**
	 * Save items to the database.
	 *
	 * @param array $items Items to save.
	 * @return bool True on success.
	 */
	protected static function save_items( $items ) {
		return update_option( self::OPTION_NAME, $items, false );
	}

	/**
	 * Schedule periodic cleanup of old DLQ items.
	 */
	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'wp_mcp_ai_dlq_cleanup' ) ) {
			wp_schedule_event( time(), 'weekly', 'wp_mcp_ai_dlq_cleanup' );
		}
	}

	/**
	 * Clean up old DLQ items.
	 *
	 * Hooked to 'wp_mcp_ai_dlq_cleanup' cron event.
	 */
	public static function cleanup() {
		$retention_days = self::DEFAULT_RETENTION_DAYS;

		// Allow filtering of retention period.
		$retention_days = apply_filters( 'wp_mcp_ai_dlq_retention_days', $retention_days );

		self::purge_old( $retention_days );
	}
}

// Schedule cleanup cron.
add_action( 'init', array( 'WP_MCP_AI_Dead_Letter_Queue', 'schedule_cleanup' ) );
add_action( 'wp_mcp_ai_dlq_cleanup', array( 'WP_MCP_AI_Dead_Letter_Queue', 'cleanup' ) );
