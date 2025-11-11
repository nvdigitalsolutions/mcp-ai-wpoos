<?php
/**
 * Performance Monitoring Admin Section for WP oOS.
 *
 * Provides admin interface for:
 * - Real-time performance metrics
 * - Test execution interface
 * - Historical performance charts
 * - Optimization toggle testing
 * - Export test results
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performance Admin Section class.
 */
class WP_MCP_AI_Section_Performance extends WP_MCP_AI_Settings_Section {

	/**
	 * Initialize the section.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_run_performance_test', array( $this, 'ajax_run_test' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_performance_metrics', array( $this, 'ajax_get_metrics' ) );
		add_action( 'wp_ajax_wp_mcp_ai_export_test_results', array( $this, 'ajax_export_results' ) );
	}

	/**
	 * Get the section ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'performance';
	}

	/**
	 * Get the section title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Performance Monitoring', 'wp-mcp-ai' );
	}

	/**
	 * Get the tab this section belongs to.
	 *
	 * @return string
	 */
	public function get_tab() {
		return 'advanced';
	}

	/**
	 * Get section description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Monitor plugin performance, run tests, and view diagnostics.', 'wp-mcp-ai' );
	}

	/**
	 * Get section priority.
	 *
	 * @return int
	 */
	public function get_priority() {
		return 60;
	}

