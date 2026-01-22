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
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 25 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_dashboard_data', array( $this, 'ajax_get_dashboard_data' ) );
		add_action( 'wp_ajax_wp_mcp_ai_control_session', array( $this, 'ajax_control_session' ) );
	}

	/**
	 * Add menu page
	 */
	public function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_assistant',
			__( 'Orchestration Dashboard', 'mcp-ai-wpoos-pro' ),
			__( 'Orchestration', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'mcp-ai-orchestration',
			array( $this, 'render_dashboard' )
		);
	}

	/**
	 * Enqueue assets
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'mcp_ai_assistant_page_mcp-ai-orchestration' !== $hook ) {
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
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
				'refreshInterval'  => 5000, // 5 seconds.
				'strings'          => array(
					'loading'      => __( 'Loading...', 'mcp-ai-wpoos-pro' ),
					'error'        => __( 'Error loading data', 'mcp-ai-wpoos-pro' ),
					'noSessions'   => __( 'No active sessions', 'mcp-ai-wpoos-pro' ),
					'pauseSession' => __( 'Pause', 'mcp-ai-wpoos-pro' ),
					'resumeSession' => __( 'Resume', 'mcp-ai-wpoos-pro' ),
					'stopSession'  => __( 'Stop', 'mcp-ai-wpoos-pro' ),
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
			<h1><?php esc_html_e( 'Autonomous Orchestration Dashboard', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Real-time monitoring and management of autonomous AI sessions with Ralph Wiggum patterns.', 'mcp-ai-wpoos-pro' ); ?>
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

		$data = $this->get_dashboard_data();
		wp_send_json_success( $data );
	}

	/**
	 * Get dashboard data
	 *
	 * @return array
	 */
	private function get_dashboard_data() {
		$data = array(
			'overview'   => $this->get_overview_metrics(),
			'capacity'   => $this->get_capacity_metrics(),
			'sessions'   => $this->get_active_sessions(),
			'activity'   => $this->get_recent_activity(),
			'timestamp'  => time(),
		);

		return $data;
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
		$transients = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_mcp_ai_session_%'"
		);
		
		foreach ( $transients as $transient ) {
			$session_data = maybe_unserialize( $transient->option_value );
			if ( isset( $session_data['status'] ) && 'active' === $session_data['status'] ) {
				$active_sessions++;
			}
		}

		// Count task plans.
		$total_plans = wp_count_posts( 'mcp_task_plan' );
		$plan_count = isset( $total_plans->publish ) ? $total_plans->publish : 0;

		// Estimate executions (placeholder).
		$total_executions = $active_sessions * 5; // Rough estimate.

		// System health (placeholder).
		$system_health = $active_sessions < 5 ? 'Healthy' : ( $active_sessions < 10 ? 'Good' : 'Busy' );

		return array(
			'active_sessions'   => $active_sessions,
			'total_plans'       => $plan_count,
			'total_executions'  => $total_executions,
			'system_health'     => $system_health,
		);
	}

	/**
	 * Get capacity metrics
	 *
	 * @return array
	 */
	private function get_capacity_metrics() {
		$overview = $this->get_overview_metrics();
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
			'utilization'   => $utilization . '%',
			'queue_length'  => $queue_length,
			'load_status'   => $load_status,
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

		$sessions = array();
		$transients = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_mcp_ai_session_%'
			LIMIT 50"
		);

		foreach ( $transients as $transient ) {
			$session_id = str_replace( array( '_transient_mcp_ai_session_', '_transient_timeout_mcp_ai_session_' ), '', $transient->option_name );
			$session_data = maybe_unserialize( $transient->option_value );

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
				'session_id'   => substr( $session_id, 0, 8 ),
				'plan_title'   => $plan_title,
				'status'       => isset( $session_data['status'] ) ? $session_data['status'] : 'unknown',
				'health'       => isset( $session_data['health'] ) ? $session_data['health'] : 'unknown',
				'iterations'   => isset( $session_data['iterations'] ) ? $session_data['iterations'] : 0,
				'max_iterations' => isset( $session_data['max_iterations'] ) ? $session_data['max_iterations'] : 25,
				'tokens_used'  => isset( $session_data['tokens_used'] ) ? $session_data['tokens_used'] : 0,
				'token_budget' => isset( $session_data['token_budget'] ) ? $session_data['token_budget'] : 10000,
				'start_time'   => isset( $session_data['start_time'] ) ? $session_data['start_time'] : time(),
			);
		}

		return $sessions;
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
			$session_data['status'] = 'stopped';
			$session_data['stop_reason'] = 'Manual stop';
		}

		set_transient( 'mcp_ai_session_' . $session_id, $session_data, 86400 );

		wp_send_json_success( array(
			'message' => 'Session updated',
			'session' => $session_data,
		) );
	}
}

// Initialize.
new WP_MCP_AI_Orchestration_Dashboard();
