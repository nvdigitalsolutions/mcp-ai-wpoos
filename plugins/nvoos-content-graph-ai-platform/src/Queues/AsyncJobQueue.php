<?php
/**
 * Async job queue for the Content Graph AI Platform addon.
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Async_Job_Queue` (Wave E2):
 * byte-identical table schema (`mcp_ai_job_queue`), priority/status/type
 * constants, cron hook names, error codes, and envelopes. Registered
 * standalone-only by `Plugin.php` — the base plugin owns the same table
 * and cron hooks in monolith installs and double registration would
 * double-consume jobs.
 *
 * Decoupling (documented, additive):
 * - Job-type execution resolves first through the
 *   `nvoos_content_graph_ai_platform/async_job_executors` filter, so the
 *   platform's workflow engine (E1) and other subsystems register
 *   executors without touching this class. Without a registered executor
 *   the base's per-type guards apply (byte-identical "not available"
 *   failures).
 * - The Action Scheduler bridge, dead-letter queue, job notifier, and
 *   base logger have no platform counterparts yet — their guards are
 *   preserved as protected seams and stay dormant until those E2 pieces
 *   port (jobs poll via the WP-Cron tick meanwhile). The bridge seam is
 *   the exception: it now resolves per install mode (base bridge
 *   monolith / `SchedulerBridge` standalone) via
 *   `scheduler_bridge_class()`.
 * - RabbitMQ gating uses the AI addon's `RabbitMqClient` (D2d) + the same
 *   `wp_mcp_ai_queue_worker_dedicated` option.
 * - The `minute` cron interval is registered by this class — the base
 *   relies on an external registration for the same interval name
 *   (documented deviation; the polling cron actually fires standalone).
 * - The base's placeholder admin page is not ported — the queue manager
 *   UI lands with the E-UI-2 managers wave.
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
 * Unified background-job queue: priority scheduling, state tracking,
 * progress (0–100%), retries with backoff, cleanup, and stats.
 *
 * @since 2.1.0
 */
class AsyncJobQueue {

	/**
	 * Database table name (without prefix) — byte-identical to the base.
	 */
	const TABLE_NAME = 'mcp_ai_job_queue';

	/**
	 * Job priority levels — byte-identical to the base.
	 */
	const PRIORITY_URGENT = 1; // Real-time (< 1s).
	const PRIORITY_HIGH   = 2; // Interactive (< 5s).
	const PRIORITY_NORMAL = 3; // Standard (< 30s).
	const PRIORITY_LOW    = 4; // Background (< 5min).
	const PRIORITY_BATCH  = 5; // Non-urgent (> 30min).

	/**
	 * Job statuses — byte-identical to the base.
	 */
	const STATUS_QUEUED    = 'queued';
	const STATUS_RUNNING   = 'running';
	const STATUS_PAUSED    = 'paused';
	const STATUS_COMPLETED = 'completed';
	const STATUS_FAILED    = 'failed';
	const STATUS_CANCELLED = 'cancelled';

	/**
	 * Job types — byte-identical to the base.
	 */
	const TYPE_COMMAND             = 'command';
	const TYPE_WORKFLOW            = 'workflow';
	const TYPE_TOOL                = 'tool';
	const TYPE_AGENTIC_LOOP        = 'agentic_loop';
	const TYPE_CONVERSATION_IMPORT = 'conversation_import';

	/**
	 * Cron hook for job processing — byte-identical to the base.
	 */
	const CRON_HOOK = 'wp_mcp_ai_process_job_queue';

	/**
	 * Cron hook for cleanup — byte-identical to the base.
	 */
	const CRON_CLEANUP_HOOK = 'wp_mcp_ai_cleanup_job_queue';

	/**
	 * Maximum job execution time (seconds).
	 */
	const MAX_EXECUTION_TIME = 300; // 5 minutes.

	/**
	 * Maximum retries for failed jobs — byte-identical to the base.
	 */
	const MAX_RETRIES = 3;

	/**
	 * Job cleanup age (days) — byte-identical to the base.
	 */
	const CLEANUP_AGE_DAYS = 30;

