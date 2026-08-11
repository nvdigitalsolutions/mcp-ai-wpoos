<?php
/**
 * Concurrent job queue manager for API request throttling.
 *
 * Storage: Custom DB table `wp_mcp_ai_concurrent_jobs` (replaces wp_options as of v1.1.37).
 * The old `wp_mcp_ai_job_queue_state` and `wp_mcp_ai_active_jobs` options are preserved
 * for one release cycle and will be cleaned up in a future release.
 *
 * @package WP_MCP_AI
 * @since 1.1.37 Storage migrated from wp_options to custom DB table for concurrency safety.
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages concurrent API requests to prevent overwhelming the service.
 */
class WP_MCP_AI_Job_Queue_Manager {

	/**
	 * Database table name for concurrent job storage.
	 *
	 * @since 1.1.37
	 * @var string
	 */
	const TABLE_NAME = 'mcp_ai_concurrent_jobs';

	/**
	 * Legacy option name for storing queue state.
	 *
	 * @deprecated 1.1.37 Use custom DB table instead.
	 * @var string
	 */
	const QUEUE_STATE_OPTION = 'wp_mcp_ai_job_queue_state';

	/**
	 * Legacy option name for storing active jobs.
	 *
	 * @deprecated 1.1.37 Use custom DB table instead.
	 * @var string
	 */
	const ACTIVE_JOBS_OPTION = 'wp_mcp_ai_active_jobs';

	/**
	 * Default maximum concurrent jobs.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_CONCURRENT = 3;

	/**
	 * Default job timeout in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_JOB_TIMEOUT = 300;

	/**
	 * Job priorities (legacy - use SLA tiers for new code).
	 */
	const PRIORITY_HIGH   = 10;
	const PRIORITY_NORMAL = 5;
	const PRIORITY_LOW    = 1;

	/**
	 * Job statuses.
	 *
	 * @since 1.1.37
	 */
	const STATUS_PENDING  = 'pending';
	const STATUS_ACTIVE   = 'active';
	const STATUS_FAILED   = 'failed';
	const STATUS_COMPLETE = 'complete';

	/**
	 * Flag tracking whether the custom table is available.
	 *
	 * @since 1.1.37
	 * @var bool|null
	 */
	private static $table_exists = null;

	/**
	 * Create the concurrent job queue database table.
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
			job_id VARCHAR(64) NOT NULL DEFAULT '',
			callable_class VARCHAR(255) DEFAULT NULL,
			callable_method VARCHAR(255) DEFAULT NULL,
			args LONGTEXT DEFAULT NULL,
			priority INT(11) NOT NULL DEFAULT 5,
			sla_tier VARCHAR(32) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			timeout INT(11) UNSIGNED NOT NULL DEFAULT 300,
			retry_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
			max_retries INT(11) UNSIGNED NOT NULL DEFAULT 3,
			last_error TEXT DEFAULT NULL,
			enqueued_at BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			started_at BIGINT(20) UNSIGNED DEFAULT NULL,
			completed_at BIGINT(20) UNSIGNED DEFAULT NULL,
			failed_at BIGINT(20) UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY status_priority (status, priority, enqueued_at),
			KEY sla_tier (sla_tier, status)
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
	 * Check if MySQL version supports SKIP LOCKED (8.0+).
	 *
	 * @since 1.1.37
	 * @return bool True if SKIP LOCKED is supported.
	 */
	private static function supports_skip_locked() {
		global $wpdb;
		$version = $wpdb->db_version();
		return version_compare( $version, '8.0.0', '>=' );
	}

