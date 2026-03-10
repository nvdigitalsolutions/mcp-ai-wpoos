<?php
/**
 * Orchestration Dashboard Admin Page
 *
 * Real-time monitoring and management of autonomous sessions.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestration Dashboard Class
 */
class WP_MCP_AI_Orchestration_Dashboard {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 100 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_dashboard_data', array( $this, 'ajax_get_dashboard_data' ) );
		add_action( 'wp_ajax_wp_mcp_ai_control_session', array( $this, 'ajax_control_session' ) );
		add_action( 'wp_ajax_wp_mcp_ai_trigger_workflow', array( $this, 'ajax_trigger_workflow' ) );
	}

	/**
	 * Add menu page under main NV oOS menu
	 */
	public function add_menu_page() {
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Real-Time Orchestration Monitor (Pro)', 'mcp-ai-wpoos-pro' ),
			__( 'Orchestration Monitor', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'mcp-ai-orchestration-pro',
			array( $this, 'render_dashboard' )
		);
	}

	/**
	 * Enqueue assets
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Check for orchestration page.
		// Hook format: 'nvoos-pro-dashboard_page_mcp-ai-orchestration-pro'
		// Also check via $_GET for additional safety.
		$is_orchestration_page = ( 'nvoos-pro-dashboard_page_mcp-ai-orchestration-pro' === $hook ) ||
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking page slug for script enqueue only.
			( isset( $_GET['page'] ) && 'mcp-ai-orchestration-pro' === $_GET['page'] );

		// Debug logging for troubleshooting asset enqueue issues.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging when WP_DEBUG is enabled.
			error_log( sprintf( 'Orchestration Dashboard: Hook=%s, GET page=%s, Is orchestration page=%s', $hook, isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'not set', $is_orchestration_page ? 'YES' : 'NO' ) );
		}

		if ( ! $is_orchestration_page ) {
			return;
		}

		// Enqueue orchestration bundle.
		wp_enqueue_script(
			'wp-mcp-ai-orchestration-bundle',
			WP_MCP_AI_PRO_URL . 'assets/js/orchestration-bundle.min.js',
			array(),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Enqueue dashboard styles.
		wp_enqueue_style(
			'wp-mcp-ai-orchestration-dashboard',
			WP_MCP_AI_PRO_URL . 'assets/css/orchestration-dashboard.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// Enqueue dashboard script.
		wp_enqueue_script(
			'wp-mcp-ai-orchestration-dashboard',
			WP_MCP_AI_PRO_URL . 'assets/js/orchestration-dashboard.js',
			array( 'jquery', 'wp-mcp-ai-orchestration-bundle' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'wp-mcp-ai-orchestration-dashboard',
			'wpMcpAiOrchestration',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
				'refreshInterval' => 5000, // 5 seconds.
				'strings'         => array(
					'loading'         => __( 'Loading...', 'mcp-ai-wpoos-pro' ),
					'error'           => __( 'Error loading data', 'mcp-ai-wpoos-pro' ),
					'noSessions'      => __( 'No active sessions', 'mcp-ai-wpoos-pro' ),
					'noWorkflows'     => __( 'No workflows found', 'mcp-ai-wpoos-pro' ),
					'pauseSession'    => __( 'Pause', 'mcp-ai-wpoos-pro' ),
					'resumeSession'   => __( 'Resume', 'mcp-ai-wpoos-pro' ),
					'stopSession'     => __( 'Stop', 'mcp-ai-wpoos-pro' ),
					'startWorkflow'   => __( 'Start Workflow', 'mcp-ai-wpoos-pro' ),
					'viewWorkflow'    => __( 'View Details', 'mcp-ai-wpoos-pro' ),
					'confirmStart'    => __( 'Are you sure you want to start this workflow?', 'mcp-ai-wpoos-pro' ),
					'workflowStarted' => __( 'Workflow started successfully', 'mcp-ai-wpoos-pro' ),
					'workflowError'   => __( 'Error starting workflow', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Render dashboard
	 */
	public function render_dashboard() {
		?>
		<div class="wrap wp-mcp-ai-orchestration-dashboard">
			<h1><?php esc_html_e( 'Orchestration Monitor (Pro)', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Real-time monitoring and management of autonomous AI sessions with advanced analytics.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<!-- Overview Cards -->
			<div class="orchestration-overview">
				<div class="overview-card" id="active-sessions-card">
					<div class="card-icon">🔄</div>
					<div class="card-content">
						<div class="card-value" data-metric="active_sessions">-</div>
						<div class="card-label"><?php esc_html_e( 'Active Sessions', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>

				<div class="overview-card" id="total-plans-card">
					<div class="card-icon">📋</div>
					<div class="card-content">
						<div class="card-value" data-metric="total_plans">-</div>
						<div class="card-label"><?php esc_html_e( 'Task Plans', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>

				<div class="overview-card" id="total-executions-card">
					<div class="card-icon">⚡</div>
					<div class="card-content">
						<div class="card-value" data-metric="total_executions">-</div>
						<div class="card-label"><?php esc_html_e( 'Tool Executions', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>

				<div class="overview-card" id="system-health-card">
					<div class="card-icon">💚</div>
					<div class="card-content">
						<div class="card-value" data-metric="system_health">-</div>
						<div class="card-label"><?php esc_html_e( 'System Health', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>
			</div>

			<!-- Capacity Analysis -->
			<div class="orchestration-section">
				<h2><?php esc_html_e( 'Capacity Analysis (Little\'s Law)', 'mcp-ai-wpoos-pro' ); ?></h2>
				<div class="capacity-metrics" id="capacity-metrics">
					<div class="capacity-item">
						<span class="label"><?php esc_html_e( 'Current Utilization:', 'mcp-ai-wpoos-pro' ); ?></span>
						<span class="value" data-metric="utilization">-</span>
					</div>
					<div class="capacity-item">
						<span class="label"><?php esc_html_e( 'Predicted Queue Length:', 'mcp-ai-wpoos-pro' ); ?></span>
						<span class="value" data-metric="queue_length">-</span>
					</div>
					<div class="capacity-item">
						<span class="label"><?php esc_html_e( 'Load Status:', 'mcp-ai-wpoos-pro' ); ?></span>
						<span class="value status-badge" data-metric="load_status">-</span>
					</div>
				</div>
			</div>

			<!-- System Status Monitor -->
			<div class="orchestration-section">
				<h2><?php esc_html_e( 'System Status', 'mcp-ai-wpoos-pro' ); ?></h2>
				<div class="system-status-grid">
					<!-- Cron Jobs Status -->
					<div class="status-card">
						<h3><span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Cron Jobs', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="status-metrics">
							<div class="metric">
								<span class="label"><?php esc_html_e( 'Active:', 'mcp-ai-wpoos-pro' ); ?></span>
								<span class="value" data-system-status="cron_active">-</span>
							</div>
							<div class="metric">
								<span class="label"><?php esc_html_e( 'Pending:', 'mcp-ai-wpoos-pro' ); ?></span>
								<span class="value" data-system-status="cron_pending">-</span>
							</div>
							<div class="metric">
								<span class="label"><?php esc_html_e( 'Failed:', 'mcp-ai-wpoos-pro' ); ?></span>
								<span class="value error" data-system-status="cron_failed">-</span>
							</div>
						</div>
					</div>

					<!-- Async Operations Status -->
					<div class="status-card">
						<h3><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Async Operations', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="status-metrics">
							<div class="metric">
								<span class="label"><?php esc_html_e( 'Status:', 'mcp-ai-wpoos-pro' ); ?></span>
								<span class="value status-badge" data-system-status="async_status">-</span>
							</div>
							<div class="metric">
								<span class="label"><?php esc_html_e( 'Stuck Jobs:', 'mcp-ai-wpoos-pro' ); ?></span>
								<span class="value warning" data-system-status="async_stuck_jobs">-</span>
							</div>
							<div class="metric">
								<span class="label"><?php esc_html_e( 'Long Running:', 'mcp-ai-wpoos-pro' ); ?></span>
								<span class="value" data-system-status="async_long_running">-</span>
							</div>
						</div>
					</div>

					<!-- System Health Status -->
					<div class="status-card">
						<h3><span class="dashicons dashicons-heart"></span> <?php esc_html_e( 'System Health', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="status-metrics">
							<div class="metric">
								<span class="label"><?php esc_html_e( 'Overall:', 'mcp-ai-wpoos-pro' ); ?></span>
								<span class="value status-badge" data-system-status="health_status">-</span>
							</div>
							<div class="metric">
								<span class="label"><?php esc_html_e( 'Label:', 'mcp-ai-wpoos-pro' ); ?></span>
								<span class="value" data-system-status="health_label">-</span>
							</div>
						</div>
					</div>

					<!-- SSE Connectivity -->
					<div class="status-card">
						<h3><span class="dashicons dashicons-update-alt"></span> <?php esc_html_e( 'SSE Streaming', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="status-metrics">
							<div class="metric">
								<span class="label"><?php esc_html_e( 'Available:', 'mcp-ai-wpoos-pro' ); ?></span>
								<span class="value" data-system-status="sse_available">-</span>
							</div>
							<div class="metric">
								<span class="label"><?php esc_html_e( 'Endpoint:', 'mcp-ai-wpoos-pro' ); ?></span>
								<span class="value small" data-system-status="sse_endpoint">-</span>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Active Sessions Table -->
			<div class="orchestration-section">
				<h2><?php esc_html_e( 'Active Sessions', 'mcp-ai-wpoos-pro' ); ?></h2>
				<div class="sessions-table-wrapper">
					<table class="wp-list-table widefat fixed striped" id="sessions-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Session ID', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Task Plan', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Health', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Progress', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Iterations', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Tokens', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Elapsed', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody id="sessions-table-body">
							<tr class="no-items">
								<td colspan="9"><?php esc_html_e( 'Loading sessions...', 'mcp-ai-wpoos-pro' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Team Workflows Table -->
			<div class="orchestration-section">
				<h2><?php esc_html_e( 'Team Workflows', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Monitor and manage multi-agent team workflows. Workflows in "initialized" state can be manually triggered if needed.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<div class="workflows-table-wrapper">
					<table class="wp-list-table widefat fixed striped" id="workflows-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Workflow ID', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Team ID', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Task Type', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'State', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Age', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Tasks', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Created', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody id="workflows-table-body">
							<tr class="no-items">
								<td colspan="8"><?php esc_html_e( 'Loading workflows...', 'mcp-ai-wpoos-pro' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Recent Activity -->
			<div class="orchestration-section">
				<h2><?php esc_html_e( 'Recent Activity', 'mcp-ai-wpoos-pro' ); ?></h2>
				<div class="activity-feed" id="activity-feed">
					<div class="activity-loading"><?php esc_html_e( 'Loading activity...', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Get dashboard data
	 */
	public function ajax_get_dashboard_data() {
		check_ajax_referer( 'wp_mcp_ai_orchestration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		WP_MCP_AI_Logger::log_debug( '[Pro Dashboard] AJAX get_dashboard_data called' );

		$data = $this->get_dashboard_data();

		WP_MCP_AI_Logger::log_debug(
			'[Pro Dashboard] Dashboard data prepared',
			array(
				'has_system_status'  => isset( $data['system_status'] ),
				'system_status_keys' => isset( $data['system_status'] ) ? array_keys( $data['system_status'] ) : array(),
			)
		);

		wp_send_json_success( $data );
	}

	/**
	 * Get dashboard data
	 *
	 * @return array
	 */
	private function get_dashboard_data() {
		$data = array(
			'overview'      => $this->get_overview_metrics(),
			'capacity'      => $this->get_capacity_metrics(),
			'sessions'      => $this->get_active_sessions(),
			'workflows'     => $this->get_team_workflows(),
			'activity'      => $this->get_recent_activity(),
			'system_status' => $this->get_system_status(),
			'timestamp'     => time(),
		);

		return $data;
	}

	/**
	 * Get system status information for dashboard updates.
	 *
	 * Includes cron status, async job health, orchestration health, and SSE connectivity.
	 *
	 * @return array System status data.
	 */
	private function get_system_status() {
		$status = array(
			'cron'   => array(),
			'async'  => array(),
			'sse'    => array(),
			'health' => array(),
		);

		// Diagnostic: Log start of status collection.
		WP_MCP_AI_Logger::log_debug( '[Pro Dashboard] Starting system status collection' );

		// Get cron job status if service is available.
		if ( class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
			try {
				WP_MCP_AI_Logger::log_debug( '[Pro Dashboard] Collecting cron status' );
				$cron_service   = new WP_MCP_AI_Cron_Status_Service();
				$cron_summary   = $cron_service->get_status_summary( 0, 5 );
				$status['cron'] = array(
					'total'     => count( $cron_summary ),
					'active'    => 0,
					'completed' => 0,
					'pending'   => 0,
					'failed'    => 0,
					'jobs'      => array(),
				);

				foreach ( $cron_summary as $job ) {
					$job_status = isset( $job['status'] ) ? $job['status'] : 'unknown';

					if ( 'active' === $job_status || 'running' === $job_status ) {
						++$status['cron']['active'];
					} elseif ( 'completed' === $job_status ) {
						++$status['cron']['completed'];
					} elseif ( 'pending' === $job_status ) {
						++$status['cron']['pending'];
					} elseif ( 'failed' === $job_status ) {
						++$status['cron']['failed'];
					}

					// Include recent jobs for display.
					if ( count( $status['cron']['jobs'] ) < 5 ) {
						$status['cron']['jobs'][] = array(
							'job_id' => isset( $job['job_id'] ) ? $job['job_id'] : '',
							'title'  => isset( $job['title'] ) ? $job['title'] : 'Unknown',
							'status' => $job_status,
						);
					}
				}
				WP_MCP_AI_Logger::log_debug( '[Pro Dashboard] Cron status collected', $status['cron'] );
			} catch ( Exception $e ) {
				// Silently fail - status monitoring should not break the dashboard.
				$status['cron']['error'] = $e->getMessage();
				WP_MCP_AI_Logger::log_error( '[Pro Dashboard] Failed to collect cron status: ' . $e->getMessage() );
			}
		} else {
			WP_MCP_AI_Logger::log_debug( '[Pro Dashboard] WP_MCP_AI_Cron_Status_Service class not available' );
		}

		// Get async health status if monitor is available.
		if ( class_exists( 'WP_MCP_AI_Async_Health_Monitor' ) ) {
			try {
				WP_MCP_AI_Logger::log_debug( '[Pro Dashboard] Collecting async status' );
				$async_health    = WP_MCP_AI_Async_Health_Monitor::check_async_health();
				$status['async'] = array(
					'status'         => isset( $async_health['status'] ) ? $async_health['status'] : 'unknown',
					'stuck_jobs'     => isset( $async_health['stuck_jobs'] ) ? $async_health['stuck_jobs'] : 0,
					'long_running'   => isset( $async_health['long_running'] ) ? $async_health['long_running'] : 0,
					'pending_jobs'   => isset( $async_health['pending_jobs'] ) ? $async_health['pending_jobs'] : 0,
					'failed_jobs'    => isset( $async_health['failed_jobs'] ) ? $async_health['failed_jobs'] : 0,
					'cron_scheduled' => isset( $async_health['cron_scheduled'] ) ? $async_health['cron_scheduled'] : false,
					'issues'         => isset( $async_health['issues'] ) ? $async_health['issues'] : array(),
				);
				WP_MCP_AI_Logger::log_debug( '[Pro Dashboard] Async status collected', $status['async'] );
			} catch ( Exception $e ) {
				$status['async']['error'] = $e->getMessage();
				WP_MCP_AI_Logger::log_error( '[Pro Dashboard] Failed to collect async status: ' . $e->getMessage() );
			}
		} else {
			WP_MCP_AI_Logger::log_debug( '[Pro Dashboard] WP_MCP_AI_Async_Health_Monitor class not available' );
		}

		// Get orchestration health status if service is available.
		if ( class_exists( 'WP_MCP_AI_Orchestration_Health_Service' ) ) {
			try {
				WP_MCP_AI_Logger::log_debug( '[Pro Dashboard] Collecting health status' );
				$health_status    = WP_MCP_AI_Orchestration_Health_Service::get_health_status();
				$status['health'] = array(
					'status'  => isset( $health_status['status'] ) ? $health_status['status'] : 'unknown',
					'label'   => isset( $health_status['label'] ) ? $health_status['label'] : 'Unknown',
					'icon'    => isset( $health_status['icon'] ) ? $health_status['icon'] : '❓',
					'metrics' => isset( $health_status['metrics'] ) ? $health_status['metrics'] : array(),
				);
				WP_MCP_AI_Logger::log_debug( '[Pro Dashboard] Health status collected', $status['health'] );
			} catch ( Exception $e ) {
				$status['health']['error'] = $e->getMessage();
				WP_MCP_AI_Logger::log_error( '[Pro Dashboard] Failed to collect health status: ' . $e->getMessage() );
			}
		} else {
			WP_MCP_AI_Logger::log_debug( '[Pro Dashboard] WP_MCP_AI_Orchestration_Health_Service class not available' );
		}

		// SSE connectivity check - basic check if SSE endpoint is configured.
		WP_MCP_AI_Logger::log_debug( '[Pro Dashboard] Collecting SSE status' );
		$status['sse'] = array(
			'available' => class_exists( 'WP_MCP_AI_SSE_Stream' ),
			'endpoint'  => rest_url( 'mcp-ai/v1/jobs' ),
		);
		WP_MCP_AI_Logger::log_debug( '[Pro Dashboard] SSE status collected', $status['sse'] );

		// Diagnostic: Log final collected status.
		WP_MCP_AI_Logger::log_debug( '[Pro Dashboard] System status collection complete', $status );

		return $status;
	}

	/**
	 * Get overview metrics
	 *
	 * @return array
	 */
	private function get_overview_metrics() {
		global $wpdb;

		// Count active sessions (from transients for now).
		$active_sessions = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$transients      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_mcp_ai_session_' ) . '%'
			)
		);

		foreach ( $transients as $transient ) {
			$session_data = json_decode( $transient->option_value, true );
			if ( ! is_array( $session_data ) ) {
				continue;
			}
			if ( isset( $session_data['status'] ) && 'active' === $session_data['status'] ) {
				++$active_sessions;
			}
		}

		// Count task plans.
		$total_plans = wp_count_posts( 'mcp_task_plan' );
		$plan_count  = isset( $total_plans->publish ) ? $total_plans->publish : 0;

		// Estimate executions (placeholder).
		$total_executions = $active_sessions * 5; // Rough estimate.

		// System health (placeholder).
		$system_health = $active_sessions < 5 ? 'Healthy' : ( $active_sessions < 10 ? 'Good' : 'Busy' );

		return array(
			'active_sessions'  => $active_sessions,
			'total_plans'      => $plan_count,
			'total_executions' => $total_executions,
			'system_health'    => $system_health,
		);
	}

	/**
	 * Get capacity metrics
	 *
	 * @return array
	 */
	private function get_capacity_metrics() {
		$overview       = $this->get_overview_metrics();
		$max_concurrent = 10; // Default max.

		$utilization = $max_concurrent > 0 ? round( ( $overview['active_sessions'] / $max_concurrent ) * 100, 2 ) : 0;

		// Little's Law: L = λ × W (simplified).
		$arrival_rate = 2; // Sessions per hour (placeholder).
		$service_time = 0.5; // Hours per session (placeholder).
		$queue_length = round( $arrival_rate * $service_time, 2 );

		$load_status = 'IDLE';
		if ( $utilization > 90 ) {
			$load_status = 'CRITICAL';
		} elseif ( $utilization > 80 ) {
			$load_status = 'WARNING';
		} elseif ( $utilization > 50 ) {
			$load_status = 'MODERATE';
		} elseif ( $utilization > 20 ) {
			$load_status = 'LIGHT';
		}

		return array(
			'utilization'    => $utilization . '%',
			'queue_length'   => $queue_length,
			'load_status'    => $load_status,
			'max_concurrent' => $max_concurrent,
		);
	}

	/**
	 * Get active sessions
	 *
	 * @return array
	 */
	private function get_active_sessions() {
		global $wpdb;

		$sessions   = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				WHERE option_name LIKE %s
				LIMIT 50",
				$wpdb->esc_like( '_transient_mcp_ai_session_' ) . '%'
			)
		);

		foreach ( $transients as $transient ) {
			$session_id   = str_replace( array( '_transient_mcp_ai_session_', '_transient_timeout_mcp_ai_session_' ), '', $transient->option_name );
			$session_data = json_decode( $transient->option_value, true );

			if ( ! is_array( $session_data ) ) {
				continue;
			}

			$plan_title = 'Unknown';
			if ( ! empty( $session_data['plan_id'] ) ) {
				$plan = get_post( $session_data['plan_id'] );
				if ( $plan ) {
					$plan_title = $plan->post_title;
				}
			}

			$sessions[] = array(
				'session_id'     => substr( $session_id, 0, 8 ),
				'plan_title'     => $plan_title,
				'status'         => isset( $session_data['status'] ) ? $session_data['status'] : 'unknown',
				'health'         => isset( $session_data['health'] ) ? $session_data['health'] : 'unknown',
				'iterations'     => isset( $session_data['iterations'] ) ? $session_data['iterations'] : 0,
				'max_iterations' => isset( $session_data['max_iterations'] ) ? $session_data['max_iterations'] : 25,
				'tokens_used'    => isset( $session_data['tokens_used'] ) ? $session_data['tokens_used'] : 0,
				'token_budget'   => isset( $session_data['token_budget'] ) ? $session_data['token_budget'] : 10000,
				'start_time'     => isset( $session_data['start_time'] ) ? $session_data['start_time'] : time(),
			);
		}

		return $sessions;
	}

	/**
	 * Get team workflows
	 *
	 * @return array
	 */
	private function get_team_workflows() {
		global $wpdb;

		$workflows = array();

		// Get workflow transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND option_name LIKE %s
				ORDER BY option_id DESC
				LIMIT 50",
				'%transient%',
				'%wp_mcp_ai_workflow_%'
			)
		);

		foreach ( $transients as $transient ) {
			// Skip timeout entries.
			if ( strpos( $transient->option_name, '_transient_timeout_' ) !== false ) {
				continue;
			}

			$workflow_data = json_decode( $transient->option_value, true );

			if ( ! is_array( $workflow_data ) || ! isset( $workflow_data['workflow_id'] ) ) {
				continue;
			}

			$created_time = isset( $workflow_data['created_at'] ) ? strtotime( $workflow_data['created_at'] ) : 0;
			$age_seconds  = time() - $created_time;
			$age_minutes  = round( $age_seconds / 60, 1 );

			// Calculate task progress.
			$tasks_total = isset( $workflow_data['tasks'] ) ? count( $workflow_data['tasks'] ) : 0;
			$tasks_done  = 0;
			if ( isset( $workflow_data['tasks'] ) && is_array( $workflow_data['tasks'] ) ) {
				foreach ( $workflow_data['tasks'] as $task ) {
					if ( isset( $task['status'] ) && 'completed' === $task['status'] ) {
						++$tasks_done;
					}
				}
			}

			// Determine if workflow is stale.
			$is_stale = false;
			if ( 'initialized' === $workflow_data['state'] && $age_seconds > 300 ) {
				$is_stale = true;
			}

			$workflows[] = array(
				'workflow_id'  => $workflow_data['workflow_id'],
				'team_id'      => $workflow_data['team_id'] ?? 'N/A',
				'task_type'    => $workflow_data['task_type'] ?? 'generic',
				'state'        => $workflow_data['state'],
				'age_seconds'  => $age_seconds,
				'age_minutes'  => $age_minutes,
				'age_display'  => $this->format_age( $age_seconds ),
				'tasks_total'  => $tasks_total,
				'tasks_done'   => $tasks_done,
				'created_at'   => $workflow_data['created_at'] ?? 'N/A',
				'started_at'   => $workflow_data['started_at'] ?? null,
				'completed_at' => $workflow_data['completed_at'] ?? null,
				'is_stale'     => $is_stale,
			);
		}

		return $workflows;
	}

	/**
	 * Format age in human-readable form
	 *
	 * @param int $seconds Age in seconds.
	 * @return string Formatted age.
	 */
	private function format_age( $seconds ) {
		if ( $seconds < 60 ) {
			return sprintf(
				/* translators: %d: number of seconds */
				_n( '%d second', '%d seconds', $seconds, 'mcp-ai-wpoos-pro' ),
				$seconds
			);
		} elseif ( $seconds < 3600 ) {
			$minutes = floor( $seconds / 60 );
			return sprintf(
				/* translators: %d: number of minutes */
				_n( '%d minute', '%d minutes', $minutes, 'mcp-ai-wpoos-pro' ),
				$minutes
			);
		} else {
			$hours = floor( $seconds / 3600 );
			return sprintf(
				/* translators: %d: number of hours */
				_n( '%d hour', '%d hours', $hours, 'mcp-ai-wpoos-pro' ),
				$hours
			);
		}
	}

	/**
	 * Get recent activity
	 *
	 * @return array
	 */
	private function get_recent_activity() {
		// Placeholder for activity feed.
		return array(
			array(
				'timestamp' => time(),
				'message'   => 'System initialized',
				'type'      => 'info',
			),
		);
	}

	/**
	 * AJAX: Control session
	 */
	public function ajax_control_session() {
		check_ajax_referer( 'wp_mcp_ai_orchestration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$action     = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';

		if ( empty( $session_id ) || empty( $action ) ) {
			wp_send_json_error( array( 'message' => 'Invalid parameters' ) );
		}

		// Get session data.
		$session_data = get_transient( 'mcp_ai_session_' . $session_id );
		if ( false === $session_data ) {
			wp_send_json_error( array( 'message' => 'Session not found' ) );
		}

		// Update session based on action.
		if ( 'pause' === $action ) {
			$session_data['status'] = 'paused';
		} elseif ( 'resume' === $action ) {
			$session_data['status'] = 'active';
		} elseif ( 'stop' === $action ) {
			$session_data['status']      = 'stopped';
			$session_data['stop_reason'] = 'Manual stop';
		}

		set_transient( 'mcp_ai_session_' . $session_id, $session_data, 86400 );

		wp_send_json_success(
			array(
				'message' => 'Session updated',
				'session' => $session_data,
			)
		);
	}

	/**
	 * AJAX: Trigger workflow execution
	 */
	public function ajax_trigger_workflow() {
		check_ajax_referer( 'wp_mcp_ai_orchestration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'mcp-ai-wpoos-pro' ) ) );
		}

		$workflow_id = isset( $_POST['workflow_id'] ) ? sanitize_text_field( wp_unslash( $_POST['workflow_id'] ) ) : '';

		if ( empty( $workflow_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid workflow ID', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Check if Enhanced Workflow Coordinator is available.
		if ( ! class_exists( 'WP_MCP_AI_Enhanced_Workflow_Coordinator' ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow coordinator not available', 'mcp-ai-wpoos-pro' ) ) );
		}

		try {
			$coordinator = new WP_MCP_AI_Enhanced_Workflow_Coordinator();

			// Execute the workflow.
			$result = $coordinator->execute_workflow( $workflow_id );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array(
						'message' => $result->get_error_message(),
						'code'    => $result->get_error_code(),
					)
				);
			}

			wp_send_json_success(
				array(
					'message'     => __( 'Workflow started successfully', 'mcp-ai-wpoos-pro' ),
					'workflow_id' => $workflow_id,
					'result'      => $result,
				)
			);

		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Error starting workflow: %s', 'mcp-ai-wpoos-pro' ),
						$e->getMessage()
					),
				)
			);
		}
	}
}

// Note: Class is instantiated in includes/orchestration-init.php on admin_init hook.
