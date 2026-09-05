<?php
/**
 * Concurrent job queue manager for the Content Graph AI Platform addon.
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Job_Queue_Manager`
 * (Wave E2): byte-identical table schema (`mcp_ai_concurrent_jobs`),
 * priority/status constants, error codes, claim/retry/fail envelopes,
 * and queue stats. A static utility — consumed by the queue-processing
 * cron path once the scheduler bridge ports; no runtime hooks of its
 * own, so nothing to double-register in monolith installs.
 *
 * Decoupling (documented, additive):
 * - The legacy option-based fallback storage (`wp_mcp_ai_job_queue_state`
 *   / `wp_mcp_ai_active_jobs`, deprecated in the base at 1.1.37) is NOT
 *   ported — the platform starts on the custom table.
 * - SLA tier limits resolve per install mode (base
 *   `WP_MCP_AI_SLA_Manager` monolith / this package's `SlaManager`
 *   standalone); the resource-manager concurrency default falls back
 *   to `DEFAULT_MAX_CONCURRENT` standalone; the DLQ forward resolves
 *   per install mode (base `WP_MCP_AI_Dead_Letter_Queue` monolith /
 *   this package's `DeadLetterQueue` standalone) behind a
 *   method_exists guard (documented hardening); logging goes through
 *   a dormant seam.
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
 * Manages concurrent background jobs to prevent overwhelming the
 * service: atomic claiming (SKIP LOCKED on MySQL 8+), retry/fail
 * handling, stale-job cleanup, and queue statistics.
 *
 * @since 2.1.0
 */
class JobQueueManager {

	/**
	 * Database table name for concurrent job storage — byte-identical.
	 */
	const TABLE_NAME = 'mcp_ai_concurrent_jobs';

	/**
	 * Legacy option names — kept for byte-identical constant surface;
	 * the legacy storage path itself is not ported (documented).
	 */
	const QUEUE_STATE_OPTION = 'wp_mcp_ai_job_queue_state';
	const ACTIVE_JOBS_OPTION = 'wp_mcp_ai_active_jobs';

	/**
	 * Default maximum concurrent jobs — byte-identical.
	 */
	const DEFAULT_MAX_CONCURRENT = 3;

	/**
	 * Default job timeout in seconds — byte-identical.
	 */
	const DEFAULT_JOB_TIMEOUT = 300;

	/**
	 * Job priorities (legacy — use SLA tiers for new code).
	 */
	const PRIORITY_HIGH   = 10;
	const PRIORITY_NORMAL = 5;
	const PRIORITY_LOW    = 1;

	/**
	 * Job statuses — byte-identical.
	 */
	const STATUS_PENDING  = 'pending';
	const STATUS_ACTIVE   = 'active';
	const STATUS_FAILED   = 'failed';
	const STATUS_COMPLETE = 'complete';

	/**
	 * Whether the custom table is available (cached).
	 *
	 * @var bool|null
	 */
	private static $table_exists = null;

	/**
	 * Create the concurrent job queue database table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
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

		// Verify instead of optimistically trusting dbDelta.
		self::$table_exists = null;
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES has no prepared-statement form for the table pattern.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		self::$table_exists = ( $table_name === $exists );
		return self::$table_exists;
	}

	/**
	 * Reset the cached table-existence probe.
	 *
	 * `use_custom_table()` memoizes its SHOW TABLES probe for the request;
	 * resetting forces the next probe to re-check. Test harnesses use this
	 * when they create and drop the real table between cases (the drop
	 * happens behind the class's back, so the cache would otherwise stay
	 * stale). Safe for production callers that rebuild the table
	 * out-of-band, too.
	 *
	 * @return void
	 */
	public static function reset_table_exists_cache(): void {
		self::$table_exists = null;
	}

	/**
	 * Get the table name with prefix.
	 *
	 * @return string Full table name.
	 */
	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Check if the MySQL server supports SKIP LOCKED (8.0+).
	 *
	 * @return bool True if SKIP LOCKED is supported.
	 */
	protected static function supports_skip_locked(): bool {
		global $wpdb;
		return version_compare( $wpdb->db_version(), '8.0.0', '>=' );
	}

