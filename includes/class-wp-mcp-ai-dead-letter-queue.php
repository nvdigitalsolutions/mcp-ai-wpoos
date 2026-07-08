<?php
/**
 * Dead Letter Queue for failed jobs and webhooks.
 *
 * Provides persistent storage and management for permanently failed operations
 * when RabbitMQ is not available. Supports retry, dismissal, and auditing.
 *
 * Storage: Custom DB table `wp_mcp_ai_dead_letters` (replaces wp_options as of v1.1.37).
 * The old `wp_mcp_ai_dead_letter_queue` option is preserved for one release cycle
 * for backward compatibility and will be cleaned up in a future release.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @since 1.1.37 Storage migrated from wp_options to custom DB table for concurrency safety.
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages dead letter queue for failed operations.
 */
class WP_MCP_AI_Dead_Letter_Queue {

	/**
	 * Database table name (without prefix).
	 *
	 * @since 1.1.37
	 * @var string
	 */
	const TABLE_NAME = 'mcp_ai_dead_letters';

	/**
	 * Legacy option name for storing DLQ items.
	 *
	 * @deprecated 1.1.37 Use custom DB table instead.
	 * @var string
	 */
	const OPTION_NAME = 'wp_mcp_ai_dead_letter_queue';

	/**
	 * Maximum items to store in DLQ.
	 *
	 * @var int
	 */
	const MAX_ITEMS = 1000;

	/**
	 * Default retention period in days.
	 *
	 * @var int
	 */
	const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Item types.
	 */
	const TYPE_CRON_JOB   = 'cron_job';
	const TYPE_WEBHOOK    = 'webhook';
	const TYPE_ASYNC_TOOL = 'async_tool';
	const TYPE_JOB_QUEUE  = 'job_queue';
	const TYPE_MESH_QUERY = 'mesh_query';

	/**
	 * Flag tracking whether the custom table is available.
	 *
	 * @since 1.1.37
	 * @var bool|null
	 */
	private static $table_exists = null;

	/**
	 * Create the dead letter queue database table.
	 *
	 * Uses dbDelta() for safe, idempotent schema management.
	 *
	 * @since 1.1.37
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			item_id VARCHAR(64) NOT NULL,
			type VARCHAR(32) NOT NULL DEFAULT '',
			identifier VARCHAR(255) NOT NULL DEFAULT '',
			data LONGTEXT DEFAULT NULL,
			failure_reason TEXT DEFAULT NULL,
			retry_history LONGTEXT DEFAULT NULL,
			retry_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
			dismissed TINYINT(1) NOT NULL DEFAULT 0,
			added_at DATETIME NOT NULL,
			added_timestamp BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			dismissed_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY item_id (item_id),
			KEY type_dismissed (type, dismissed),
			KEY added_timestamp (added_timestamp)
		) $charset_collate;";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );

		self::$table_exists = true;
	}

	/**
	 * Check if the custom table is available.
	 *
	 * @since 1.1.37
	 * @return bool True if the custom table exists and should be used.
	 */
	private static function use_custom_table() {
		if ( null !== self::$table_exists ) {
			return self::$table_exists;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		self::$table_exists = ( $table_name === $exists );
		return self::$table_exists;
	}

	/**
	 * Get the table name with prefix.
	 *
	 * @since 1.1.37
	 * @return string Full table name.
	 */
	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Add an item to the dead letter queue.
	 *
	 * @param string $type           Item type (cron_job, webhook, async_tool, job_queue).
	 * @param string $identifier     Unique identifier for the item.
	 * @param array  $data           Item data including payload and context.
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

		$item_id = self::generate_item_id( $type, $identifier );

		// Use custom table if available.
		if ( self::use_custom_table() ) {
			return self::add_to_table( $item_id, $type, $identifier, $data, $failure_reason, $retry_history );
		}

		// Fallback: legacy option-based storage.
		return self::add_to_option( $item_id, $type, $identifier, $data, $failure_reason, $retry_history );
	}

