<?php
/**
 * Job Store — persistent job tracking for QueueClientInterface.
 *
 * Provides durable, transport-agnostic job state storage in a custom
 * database table. Used by the WordPress QueueClient adapter as the
 * source of truth for job status, results, and lifecycle events.
 *
 * @package WP_MCP_AI
 * @since   1.1.45
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job Store class.
 *
 * CRUD operations for the wp_mcp_ai_jobs table.
 *
 * @since 1.1.45
 */
class WP_MCP_AI_Job_Store {

	/**
	 * Table name (without prefix).
	 *
	 * @var string
	 */
	const TABLE_NAME = 'mcp_ai_jobs';

	/**
	 * Cached result of the table-existence check.
	 *
	 * @var bool|null
	 */
	private static $table_exists_cache = null;

	/**
	 * Valid job statuses.
	 *
	 * @var string[]
	 */
	const VALID_STATUSES = array(
		'queued',
		'running',
		'completed',
		'failed',
		'cancelled',
	);

	/**
	 * Create the jobs table.
	 *
	 * Uses dbDelta() for safe, idempotent schema management.
	 *
	 * @since 1.1.45
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id VARCHAR(64) NOT NULL DEFAULT '',
			handler VARCHAR(255) NOT NULL DEFAULT '',
			payload LONGTEXT,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			result LONGTEXT,
			error TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			started_at DATETIME NULL,
			completed_at DATETIME NULL,
			attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			max_attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 3,
			claimed_by VARCHAR(64) NULL,
			claimed_at DATETIME NULL,
			user_id BIGINT(20) UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY status_created (status, created_at),
			KEY handler_status (handler, status),
			KEY claimed (claimed_by, claimed_at)
		) {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );

		self::$table_exists_cache = true;
	}

	/**
	 * Check if the jobs table exists.
	 *
	 * @since 1.1.45
	 * @return bool True if table exists.
	 */
	public static function table_exists() {
		if ( null !== self::$table_exists_cache ) {
			return self::$table_exists_cache;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		self::$table_exists_cache = ( $table_name === $exists );

		return self::$table_exists_cache;
	}

	/**
	 * Get the full table name with prefix.
	 *
	 * @since 1.1.45
	 * @return string Full table name.
	 */
	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Insert a new job into the store.
	 *
	 * @since 1.1.45
	 *
	 * @param array $data {
	 *     Job data.
	 *
	 *     @type string $job_id   Unique job identifier.
	 *     @type string $handler  Handler class name or ID.
	 *     @type string $payload  JSON-encoded payload.
	 *     @type string $status   Initial status (default 'queued').
	 *     @type int    $user_id  WordPress user ID who enqueued the job.
	 * }
	 * @return bool True on success, false on failure.
	 */
	public static function insert( array $data ) {
		if ( ! self::table_exists() ) {
			return false;
		}

		global $wpdb;

		$defaults = array(
			'job_id'  => '',
			'handler' => '',
			'payload' => '',
			'status'  => 'queued',
			'user_id' => null,
		);

		$data = wp_parse_args( $data, $defaults );

		// Validate required fields.
		if ( empty( $data['job_id'] ) || empty( $data['handler'] ) ) {
			return false;
		}

		// Validate status.
		if ( ! in_array( $data['status'], self::VALID_STATUSES, true ) ) {
			$data['status'] = 'queued';
		}

		$row = array(
			'job_id'  => sanitize_text_field( $data['job_id'] ),
			'handler' => sanitize_text_field( $data['handler'] ),
			'payload' => $data['payload'],
			'status'  => $data['status'],
			'user_id' => $data['user_id'] ? absint( $data['user_id'] ) : null,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
		$inserted = $wpdb->insert( self::get_table_name(), $row );

		if ( false === $inserted ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to insert job into job store.',
					array(
						'job_id' => $data['job_id'],
						'error'  => $wpdb->last_error,
					)
				);
			}
			return false;
		}

		return true;
	}