	/**
	 * Get field definitions for this section.
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(); // No settings fields, just display content.
	}

	/**
	 * Render the section.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-mcp-ai' ) );
		}

		// Load performance reporter.
		if ( ! class_exists( 'WP_MCP_AI_Performance_Reporter' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-performance-service.php';
		}

		// Get current metrics.
		$report = WP_MCP_AI_Performance_Reporter::generate_report(
			array(
				'time_period' => '-7 days',
			)
		);

		// Get orchestration health status for System Health display.
		$orchestration_health = $this->get_orchestration_health_status();

		?>
		<div class="wrap wp-mcp-ai-performance-section">
			<h1><?php esc_html_e( 'Performance Monitoring', 'wp-mcp-ai' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Monitor plugin performance, run diagnostic tests, and view historical trends.', 'wp-mcp-ai' ); ?>
			</p>

			<!-- Overall Health Status -->
			<div class="wp-mcp-ai-performance-dashboard">
				<h2><?php esc_html_e( 'System Health', 'wp-mcp-ai' ); ?></h2>
				<?php $this->render_orchestration_health_status( $orchestration_health ); ?>

				<!-- Summary Stats -->
				<div class="performance-summary">
					<div class="stat-card">
						<h3><?php esc_html_e( 'Components', 'wp-mcp-ai' ); ?></h3>
						<div class="stat-value"><?php echo esc_html( $report['summary']['total_components'] ); ?></div>
					</div>
					<div class="stat-card">
						<h3><?php esc_html_e( 'Alerts', 'wp-mcp-ai' ); ?></h3>
						<div class="stat-value"><?php echo esc_html( $report['summary']['total_alerts'] ); ?></div>
					</div>
					<div class="stat-card">
						<h3><?php esc_html_e( 'Recommendations', 'wp-mcp-ai' ); ?></h3>
						<div class="stat-value"><?php echo esc_html( $report['summary']['total_recommendations'] ); ?></div>
					</div>
				</div>
			</div>

			<!-- Test Execution Interface -->
			<div class="wp-mcp-ai-test-execution">
				<h2><?php esc_html_e( 'Run Performance Tests', 'wp-mcp-ai' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Execute comprehensive performance tests to identify issues and measure improvements.', 'wp-mcp-ai' ); ?>
				</p>

				<div class="test-controls">
					<button type="button" class="button button-primary" data-test-type="stress">
						<?php esc_html_e( 'Run Stress Test', 'wp-mcp-ai' ); ?>
					</button>
					<button type="button" class="button button-primary" data-test-type="security">
						<?php esc_html_e( 'Run Security Test', 'wp-mcp-ai' ); ?>
					</button>
					<button type="button" class="button button-primary" data-test-type="speed">
						<?php esc_html_e( 'Run Speed Benchmark', 'wp-mcp-ai' ); ?>
					</button>
					<button type="button" class="button button-primary" data-test-type="optimization">
						<?php esc_html_e( 'Run Optimization Test', 'wp-mcp-ai' ); ?>
					</button>
				</div>

				<div class="test-results-container" style="display:none;">
					<h3><?php esc_html_e( 'Test Results', 'wp-mcp-ai' ); ?></h3>
					<div class="test-results"></div>
				</div>
			</div>

			<!-- Component Performance -->
			<div class="wp-mcp-ai-component-performance">
				<h2><?php esc_html_e( 'Component Performance', 'wp-mcp-ai' ); ?></h2>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Component', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Health', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Avg Response Time', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Trend', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wp-mcp-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $report['components'] as $component_id => $component_data ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $this->format_component_name( $component_id ) ); ?></strong></td>
								<td>
									<span class="health-badge health-badge-<?php echo esc_attr( $component_data['health_status'] ); ?>">
										<?php echo esc_html( ucfirst( $component_data['health_status'] ) ); ?>
									</span>
								</td>
								<td>
									<?php
									$avg_time = 0;
									if ( isset( $component_data['metrics']['avg_response_time'] ) ) {
										$times    = $component_data['metrics']['avg_response_time'];
										$avg_time = ! empty( $times ) ? array_sum( $times ) / count( $times ) : 0;
									}
									echo esc_html( number_format( $avg_time, 2 ) );
									?>
									ms
								</td>
								<td>
									<?php
									$trend = 'stable';
									if ( ! empty( $component_data['trends'] ) ) {
										$first_trend = reset( $component_data['trends'] );
										$trend       = isset( $first_trend['trend'] ) ? $first_trend['trend'] : 'stable';
									}
									echo esc_html( $this->format_trend( $trend ) );
									?>
								</td>
								<td>
									<button type="button" class="button button-small view-details" data-component="<?php echo esc_attr( $component_id ); ?>">
										<?php esc_html_e( 'View Details', 'wp-mcp-ai' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Performance Alerts -->
			<?php if ( ! empty( $report['alerts'] ) ) : ?>
				<div class="wp-mcp-ai-performance-alerts">
					<h2><?php esc_html_e( 'Performance Alerts', 'wp-mcp-ai' ); ?></h2>

					<div class="alerts-list">
						<?php foreach ( $report['alerts'] as $alert ) : ?>
							<div class="alert alert-<?php echo esc_attr( $alert['severity'] ); ?>">
								<span class="alert-icon dashicons dashicons-warning"></span>
								<div class="alert-content">
									<strong><?php echo esc_html( ucfirst( $alert['severity'] ) ); ?>:</strong>
									<?php echo esc_html( $alert['message'] ); ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<!-- Recommendations -->
			<?php if ( ! empty( $report['recommendations'] ) ) : ?>
				<div class="wp-mcp-ai-performance-recommendations">
					<h2><?php esc_html_e( 'Optimization Recommendations', 'wp-mcp-ai' ); ?></h2>

					<div class="recommendations-list">
						<?php foreach ( $report['recommendations'] as $recommendation ) : ?>
							<div class="recommendation recommendation-<?php echo esc_attr( $recommendation['severity'] ); ?>">
								<span class="recommendation-icon dashicons dashicons-lightbulb"></span>
								<div class="recommendation-content">
									<strong><?php echo esc_html( $recommendation['action'] ); ?></strong>
									<p><?php echo esc_html( $recommendation['reason'] ); ?></p>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<!-- Export Options -->
			<div class="wp-mcp-ai-export-options">
				<h2><?php esc_html_e( 'Export Test Results', 'wp-mcp-ai' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Download performance test results for external analysis.', 'wp-mcp-ai' ); ?>
				</p>

				<button type="button" class="button" id="export-results-json">
					<?php esc_html_e( 'Export as JSON', 'wp-mcp-ai' ); ?>
				</button>
				<button type="button" class="button" id="export-results-csv">
					<?php esc_html_e( 'Export as CSV', 'wp-mcp-ai' ); ?>
				</button>
			</div>
		</div>

		<style>
			.wp-mcp-ai-performance-section {
				max-width: 1200px;
			}
			.wp-mcp-ai-performance-dashboard {
				background: #fff;
				padding: 20px;
				margin: 20px 0;
				border: 1px solid #ccd0d4;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
			}
			.wp-mcp-ai-orchestration-health-banner {
				background: #f8f9fa;
				padding: 15px;
				margin: 15px 0;
				border-radius: 4px;
				border-left: 4px solid #ccd0d4;
			}
			.wp-mcp-ai-orchestration-health-banner.status-healthy {
				border-left-color: #46b450;
			}
			.wp-mcp-ai-orchestration-health-banner.status-warning {
				border-left-color: #f0b849;
			}
			.wp-mcp-ai-orchestration-health-banner.status-critical {
				border-left-color: #dc3232;
			}
			.wp-mcp-ai-orchestration-health-banner.status-unknown {
				border-left-color: #72aee6;
			}
			.health-status {
				display: flex;
				align-items: center;
				font-size: 18px;
				margin: 0 0 15px 0;
			}
			.health-status-healthy { color: #46b450; }
			.health-status-good { color: #46b450; }
			.health-status-fair { color: #ffb900; }
			.health-status-warning { color: #f0b849; }
			.health-status-critical { color: #dc3232; }
			.health-status-unknown { color: #72aee6; }
			.health-icon {
				font-size: 24px;
				margin-right: 10px;
			}
			.health-label {
				font-weight: 600;
			}
			.health-metrics {
				display: flex;
				gap: 20px;
				flex-wrap: wrap;
				font-size: 14px;
			}
			.health-metrics .metric {
				display: flex;
				align-items: center;
				gap: 5px;
				color: #666;
			}
			.health-metrics .metric .dashicons {
				font-size: 16px;
			}
			.performance-summary {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
				gap: 15px;
				margin-top: 20px;
			}
			.stat-card {
				background: #f8f9fa;
				padding: 15px;
				border-radius: 4px;
				text-align: center;
			}
			.stat-card h3 {
				margin: 0 0 10px;
				font-size: 14px;
				color: #666;
			}
			.stat-value {
				font-size: 32px;
				font-weight: bold;
				color: #2271b1;
			}
			.test-controls {
				margin: 15px 0;
			}
			.test-controls button {
				margin-right: 10px;
				margin-bottom: 10px;
			}
			.test-results-container {
				background: #f8f9fa;
				padding: 15px;
				margin-top: 15px;
				border-radius: 4px;
			}
			.health-badge {
				display: inline-block;
				padding: 3px 8px;
				border-radius: 3px;
				font-size: 12px;
				font-weight: 600;
			}
			.health-badge-good {
				background: #d4edda;
				color: #155724;
			}
			.health-badge-warning {
				background: #fff3cd;
				color: #856404;
			}
			.health-badge-critical {
				background: #f8d7da;
				color: #721c24;
			}
			.alerts-list, .recommendations-list {
				margin-top: 15px;
			}
			.alert, .recommendation {
				display: flex;
				align-items: flex-start;
				padding: 12px;
				margin-bottom: 10px;
				border-radius: 4px;
				border-left: 4px solid;
			}
			.alert-critical, .recommendation-critical {
				background: #f8d7da;
				border-color: #dc3232;
			}
			.alert-high, .recommendation-high {
				background: #fff3cd;
				border-color: #f0b849;
			}
			.alert-medium, .recommendation-medium {
				background: #d1ecf1;
				border-color: #2271b1;
			}
			.alert-icon, .recommendation-icon {
				margin-right: 10px;
				color: inherit;
			}
		</style>
		<?php
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Only load on settings page.
		if ( strpos( $hook, 'wp-mcp-ai' ) === false ) {
			return;
		}

		wp_enqueue_script(
			'wp-mcp-ai-performance-admin',
			WP_MCP_AI_URL . 'assets/js/performance-admin.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-performance-admin',
			'wpMcpAiPerformance',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_performance' ),
			)
		);
	}

	/**
	 * AJAX handler for running performance tests.
	 */
	public function ajax_run_test() {
		check_ajax_referer( 'wp_mcp_ai_performance', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-mcp-ai' ) ) );
		}

		$test_type = isset( $_POST['test_type'] ) ? sanitize_key( $_POST['test_type'] ) : '';

		if ( empty( $test_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid test type.', 'wp-mcp-ai' ) ) );
		}

		// Return information about running tests via CLI.
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: test type */
					__( 'To run %s tests, use: ./bin/run-performance-tests.sh --suite=%s', 'wp-mcp-ai' ),
					$test_type,
					$test_type
				),
			)
		);
	}

	/**
	 * AJAX handler for getting performance metrics.
	 */
	public function ajax_get_metrics() {
		check_ajax_referer( 'wp_mcp_ai_performance', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-mcp-ai' ) ) );
		}

		$component = isset( $_POST['component'] ) ? sanitize_key( $_POST['component'] ) : 'rest_api';

		$trends = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends( $component, '-7 days' );

		wp_send_json_success( $trends );
	}

	/**
	 * AJAX handler for exporting test results.
	 */
	public function ajax_export_results() {
		check_ajax_referer( 'wp_mcp_ai_performance', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-mcp-ai' ) ) );
		}

		$format = isset( $_POST['format'] ) ? sanitize_key( $_POST['format'] ) : 'json';

		$report = WP_MCP_AI_Performance_Reporter::generate_report();

		if ( 'json' === $format ) {
			wp_send_json_success( $report );
		} else {
			// CSV export would be implemented here.
			wp_send_json_success( array( 'message' => __( 'CSV export coming soon.', 'wp-mcp-ai' ) ) );
		}
	}

	/**
	 * Get health icon for status.
	 *
	 * @param string $status Health status.
	 * @return string Icon name.
	 */
	protected function get_health_icon( $status ) {
		$icons = array(
			'good'     => 'yes-alt',
			'fair'     => 'info',
			'warning'  => 'warning',
			'critical' => 'dismiss',
		);

		return isset( $icons[ $status ] ) ? $icons[ $status ] : 'info';
	}

	/**
	 * Format component name for display.
	 *
	 * @param string $component_id Component ID.
	 * @return string Formatted name.
	 */
	protected function format_component_name( $component_id ) {
		$names = array(
			'rest_api'      => 'REST API',
			'chat_ui'       => 'Chat UI',
			'mcp_core'      => 'MCP Core',
			'elementor'     => 'Elementor',
			'cpt_assistant' => 'CPT: Assistant',
			'cpt_ai_peer'   => 'CPT: AI Peer',
		);

		return isset( $names[ $component_id ] ) ? $names[ $component_id ] : ucwords( str_replace( '_', ' ', $component_id ) );
	}

	/**
	 * Format trend for display.
	 *
	 * @param string $trend Trend value.
	 * @return string Formatted trend with icon.
	 */
	protected function format_trend( $trend ) {
		$icons = array(
			'improving' => '↗️ ' . __( 'Improving', 'wp-mcp-ai' ),
			'stable'    => '→ ' . __( 'Stable', 'wp-mcp-ai' ),
			'degrading' => '↘️ ' . __( 'Degrading', 'wp-mcp-ai' ),
			'no_data'   => '— ' . __( 'No Data', 'wp-mcp-ai' ),
		);

		return isset( $icons[ $trend ] ) ? $icons[ $trend ] : $trend;
	}

	/**
	 * Get orchestration health status with error handling.
	 *
	 * @return array Health status array with status, label, icon, and metrics.
	 */
	protected function get_orchestration_health_status() {
		// Default fallback health status.
		$default_health = array(
			'status'  => 'unknown',
			'label'   => __( 'Unknown', 'wp-mcp-ai' ),
			'icon'    => '○',
			'metrics' => array(
				'memory'       => array(
					'percent' => 0,
					'usage'   => 0,
					'limit'   => 0,
				),
				'error_rate'   => 0,
				'avg_response' => 0,
			),
		);

		try {
			// Check if the Orchestration Health Service exists.
			if ( ! class_exists( 'WP_MCP_AI_Orchestration_Health_Service' ) ) {
				return $default_health;
			}

			// Get health status from the orchestration service.
			$health = WP_MCP_AI_Orchestration_Health_Service::get_health_status();

			// Validate the response.
			if ( ! is_array( $health ) || ! isset( $health['status'] ) ) {
				return $default_health;
			}

			return $health;

		} catch ( Exception $e ) {
			// Log error if logging is available.
			if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_warning' ) ) {
				try {
					WP_MCP_AI_Logger::log_warning(
						'Failed to get orchestration health status: ' . $e->getMessage(),
						array(
							'component' => 'performance_section',
							'method'    => 'get_orchestration_health_status',
							'exception' => $e->getMessage(),
						)
					);
				} catch ( Exception $log_error ) {
					// Silently fail on logging errors.
				}
			}

			return $default_health;
		}
	}

	/**
	 * Render orchestration health status display.
	 *
	 * @param array $health Health status array.
	 */
	protected function render_orchestration_health_status( $health ) {
		$status  = isset( $health['status'] ) ? sanitize_key( $health['status'] ) : 'unknown';
		$label   = isset( $health['label'] ) ? sanitize_text_field( $health['label'] ) : __( 'Unknown', 'wp-mcp-ai' );
		$icon    = isset( $health['icon'] ) ? sanitize_text_field( $health['icon'] ) : '○';
		$metrics = isset( $health['metrics'] ) && is_array( $health['metrics'] ) ? $health['metrics'] : array();

		$memory_percent = isset( $metrics['memory']['percent'] ) ? floatval( $metrics['memory']['percent'] ) : 0;
		$error_rate     = isset( $metrics['error_rate'] ) ? floatval( $metrics['error_rate'] ) : 0;
		$avg_response   = isset( $metrics['avg_response'] ) ? floatval( $metrics['avg_response'] ) : 0;

		// Map orchestration status to health icon.
		$health_icon_map = array(
			'healthy'  => 'yes-alt',
			'warning'  => 'warning',
			'critical' => 'dismiss',
			'unknown'  => 'info',
		);

		$dashicon = isset( $health_icon_map[ $status ] ) ? $health_icon_map[ $status ] : 'info';
		?>
		<div class="wp-mcp-ai-orchestration-health-banner status-<?php echo esc_attr( $status ); ?>">
			<div class="health-status health-status-<?php echo esc_attr( $status ); ?>">
				<span class="health-icon dashicons dashicons-<?php echo esc_attr( $dashicon ); ?>"></span>
				<span class="health-label"><?php echo esc_html( $label ); ?></span>
			</div>
			<div class="health-metrics">
				<span class="metric">
					<span class="dashicons dashicons-performance"></span>
					<?php
					/* translators: %s: memory usage percentage */
					printf( esc_html__( 'Memory: %s%%', 'wp-mcp-ai' ), esc_html( number_format( $memory_percent, 1 ) ) );
					?>
				</span>
				<span class="metric">
					<span class="dashicons dashicons-warning"></span>
					<?php
					/* translators: %s: error rate percentage */
					printf( esc_html__( 'Errors: %s%%', 'wp-mcp-ai' ), esc_html( number_format( $error_rate, 1 ) ) );
					?>
				</span>
				<span class="metric">
					<span class="dashicons dashicons-clock"></span>
					<?php
					/* translators: %s: average response time in seconds */
					printf( esc_html__( 'Avg Response: %ss', 'wp-mcp-ai' ), esc_html( number_format( $avg_response, 1 ) ) );
					?>
				</span>
			</div>
		</div>
		<?php
	}
}