	/**
	 * Add item to custom DB table.
	 *
	 * @since 1.1.37
	 *
	 * @param string $item_id        Generated item ID.
	 * @param string $type           Item type.
	 * @param string $identifier     Item identifier.
	 * @param array  $data           Item data.
	 * @param string $failure_reason Failure reason.
	 * @param array  $retry_history  Retry history.
	 * @return bool True on success.
	 */
	private static function add_to_table( $item_id, $type, $identifier, $data, $failure_reason, $retry_history ) {
		global $wpdb;

		// Check capacity and prune if needed.
		$count = self::count_items();
		if ( $count >= self::MAX_ITEMS ) {
			self::prune_oldest_from_table( 100 );
		}

		$row = array(
			'item_id'         => $item_id,
			'type'            => $type,
			'identifier'      => $identifier,
			'data'            => wp_json_encode( $data ),
			'failure_reason'  => sanitize_textarea_field( $failure_reason ),
			'retry_history'   => wp_json_encode( is_array( $retry_history ) ? $retry_history : array() ),
			'retry_count'     => count( $retry_history ),
			'dismissed'       => 0,
			'added_at'        => current_time( 'mysql', true ),
			'added_timestamp' => time(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
		$inserted = $wpdb->insert( self::get_table_name(), $row );

		if ( false === $inserted ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to insert DLQ item into custom table.',
				array(
					'item_id' => $item_id,
					'error'   => $wpdb->last_error,
				)
			);
			return false;
		}

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

		/** This action is documented in this file. */
		do_action( 'wp_mcp_ai_dlq_item_added', $item_id, $type, $identifier, $data, $failure_reason );

		return true;
	}

	/**
	 * Add item to legacy option storage.
	 *
	 * @deprecated 1.1.37 Use add_to_table() instead.
	 *
	 * @param string $item_id        Generated item ID.
	 * @param string $type           Item type.
	 * @param string $identifier     Item identifier.
	 * @param array  $data           Item data.
	 * @param string $failure_reason Failure reason.
	 * @param array  $retry_history  Retry history.
	 * @return bool True on success.
	 */
	private static function add_to_option( $item_id, $type, $identifier, $data, $failure_reason, $retry_history ) {
		$items = self::get_all_from_option();

		if ( count( $items ) >= self::MAX_ITEMS ) {
			self::prune_oldest_from_option( 100 );
			$items = self::get_all_from_option();
		}

		$items[ $item_id ] = array(
			'id'              => $item_id,
			'type'            => $type,
			'identifier'      => $identifier,
			'data'            => $data,
			'failure_reason'  => sanitize_textarea_field( $failure_reason ),
			'retry_history'   => is_array( $retry_history ) ? $retry_history : array(),
			'retry_count'     => count( $retry_history ),
			'added_at'        => current_time( 'mysql', true ),
			'added_timestamp' => time(),
			'dismissed'       => false,
		);

		$saved = self::save_items_to_option( $items );

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

			/** This action is documented in this file. */
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
		if ( self::use_custom_table() ) {
			return self::get_all_from_table( $filters );
		}
		return self::get_all_from_option( $filters );
	}

	/**
	 * Get all items from custom DB table.
	 *
	 * @since 1.1.37
	 *
	 * @param array $filters Optional filters.
	 * @return array Array of DLQ items.
	 */
	private static function get_all_from_table( $filters = array() ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$where      = array( '1=1' );
		$params     = array();

		if ( ! empty( $filters['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = sanitize_key( $filters['type'] );
		}

		if ( isset( $filters['dismissed'] ) ) {
			$where[]  = 'dismissed = %d';
			$params[] = $filters['dismissed'] ? 1 : 0;
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'added_timestamp >= %d';
			$params[] = strtotime( $filters['date_from'] );
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'added_timestamp <= %d';
			$params[] = strtotime( $filters['date_to'] );
		}

		$where_clause = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE $where_clause ORDER BY added_timestamp DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $params, array( self::MAX_ITEMS ) )
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$items = array();
		foreach ( $rows as $row ) {
			$item = array(
				'id'              => $row['item_id'],
				'type'            => $row['type'],
				'identifier'      => $row['identifier'],
				'data'            => json_decode( $row['data'], true ),
				'failure_reason'  => $row['failure_reason'],
				'retry_history'   => json_decode( $row['retry_history'], true ),
				'retry_count'     => (int) $row['retry_count'],
				'added_at'        => $row['added_at'],
				'added_timestamp' => (int) $row['added_timestamp'],
				'dismissed'       => (bool) $row['dismissed'],
			);

			if ( ! empty( $row['dismissed_at'] ) ) {
				$item['dismissed_at'] = $row['dismissed_at'];
			}

			$items[ $row['item_id'] ] = $item;
		}

		return $items;
	}

	/**
	 * Get all items from legacy option storage.
	 *
	 * @deprecated 1.1.37 Use get_all_from_table() instead.
	 *
	 * @param array $filters Optional filters.
	 * @return array Array of DLQ items.
	 */
	private static function get_all_from_option( $filters = array() ) {
		$items = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $items ) ) {
			$items = array();
		}

		if ( ! empty( $filters ) ) {
			$items = self::filter_items( $items, $filters );
		}

		return $items;
	}

