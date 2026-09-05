<?php
/**
 * Dead letter queue for the Content Graph AI Platform addon.
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Dead_Letter_Queue`
 * (Wave E2): byte-identical table schema (`mcp_ai_dead_letters`),
 * type/limit/retention constants, error codes, item shapes, stats shape,
 * the `wp_mcp_ai_dlq_item_added` action, the
 * `wp_mcp_ai_dlq_retention_days` filter, and the `wp_mcp_ai_dlq_cleanup`
 * cron hook. A static utility with no hooks of its own — cron wiring
 * registers standalone-only via `Plugin::registerDeadLetterQueue()`.
 *
 * Decoupling (documented, additive):
 * - The legacy option-based storage (`wp_mcp_ai_dead_letter_queue`,
 *   deprecated in the base at 1.1.37) is NOT ported — the platform
 *   starts on the custom table. When the table is unavailable the API
 *   degrades to empty results instead of the base's option fallback.
 * - `retry_job_queue()` resolves the queue manager per install mode
 *   (base `WP_MCP_AI_Job_Queue_Manager` monolith / this package's
 *   `JobQueueManager` standalone).
 * - `retry_webhook()` and `retry_async_tool()` keep the base's
 *   `class_exists` guards but gate them on the base plugin being booted
 *   (documented hardening — the monorepo autoloader can resolve base
 *   classes in standalone installs); dormant standalone until the job
 *   notifier and async tool executor port.
 * - Logging goes through a dormant seam (`log_error`/`log_event`) that
 *   targets the base logger in monolith installs.
 *
 * @package NvoosContentGraphAiPlatform\Queues
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Queues;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the dead letter queue for permanently failed operations:
 * persistent storage, retry dispatch, dismissal, and auditing.
 *
 * @since 2.1.0
 */
class DeadLetterQueue {

	/**
	 * Database table name (without prefix) — byte-identical.
	 */
	const TABLE_NAME = 'mcp_ai_dead_letters';

	/**
	 * Legacy option name — kept for the byte-identical constant surface;
	 * the legacy option storage itself is not ported (documented).
	 */
	const OPTION_NAME = 'wp_mcp_ai_dead_letter_queue';

	/**
	 * Maximum items to store in DLQ — byte-identical.
	 */
	const MAX_ITEMS = 1000;

	/**
	 * Default retention period in days — byte-identical.
	 */
	const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Item types — byte-identical.
	 */
	const TYPE_CRON_JOB   = 'cron_job';
	const TYPE_WEBHOOK    = 'webhook';
	const TYPE_ASYNC_TOOL = 'async_tool';
	const TYPE_JOB_QUEUE  = 'job_queue';
	const TYPE_MESH_QUERY = 'mesh_query';

	/**
	 * Whether the custom table is available (cached).
	 *
	 * @var bool|null
	 */
	private static $table_exists = null;