	/**
	 * Enqueue a job for execution.
	 *
	 * Supports both legacy priority values and SLA tier-based priorities.
	 *
	 * @param string $job_id   Unique job identifier.
	 * @param array  $job_data Job data including callable and arguments.
	 * @return bool True on success, false on failure.
	 */
	public static function enqueue_job( $job_id, array $job_data ) {
		$job_id = sanitize_key( $job_id );

		if ( '' === $job_id ) {
			return false;
		}

		if ( ! isset( $job_data['callable'] ) || ! is_callable( $job_data['callable'] ) ) {
			static::log_error(
				'Cannot enqueue job with invalid callable.',
				array( 'job_id' => $job_id )
			);
			return false;
		}

		if ( self::job_exists( $job_id ) ) {
			static::log_event(
				'job_already_queued',
				'Job already exists in queue.',
				array( 'job_id' => $job_id )
			);
			return false;
		}

		$priority = self::PRIORITY_NORMAL;
		$sla_tier = null;

		if ( static::sla_available() ) {
			$sla_class = static::sla_class();
			if ( isset( $job_data['sla_tier'] ) ) {
				$sla_tier = sanitize_key( $job_data['sla_tier'] );
				$priority = $sla_class::get_priority( $sla_tier );
			} elseif ( isset( $job_data['tool'] ) && is_object( $job_data['tool'] ) ) {
				$sla_tier = $sla_class::get_tier_for_tool( $job_data['tool'] );
				$priority = $sla_class::get_priority( $sla_tier );
			}
		}

		if ( isset( $job_data['priority'] ) ) {
			$priority = absint( $job_data['priority'] );
		}

		$timeout = isset( $job_data['timeout'] ) ? absint( $job_data['timeout'] ) : self::DEFAULT_JOB_TIMEOUT;

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

		return self::enqueue_to_table( $job_id, $job_data, $callable_class, $callable_method, $priority, $sla_tier, $timeout );
	}