	/**
	 * Count total items in the custom table.
	 *
	 * @since 1.1.37
	 * @return int Item count.
	 */
	private static function count_items() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::get_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
		$item_id = sanitize_key( $item_id );

		if ( self::use_custom_table() ) {
			return self::get_from_table( $item_id );
		}

		$items = self::get_all_from_option();
		return isset( $items[ $item_id ] ) ? $items[ $item_id ] : null;
	}

	/**
	 * Get a single item from custom table.
	 *
	 * @since 1.1.37
	 *
	 * @param string $item_id DLQ item ID.
	 * @return array|null Item data or null if not found.
	 */
	private static function get_from_table( $item_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_table_name() . ' WHERE item_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$item_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'id'              => $row['item_id'],
			'type'            => $row['type'],
			'identifier'      => $row['identifier'],
			'data'            => json_decode( $row['data'], true ),
			'failure_reason'  => $row['failure_reason'],
			'retry_history'   => json_decode( $row['retry_history'], true ),
			'retry_count'     => (int) $row['retry_count'],
			'added_at'        => $row['added_at'],
			'added_timestamp' => (int) $row['added_timestamp'],
			'dismissed'       => (bool) $row['dismissed'],
		);
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
			self::update_retry_history( $item_id, 'failed', $result->get_error_message() );
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
	 * Update retry history for an item.
	 *
	 * @since 1.1.37
	 *
	 * @param string $item_id       DLQ item ID.
	 * @param string $result        Result of retry ('failed').
	 * @param string $error_message Error message.
	 * @return void
	 */
	private static function update_retry_history( $item_id, $result, $error_message ) {
		if ( self::use_custom_table() ) {
			global $wpdb;

			$item = self::get_from_table( $item_id );
			if ( ! $item ) {
				return;
			}

			$retry_history   = is_array( $item['retry_history'] ) ? $item['retry_history'] : array();
			$retry_history[] = array(
				'timestamp'     => time(),
				'result'        => $result,
				'error_message' => $error_message,
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				self::get_table_name(),
				array(
					'retry_history' => wp_json_encode( $retry_history ),
					'retry_count'   => count( $retry_history ),
				),
				array( 'item_id' => $item_id )
			);
		} else {
			$items = self::get_all_from_option();
			if ( isset( $items[ $item_id ] ) ) {
				$items[ $item_id ]['retry_history'][] = array(
					'timestamp'     => time(),
					'result'        => $result,
					'error_message' => $error_message,
				);
				$items[ $item_id ]['retry_count']     = count( $items[ $item_id ]['retry_history'] );
				self::save_items_to_option( $items );
			}
		}
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

		if ( class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
			$executor = new WP_MCP_AI_Tool_Async_Executor();
			$executor->init();
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
		$item_id = sanitize_key( $item_id );

		if ( self::use_custom_table() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				self::get_table_name(),
				array(
					'dismissed'    => 1,
					'dismissed_at' => current_time( 'mysql', true ),
				),
				array( 'item_id' => $item_id )
			);
			return false !== $updated;
		}

		$items = self::get_all_from_option();

		if ( ! isset( $items[ $item_id ] ) ) {
			return false;
		}

		$items[ $item_id ]['dismissed']    = true;
		$items[ $item_id ]['dismissed_at'] = current_time( 'mysql', true );

		return self::save_items_to_option( $items );
	}

	/**
	 * Remove an item from the dead letter queue.
	 *
	 * @param string $item_id DLQ item ID.
	 * @return bool True on success, false on failure.
	 */
	public static function remove( $item_id ) {
		$item_id = sanitize_key( $item_id );

		if ( self::use_custom_table() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete(
				self::get_table_name(),
				array( 'item_id' => $item_id )
			);
			return false !== $deleted;
		}

		$items = self::get_all_from_option();

		if ( ! isset( $items[ $item_id ] ) ) {
			return false;
		}

		unset( $items[ $item_id ] );

		return self::save_items_to_option( $items );
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

		if ( self::use_custom_table() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$purged = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . self::get_table_name() . ' WHERE added_timestamp < %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$cutoff_time
				)
			);

			if ( $purged > 0 ) {
				WP_MCP_AI_Logger::log_event(
					'dlq_purged',
					'Old items purged from dead letter queue.',
					array( 'purged_count' => $purged )
				);
			}

			return (int) $purged;
		}

		$items  = self::get_all_from_option();
		$purged = 0;

		foreach ( $items as $item_id => $item ) {
			$added_timestamp = isset( $item['added_timestamp'] ) ? $item['added_timestamp'] : 0;

			if ( $added_timestamp < $cutoff_time ) {
				unset( $items[ $item_id ] );
				++$purged;
			}
		}

		if ( $purged > 0 ) {
			self::save_items_to_option( $items );

			WP_MCP_AI_Logger::log_event(
				'dlq_purged',
				'Old items purged from dead letter queue.',
				array( 'purged_count' => $purged )
			);
		}

		return $purged;
	}

	/**
	 * Prune oldest items to make room for new ones (custom table).
	 *
	 * @since 1.1.37
	 *
	 * @param int $count Number of oldest items to remove.
	 * @return int Number of items pruned.
	 */
	private static function prune_oldest_from_table( $count ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pruned = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::get_table_name() . ' ORDER BY added_timestamp ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$count
			)
		);

		return (int) $pruned;
	}

	/**
	 * Prune oldest items to make room for new ones (option storage).
	 *
	 * @deprecated 1.1.37 Use prune_oldest_from_table() instead.
	 *
	 * @param int $count Number of oldest items to remove.
	 * @return int Number of items pruned.
	 */
	private static function prune_oldest_from_option( $count ) {
		$items = self::get_all_from_option();

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
			self::save_items_to_option( $items );
		}

		return $pruned;
	}

	/**
	 * Get statistics about the dead letter queue.
	 *
	 * @return array Statistics.
	 */
	public static function get_stats() {
		if ( self::use_custom_table() ) {
			return self::get_stats_from_table();
		}
		return self::get_stats_from_option();
	}

	/**
	 * Get stats from custom table.
	 *
	 * @since 1.1.37
	 * @return array Statistics.
	 */
	private static function get_stats_from_table() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::get_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$dismissed = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE dismissed = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$type_rows = $wpdb->get_results( 'SELECT type, COUNT(*) as cnt FROM ' . self::get_table_name() . ' GROUP BY type' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$by_type = array();
		foreach ( $type_rows as $row ) {
			$by_type[ $row->type ] = (int) $row->cnt;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$oldest = $wpdb->get_var( 'SELECT added_at FROM ' . self::get_table_name() . ' ORDER BY added_timestamp ASC LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$newest = $wpdb->get_var( 'SELECT added_at FROM ' . self::get_table_name() . ' ORDER BY added_timestamp DESC LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'total'       => $total,
			'by_type'     => $by_type,
			'dismissed'   => $dismissed,
			'active'      => $total - $dismissed,
			'oldest_date' => $oldest,
			'newest_date' => $newest,
		);
	}

	/**
	 * Get stats from legacy option storage.
	 *
	 * @deprecated 1.1.37 Use get_stats_from_table() instead.
	 * @return array Statistics.
	 */
	private static function get_stats_from_option() {
		$items = self::get_all_from_option();

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
	 * Filter items based on criteria (option storage only).
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
	 * Save items to legacy option storage.
	 *
	 * @deprecated 1.1.37 Use custom DB table instead.
	 *
	 * @param array $items Items to save.
	 * @return bool True on success.
	 */
	private static function save_items_to_option( $items ) {
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

		/** This filter is documented in this file. */
		$retention_days = apply_filters( 'wp_mcp_ai_dlq_retention_days', $retention_days );

		self::purge_old( $retention_days );
	}
}

// Schedule cleanup cron.
add_action( 'init', array( 'WP_MCP_AI_Dead_Letter_Queue', 'schedule_cleanup' ) );
add_action( 'wp_mcp_ai_dlq_cleanup', array( 'WP_MCP_AI_Dead_Letter_Queue', 'cleanup' ) );
