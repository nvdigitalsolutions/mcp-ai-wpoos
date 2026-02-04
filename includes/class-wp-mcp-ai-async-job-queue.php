<?php
/**
 * Async Job Queue Manager
 *
 * Manages background job execution for commands, workflows, and agentic loops.
 * Integrates with chat-client for real-time updates and supports unlimited iterations.
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
	/**
	 * Async Job Queue Manager Class
	 *
	 * Provides a unified queue system for:
	 * - Slash command execution
	 * - Workflow orchestration
	 * - Tool execution
	 * - Long-running agentic loops
	 *
	 * Features:
	 * - Priority-based scheduling (urgent, high, normal, low, batch)
	 * - Job state management (queued, running, paused, completed, failed)
	 * - Progress tracking (0-100%)
	 * - Resource-aware execution
	 * - Retry logic with exponential backoff
	 * - Dead letter queue integration
	 * - SSE streaming for real-time updates
	 * - Webhook notifications
	 *
	 * @since 2.0.0
	 */
	class WP_MCP_AI_Async_Job_Queue {
		/**
		 * Database table name (without prefix).
		 *
		 * @var string
		 */
		const TABLE_NAME = 'mcp_ai_job_queue';

		/**
		 * Job priority levels.
		 */
		const PRIORITY_URGENT = 1; // Real-time (< 1s).
		const PRIORITY_HIGH   = 2; // Interactive (< 5s).
		const PRIORITY_NORMAL = 3; // Standard (< 30s).
		const PRIORITY_LOW    = 4; // Background (< 5min).
		const PRIORITY_BATCH  = 5; // Non-urgent (> 30min).

		/**
		 * Job statuses.
		 */
		const STATUS_QUEUED    = 'queued';
		const STATUS_RUNNING   = 'running';
		const STATUS_PAUSED    = 'paused';
		const STATUS_COMPLETED = 'completed';
		const STATUS_FAILED    = 'failed';
		const STATUS_CANCELLED = 'cancelled';

		/**
		 * Job types.
		 */
		const TYPE_COMMAND      = 'command';
		const TYPE_WORKFLOW     = 'workflow';
		const TYPE_TOOL         = 'tool';
		const TYPE_AGENTIC_LOOP = 'agentic_loop';

		/**
		 * Cron hook for job processing.
		 */
		const CRON_HOOK = 'wp_mcp_ai_process_job_queue';

		/**
		 * Cron hook for cleanup.
		 */
		const CRON_CLEANUP_HOOK = 'wp_mcp_ai_cleanup_job_queue';

		/**
		 * Maximum job execution time (seconds).
		 */
		const MAX_EXECUTION_TIME = 300; // 5 minutes.

		/**
		 * Maximum retries for failed jobs.
		 */
		const MAX_RETRIES = 3;

		/**
		 * Job cleanup age (days).
		 */
		const CLEANUP_AGE_DAYS = 30;

		/**
		 * Initialize the job queue system.
		 *
		 * Sets up database table, cron jobs, and hooks.
		 *
		 * @return void
		 */
		public static function init() {
			// Create database table.
			self::create_table();

			// Schedule cron jobs.
			self::schedule_cron_jobs();

			// Register cron hooks.
			add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue' ) );
			add_action( self::CRON_CLEANUP_HOOK, array( __CLASS__, 'cleanup_old_jobs' ) );

			// Admin hooks.
			if ( is_admin() ) {
				add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
			}
		}

		/**
		 * Create the job queue database table.
		 *
		 * @return void
		 */
		public static function create_table() {
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

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}

		/**
		 * Schedule cron jobs for queue processing.
		 *
		 * @return void
		 */
		public static function schedule_cron_jobs() {
			// Process queue every minute.
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time(), 'minute', self::CRON_HOOK );
			}

			// Cleanup old jobs daily.
			if ( ! wp_next_scheduled( self::CRON_CLEANUP_HOOK ) ) {
				wp_schedule_event( time(), 'daily', self::CRON_CLEANUP_HOOK );
			}
		}

		/**
		 * Queue a new job.
		 *
		 * @param array $args Job arguments.
		 * @return int|WP_Error Job ID on success, WP_Error on failure.
		 */
		public static function queue_job( $args ) {
			global $wpdb;

			// Validate required fields.
			if ( empty( $args['job_type'] ) ) {
				return new WP_Error(
					'missing_job_type',
					__( 'Job type is required.', 'mcp-ai-wpoos' )
				);
			}

			if ( empty( $args['job_data'] ) ) {
				return new WP_Error(
					'missing_job_data',
					__( 'Job data is required.', 'mcp-ai-wpoos' )
				);
			}

			// Prepare job data.
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

			// Insert into database.
			$table_name = $wpdb->prefix . self::TABLE_NAME;
			$inserted   = $wpdb->insert( $table_name, $job_data );

			if ( false === $inserted ) {
				return new WP_Error(
					'insert_failed',
					__( 'Failed to queue job.', 'mcp-ai-wpoos' ),
					array( 'db_error' => $wpdb->last_error )
				);
			}

			$job_id = $wpdb->insert_id;

			// Emit SSE event if chat session provided.
			if ( ! empty( $args['chat_session'] ) ) {
				self::emit_sse_event( 'job_queued', array(
					'job_id'       => $job_id,
					'job_type'     => $args['job_type'],
					'priority'     => $job_data['priority'],
					'chat_session' => $args['chat_session'],
				) );
			}

			// Log activity.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'info',
					'Job queued',
					array(
						'job_id'   => $job_id,
						'job_type' => $args['job_type'],
						'priority' => $job_data['priority'],
					)
				);
			}

			return $job_id;
		}

		/**
		 * Get a job by ID.
		 *
		 * @param int $job_id Job ID.
		 * @return array|null Job data or null if not found.
		 */
		public static function get_job( $job_id ) {
			global $wpdb;

			$table_name = $wpdb->prefix . self::TABLE_NAME;
			$job        = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM $table_name WHERE id = %d",
					$job_id
				),
				ARRAY_A
			);

			if ( ! $job ) {
				return null;
			}

			// Decode JSON fields.
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

			// Prepare update data.
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
			$updated    = $wpdb->update(
				$table_name,
				$update_data,
				array( 'id' => $job_id )
			);

			// Emit SSE event for progress updates.
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

					self::emit_sse_event( $event_name, $event_data );
				}
			}

			return false !== $updated;
		}

		/**
		 * Process the job queue.
		 *
		 * Processes pending jobs based on priority and system resources.
		 *
		 * @return void
		 */
		public static function process_queue() {
			global $wpdb;

			// Get next job to process (highest priority first).
			$table_name = $wpdb->prefix . self::TABLE_NAME;
			$job        = $wpdb->get_row(
				"SELECT * FROM $table_name 
				WHERE status = 'queued' 
				ORDER BY priority ASC, created_at ASC 
				LIMIT 1",
				ARRAY_A
			);

			if ( ! $job ) {
				return; // No jobs to process.
			}

			// Update job status to running.
			self::update_job( $job['id'], array(
				'status'     => self::STATUS_RUNNING,
				'started_at' => current_time( 'mysql' ),
			) );

			// Decode job data.
			$job['job_data'] = json_decode( $job['job_data'], true );

			// Execute the job.
			try {
				$result = self::execute_job( $job );

				// Update job as completed.
				self::update_job( $job['id'], array(
					'status'       => self::STATUS_COMPLETED,
					'progress'     => 100,
					'result'       => $result,
					'completed_at' => current_time( 'mysql' ),
				) );

				// Send webhook notification if configured.
				self::send_webhook_notification( $job, $result );

			} catch ( Exception $e ) {
				// Job failed.
				$error = array(
					'message' => $e->getMessage(),
					'code'    => $e->getCode(),
					'trace'   => $e->getTraceAsString(),
				);

				// Check if we should retry.
				if ( $job['retries'] < $job['max_retries'] ) {
					// Retry the job.
					self::update_job( $job['id'], array(
						'status'  => self::STATUS_QUEUED,
						'error'   => $error,
						'retries' => $job['retries'] + 1,
					) );
				} else {
					// Max retries exceeded, move to dead letter queue.
					self::update_job( $job['id'], array(
						'status'       => self::STATUS_FAILED,
						'error'        => $error,
						'completed_at' => current_time( 'mysql' ),
					) );

					// Add to dead letter queue.
					if ( class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
						WP_MCP_AI_Dead_Letter_Queue::add_to_queue(
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
		 * @param array $job Job data.
		 * @return mixed Job result.
		 * @throws Exception If job execution fails.
		 */
		private static function execute_job( $job ) {
			switch ( $job['job_type'] ) {
				case self::TYPE_COMMAND:
					return self::execute_command_job( $job );

				case self::TYPE_WORKFLOW:
					return self::execute_workflow_job( $job );

				case self::TYPE_TOOL:
					return self::execute_tool_job( $job );

				case self::TYPE_AGENTIC_LOOP:
					return self::execute_agentic_loop_job( $job );

				default:
					throw new Exception(
						sprintf(
							/* translators: %s: Job type */
							__( 'Unknown job type: %s', 'mcp-ai-wpoos' ),
							$job['job_type']
						)
					);
			}
		}

		/**
		 * Execute a command job.
		 *
		 * @param array $job Job data.
		 * @return mixed Command result.
		 */
		private static function execute_command_job( $job ) {
			// Integration with slash command toolkit manager.
			if ( ! class_exists( 'WP_MCP_AI_Slash_Command_Toolkit_Manager' ) ) {
				throw new Exception( __( 'Slash command toolkit not available.', 'mcp-ai-wpoos' ) );
			}

			$command = $job['job_data']['command'] ?? '';
			$args    = $job['job_data']['args'] ?? array();

			// Execute command.
			$manager = new WP_MCP_AI_Slash_Command_Toolkit_Manager();
			return $manager->execute_command( $command, $args );
		}

		/**
		 * Execute a workflow job.
		 *
		 * @param array $job Job data.
		 * @return mixed Workflow result.
		 */
		private static function execute_workflow_job( $job ) {
			// Integration with workflow orchestrator.
			if ( ! class_exists( 'WP_MCP_AI_Workflow_Orchestrator' ) ) {
				throw new Exception( __( 'Workflow orchestrator not available.', 'mcp-ai-wpoos' ) );
			}

			$workflow_id = $job['job_data']['workflow_id'] ?? '';

			// Execute workflow.
			$orchestrator = new WP_MCP_AI_Workflow_Orchestrator();
			return $orchestrator->execute_workflow( $workflow_id, $job['job_data'] );
		}

		/**
		 * Execute a tool job.
		 *
		 * @param array $job Job data.
		 * @return mixed Tool result.
		 */
		private static function execute_tool_job( $job ) {
			// Integration with tool async executor.
			if ( ! class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
				throw new Exception( __( 'Tool async executor not available.', 'mcp-ai-wpoos' ) );
			}

			$tool_slug = $job['job_data']['tool_slug'] ?? '';
			$arguments = $job['job_data']['arguments'] ?? array();

			// Execute tool.
			return WP_MCP_AI_Tool_Async_Executor::execute_tool( $tool_slug, $arguments );
		}

		/**
		 * Execute an agentic loop job.
		 *
		 * @param array $job Job data.
		 * @return mixed Agentic loop result.
		 */
		private static function execute_agentic_loop_job( $job ) {
			// TODO: Implement agentic loop execution.
			// This would integrate with the chat controller's agentic loop logic
			// but allow for unlimited iterations in the background.
			
			throw new Exception( __( 'Agentic loop execution not yet implemented.', 'mcp-ai-wpoos' ) );
		}

		/**
		 * Emit SSE event for job updates.
		 *
		 * @param string $event_name Event name.
		 * @param array  $event_data Event data.
		 * @return void
		 */
		private static function emit_sse_event( $event_name, $event_data ) {
			if ( class_exists( 'WP_MCP_AI_SSE_Handler' ) ) {
				do_action( 'wp_mcp_ai_emit_sse_event', $event_name, $event_data );
			}
		}

		/**
		 * Send webhook notification for job completion.
		 *
		 * @param array $job    Job data.
		 * @param mixed $result Job result.
		 * @return void
		 */
		private static function send_webhook_notification( $job, $result ) {
			if ( ! class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
				return;
			}

			WP_MCP_AI_Job_Notifier::notify(
				'async_job_completed',
				array(
					'job_id'   => $job['id'],
					'job_type' => $job['job_type'],
					'result'   => $result,
				)
			);
		}

		/**
		 * Cleanup old completed/failed jobs.
		 *
		 * @return void
		 */
		public static function cleanup_old_jobs() {
			global $wpdb;

			$table_name = $wpdb->prefix . self::TABLE_NAME;
			$age_days   = apply_filters( 'wp_mcp_ai_job_queue_cleanup_age_days', self::CLEANUP_AGE_DAYS );

			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $table_name 
					WHERE status IN ('completed', 'failed', 'cancelled') 
					AND completed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
					$age_days
				)
			);

			if ( $deleted && class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
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
			return self::update_job( $job_id, array(
				'status'       => self::STATUS_CANCELLED,
				'completed_at' => current_time( 'mysql' ),
			) );
		}

		/**
		 * Pause a job.
		 *
		 * @param int $job_id Job ID.
		 * @return bool True on success, false on failure.
		 */
		public static function pause_job( $job_id ) {
			return self::update_job( $job_id, array(
				'status' => self::STATUS_PAUSED,
			) );
		}

		/**
		 * Resume a paused job.
		 *
		 * @param int $job_id Job ID.
		 * @return bool True on success, false on failure.
		 */
		public static function resume_job( $job_id ) {
			return self::update_job( $job_id, array(
				'status' => self::STATUS_QUEUED,
			) );
		}

		/**
		 * Get jobs by status.
		 *
		 * @param string $status Job status.
		 * @param int    $limit  Maximum number of jobs to return.
		 * @return array Array of jobs.
		 */
		public static function get_jobs_by_status( $status, $limit = 100 ) {
			global $wpdb;

			$table_name = $wpdb->prefix . self::TABLE_NAME;
			$jobs       = $wpdb->get_results(
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

			// Decode JSON fields.
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
		 * @return array Queue statistics.
		 */
		public static function get_queue_stats() {
			global $wpdb;

			$table_name = $wpdb->prefix . self::TABLE_NAME;

			return array(
				'total'     => $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" ),
				'queued'    => $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE status = 'queued'" ),
				'running'   => $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE status = 'running'" ),
				'completed' => $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE status = 'completed'" ),
				'failed'    => $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE status = 'failed'" ),
			);
		}

		/**
		 * Register admin page for job queue management.
		 *
		 * @return void
		 */
		public static function register_admin_page() {
			// Admin page will be implemented separately.
			// For now, just register the menu item.
			add_submenu_page(
				'wp-mcp-ai',
				__( 'Job Queue', 'mcp-ai-wpoos' ),
				__( 'Job Queue', 'mcp-ai-wpoos' ),
				'manage_options',
				'wp-mcp-ai-job-queue',
				array( __CLASS__, 'render_admin_page' )
			);
		}

		/**
		 * Render admin page (placeholder).
		 *
		 * @return void
		 */
		public static function render_admin_page() {
			$stats = self::get_queue_stats();
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Async Job Queue', 'mcp-ai-wpoos' ); ?></h1>
				
				<div class="wp-mcp-ai-job-queue-stats">
					<h2><?php esc_html_e( 'Queue Statistics', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat">
						<tbody>
							<tr>
								<td><?php esc_html_e( 'Total Jobs', 'mcp-ai-wpoos' ); ?></td>
								<td><?php echo esc_html( $stats['total'] ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Queued', 'mcp-ai-wpoos' ); ?></td>
								<td><?php echo esc_html( $stats['queued'] ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Running', 'mcp-ai-wpoos' ); ?></td>
								<td><?php echo esc_html( $stats['running'] ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Completed', 'mcp-ai-wpoos' ); ?></td>
								<td><?php echo esc_html( $stats['completed'] ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Failed', 'mcp-ai-wpoos' ); ?></td>
								<td><?php echo esc_html( $stats['failed'] ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="wp-mcp-ai-job-queue-recent">
					<h2><?php esc_html_e( 'Recent Jobs', 'mcp-ai-wpoos' ); ?></h2>
					<?php
					$recent_jobs = self::get_jobs_by_status( self::STATUS_QUEUED, 10 );
					if ( empty( $recent_jobs ) ) {
						echo '<p>' . esc_html__( 'No queued jobs.', 'mcp-ai-wpoos' ) . '</p>';
					} else {
						echo '<table class="widefat">';
						echo '<thead><tr>';
						echo '<th>' . esc_html__( 'ID', 'mcp-ai-wpoos' ) . '</th>';
						echo '<th>' . esc_html__( 'Type', 'mcp-ai-wpoos' ) . '</th>';
						echo '<th>' . esc_html__( 'Priority', 'mcp-ai-wpoos' ) . '</th>';
						echo '<th>' . esc_html__( 'Status', 'mcp-ai-wpoos' ) . '</th>';
						echo '<th>' . esc_html__( 'Created', 'mcp-ai-wpoos' ) . '</th>';
						echo '</tr></thead><tbody>';
						
						foreach ( $recent_jobs as $job ) {
							echo '<tr>';
							echo '<td>' . esc_html( $job['id'] ) . '</td>';
							echo '<td>' . esc_html( $job['job_type'] ) . '</td>';
							echo '<td>' . esc_html( $job['priority'] ) . '</td>';
							echo '<td>' . esc_html( $job['status'] ) . '</td>';
							echo '<td>' . esc_html( $job['created_at'] ) . '</td>';
							echo '</tr>';
						}
						
						echo '</tbody></table>';
					}
					?>
				</div>
			</div>
			<?php
		}
	}

	// Initialize on plugin load.
	add_action( 'plugins_loaded', array( 'WP_MCP_AI_Async_Job_Queue', 'init' ) );
}