	/**
	 * Get a job by job_id.
	 *
	 * @since 1.1.45
	 *
	 * @param string $job_id Job identifier.
	 * @return array|null Job row as associative array, or null if not found.
	 */
	public static function get( $job_id ) {
		if ( ! self::table_exists() ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_table_name() . ' WHERE job_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				sanitize_text_field( $job_id )
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Update job status.
	 *
	 * @since 1.1.45
	 *
	 * @param string $job_id Job identifier.
	 * @param string $status New status.
	 * @return bool True on success.
	 */
	public static function update_status( $job_id, $status ) {
		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			return false;
		}

		if ( ! self::table_exists() ) {
			return false;
		}

		global $wpdb;

		$data = array( 'status' => $status );

		// Set timestamps based on status transitions.
		if ( 'running' === $status ) {
			$data['started_at'] = current_time( 'mysql', true );
		} elseif ( in_array( $status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			$data['completed_at'] = current_time( 'mysql', true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
		$updated = $wpdb->update(
			self::get_table_name(),
			$data,
			array( 'job_id' => sanitize_text_field( $job_id ) )
		);

		return false !== $updated;
	}

	/**
	 * Mark a job as completed with result.
	 *
	 * @since 1.1.45
	 *
	 * @param string $job_id Job identifier.
	 * @param mixed  $result Result data (will be JSON-encoded).
	 * @param string $error  Error message (optional).
	 * @return bool True on success.
	 */
	public static function complete( $job_id, $result = null, $error = null ) {
		if ( ! self::table_exists() ) {
			return false;
		}

		global $wpdb;

		$data = array(
			'status'       => null === $error ? 'completed' : 'failed',
			'completed_at' => current_time( 'mysql', true ),
			'result'       => null !== $result ? wp_json_encode( $result ) : null,
			'error'        => $error ? sanitize_text_field( $error ) : null,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
		$updated = $wpdb->update(
			self::get_table_name(),
			$data,
			array( 'job_id' => sanitize_text_field( $job_id ) )
		);

		return false !== $updated;
	}

	/**
	 * Increment attempt counter.
	 *
	 * @since 1.1.45
	 *
	 * @param string $job_id Job identifier.
	 * @return bool True on success.
	 */
	public static function increment_attempts( $job_id ) {
		if ( ! self::table_exists() ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::get_table_name() . ' SET attempts = attempts + 1 WHERE job_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				sanitize_text_field( $job_id )
			)
		);

		return false !== $updated;
	}

	/**
	 * Claim a job for processing.
	 *
	 * Uses atomic UPDATE with claimed_by check to prevent double-claiming.
	 *
	 * @since 1.1.45
	 *
	 * @param string $job_id    Job identifier.
	 * @param string $worker_id Unique worker identifier.
	 * @return bool True if claimed successfully, false if already claimed.
	 */
	public static function claim( $job_id, $worker_id ) {
		if ( ! self::table_exists() ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::get_table_name() . ' SET claimed_by = %s, claimed_at = %s, status = %s WHERE job_id = %s AND claimed_by IS NULL AND status = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				sanitize_text_field( $worker_id ),
				current_time( 'mysql', true ),
				'running',
				sanitize_text_field( $job_id ),
				'queued'
			)
		);

		return 1 === $updated;
	}

	/**
	 * Release a claimed job back to queued state.
	 *
	 * @since 1.1.45
	 *
	 * @param string $job_id Job identifier.
	 * @return bool True on success.
	 */
	public static function release( $job_id ) {
		if ( ! self::table_exists() ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
		$updated = $wpdb->update(
			self::get_table_name(),
			array(
				'claimed_by' => null,
				'claimed_at' => null,
				'status'     => 'queued',
			),
			array( 'job_id' => sanitize_text_field( $job_id ) )
		);

		return false !== $updated;
	}

	/**
	 * Delete a job from the store.
	 *
	 * @since 1.1.45
	 *
	 * @param string $job_id Job identifier.
	 * @return bool True on success.
	 */
	public static function delete( $job_id ) {
		if ( ! self::table_exists() ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
		$deleted = $wpdb->delete(
			self::get_table_name(),
			array( 'job_id' => sanitize_text_field( $job_id ) )
		);

		return false !== $deleted;
	}

	/**
	 * Delete completed/failed/cancelled jobs older than a given age.
	 *
	 * @since 1.1.45
	 *
	 * @param int $max_age_seconds Maximum age in seconds (default: 7 days).
	 * @return int Number of deleted rows.
	 */
	public static function purge_completed( $max_age_seconds = 604800 ) {
		if ( ! self::table_exists() ) {
			return 0;
		}

		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $max_age_seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::get_table_name() . ' WHERE status IN (%s, %s, %s) AND completed_at < %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'completed',
				'failed',
				'cancelled',
				$cutoff
			)
		);

		return (int) $deleted;
	}

	/**
	 * Get job store statistics (counts by status).
	 *
	 * @since 1.3.0
	 *
	 * @return array{total: int, queued: int, running: int, completed: int, failed: int, cancelled: int}
	 */
	public static function get_stats() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array(
				'queued'    => 0,
				'running'   => 0,
				'completed' => 0,
				'failed'    => 0,
				'cancelled' => 0,
				'total'     => 0,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			'SELECT status, COUNT(*) as cnt FROM ' . self::get_table_name() . ' GROUP BY status', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name via get_table_name(), hardcoded column names.
			ARRAY_A
		);

		$counts = array(
			'queued'    => 0,
			'running'   => 0,
			'completed' => 0,
			'failed'    => 0,
			'cancelled' => 0,
		);

		$total = 0;
		foreach ( $rows as $row ) {
			$status = $row['status'];
			$cnt    = (int) $row['cnt'];
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = $cnt;
			}
			$total += $cnt;
		}

		$counts['total'] = $total;

		return $counts;
	}

	/**
	 * List jobs with optional filters.
	 *
	 * @since 1.1.45
	 *
	 * @param array $filters {
	 *     Optional filters.
	 *
	 *     @type string $status   Filter by status.
	 *     @type string $handler  Filter by handler.
	 *     @type int    $user_id  Filter by user ID.
	 * }
	 * @param int   $limit   Maximum results (1-100, default 50).
	 * @return array Array of job rows.
	 */
	public static function list_jobs( array $filters = array(), $limit = 50 ) {
		if ( ! self::table_exists() ) {
			return array();
		}

		global $wpdb;

		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = sanitize_text_field( $filters['status'] );
		}

		if ( ! empty( $filters['handler'] ) ) {
			$where[] = 'handler = %s';
			$args[]  = sanitize_text_field( $filters['handler'] );
		}

		if ( ! empty( $filters['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$args[]  = absint( $filters['user_id'] );
		}

		$where_clause = implode( ' AND ', $where );
		$limit        = min( 100, max( 1, absint( $limit ) ) );
		$args[]       = $limit;

		// Build the full prepared query string.
		$sql = 'SELECT * FROM ' . self::get_table_name() . ' WHERE ' . $where_clause . ' ORDER BY created_at DESC LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $where_clause is built from allowed filter keys only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return $rows ? $rows : array();
	}
}