	/**
	 * Initialize the job queue system.
	 *
	 * Sets up the database table, the minute cron interval, cron jobs,
	 * and the processing hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		self::create_table();
		self::register_minute_schedule();
		self::schedule_cron_jobs();

		add_action( self::CRON_HOOK, array( static::class, 'process_queue' ) );
		add_action( self::CRON_CLEANUP_HOOK, array( static::class, 'cleanup_old_jobs' ) );
	}

	/**
	 * Register the minute cron interval (documented deviation — the base
	 * relies on an external registration for the same interval name).
	 *
	 * @return void
	 */
	public static function register_minute_schedule(): void {
		if ( has_filter( 'cron_schedules', array( static::class, 'add_minute_schedule' ) ) ) {
			return;
		}

		add_filter( 'cron_schedules', array( static::class, 'add_minute_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- The queue polling tick intentionally runs every minute.
	}

	/**
	 * Add the minute interval to the cron schedules list.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Updated schedules.
	 */
	public static function add_minute_schedule( array $schedules ): array {
		$schedules['minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Once a Minute', 'nvoos-content-graph-ai-platform' ),
		);

		return $schedules;
	}

	/**
	 * Create the job queue database table (byte-identical schema).
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_type VARCHAR(50) NOT NULL,
			job_data LONGTEXT NOT NULL,
			priority TINYINT(1) NOT NULL DEFAULT 3,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			progress TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			started_at DATETIME DEFAULT NULL,
			completed_at DATETIME DEFAULT NULL,
			result LONGTEXT DEFAULT NULL,
			error LONGTEXT DEFAULT NULL,
			retries TINYINT(2) UNSIGNED NOT NULL DEFAULT 0,
			max_retries TINYINT(2) UNSIGNED NOT NULL DEFAULT 3,
			chat_session VARCHAR(255) DEFAULT NULL,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			assistant_id BIGINT(20) UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status_priority (status, priority),
			KEY chat_session (chat_session),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) $charset_collate;";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );
	}

	/**
	 * Schedule cron jobs for queue processing.
	 *
	 * When RabbitMQ is the primary transport with a dedicated queue
	 * worker, the DB polling cron is skipped; the daily cleanup cron
	 * always runs.
	 *
	 * @return void
	 */
	public static function schedule_cron_jobs(): void {
		// The minute interval must be registered before scheduling —
		// wp_schedule_event() silently drops events for unknown recurrences.
		static::register_minute_schedule();

		$is_rmq = static::is_rabbitmq_primary_transport();

		if ( $is_rmq ) {
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::CRON_HOOK );
			}
		}

		if ( ! $is_rmq && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'minute', self::CRON_HOOK ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- The queue polling tick intentionally runs every minute.
		}