	/**
	 * Enqueue a job for execution.
	 *
	 * Supports both legacy priority values and SLA tier-based priorities.
	 *
	 * @param string $job_id   Unique job identifier.
	 * @param array  $job_data Job data including callable and arguments.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function enqueue_job( $job_id, array $job_data ) {
		$job_id = sanitize_key( $job_id );

		if ( '' === $job_id ) {
			return false;
		}

		// Validate job data.
		if ( ! isset( $job_data['callable'] ) || ! is_callable( $job_data['callable'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Cannot enqueue job with invalid callable.',
				array( 'job_id' => $job_id )
			);
			return false;
		}

		// Check if job already exists.
		if ( self::job_exists( $job_id ) ) {
			WP_MCP_AI_Logger::log_event(
				'job_already_queued',
				'Job already exists in queue.',
				array( 'job_id' => $job_id )
			);
			return false;
		}

		// Determine priority using SLA Manager if available and enabled.
		$priority = self::PRIORITY_NORMAL;
		$sla_tier = null;

		if ( class_exists( 'WP_MCP_AI_SLA_Manager' ) && WP_MCP_AI_SLA_Manager::is_enabled() ) {
			if ( isset( $job_data['sla_tier'] ) ) {
				$sla_tier = sanitize_key( $job_data['sla_tier'] );
				$priority = WP_MCP_AI_SLA_Manager::get_priority( $sla_tier );
			} elseif ( isset( $job_data['tool'] ) && is_object( $job_data['tool'] ) ) {
				$sla_tier = WP_MCP_AI_SLA_Manager::get_tier_for_tool( $job_data['tool'] );
				$priority = WP_MCP_AI_SLA_Manager::get_priority( $sla_tier );
			}
		}

		// Allow explicit priority override (legacy behavior).
		if ( isset( $job_data['priority'] ) ) {
			$priority = absint( $job_data['priority'] );
		}

		$timeout = isset( $job_data['timeout'] ) ? absint( $job_data['timeout'] ) : self::DEFAULT_JOB_TIMEOUT;

		// Extract callable info for storage.
		$callable_class  = null;
		$callable_method = null;
		if ( is_array( $job_data['callable'] ) ) {
			if ( is_object( $job_data['callable'][0] ) ) {
				$callable_class = get_class( $job_data['callable'][0] );
			} elseif ( is_string( $job_data['callable'][0] ) ) {
				$callable_class = $job_data['callable'][0];
			}
			$callable_method = isset( $job_data['callable'][1] ) ? $job_data['callable'][1] : null;
		} elseif ( is_string( $job_data['callable'] ) ) {
			$callable_class = $job_data['callable'];
		}

		// Use custom table if available.
		if ( self::use_custom_table() ) {
			return self::enqueue_to_table( $job_id, $job_data, $callable_class, $callable_method, $priority, $sla_tier, $timeout );
		}

		// Fallback: legacy option-based storage.
		return self::enqueue_to_option( $job_id, $job_data, $priority, $sla_tier, $timeout );
	}

	/**
	 * Check if a job exists (in table or option).
	 *
	 * @since 1.1.37
	 *
	 * @param string $job_id Job identifier.
	 * @return bool True if job exists.
	 */
	private static function job_exists( $job_id ) {
		if ( self::use_custom_table() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE job_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$job_id
				)
			);
			return $count > 0;
		}

		$queue = self::get_queue_state_from_option();
		return isset( $queue[ $job_id ] );
	}

	/**
	 * Enqueue job to custom DB table.
	 *
	 * @since 1.1.37
	 *
	 * @param string      $job_id          Job ID.
	 * @param array       $job_data        Full job data.
	 * @param string|null $callable_class  Callable class name.
	 * @param string|null $callable_method Callable method name.
	 * @param int         $priority        Job priority.
	 * @param string|null $sla_tier        SLA tier.
	 * @param int         $timeout         Job timeout.
	 * @return bool True on success.
	 */
	private static function enqueue_to_table( $job_id, $job_data, $callable_class, $callable_method, $priority, $sla_tier, $timeout ) {
		global $wpdb;

		$row = array(
			'job_id'          => $job_id,
			'callable_class'  => $callable_class,
			'callable_method' => $callable_method,
			'args'            => wp_json_encode( isset( $job_data['args'] ) ? $job_data['args'] : array() ),
			'priority'        => $priority,
			'sla_tier'        => $sla_tier,
			'status'          => self::STATUS_PENDING,
			'timeout'         => $timeout,
			'retry_count'     => 0,
			'max_retries'     => 3,
			'enqueued_at'     => time(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
		$inserted = $wpdb->insert( self::get_table_name(), $row );

		if ( false === $inserted ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to insert job into custom table.',
				array(
					'job_id' => $job_id,
					'error'  => $wpdb->last_error,
				)
			);
			return false;
		}

		WP_MCP_AI_Logger::log_event(
			'job_enqueued',
			'Job added to queue.',
			array(
				'job_id'   => $job_id,
				'priority' => $priority,
				'sla_tier' => $sla_tier,
			)
		);

		return true;
	}

	/**
	 * Enqueue job to legacy option storage.
	 *
	 * @deprecated 1.1.37 Use enqueue_to_table() instead.
	 *
	 * @param string      $job_id   Job identifier.
	 * @param array       $job_data Full job data.
	 * @param int         $priority Job priority.
	 * @param string|null $sla_tier SLA tier.
	 * @param int         $timeout  Job timeout.
	 * @return bool True on success.
	 */
	private static function enqueue_to_option( $job_id, $job_data, $priority, $sla_tier, $timeout ) {
		$queue = self::get_queue_state_from_option();

		if ( isset( $queue[ $job_id ] ) ) {
			WP_MCP_AI_Logger::log_event(
				'job_already_queued',
				'Job already exists in queue.',
				array( 'job_id' => $job_id )
			);
			return false;
		}

		$queue[ $job_id ] = array(
			'callable'    => $job_data['callable'],
			'args'        => isset( $job_data['args'] ) ? $job_data['args'] : array(),
			'priority'    => $priority,
			'sla_tier'    => $sla_tier,
			'timeout'     => $timeout,
			'enqueued_at' => time(),
			'retry_count' => 0,
			'status'      => self::STATUS_PENDING,
		);

		$saved = self::save_queue_state_to_option( $queue );

		if ( $saved ) {
			WP_MCP_AI_Logger::log_event(
				'job_enqueued',
				'Job added to queue.',
				array(
					'job_id'   => $job_id,
					'priority' => $priority,
					'sla_tier' => $sla_tier,
				)
			);
		}

		return $saved;
	}

	/**
	 * Process the job queue.
	 *
	 * Respects SLA tier-based concurrency limits if enabled.
	 *
	 * @param int $max_concurrent Maximum number of concurrent jobs.
	 *
	 * @return array Processing results.
	 */
	public static function process_queue( $max_concurrent = null ) {
		if ( null === $max_concurrent ) {
			if ( class_exists( 'WP_MCP_AI_Resource_Manager' ) ) {
				$resource_mgr   = WP_MCP_AI_Resource_Manager::instance();
				$max_concurrent = $resource_mgr->get_max_concurrent_requests();
			} else {
				$max_concurrent = self::DEFAULT_MAX_CONCURRENT;
			}
		}

		$max_concurrent = max( 1, absint( $max_concurrent ) );

		// Clean up stale active jobs.
		self::cleanup_stale_jobs();

		$active_count = self::count_active_jobs();

		// Check if we can process more jobs.
		if ( $active_count >= $max_concurrent ) {
			WP_MCP_AI_Logger::log_event(
				'queue_at_capacity',
				'Job queue at maximum concurrent capacity.',
				array(
					'active_count'   => $active_count,
					'max_concurrent' => $max_concurrent,
				)
			);
			return array(
				'processed' => 0,
				'active'    => $active_count,
				'reason'    => 'at_capacity',
			);
		}

		$slots_available = $max_concurrent - $active_count;

		// Claim pending jobs atomically.
		$claimed_jobs = self::claim_pending_jobs( $slots_available );

		if ( empty( $claimed_jobs ) ) {
			return array(
				'processed' => 0,
				'active'    => $active_count,
				'reason'    => 'no_pending_jobs',
			);
		}

		// Apply SLA tier limits if available.
		if ( class_exists( 'WP_MCP_AI_SLA_Manager' ) && WP_MCP_AI_SLA_Manager::is_enabled() ) {
			$claimed_jobs = self::apply_sla_tier_limits_to_claimed( $claimed_jobs );
		}

		$processed = 0;

		foreach ( $claimed_jobs as $job ) {
			$job_id = $job['job_id'];

			// Execute the job.
			$result = self::call_job( $job );

			if ( is_wp_error( $result ) ) {
				self::handle_job_failure_table( $job, $result );
			} else {
				self::mark_job_complete_table( $job_id );
			}

			++$processed;
		}

		WP_MCP_AI_Logger::log_event(
			'queue_processed',
			'Job queue processing cycle completed.',
			array(
				'processed' => $processed,
				'active'    => self::count_active_jobs(),
			)
		);

		return array(
			'processed' => $processed,
			'active'    => self::count_active_jobs(),
			'reason'    => 'success',
		);
	}

	/**
	 * Apply SLA tier-based concurrency limits to claimed jobs.
	 *
	 * @since 1.1.37
	 *
	 * @param array $claimed_jobs Claimed jobs.
	 * @return array Filtered jobs respecting tier limits.
	 */
	private static function apply_sla_tier_limits_to_claimed( $claimed_jobs ) {
		$active_by_tier = array();

		// Count active jobs per tier.
		if ( self::use_custom_table() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				'SELECT sla_tier, COUNT(*) as cnt FROM ' . self::get_table_name() . " WHERE status = 'active' AND sla_tier IS NOT NULL GROUP BY sla_tier" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
			foreach ( $rows as $row ) {
				$active_by_tier[ $row->sla_tier ] = (int) $row->cnt;
			}
		}

		$filtered = array();
		foreach ( $claimed_jobs as $job ) {
			$tier = isset( $job['sla_tier'] ) ? $job['sla_tier'] : null;

			if ( ! $tier ) {
				$filtered[] = $job;
				continue;
			}

			$tier_max_concurrent = WP_MCP_AI_SLA_Manager::get_default_concurrent( $tier );
			$tier_active_count   = isset( $active_by_tier[ $tier ] ) ? $active_by_tier[ $tier ] : 0;

			if ( $tier_active_count < $tier_max_concurrent ) {
				$filtered[] = $job;
				if ( ! isset( $active_by_tier[ $tier ] ) ) {
					$active_by_tier[ $tier ] = 0;
				}
				++$active_by_tier[ $tier ];
			} else {
				// Release the job back to pending if tier is at capacity.
				self::release_job( $job['job_id'] );
			}
		}

		return $filtered;
	}

	/**
	 * Atomically claim pending jobs from the custom table.
	 *
	 * Uses SELECT ... FOR UPDATE SKIP LOCKED (MySQL 8.0+) or FOR UPDATE (fallback)
	 * to prevent duplicate job execution across concurrent workers.
	 *
	 * @since 1.1.37
	 *
	 * @param int $limit Maximum jobs to claim.
	 * @return array Array of claimed job rows.
	 */
	private static function claim_pending_jobs( $limit ) {
		if ( ! self::use_custom_table() ) {
			// Fallback: legacy option-based claiming.
			return self::claim_pending_jobs_from_option( $limit );
		}

		global $wpdb;
		$table_name = self::get_table_name();
		$claimed    = array();

		$skip_locked = self::supports_skip_locked() ? ' SKIP LOCKED' : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table, transactional claim required.
		$wpdb->query( 'START TRANSACTION' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE status = %s ORDER BY priority DESC, enqueued_at ASC LIMIT %d FOR UPDATE" . $skip_locked, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				self::STATUS_PENDING,
				$limit
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			// Mark as active.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$table_name,
				array(
					'status'     => self::STATUS_ACTIVE,
					'started_at' => time(),
				),
				array( 'id' => $row['id'] )
			);

			if ( false !== $updated ) {
				$row['args'] = json_decode( $row['args'], true );
				$claimed[]   = $row;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( 'COMMIT' );

		return $claimed;
	}

	/**
	 * Claim pending jobs from legacy option storage.
	 *
	 * @deprecated 1.1.37 Use claim_pending_jobs() instead.
	 *
	 * @param int $limit Maximum jobs to claim.
	 * @return array Array of claimed job rows.
	 */
	private static function claim_pending_jobs_from_option( $limit ) {
		$queue        = self::get_queue_state_from_option();
		$pending_jobs = self::get_pending_jobs_from_option( $queue );
		$claimed      = array();
		$count        = 0;

		foreach ( $pending_jobs as $job_id => $job ) {
			if ( $count >= $limit ) {
				break;
			}

			if ( self::mark_job_active_in_option( $job_id, $job ) ) {
				$claimed[] = array_merge( array( 'job_id' => $job_id ), $job );
				++$count;
			}
		}

		return $claimed;
	}

	/**
	 * Release a claimed job back to pending status.
	 *
	 * @since 1.1.37
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	private static function release_job( $job_id ) {
		if ( ! self::use_custom_table() ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::get_table_name(),
			array(
				'status'     => self::STATUS_PENDING,
				'started_at' => null,
			),
			array(
				'job_id' => $job_id,
				'status' => self::STATUS_ACTIVE,
			)
		);
	}

	/**
	 * Count active jobs.
	 *
	 * @since 1.1.37
	 * @return int Active job count.
	 */
	public static function count_active_jobs() {
		if ( self::use_custom_table() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE status = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					self::STATUS_ACTIVE
				)
			);
		}

		$active_jobs = self::get_active_jobs_from_option();
		return count( $active_jobs );
	}

	/**
	 * Call a job from table row data.
	 *
	 * @since 1.1.37
	 *
	 * @param array $job Job row data.
	 * @return mixed|WP_Error Job result or error.
	 */
	private static function call_job( $job ) {
		WP_MCP_AI_Logger::log_event(
			'job_executing',
			'Executing job.',
			array( 'job_id' => $job['job_id'] )
		);

		try {
			$callable = null;

			if ( ! empty( $job['callable_class'] ) && ! empty( $job['callable_method'] ) ) {
				if ( class_exists( $job['callable_class'] ) ) {
					$instance = new $job['callable_class']();
					$callable = array( $instance, $job['callable_method'] );
				}
			} elseif ( ! empty( $job['callable_class'] ) && function_exists( $job['callable_class'] ) ) {
				$callable = $job['callable_class'];
			}

			if ( ! is_callable( $callable ) ) {
				return new WP_Error(
					'wp_mcp_ai_job_not_callable',
					'Job callable is not valid.',
					array( 'job_id' => $job['job_id'] )
				);
			}

			$args   = isset( $job['args'] ) ? $job['args'] : array();
			$result = call_user_func_array( $callable, $args );
			return $result;
		} catch ( Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_job_exception',
				$e->getMessage(),
				array(
					'job_id'    => $job['job_id'],
					'exception' => $e,
				)
			);
		}
	}

	/**
	 * Handle job failure from table row.
	 *
	 * @since 1.1.37
	 *
	 * @param array    $job   Job row data.
	 * @param WP_Error $error Error object.
	 * @return void
	 */
	private static function handle_job_failure_table( $job, $error ) {
		$job_id      = $job['job_id'];
		$retry_count = isset( $job['retry_count'] ) ? (int) $job['retry_count'] : 0;
		$max_retries = isset( $job['max_retries'] ) ? (int) $job['max_retries'] : 3;

		WP_MCP_AI_Logger::log_error(
			'Job execution failed.',
			array(
				'job_id'        => $job_id,
				'retry_count'   => $retry_count,
				'error_code'    => $error->get_error_code(),
				'error_message' => $error->get_error_message(),
			)
		);

		if ( $retry_count < $max_retries ) {
			// Retry: mark as pending with incremented retry count.
			if ( self::use_custom_table() ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					self::get_table_name(),
					array(
						'status'      => self::STATUS_PENDING,
						'started_at'  => null,
						'retry_count' => $retry_count + 1,
						'last_error'  => $error->get_error_message(),
					),
					array( 'job_id' => $job_id )
				);
			}
			return;
		}

		// Max retries exceeded - move to dead letter queue.
		if ( class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			$retry_history = array();
			for ( $i = 0; $i <= $retry_count; $i++ ) {
				$retry_history[] = array(
					'timestamp' => time() - ( ( $retry_count - $i ) * 300 ),
					'result'    => 'failed',
					'error'     => $error->get_error_message(),
				);
			}

			WP_MCP_AI_Dead_Letter_Queue::add(
				WP_MCP_AI_Dead_Letter_Queue::TYPE_JOB_QUEUE,
				$job_id,
				array(
					'job_id'   => $job_id,
					'job_data' => $job,
				),
				$error->get_error_message(),
				$retry_history
			);
		}

		// Mark as failed.
		if ( self::use_custom_table() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				self::get_table_name(),
				array(
					'status'     => self::STATUS_FAILED,
					'failed_at'  => time(),
					'last_error' => $error->get_error_message(),
				),
				array( 'job_id' => $job_id )
			);
		}
	}

	/**
	 * Mark a job as complete in the custom table.
	 *
	 * @since 1.1.37
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	private static function mark_job_complete_table( $job_id ) {
		if ( self::use_custom_table() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				self::get_table_name(),
				array( 'job_id' => $job_id )
			);
		}

		WP_MCP_AI_Logger::log_event(
			'job_completed',
			'Job completed successfully.',
			array( 'job_id' => $job_id )
		);
	}

	/**
	 * Clean up stale active jobs.
	 *
	 * @return int Number of jobs cleaned up.
	 */
	protected static function cleanup_stale_jobs() {
		if ( self::use_custom_table() ) {
			global $wpdb;
			$now = time();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cleaned = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::get_table_name() . ' SET status = %s, last_error = %s WHERE status = %s AND started_at IS NOT NULL AND (started_at + timeout) < %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					self::STATUS_PENDING,
					'Job timed out',
					self::STATUS_ACTIVE,
					$now
				)
			);

			if ( $cleaned > 0 ) {
				WP_MCP_AI_Logger::log_event(
					'job_timeout_cleanup',
					'Stale jobs cleaned up.',
					array( 'count' => $cleaned )
				);
			}

			return (int) $cleaned;
		}

		// Legacy option cleanup.
		$active_jobs  = self::get_active_jobs_from_option();
		$current_time = time();
		$cleaned      = 0;

		foreach ( $active_jobs as $job_id => $job_data ) {
			$started_at = isset( $job_data['started_at'] ) ? absint( $job_data['started_at'] ) : 0;
			$timeout    = isset( $job_data['timeout'] ) ? absint( $job_data['timeout'] ) : self::DEFAULT_JOB_TIMEOUT;

			if ( $current_time - $started_at > $timeout ) {
				unset( $active_jobs[ $job_id ] );

				WP_MCP_AI_Logger::log_event(
					'job_timeout',
					'Job timed out and was removed from active queue.',
					array(
						'job_id'     => $job_id,
						'started_at' => $started_at,
						'timeout'    => $timeout,
					)
				);

				++$cleaned;
			}
		}

		if ( $cleaned > 0 ) {
			self::save_active_jobs_to_option( $active_jobs );
		}

		return $cleaned;
	}

	// ──── Legacy option-based methods (fallback when table doesn't exist) ────

	/**
	 * Get pending jobs from option array sorted by priority.
	 *
	 * @deprecated 1.1.37
	 *
	 * @param array $queue Queue state.
	 * @return array Pending jobs sorted by priority.
	 */
	private static function get_pending_jobs_from_option( array $queue ) {
		$pending = array();

		foreach ( $queue as $job_id => $job ) {
			if ( isset( $job['status'] ) && self::STATUS_PENDING === $job['status'] ) {
				$pending[ $job_id ] = $job;
			}
		}

		uasort(
			$pending,
			function ( $a, $b ) {
				$priority_a = isset( $a['priority'] ) ? absint( $a['priority'] ) : self::PRIORITY_NORMAL;
				$priority_b = isset( $b['priority'] ) ? absint( $b['priority'] ) : self::PRIORITY_NORMAL;

				if ( $priority_a !== $priority_b ) {
					return $priority_b - $priority_a;
				}

				$time_a = isset( $a['enqueued_at'] ) ? absint( $a['enqueued_at'] ) : 0;
				$time_b = isset( $b['enqueued_at'] ) ? absint( $b['enqueued_at'] ) : 0;

				return $time_a - $time_b;
			}
		);

		return $pending;
	}

	/**
	 * Get queue state from legacy option.
	 *
	 * @deprecated 1.1.37
	 * @return array Queue state.
	 */
	private static function get_queue_state_from_option() {
		$queue = get_option( self::QUEUE_STATE_OPTION, array() );
		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Save queue state to legacy option.
	 *
	 * @deprecated 1.1.37
	 *
	 * @param array $queue Queue state.
	 * @return bool True on success.
	 */
	private static function save_queue_state_to_option( array $queue ) {
		return update_option( self::QUEUE_STATE_OPTION, $queue, false );
	}

	/**
	 * Get active jobs from legacy option.
	 *
	 * @deprecated 1.1.37
	 * @return array Active jobs.
	 */
	private static function get_active_jobs_from_option() {
		$active = get_option( self::ACTIVE_JOBS_OPTION, array() );
		return is_array( $active ) ? $active : array();
	}

	/**
	 * Save active jobs to legacy option.
	 *
	 * @deprecated 1.1.37
	 *
	 * @param array $active_jobs Active jobs.
	 * @return bool True on success.
	 */
	private static function save_active_jobs_to_option( array $active_jobs ) {
		return update_option( self::ACTIVE_JOBS_OPTION, $active_jobs, false );
	}

	/**
	 * Mark a job as active in legacy option storage.
	 *
	 * @deprecated 1.1.37
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $job    Job data.
	 * @return bool True on success.
	 */
	private static function mark_job_active_in_option( $job_id, array $job ) {
		$active_jobs = self::get_active_jobs_from_option();

		$active_jobs[ $job_id ] = array(
			'started_at' => time(),
			'timeout'    => isset( $job['timeout'] ) ? absint( $job['timeout'] ) : self::DEFAULT_JOB_TIMEOUT,
		);

		return self::save_active_jobs_to_option( $active_jobs );
	}

	/**
	 * Apply SLA tier-based concurrency limits (legacy option path).
	 *
	 * @deprecated 1.1.37
	 *
	 * @param array $pending_jobs Pending jobs.
	 * @param array $active_jobs  Currently active jobs.
	 * @return array Filtered pending jobs respecting tier limits.
	 */
	protected static function apply_sla_tier_limits( $pending_jobs, $active_jobs ) {
		$active_by_tier = array();
		foreach ( $active_jobs as $active_job ) {
			$tier = isset( $active_job['sla_tier'] ) ? $active_job['sla_tier'] : null;
			if ( $tier ) {
				if ( ! isset( $active_by_tier[ $tier ] ) ) {
					$active_by_tier[ $tier ] = 0;
				}
				++$active_by_tier[ $tier ];
			}
		}

		$filtered = array();
		foreach ( $pending_jobs as $job_id => $job ) {
			$tier = isset( $job['sla_tier'] ) ? $job['sla_tier'] : null;

			if ( ! $tier ) {
				$filtered[ $job_id ] = $job;
				continue;
			}

			$tier_max_concurrent = WP_MCP_AI_SLA_Manager::get_default_concurrent( $tier );
			$tier_active_count   = isset( $active_by_tier[ $tier ] ) ? $active_by_tier[ $tier ] : 0;

			if ( $tier_active_count < $tier_max_concurrent ) {
				$filtered[ $job_id ] = $job;
				if ( ! isset( $active_by_tier[ $tier ] ) ) {
					$active_by_tier[ $tier ] = 0;
				}
				++$active_by_tier[ $tier ];
			}
		}

		return $filtered;
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array Queue statistics.
	 */
	public static function get_queue_stats() {
		if ( self::use_custom_table() ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::get_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$active = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE status = %s', self::STATUS_ACTIVE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$pending = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE status = %s', self::STATUS_PENDING ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$failed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE status = %s', self::STATUS_FAILED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			return array(
				'total'   => $total,
				'active'  => $active,
				'pending' => $pending,
				'failed'  => $failed,
			);
		}

		$queue       = self::get_queue_state_from_option();
		$active_jobs = self::get_active_jobs_from_option();

		$stats = array(
			'total'   => count( $queue ),
			'active'  => count( $active_jobs ),
			'pending' => 0,
			'failed'  => 0,
		);

		foreach ( $queue as $job ) {
			$status = isset( $job['status'] ) ? $job['status'] : self::STATUS_PENDING;

			if ( self::STATUS_PENDING === $status ) {
				++$stats['pending'];
			} elseif ( self::STATUS_FAILED === $status ) {
				++$stats['failed'];
			}
		}

		return $stats;
	}

	/**
	 * Clear all jobs from the queue.
	 *
	 * @return bool True on success.
	 */
	public static function clear_queue() {
		if ( self::use_custom_table() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'TRUNCATE TABLE ' . self::get_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// Also clear legacy options.
		delete_option( self::QUEUE_STATE_OPTION );
		delete_option( self::ACTIVE_JOBS_OPTION );

		WP_MCP_AI_Logger::log_event( 'queue_cleared', 'Job queue cleared.' );

		return true;
	}
}