	/**
	 * Check if a job exists in the table.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool True if job exists.
	 */
	private static function job_exists( $job_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table not covered by WP object cache.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE job_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-controlled table name.
				$job_id
			)
		);

		return $count > 0;
	}

	/**
	 * Enqueue a job to the custom DB table.
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
	private static function enqueue_to_table( $job_id, $job_data, $callable_class, $callable_method, $priority, $sla_tier, $timeout ): bool {
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
			static::log_error(
				'Failed to insert job into custom table.',
				array(
					'job_id' => $job_id,
					'error'  => $wpdb->last_error,
				)
			);
			return false;
		}

		static::log_event(
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
	 * Process the job queue.
	 *
	 * Respects SLA tier-based concurrency limits if enabled.
	 *
	 * @param int|null $max_concurrent Maximum number of concurrent jobs.
	 * @return array Processing results.
	 */
	public static function process_queue( $max_concurrent = null ) {
		if ( null === $max_concurrent ) {
			$max_concurrent = static::default_max_concurrent();
		}

		$max_concurrent = max( 1, absint( $max_concurrent ) );

		self::cleanup_stale_jobs();

		$active_count = self::count_active_jobs();

		if ( $active_count >= $max_concurrent ) {
			static::log_event(
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

		$claimed_jobs = self::claim_pending_jobs( $slots_available );

		if ( empty( $claimed_jobs ) ) {
			return array(
				'processed' => 0,
				'active'    => $active_count,
				'reason'    => 'no_pending_jobs',
			);
		}

		if ( static::sla_available() ) {
			$claimed_jobs = self::apply_sla_tier_limits_to_claimed( $claimed_jobs );
		}

		$processed = 0;

		foreach ( $claimed_jobs as $job ) {
			$job_id = $job['job_id'];

			$result = self::call_job( $job );

			if ( is_wp_error( $result ) ) {
				self::handle_job_failure_table( $job, $result );
			} else {
				self::mark_job_complete_table( $job_id );
			}

			++$processed;
		}

		static::log_event(
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
	 * @param array $claimed_jobs Claimed jobs.
	 * @return array Filtered jobs respecting tier limits.
	 */
	private static function apply_sla_tier_limits_to_claimed( $claimed_jobs ): array {
		$active_by_tier = array();

		if ( self::use_custom_table() ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; static table name.
			$rows = $wpdb->get_results(
				'SELECT sla_tier, COUNT(*) as cnt FROM ' . self::get_table_name() . " WHERE status = 'active' AND sla_tier IS NOT NULL GROUP BY sla_tier"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

			$tier_max_concurrent = static::sla_class()::get_default_concurrent( $tier );
			$tier_active_count   = isset( $active_by_tier[ $tier ] ) ? $active_by_tier[ $tier ] : 0;

			if ( $tier_active_count < $tier_max_concurrent ) {
				$filtered[] = $job;
				if ( ! isset( $active_by_tier[ $tier ] ) ) {
					$active_by_tier[ $tier ] = 0;
				}
				++$active_by_tier[ $tier ];
			} else {
				self::release_job( $job['job_id'] );
			}
		}

		return $filtered;
	}

	/**
	 * Atomically claim pending jobs from the custom table.
	 *
	 * @param int $limit Maximum jobs to claim.
	 * @return array Array of claimed job rows.
	 */
	private static function claim_pending_jobs( $limit ): array {
		if ( ! self::use_custom_table() ) {
			return array();
		}

		global $wpdb;
		$table_name = self::get_table_name();
		$claimed    = array();

		$skip_locked = static::supports_skip_locked() ? ' SKIP LOCKED' : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; transactional claim required.
		$wpdb->query( 'START TRANSACTION' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; transactional claim required.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE status = %s ORDER BY priority DESC, enqueued_at ASC LIMIT %d FOR UPDATE" . $skip_locked, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-controlled table name + static lock clause.
				self::STATUS_PENDING,
				$limit
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; transactional claim required.
		$wpdb->query( 'COMMIT' );

		return $claimed;
	}

	/**
	 * Release a claimed job back to pending status.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	private static function release_job( $job_id ): void {
		if ( ! self::use_custom_table() ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
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
	 * @return int Active job count.
	 */
	public static function count_active_jobs(): int {
		if ( ! self::use_custom_table() ) {
			return 0;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE status = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-controlled table name.
				self::STATUS_ACTIVE
			)
		);
	}

	/**
	 * Call a job from table row data.
	 *
	 * @param array $job Job row data.
	 * @return mixed|\WP_Error Job result or error.
	 */
	private static function call_job( $job ) {
		static::log_event(
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
				return new \WP_Error(
					'wp_mcp_ai_job_not_callable',
					'Job callable is not valid.',
					array( 'job_id' => $job['job_id'] )
				);
			}

			$args   = isset( $job['args'] ) ? $job['args'] : array();
			$result = call_user_func_array( $callable, $args );
			return $result;
		} catch ( \Exception $e ) {
			return new \WP_Error(
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
	 * Handle job failure from a table row (retry then fail).
	 *
	 * @param array     $job   Job row data.
	 * @param \WP_Error $error Error object.
	 * @return void
	 */
	private static function handle_job_failure_table( $job, $error ): void {
		$job_id      = $job['job_id'];
		$retry_count = isset( $job['retry_count'] ) ? (int) $job['retry_count'] : 0;
		$max_retries = isset( $job['max_retries'] ) ? (int) $job['max_retries'] : 3;

		static::log_error(
			'Job execution failed.',
			array(
				'job_id'        => $job_id,
				'retry_count'   => $retry_count,
				'error_code'    => $error->get_error_code(),
				'error_message' => $error->get_error_message(),
			)
		);

		global $wpdb;

		if ( $retry_count < $max_retries ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
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
			return;
		}

		if ( static::dead_letter_available() ) {
			$dlq_class = class_exists( 'WP_MCP_AI_Dead_Letter_Queue' )
				? \WP_MCP_AI_Dead_Letter_Queue::class
				: __NAMESPACE__ . '\DeadLetterQueue';

			$retry_history = array();
			for ( $i = 0; $i <= $retry_count; $i++ ) {
				$retry_history[] = array(
					'timestamp' => time() - ( ( $retry_count - $i ) * 300 ),
					'result'    => 'failed',
					'error'     => $error->get_error_message(),
				);
			}

			$dlq_class::add(
				$dlq_class::TYPE_JOB_QUEUE,
				$job_id,
				array(
					'job_id'   => $job_id,
					'job_data' => $job,
				),
				$error->get_error_message(),
				$retry_history
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
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

	/**
	 * Mark a job as complete in the custom table.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	private static function mark_job_complete_table( $job_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->delete(
			self::get_table_name(),
			array( 'job_id' => $job_id )
		);

		static::log_event(
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
	protected static function cleanup_stale_jobs(): int {
		if ( ! self::use_custom_table() ) {
			return 0;
		}

		global $wpdb;
		$now = time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; static table name.
		$cleaned = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::get_table_name() . ' SET status = %s, last_error = %s WHERE status = %s AND started_at IS NOT NULL AND (started_at + timeout) < %d',
				self::STATUS_PENDING,
				'Job timed out',
				self::STATUS_ACTIVE,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $cleaned > 0 ) {
			static::log_event(
				'job_timeout_cleanup',
				'Stale jobs cleaned up.',
				array( 'count' => $cleaned )
			);
		}

		return (int) $cleaned;
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array Queue statistics.
	 */
	public static function get_queue_stats() {
		if ( ! self::use_custom_table() ) {
			return array(
				'total'   => 0,
				'active'  => 0,
				'pending' => 0,
				'failed'  => 0,
			);
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table; static table name.
		$total   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::get_table_name() );
		$active  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE status = %s', self::STATUS_ACTIVE ) );
		$pending = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE status = %s', self::STATUS_PENDING ) );
		$failed  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE status = %s', self::STATUS_FAILED ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'total'   => $total,
			'active'  => $active,
			'pending' => $pending,
			'failed'  => $failed,
		);
	}

	/**
	 * Clear all jobs from the queue.
	 *
	 * @return bool True on success.
	 */
	public static function clear_queue() {
		if ( self::use_custom_table() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table; static table name.
			$wpdb->query( 'TRUNCATE TABLE ' . self::get_table_name() );
		}

		delete_option( self::QUEUE_STATE_OPTION );
		delete_option( self::ACTIVE_JOBS_OPTION );

		static::log_event( 'queue_cleared', 'Job queue cleared.' );

		return true;
	}

	// ─── Seams (dormant-until-owning-wave) ──────────────────────────

	/**
	 * Resolve the default max-concurrent value.
	 *
	 * The base delegates to `WP_MCP_AI_Resource_Manager`; standalone falls
	 * back to DEFAULT_MAX_CONCURRENT until the resource manager ports.
	 *
	 * @return int
	 */
	protected static function default_max_concurrent(): int {
		if ( class_exists( 'WP_MCP_AI_Resource_Manager' ) ) {
			$manager = \WP_MCP_AI_Resource_Manager::instance();
			if ( method_exists( $manager, 'get_max_concurrent_requests' ) ) {
				return max( 1, absint( $manager->get_max_concurrent_requests() ) );
			}
		}

		return self::DEFAULT_MAX_CONCURRENT;
	}

	/**
	 * Whether the SLA manager is available.
	 *
	 * Resolves per install mode: the base `WP_MCP_AI_SLA_Manager` in
	 * monolith installs, this package's `SlaManager` standalone. The
	 * method_exists guard is a documented hardening deviation.
	 *
	 * @return bool
	 */
	protected static function sla_available(): bool {
		$sla_class = static::sla_class();

		return method_exists( $sla_class, 'is_enabled' )
			&& $sla_class::is_enabled();
	}

	/**
	 * Resolve the SLA manager class.
	 *
	 * The base plugin owns SLA prioritization in monolith installs;
	 * standalone resolves this package's `SlaManager`. The base probe
	 * is gated on the base plugin being booted — the monorepo autoloader
	 * can resolve base classes in standalone installs.
	 *
	 * @return string Fully-qualified class name.
	 */
	protected static function sla_class(): string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_SLA_Manager' ) ) {
			return 'WP_MCP_AI_SLA_Manager';
		}

		return __NAMESPACE__ . '\SlaManager';
	}

	/**
	 * Whether the dead-letter queue is available.
	 *
	 * Resolves per install mode: the base `WP_MCP_AI_Dead_Letter_Queue`
	 * in monolith installs, this package's `DeadLetterQueue` standalone.
	 * The method_exists guard is a documented hardening deviation.
	 *
	 * @return bool
	 */
	protected static function dead_letter_available(): bool {
		if ( class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) && method_exists( 'WP_MCP_AI_Dead_Letter_Queue', 'add' ) ) {
			return true;
		}

		return class_exists( __NAMESPACE__ . '\DeadLetterQueue' )
			&& method_exists( __NAMESPACE__ . '\DeadLetterQueue', 'add' );
	}

	/**
	 * Log a queue error event.
	 *
	 * Dormant standalone until the platform's logging piece ports.
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
	 * Dormant standalone until the platform's logging piece ports.
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