		if ( ! wp_next_scheduled( self::CRON_CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_CLEANUP_HOOK );
		}
	}

	/**
	 * Queue a new job.
	 *
	 * @param array $args Job arguments.
	 * @return int|\WP_Error Job ID on success, WP_Error on failure.
	 */
	public static function queue_job( $args ) {
		global $wpdb;

		if ( empty( $args['job_type'] ) ) {
			return new \WP_Error(
				'missing_job_type',
				__( 'Job type is required.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( empty( $args['job_data'] ) ) {
			return new \WP_Error(
				'missing_job_data',
				__( 'Job data is required.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$job_data = array(
			'job_type'     => sanitize_text_field( $args['job_type'] ),
			'job_data'     => wp_json_encode( $args['job_data'] ),
			'priority'     => isset( $args['priority'] ) ? absint( $args['priority'] ) : self::PRIORITY_NORMAL,
			'status'       => self::STATUS_QUEUED,
			'progress'     => 0,
			'created_at'   => current_time( 'mysql' ),
			'retries'      => 0,
			'max_retries'  => isset( $args['max_retries'] ) ? absint( $args['max_retries'] ) : self::MAX_RETRIES,
			'chat_session' => isset( $args['chat_session'] ) ? sanitize_text_field( $args['chat_session'] ) : null,
			'user_id'      => isset( $args['user_id'] ) ? absint( $args['user_id'] ) : get_current_user_id(),
			'assistant_id' => isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : null,
		);

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$inserted   = $wpdb->insert( $table_name, $job_data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table not covered by WP object cache; direct query required for real-time job status.

		if ( false === $inserted ) {
			return new \WP_Error(
				'insert_failed',
				__( 'Failed to queue job.', 'nvoos-content-graph-ai-platform' ),
				array( 'db_error' => $wpdb->last_error )
			);
		}

		$job_id = $wpdb->insert_id;

		if ( ! empty( $args['chat_session'] ) ) {
			static::emit_sse_event(
				'job_queued',
				array(
					'job_id'       => $job_id,
					'job_type'     => $args['job_type'],
					'priority'     => $job_data['priority'],
					'chat_session' => $args['chat_session'],
				)
			);
		}

		static::log_event(
			'info',
			'Job queued',
			array(
				'job_id'   => $job_id,
				'job_type' => $args['job_type'],
				'priority' => $job_data['priority'],
			)
		);

		static::maybe_enqueue_through_scheduler_bridge( $job_id );

		return $job_id;
	}

	/**
	 * Process a single queued job by ID.
	 *
	 * Idempotent: jobs that are not in the `queued` state are skipped.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	public static function process_specific_job( $job_id ): void {
		global $wpdb;

		$job_id = (int) $job_id;
		if ( $job_id <= 0 ) {
			return;
		}

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a safe, plugin-controlled value.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table not covered by WP object cache; direct query required for real-time job status.
		$job = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE id = %d AND status = %s",
				$job_id,
				self::STATUS_QUEUED
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $job ) {
			return;
		}

		self::update_job(
			$job['id'],
			array(
				'status'     => self::STATUS_RUNNING,
				'started_at' => current_time( 'mysql' ),
			)
		);

		$decoded_job_data = json_decode( $job['job_data'], true );
		if ( ! is_array( $decoded_job_data ) ) {
			self::update_job(
				$job['id'],
				array(
					'status'       => self::STATUS_FAILED,
					'error'        => array(
						'message' => __( 'Job data is not valid JSON.', 'nvoos-content-graph-ai-platform' ),
						'code'    => 'invalid_job_data',
					),
					'completed_at' => current_time( 'mysql' ),
				)
			);
			return;
		}
		$job['job_data'] = $decoded_job_data;

		$retries     = isset( $job['retries'] ) ? (int) $job['retries'] : 0;
		$max_retries = isset( $job['max_retries'] ) ? (int) $job['max_retries'] : self::MAX_RETRIES;

		try {
			$result = static::execute_job( $job );

			self::update_job(
				$job['id'],
				array(
					'status'       => self::STATUS_COMPLETED,
					'progress'     => 100,
					'result'       => $result,
					'completed_at' => current_time( 'mysql' ),
				)
			);

			static::send_webhook_notification( $job, $result );
		} catch ( \Exception $e ) {
			$error = array(
				'message' => $e->getMessage(),
				'code'    => $e->getCode(),
				'trace'   => $e->getTraceAsString(),
			);

			if ( $retries < $max_retries ) {
				self::update_job(
					$job['id'],
					array(
						'status'  => self::STATUS_QUEUED,
						'error'   => $error,
						'retries' => $retries + 1,
					)
				);

				static::maybe_enqueue_through_scheduler_bridge( (int) $job['id'] );
			} else {
				self::update_job(
					$job['id'],
					array(
						'status'       => self::STATUS_FAILED,
						'error'        => $error,
						'completed_at' => current_time( 'mysql' ),
					)
				);

				if ( static::dead_letter_available() ) {
					\WP_MCP_AI_Dead_Letter_Queue::add_to_queue(
						'async_job',
						$job['job_data'],
						$error
					);
				}
			}
		}
	}

	/**
	 * Get a job by ID (JSON fields decoded).
	 *
	 * @param int $job_id Job ID.
	 * @return array|null Job data or null if not found.
	 */
	public static function get_job( $job_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a safe, plugin-controlled value.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table not covered by WP object cache; direct query required for real-time job status.
		$job = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $job_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a safe, plugin-controlled value.
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $job ) {
			return null;
		}

		$job['job_data'] = json_decode( $job['job_data'], true );
		if ( ! empty( $job['result'] ) ) {
			$job['result'] = json_decode( $job['result'], true );
		}
		if ( ! empty( $job['error'] ) ) {
			$job['error'] = json_decode( $job['error'], true );
		}

		return $job;
	}

	/**
	 * Update a job.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $data   Data to update.
	 * @return bool True on success, false on failure.
	 */
	public static function update_job( $job_id, $data ) {
		global $wpdb;

		$update_data = array();

		if ( isset( $data['status'] ) ) {
			$update_data['status'] = sanitize_text_field( $data['status'] );
		}

		if ( isset( $data['progress'] ) ) {
			$update_data['progress'] = min( 100, absint( $data['progress'] ) );
		}

		if ( isset( $data['result'] ) ) {
			$update_data['result'] = wp_json_encode( $data['result'] );
		}

		if ( isset( $data['error'] ) ) {
			$update_data['error'] = wp_json_encode( $data['error'] );
		}

		if ( isset( $data['started_at'] ) ) {
			$update_data['started_at'] = sanitize_text_field( $data['started_at'] );
		}

		if ( isset( $data['completed_at'] ) ) {
			$update_data['completed_at'] = sanitize_text_field( $data['completed_at'] );
		}

		if ( isset( $data['retries'] ) ) {
			$update_data['retries'] = absint( $data['retries'] );
		}

		if ( empty( $update_data ) ) {
			return false;
		}

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$updated    = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table not covered by WP object cache; direct query required for real-time job status.
			$table_name,
			$update_data,
			array( 'id' => $job_id )
		);

		if ( isset( $data['progress'] ) || isset( $data['status'] ) ) {
			$job = self::get_job( $job_id );
			if ( $job && ! empty( $job['chat_session'] ) ) {
				$event_data = array(
					'job_id'       => $job_id,
					'chat_session' => $job['chat_session'],
				);

				if ( isset( $data['progress'] ) ) {
					$event_data['progress'] = $data['progress'];
				}

				if ( isset( $data['status'] ) ) {
					$event_data['status'] = $data['status'];
				}

				if ( isset( $data['result'] ) ) {
					$event_data['result'] = $data['result'];
				}

				$event_name = isset( $data['status'] ) && self::STATUS_COMPLETED === $data['status']
					? 'job_completed'
					: 'job_progress';

				static::emit_sse_event( $event_name, $event_data );
			}
		}

		return false !== $updated;
	}

	/**
	 * Process the job queue (one job per tick, highest priority first).
	 *
	 * @return void
	 */
	public static function process_queue(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a safe, plugin-controlled value.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table not covered by WP object cache; direct query required for real-time job status.
		$job = $wpdb->get_row(
			"SELECT * FROM $table_name
			WHERE status = 'queued'
			ORDER BY priority ASC, created_at ASC
			LIMIT 1",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $job ) {
			return; // No jobs to process.
		}

		self::update_job(
			$job['id'],
			array(
				'status'     => self::STATUS_RUNNING,
				'started_at' => current_time( 'mysql' ),
			)
		);

		$job['job_data'] = json_decode( $job['job_data'], true );

		try {
			$result = static::execute_job( $job );

			self::update_job(
				$job['id'],
				array(
					'status'       => self::STATUS_COMPLETED,
					'progress'     => 100,
					'result'       => $result,
					'completed_at' => current_time( 'mysql' ),
				)
			);

			static::send_webhook_notification( $job, $result );
		} catch ( \Exception $e ) {
			$error = array(
				'message' => $e->getMessage(),
				'code'    => $e->getCode(),
				'trace'   => $e->getTraceAsString(),
			);

			if ( $job['retries'] < $job['max_retries'] ) {
				self::update_job(
					$job['id'],
					array(
						'status'  => self::STATUS_QUEUED,
						'error'   => $error,
						'retries' => $job['retries'] + 1,
					)
				);
			} else {
				self::update_job(
					$job['id'],
					array(
						'status'       => self::STATUS_FAILED,
						'error'        => $error,
						'completed_at' => current_time( 'mysql' ),
					)
				);

				if ( static::dead_letter_available() ) {
					\WP_MCP_AI_Dead_Letter_Queue::add_to_queue(
						'async_job',
						$job['job_data'],
						$error
					);
				}
			}
		}
	}

	/**
	 * Execute a job based on its type.
	 *
	 * Registered platform executors (the
	 * `nvoos_content_graph_ai_platform/async_job_executors` filter) take
	 * precedence; without one the base's per-type guards apply.
	 *
	 * @param array $job Job data.
	 * @return mixed Job result.
	 * @throws \Exception If job execution fails.
	 */
	protected static function execute_job( $job ) {
		$executor = static::executor_for_type( (string) $job['job_type'] );
		if ( is_callable( $executor ) ) {
			return call_user_func( $executor, $job['job_data'], (int) $job['id'] );
		}

		switch ( $job['job_type'] ) {
			case self::TYPE_COMMAND:
				return self::execute_command_job( $job );

			case self::TYPE_WORKFLOW:
				return self::execute_workflow_job( $job );

			case self::TYPE_TOOL:
				return self::execute_tool_job( $job );

			case self::TYPE_AGENTIC_LOOP:
				return self::execute_agentic_loop_job( $job );

			case self::TYPE_CONVERSATION_IMPORT:
				return self::execute_conversation_import_job( $job );

			default:
				throw new \Exception(
					sprintf(
						/* translators: %s: Job type */
						__( 'Unknown job type: %s', 'nvoos-content-graph-ai-platform' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
						$job['job_type'] // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
					)
				);
		}
	}

	/**
	 * Resolve a registered executor for a job type.
	 *
	 * @param string $type Job type.
	 * @return callable|null
	 */
	protected static function executor_for_type( string $type ): ?callable {
		/**
		 * Filter the platform's async-job executors.
		 *
		 * Executors receive ( array $job_data, int $job_id ) and return the
		 * job result; throwing aborts the job into the retry/fail flow.
		 *
		 * @since 2.1.0
		 *
		 * @param array<string, callable> $executors Map of job type → callable.
		 */
		$executors = apply_filters( 'nvoos_content_graph_ai_platform/async_job_executors', array() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Ecosystem slash-hook convention, mirroring nvoos_content_graph_ai/continue_chat.

		if ( ! is_array( $executors ) || ! isset( $executors[ $type ] ) ) {
			return null;
		}

		return is_callable( $executors[ $type ] ) ? $executors[ $type ] : null;
	}

	/**
	 * Execute a conversation import job (base-class guarded).
	 *
	 * @param array $job Job data.
	 * @return mixed Import report.
	 * @throws \Exception If the import bridge is unavailable.
	 */
	private static function execute_conversation_import_job( $job ) {
		if ( ! class_exists( 'WP_MCP_AI_Conversation_Import_Queue' ) ) {
			throw new \Exception( __( 'Conversation import bridge not available.', 'nvoos-content-graph-ai-platform' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
		}

		return \WP_MCP_AI_Conversation_Import_Queue::execute( $job['job_data'], (int) $job['id'] );
	}

	/**
	 * Execute a command job (base-class guarded).
	 *
	 * @param array $job Job data.
	 * @return mixed Command result.
	 * @throws \Exception If the slash command toolkit is not available.
	 */
	private static function execute_command_job( $job ) {
		if ( ! class_exists( 'WP_MCP_AI_Slash_Command_Toolkit_Manager' ) ) {
			throw new \Exception( __( 'Slash command toolkit not available.', 'nvoos-content-graph-ai-platform' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
		}

		$command = $job['job_data']['command'] ?? '';
		$args    = $job['job_data']['args'] ?? array();

		$manager = new \WP_MCP_AI_Slash_Command_Toolkit_Manager();
		return $manager->execute_command( $command, $args );
	}

	/**
	 * Execute a workflow job (base-class guarded).
	 *
	 * @param array $job Job data.
	 * @return mixed Workflow result.
	 * @throws \Exception If the workflow orchestrator is not available.
	 */
	private static function execute_workflow_job( $job ) {
		if ( ! class_exists( 'WP_MCP_AI_Workflow_Orchestrator' ) ) {
			throw new \Exception( __( 'Workflow orchestrator not available.', 'nvoos-content-graph-ai-platform' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
		}

		$workflow_id = $job['job_data']['workflow_id'] ?? '';

		$orchestrator = new \WP_MCP_AI_Workflow_Orchestrator();
		return $orchestrator->execute_workflow( $workflow_id, $job['job_data'] );
	}

	/**
	 * Execute a tool job (base-class guarded).
	 *
	 * @param array $job Job data.
	 * @return mixed Tool result.
	 * @throws \Exception If the tool async executor is not available.
	 */
	private static function execute_tool_job( $job ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
			throw new \Exception( __( 'Tool async executor not available.', 'nvoos-content-graph-ai-platform' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
		}

		$tool_slug = $job['job_data']['tool_slug'] ?? '';
		$arguments = $job['job_data']['arguments'] ?? array();

		return \WP_MCP_AI_Tool_Async_Executor::execute_tool( $tool_slug, $arguments );
	}

	/**
	 * Execute an agentic loop job (not yet implemented — parity with base).
	 *
	 * @param array $job Job data (unused).
	 * @return mixed Agentic loop result.
	 * @throws \Exception Always thrown as not yet implemented.
	 */
	private static function execute_agentic_loop_job( $job ) {
		unset( $job ); // Reserved for future implementation.

		throw new \Exception( __( 'Agentic loop execution not yet implemented.', 'nvoos-content-graph-ai-platform' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
	}

	/**
	 * Cleanup old completed/failed/cancelled jobs.
	 *
	 * @return void
	 */
	public static function cleanup_old_jobs(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$age_days   = apply_filters( 'wp_mcp_ai_job_queue_cleanup_age_days', self::CLEANUP_AGE_DAYS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a safe, plugin-controlled value.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table not covered by WP object cache; direct query required for real-time job status.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name
				WHERE status IN ('completed', 'failed', 'cancelled')
				AND completed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$age_days
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $deleted ) {
			static::log_event(
				'info',
				'Cleaned up old jobs',
				array( 'count' => $deleted )
			);
		}
	}

	/**
	 * Cancel a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool True on success, false on failure.
	 */
	public static function cancel_job( $job_id ) {
		return self::update_job(
			$job_id,
			array(
				'status'       => self::STATUS_CANCELLED,
				'completed_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Pause a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool True on success, false on failure.
	 */
	public static function pause_job( $job_id ) {
		return self::update_job(
			$job_id,
			array(
				'status' => self::STATUS_PAUSED,
			)
		);
	}

	/**
	 * Resume a paused job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool True on success, false on failure.
	 */
	public static function resume_job( $job_id ) {
		return self::update_job(
			$job_id,
			array(
				'status' => self::STATUS_QUEUED,
			)
		);
	}

	/**
	 * Get jobs by status.
	 *
	 * @param string $status Job status.
	 * @param int    $limit  Maximum number of jobs to return.
	 * @return array Array of jobs (JSON fields decoded).
	 */
	public static function get_jobs_by_status( $status, $limit = 100 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a safe, plugin-controlled value.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table not covered by WP object cache; direct query required for real-time job status.
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name
				WHERE status = %s
				ORDER BY created_at DESC
				LIMIT %d",
				$status,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $jobs as &$job ) {
			$job['job_data'] = json_decode( $job['job_data'], true );
			if ( ! empty( $job['result'] ) ) {
				$job['result'] = json_decode( $job['result'], true );
			}
			if ( ! empty( $job['error'] ) ) {
				$job['error'] = json_decode( $job['error'], true );
			}
		}

		return $jobs;
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array Queue statistics (total/queued/running/completed/failed).
	 */
	public static function get_queue_stats() {
		global $wpdb;

		$table_name = esc_sql( $wpdb->prefix . self::TABLE_NAME );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct queries on custom plugin table with esc_sql()-escaped table name; no WP API for custom tables.
		return array(
			'total'     => $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ),
			'queued'    => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE status = %s", 'queued' ) ),
			'running'   => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE status = %s", 'running' ) ),
			'completed' => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE status = %s", 'completed' ) ),
			'failed'    => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE status = %s", 'failed' ) ),
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ─── Seams (per-install-mode / dormant-until-owning-wave) ────────

	/**
	 * Whether RabbitMQ is the primary job transport.
	 *
	 * Uses the AI addon's `RabbitMqClient` (D2d) and the byte-identical
	 * `wp_mcp_ai_queue_worker_dedicated` option.
	 *
	 * @return bool
	 */
	protected static function is_rabbitmq_primary_transport(): bool {
		if ( ! class_exists( 'NvoosContentGraphAi\Provider\RabbitMqClient' ) ) {
			return false;
		}

		try {
			if ( ! \NvoosContentGraphAi\Provider\RabbitMqClient::get_instance()->is_available() ) {
				return false;
			}
		} catch ( \Exception $e ) {
			return false;
		}

		return (bool) get_option( 'wp_mcp_ai_queue_worker_dedicated', false );
	}

	/**
	 * Whether the Action Scheduler bridge is available.
	 *
	 * Resolves per install mode: the base `WP_MCP_AI_Async_Scheduler_Bridge`
	 * in monolith installs, this package's `SchedulerBridge` standalone.
	 * The method_exists guards are a documented hardening deviation. When
	 * neither bridge is usable the queue polls via the WP-Cron tick.
	 *
	 * @return bool
	 */
	protected static function scheduler_bridge_available(): bool {
		if ( defined( 'WP_MCP_AI_PATH' )
			&& class_exists( 'WP_MCP_AI_Async_Scheduler_Bridge' )
			&& method_exists( 'WP_MCP_AI_Async_Scheduler_Bridge', 'is_available' )
			&& method_exists( 'WP_MCP_AI_Async_Scheduler_Bridge', 'enqueue_job' )
		) {
			return \WP_MCP_AI_Async_Scheduler_Bridge::is_available();
		}

		return method_exists( __NAMESPACE__ . '\SchedulerBridge', 'is_available' )
			&& method_exists( __NAMESPACE__ . '\SchedulerBridge', 'enqueue_job' )
			&& \NvoosContentGraphAiPlatform\Queues\SchedulerBridge::is_available();
	}

	/**
	 * Resolve the Action Scheduler bridge class.
	 *
	 * The base plugin owns AS dispatch in monolith installs; standalone
	 * resolves this package's `SchedulerBridge`.
	 *
	 * @return string Fully-qualified class name.
	 */
	protected static function scheduler_bridge_class(): string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Async_Scheduler_Bridge' ) ) {
			return 'WP_MCP_AI_Async_Scheduler_Bridge';
		}

		return __NAMESPACE__ . '\SchedulerBridge';
	}

	/**
	 * Whether the dead-letter queue is available.
	 *
	 * Dormant until the DLQ ports (E2). The method_exists guard is a
	 * documented hardening deviation — the base's own call targets a
	 * method its DLQ class does not expose.
	 *
	 * @return bool
	 */
	protected static function dead_letter_available(): bool {
		return class_exists( 'WP_MCP_AI_Dead_Letter_Queue' )
			&& method_exists( 'WP_MCP_AI_Dead_Letter_Queue', 'add_to_queue' );
	}

	/**
	 * Whether the job notifier (webhooks) is available.
	 *
	 * Dormant by design: the base's own call targets a `notify()` method
	 * neither notifier class exposes (documented hardening deviation —
	 * kept byte-identical even though the notifier itself has now ported
	 * to this package in Wave E2).
	 *
	 * @return bool
	 */
	protected static function notifier_available(): bool {
		return class_exists( 'WP_MCP_AI_Job_Notifier' )
			&& method_exists( 'WP_MCP_AI_Job_Notifier', 'notify' );
	}

	/**
	 * Whether the SSE handler is available.
	 *
	 * @return bool
	 */
	protected static function sse_handler_available(): bool {
		return class_exists( 'WP_MCP_AI_SSE_Handler' );
	}

	/**
	 * Dispatch a job through the Action Scheduler bridge if available.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	protected static function maybe_enqueue_through_scheduler_bridge( $job_id ): void {
		if ( ! static::scheduler_bridge_available() ) {
			return;
		}
		$bridge_class = static::scheduler_bridge_class();
		$bridge_class::enqueue_job( (int) $job_id );
	}

	/**
	 * Emit an SSE event for job updates (byte-identical action name).
	 *
	 * @param string $event_name Event name.
	 * @param array  $event_data Event data.
	 * @return void
	 */
	protected static function emit_sse_event( $event_name, $event_data ): void {
		if ( static::sse_handler_available() ) {
			do_action( 'wp_mcp_ai_emit_sse_event', $event_name, $event_data );
		}
	}

	/**
	 * Send a webhook notification for job completion.
	 *
	 * @param array $job    Job data.
	 * @param mixed $result Job result.
	 * @return void
	 */
	protected static function send_webhook_notification( $job, $result ): void {
		if ( ! static::notifier_available() ) {
			return;
		}

		\WP_MCP_AI_Job_Notifier::notify(
			'async_job_completed',
			array(
				'job_id'   => $job['id'],
				'job_type' => $job['job_type'],
				'result'   => $result,
			)
		);
	}

	/**
	 * Log a queue event.
	 *
	 * Byte-identical guard on the base logger; dormant standalone until
	 * the platform's logging piece ports.
	 *
	 * @param string $level   Log level.
	 * @param string $message Human-readable message.
	 * @param array  $data    Structured event data.
	 * @return void
	 */
	protected static function log_event( $level, $message, $data = array() ): void {
		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			return;
		}

		\WP_MCP_AI_Logger::log_event( $level, $message, $data );
	}
}