	/**
	 * Create the dead letter queue database table.
	 *
	 * Uses dbDelta() for safe, idempotent schema management.
	 *
	 * @return void
	 */
	public static function create_table(): void {
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
	 * @return bool True if the custom table exists and should be used.
	 */
	protected static function use_custom_table(): bool {
		if ( null !== self::$table_exists ) {
			return self::$table_exists;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES has no prepared-statement form for the table pattern.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		self::$table_exists = ( $table_name === $exists );
		return self::$table_exists;
	}

	/**
	 * Get the table name with prefix.
	 *
	 * @return string Full table name.
	 */
	private static function get_table_name(): string {
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
	 * @return bool|\WP_Error True on success, WP_Error on validation failure, false when storage is unavailable.
	 */
	public static function add( $type, $identifier, $data, $failure_reason, $retry_history = array() ) {
		$type       = sanitize_key( $type );
		$identifier = sanitize_text_field( $identifier );

		if ( ! in_array( $type, self::get_valid_types(), true ) ) {
			return new \WP_Error(
				'invalid_type',
				__( 'Invalid dead letter queue item type.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( '' === $identifier ) {
			return new \WP_Error(
				'invalid_identifier',
				__( 'Dead letter queue item must have an identifier.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$item_id = self::generate_item_id( $type, $identifier );

		if ( self::use_custom_table() ) {
			return self::add_to_table( $item_id, $type, $identifier, $data, $failure_reason, $retry_history );
		}

		static::log_error(
			'Dead letter queue table unavailable; item not stored.',
			array(
				'type'       => $type,
				'identifier' => $identifier,
			)
		);

		return false;
	}

	/**
	 * Add item to the custom DB table.
	 *
	 * @param string $item_id        Generated item ID.
	 * @param string $type           Item type.
	 * @param string $identifier     Item identifier.
	 * @param array  $data           Item data.
	 * @param string $failure_reason Failure reason.
	 * @param array  $retry_history  Retry history.
	 * @return bool True on success.
	 */
	private static function add_to_table( $item_id, $type, $identifier, $data, $failure_reason, $retry_history ): bool {
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
			static::log_error(
				'Failed to insert DLQ item into custom table.',
				array(
					'item_id' => $item_id,
					'error'   => $wpdb->last_error,
				)
			);
			return false;
		}

		static::log_event(
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
		 * @param string $item_id        Generated item ID.
		 * @param string $type           Item type.
		 * @param string $identifier     Item identifier.
		 * @param array  $data           Item data.
		 * @param string $failure_reason Failure reason.
		 */
		do_action( 'wp_mcp_ai_dlq_item_added', $item_id, $type, $identifier, $data, $failure_reason );

		return true;
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

		return array();
	}

	/**
	 * Get all items from the custom DB table.
	 *
	 * @param array $filters Optional filters.
	 * @return array Array of DLQ items.
	 */
	private static function get_all_from_table( $filters = array() ): array {
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; static table name and static WHERE fragments.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE $where_clause ORDER BY added_timestamp DESC LIMIT %d",
				array_merge( $params, array( self::MAX_ITEMS ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
	 * Count total items in the custom table.
	 *
	 * @return int Item count.
	 */
	private static function count_items(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table; static table name.
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::get_table_name() );
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

		return null;
	}

	/**
	 * Get a single item from the custom table.
	 *
	 * @param string $item_id DLQ item ID.
	 * @return array|null Item data or null if not found.
	 */
	private static function get_from_table( $item_id ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; static table name.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_table_name() . ' WHERE item_id = %s',
				$item_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public static function retry( $item_id ) {
		$item = self::get( $item_id );

		if ( ! $item ) {
			return new \WP_Error(
				'item_not_found',
				__( 'Dead letter queue item not found.', 'nvoos-content-graph-ai-platform' )
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
				$result = new \WP_Error(
					'unsupported_type',
					__( 'Unsupported item type for retry.', 'nvoos-content-graph-ai-platform' )
				);
		}

		if ( is_wp_error( $result ) ) {
			// Update retry history with failure.
			self::update_retry_history( $item_id, 'failed', $result->get_error_message() );
			return $result;
		}

		// Remove from DLQ on successful retry.
		self::remove( $item_id );

		static::log_event(
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
	 * @param string $item_id       DLQ item ID.
	 * @param string $result        Result of retry ('failed').
	 * @param string $error_message Error message.
	 * @return void
	 */
	private static function update_retry_history( $item_id, $result, $error_message ): void {
		if ( ! self::use_custom_table() ) {
			return;
		}

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->update(
			self::get_table_name(),
			array(
				'retry_history' => wp_json_encode( $retry_history ),
				'retry_count'   => count( $retry_history ),
			),
			array( 'item_id' => $item_id )
		);
	}

	/**
	 * Retry a webhook delivery.
	 *
	 * The base-sender probe is gated on the base plugin being booted
	 * (documented deviation — the base probes the class only, which the
	 * monorepo autoloader can resolve even in standalone installs).
	 *
	 * @param array $item DLQ item.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	protected static function retry_webhook( $item ) {
		if ( ! isset( $item['data']['url'], $item['data']['payload'] ) ) {
			return new \WP_Error(
				'invalid_webhook_data',
				__( 'Webhook data is incomplete.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$url     = $item['data']['url'];
		$payload = $item['data']['payload'];

		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			\WP_MCP_AI_Job_Notifier::send_webhook( $url, $payload );
			return true;
		}

		return new \WP_Error(
			'webhook_sender_unavailable',
			__( 'Webhook sender not available.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Retry a cron job.
	 *
	 * @param array $item DLQ item.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	protected static function retry_cron_job( $item ) {
		if ( ! isset( $item['data']['hook'], $item['data']['args'] ) ) {
			return new \WP_Error(
				'invalid_cron_data',
				__( 'Cron job data is incomplete.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$hook      = $item['data']['hook'];
		$args      = $item['data']['args'];
		$timestamp = isset( $item['data']['timestamp'] ) ? $item['data']['timestamp'] : time();

		$scheduled = wp_schedule_single_event( $timestamp, $hook, $args );

		if ( false === $scheduled ) {
			return new \WP_Error(
				'cron_schedule_failed',
				__( 'Failed to reschedule cron job.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return true;
	}

	/**
	 * Retry an async tool execution.
	 *
	 * The base-executor probe is gated on the base plugin being booted
	 * (documented deviation — see retry_webhook()).
	 *
	 * @param array $item DLQ item.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	protected static function retry_async_tool( $item ) {
		if ( ! isset( $item['data']['job_id'] ) ) {
			return new \WP_Error(
				'invalid_async_tool_data',
				__( 'Async tool data is incomplete.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
			$executor = new \WP_MCP_AI_Tool_Async_Executor();
			$executor->init();
			return true;
		}

		return new \WP_Error(
			'async_executor_unavailable',
			__( 'Async tool executor not available.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Retry a job queue item.
	 *
	 * Resolves the queue manager per install mode: the base
	 * `WP_MCP_AI_Job_Queue_Manager` in monolith installs (probe gated on
	 * the base plugin being booted — documented deviation), this package's
	 * `JobQueueManager` standalone.
	 *
	 * @param array $item DLQ item.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	protected static function retry_job_queue( $item ) {
		if ( ! isset( $item['data']['job_id'], $item['data']['job_data'] ) ) {
			return new \WP_Error(
				'invalid_job_queue_data',
				__( 'Job queue data is incomplete.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$job_id   = $item['data']['job_id'];
		$job_data = $item['data']['job_data'];

		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Job_Queue_Manager' ) ) {
			$result = \WP_MCP_AI_Job_Queue_Manager::enqueue_job( $job_id, $job_data );
		} elseif ( class_exists( __NAMESPACE__ . '\JobQueueManager' ) ) {
			$result = JobQueueManager::enqueue_job( $job_id, $job_data );
		} else {
			return new \WP_Error(
				'job_queue_manager_unavailable',
				__( 'Job queue manager not available.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( ! $result ) {
			return new \WP_Error(
				'job_enqueue_failed',
				__( 'Failed to re-enqueue job.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return true;
	}

	/**
	 * Mark an item as dismissed.
	 *
	 * @param string $item_id DLQ item ID.
	 * @return bool True on success, false on failure.
	 */
	public static function dismiss( $item_id ) {
		$item_id = sanitize_key( $item_id );

		if ( ! self::use_custom_table() ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
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

	/**
	 * Remove an item from the dead letter queue.
	 *
	 * @param string $item_id DLQ item ID.
	 * @return bool True on success, false on failure.
	 */
	public static function remove( $item_id ) {
		$item_id = sanitize_key( $item_id );

		if ( ! self::use_custom_table() ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$deleted = $wpdb->delete(
			self::get_table_name(),
			array( 'item_id' => $item_id )
		);

		return false !== $deleted;
	}

	/**
	 * Purge old items from the dead letter queue.
	 *
	 * @param int|null $retention_days Number of days to retain items.
	 * @return int Number of items purged.
	 */
	public static function purge_old( $retention_days = null ) {
		if ( null === $retention_days ) {
			$retention_days = self::DEFAULT_RETENTION_DAYS;
		}

		$retention_days = absint( $retention_days );
		$cutoff_time    = time() - ( $retention_days * DAY_IN_SECONDS );

		if ( ! self::use_custom_table() ) {
			return 0;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; static table name.
		$purged = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::get_table_name() . ' WHERE added_timestamp < %d',
				$cutoff_time
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $purged > 0 ) {
			static::log_event(
				'dlq_purged',
				'Old items purged from dead letter queue.',
				array( 'purged_count' => $purged )
			);
		}

		return (int) $purged;
	}

	/**
	 * Prune oldest items to make room for new ones (custom table).
	 *
	 * @param int $count Number of oldest items to remove.
	 * @return int Number of items pruned.
	 */
	private static function prune_oldest_from_table( $count ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; static table name.
		$pruned = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::get_table_name() . ' ORDER BY added_timestamp ASC LIMIT %d',
				$count
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $pruned;
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

		return array(
			'total'       => 0,
			'by_type'     => array(),
			'dismissed'   => 0,
			'active'      => 0,
			'oldest_date' => null,
			'newest_date' => null,
		);
	}

	/**
	 * Get stats from the custom table.
	 *
	 * @return array Statistics.
	 */
	private static function get_stats_from_table() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table; static table name.
		$total     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::get_table_name() );
		$dismissed = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE dismissed = 1' );

		$type_rows = $wpdb->get_results( 'SELECT type, COUNT(*) as cnt FROM ' . self::get_table_name() . ' GROUP BY type' );

		$by_type = array();
		foreach ( $type_rows as $row ) {
			$by_type[ $row->type ] = (int) $row->cnt;
		}

		$oldest = $wpdb->get_var( 'SELECT added_at FROM ' . self::get_table_name() . ' ORDER BY added_timestamp ASC LIMIT 1' );
		$newest = $wpdb->get_var( 'SELECT added_at FROM ' . self::get_table_name() . ' ORDER BY added_timestamp DESC LIMIT 1' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

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
	 * Byte-identical to the base — `TYPE_MESH_QUERY` is intentionally
	 * absent (the base declares the constant but never accepts it).
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
	 * Schedule periodic cleanup of old DLQ items.
	 *
	 * @return void
	 */
	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'wp_mcp_ai_dlq_cleanup' ) ) {
			wp_schedule_event( time(), 'weekly', 'wp_mcp_ai_dlq_cleanup' );
		}
	}

	/**
	 * Clean up old DLQ items.
	 *
	 * Hooked to the 'wp_mcp_ai_dlq_cleanup' cron event.
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		$retention_days = self::DEFAULT_RETENTION_DAYS;

		/**
		 * Filters the DLQ retention period in days.
		 *
		 * @param int $retention_days Retention days.
		 */
		$retention_days = apply_filters( 'wp_mcp_ai_dlq_retention_days', $retention_days );

		self::purge_old( $retention_days );
	}

	// ─── Seams ──────────────────────────────────────────────────────

	/**
	 * Log a queue error event.
	 *
	 * Dormant standalone until the platform's logging piece ports; the
	 * base logger is targeted in monolith installs.
	 *
	 * @param string $message Human-readable message.
	 * @param array  $data    Structured event data.
	 * @return void
	 */
	protected static function log_error( $message, $data = array() ): void {
		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			return;
		}

		\WP_MCP_AI_Logger::log_error( $message, $data );
	}

	/**
	 * Log a queue event.
	 *
	 * Dormant standalone until the platform's logging piece ports; the
	 * base logger is targeted in monolith installs.
	 *
	 * @param string $event   Event identifier.
	 * @param string $message Human-readable message.
	 * @param array  $data    Structured event data.
	 * @return void
	 */
	protected static function log_event( $event, $message, $data = array() ): void {
		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			return;
		}

		\WP_MCP_AI_Logger::log_event( $event, $message, $data );
	}
}
