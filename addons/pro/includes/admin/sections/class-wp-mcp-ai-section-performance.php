<?php
/**
 * Performance Monitoring Admin Section for NV oOS.
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
		// Register AJAX handlers for both admin and frontend (needed for Elementor widgets).
		add_action( 'wp_ajax_wp_mcp_ai_run_performance_test', array( $this, 'ajax_run_test' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_performance_metrics', array( $this, 'ajax_get_metrics' ) );
		add_action( 'wp_ajax_wp_mcp_ai_export_test_results', array( $this, 'ajax_export_results' ) );

		// Only register admin-specific hooks when in admin context.
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}
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
		return __( 'Performance Monitoring', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tab this section belongs to.
	 *
	 * This section is embedded as a sub-tab in Advanced Settings.
	 * Return a non-existent tab to hide it from main navigation.
	 *
	 * @return string
	 */
	public function get_tab() {
		return '__hidden__';
	}

	/**
	 * Get section description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Monitor plugin performance, run tests, and view diagnostics.', 'mcp-ai-wpoos-pro' );
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		// Load performance reporter.
		if ( ! class_exists( 'WP_MCP_AI_Performance_Reporter' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-performance-reporter.php';
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
			<h1><?php esc_html_e( 'Performance Monitoring', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Monitor plugin performance, run diagnostic tests, and view historical trends.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<!-- Overall Health Status -->
			<div class="wp-mcp-ai-performance-dashboard">
				<h2><?php esc_html_e( 'System Health', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php $this->render_orchestration_health_status( $orchestration_health ); ?>

				<!-- Summary Stats -->
				<div class="performance-summary">
					<div class="stat-card">
						<h3><?php esc_html_e( 'Components', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="stat-value"><?php echo esc_html( $report['summary']['total_components'] ); ?></div>
					</div>
					<div class="stat-card">
						<h3><?php esc_html_e( 'Alerts', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="stat-value"><?php echo esc_html( $report['summary']['total_alerts'] ); ?></div>
					</div>
					<div class="stat-card">
						<h3><?php esc_html_e( 'Recommendations', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="stat-value"><?php echo esc_html( $report['summary']['total_recommendations'] ); ?></div>
					</div>
				</div>
			</div>

			<!-- Test Execution Interface -->
			<div class="wp-mcp-ai-test-execution">
				<h2><?php esc_html_e( 'Run Performance Tests', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Execute comprehensive performance tests to identify issues and measure improvements.', 'mcp-ai-wpoos-pro' ); ?>
				</p>

				<?php
				// Check if this is a production environment.
				$phpunit_bin = WP_MCP_AI_PATH . 'vendor/bin/phpunit';
				if ( ! file_exists( $phpunit_bin ) ) :
					?>
					<div class="notice notice-info inline">
						<p>
							<strong><?php esc_html_e( 'Test Mode: Lightweight Checks', 'mcp-ai-wpoos-pro' ); ?></strong><br>
							<?php esc_html_e( 'Running production-safe performance checks. These work on all hosting platforms including Cloudways.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
						<p>
							<strong><?php esc_html_e( 'Want Full PHPUnit Test Suites?', 'mcp-ai-wpoos-pro' ); ?></strong><br>
							<?php esc_html_e( 'For comprehensive testing with PHPUnit, you need to install development dependencies. This is recommended for local development environments only.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
						<p>
							<button type="button" class="button button-secondary" id="show-phpunit-instructions">
								<span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'How to Enable Full Tests', 'mcp-ai-wpoos-pro' ); ?>
							</button>
						</p>
						<div id="phpunit-instructions" style="display:none; margin-top: 15px; padding: 15px; background: #f8f9fa; border-left: 4px solid #2271b1;">
							<h4><?php esc_html_e( 'Install PHPUnit Test Suite', 'mcp-ai-wpoos-pro' ); ?></h4>
							<p><?php esc_html_e( 'Option 1: Via Composer (Local Development)', 'mcp-ai-wpoos-pro' ); ?></p>
							<pre style="background: #23282d; color: #f0f0f1; padding: 10px; border-radius: 3px; overflow-x: auto;">cd <?php echo esc_html( WP_MCP_AI_PATH ); ?>

composer install</pre>
							<p><?php esc_html_e( 'Option 2: Download Pre-packaged Tests', 'mcp-ai-wpoos-pro' ); ?></p>
							<p>
								<a href="https://github.com/nvdigitalsolutions/wp-mcp-ai/releases" class="button" target="_blank">
									<span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
									<?php esc_html_e( 'Download from GitHub Releases', 'mcp-ai-wpoos-pro' ); ?>
								</a>
								<span class="description" style="margin-left: 10px;">
									<?php esc_html_e( '(~140 MB - includes PHPUnit test framework)', 'mcp-ai-wpoos-pro' ); ?>
								</span>
							</p>
							<p class="description">
								<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php esc_html_e( 'PHPUnit is not recommended for production servers. The lightweight checks above are optimized for production use.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</div>
					</div>
				<?php else : ?>
					<div class="notice notice-success inline">
						<p>
							<strong><?php esc_html_e( 'Test Mode: Full PHPUnit Suite', 'mcp-ai-wpoos-pro' ); ?></strong><br>
							<?php esc_html_e( 'PHPUnit is installed. Running comprehensive test suites with detailed assertions.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<div class="test-controls">
					<button type="button" class="button button-primary" data-test-type="stress">
						<?php esc_html_e( 'Run Stress Test', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<button type="button" class="button button-primary" data-test-type="security">
						<?php esc_html_e( 'Run Security Test', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<button type="button" class="button button-primary" data-test-type="speed">
						<?php esc_html_e( 'Run Speed Benchmark', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<button type="button" class="button button-primary" data-test-type="optimization">
						<?php esc_html_e( 'Run Optimization Test', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				</div>

				<div class="test-results-container" style="display:none;">
					<h3><?php esc_html_e( 'Test Results', 'mcp-ai-wpoos-pro' ); ?></h3>
					<div class="test-results"></div>
				</div>
			</div>

			<!-- Component Performance -->
			<div class="wp-mcp-ai-component-performance">
				<h2><?php esc_html_e( 'Component Performance', 'mcp-ai-wpoos-pro' ); ?></h2>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Component', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Health', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Avg Response Time', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Trend', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
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
										<?php esc_html_e( 'View Details', 'mcp-ai-wpoos-pro' ); ?>
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
					<h2><?php esc_html_e( 'Performance Alerts', 'mcp-ai-wpoos-pro' ); ?></h2>

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
					<h2><?php esc_html_e( 'Optimization Recommendations', 'mcp-ai-wpoos-pro' ); ?></h2>

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
				<h2><?php esc_html_e( 'Export Test Results', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Download performance test results for external analysis.', 'mcp-ai-wpoos-pro' ); ?>
				</p>

				<button type="button" class="button" id="export-results-json">
					<?php esc_html_e( 'Export as JSON', 'mcp-ai-wpoos-pro' ); ?>
				</button>
				<button type="button" class="button" id="export-results-csv">
					<?php esc_html_e( 'Export as CSV', 'mcp-ai-wpoos-pro' ); ?>
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
			.test-summary {
				margin: 10px 0;
				padding: 10px;
				background: #fff;
				border-left: 3px solid #2271b1;
			}
			.cli-command, .setup-command {
				margin: 10px 0;
				padding: 10px;
				background: #fff;
				border-radius: 4px;
			}
			.cli-command code, .setup-command code {
				display: block;
				padding: 8px;
				background: #23282d;
				color: #f0f0f1;
				border-radius: 3px;
				font-family: Consolas, Monaco, monospace;
				font-size: 13px;
			}
			.test-details {
				margin: 10px 0;
				padding: 10px;
				background: #fff;
			}
			.test-output {
				margin: 10px 0;
			}
			.test-output summary {
				cursor: pointer;
				padding: 8px;
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 3px;
				font-weight: 600;
			}
			.test-output summary:hover {
				background: #f0f0f1;
			}
			.test-output pre {
				margin: 10px 0 0;
				padding: 10px;
				background: #23282d;
				color: #f0f0f1;
				border-radius: 3px;
				overflow-x: auto;
				font-family: Consolas, Monaco, monospace;
				font-size: 12px;
				line-height: 1.5;
				max-height: 400px;
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
		if ( strpos( $hook, 'mcp-ai-wpoos-pro' ) === false ) {
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
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_performance' ),
				'runningText' => __( 'Running...', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * AJAX handler for running performance tests.
	 */
	public function ajax_run_test() {
		try {
			check_ajax_referer( 'wp_mcp_ai_performance', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
			}

			$test_type = isset( $_POST['test_type'] ) ? sanitize_key( $_POST['test_type'] ) : '';

			if ( empty( $test_type ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid test type.', 'mcp-ai-wpoos-pro' ) ) );
			}

			// Try to run the test programmatically.
			$result = $this->run_performance_test_programmatically( $test_type );

			if ( $result['success'] ) {
				wp_send_json_success( $result );
			} else {
				wp_send_json_error( $result );
			}
		} catch ( Throwable $e ) {
			// Intentionally empty - error handled elsewhere.
			// Log the error for debugging.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WP_MCP_AI Performance Test Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Test execution failed: %s', 'mcp-ai-wpoos-pro' ),
						$e->getMessage()
					),
				)
			);
		}
	}

	/**
	 * Run performance test programmatically.
	 *
	 * @param string $test_type Type of test to run.
	 * @return array Test results with success status and message.
	 */
	protected function run_performance_test_programmatically( $test_type ) {
		// Check if exec() function is available.
		if ( ! function_exists( 'exec' ) ) {
			return array(
				'success'     => false,
				'message'     => __( 'Shell execution is disabled on this server.', 'mcp-ai-wpoos-pro' ),
				'details'     => __( 'The exec() function is disabled in your PHP configuration. Performance tests requiring PHPUnit cannot run from the admin interface. You can still use the lightweight checks below, or run tests via CLI if you have command-line access.', 'mcp-ai-wpoos-pro' ),
				'cli_command' => './bin/run-performance-tests.sh --suite=' . $test_type,
			);
		}

		// Check if PHPUnit is available.
		$phpunit_bin = WP_MCP_AI_PATH . 'vendor/bin/phpunit';
		$has_phpunit = file_exists( $phpunit_bin );

		// If PHPUnit is not available, run lightweight production checks instead.
		if ( ! $has_phpunit ) {
			return $this->run_lightweight_check( $test_type );
		}

		// Map test types to file paths.
		$test_files = array(
			'stress'       => WP_MCP_AI_PATH . 'tests/performance/test-stress-suite.php',
			'security'     => WP_MCP_AI_PATH . 'tests/security/test-security-suite.php',
			'speed'        => WP_MCP_AI_PATH . 'tests/performance/test-speed-benchmarks.php',
			'optimization' => WP_MCP_AI_PATH . 'tests/performance/test-optimization-comparison.php',
		);

		if ( ! isset( $test_files[ $test_type ] ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: test type */
					__( 'Unknown test type: %s', 'mcp-ai-wpoos-pro' ),
					$test_type
				),
			);
		}

		$test_file = $test_files[ $test_type ];

		if ( ! file_exists( $test_file ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: file path */
					__( 'Test file not found: %s', 'mcp-ai-wpoos-pro' ),
					basename( $test_file )
				),
			);
		}

		// Check if PHPUnit is available.
		$phpunit_bin = WP_MCP_AI_PATH . 'vendor/bin/phpunit';
		if ( ! file_exists( $phpunit_bin ) ) {
			return array(
				'success'       => false,
				'message'       => __( 'Performance tests require development dependencies.', 'mcp-ai-wpoos-pro' ),
				'details'       => __( 'These tests are designed for local development environments. On production or managed hosting (like Cloudways), performance monitoring is available through the dashboard metrics above.', 'mcp-ai-wpoos-pro' ),
				'setup_command' => 'composer install',
				'cli_command'   => './bin/run-performance-tests.sh --suite=' . $test_type,
			);
		}

		// Execute the test using shell_exec with timeout.
		$command = sprintf(
			'cd %s && %s %s --no-configuration 2>&1',
			escapeshellarg( WP_MCP_AI_PATH ),
			escapeshellarg( $phpunit_bin ),
			escapeshellarg( $test_file )
		);

		// Set timeout using timeout command if available.
		if ( $this->command_exists( 'timeout' ) ) {
			$command = 'timeout 60 ' . $command;
		}

		$output     = array();
		$return_var = 0;

		// Execute the test.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $return_var );

		$output_text = implode( "\n", $output );

		// Check if WordPress test environment is required.
		if ( strpos( $output_text, 'WP_UnitTestCase' ) !== false || strpos( $output_text, 'WP_TESTS_DIR' ) !== false ) {
			return array(
				'success'       => false,
				'message'       => __( 'WordPress test environment not configured. Use CLI command below or run setup first.', 'mcp-ai-wpoos-pro' ),
				'details'       => __( 'The performance tests require the WordPress test framework. Please run the setup script or use the CLI command.', 'mcp-ai-wpoos-pro' ),
				'cli_command'   => './bin/run-performance-tests.sh --suite=' . $test_type,
				'setup_command' => 'composer run test:install',
			);
		}

		if ( 0 === $return_var ) {
			// Parse output for test results.
			$summary = $this->parse_test_output( $output_text );

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %1$s: test type, %2$s: test summary */
					__( '%1$s tests completed successfully. %2$s', 'mcp-ai-wpoos-pro' ),
					ucfirst( $test_type ),
					$summary
				),
				'output'  => $output_text,
				'summary' => $summary,
			);
		} else {
			return array(
				'success'     => false,
				'message'     => sprintf(
					/* translators: %s: test type */
					__( '%s tests failed. See details below.', 'mcp-ai-wpoos-pro' ),
					ucfirst( $test_type )
				),
				'output'      => $output_text,
				'return_code' => $return_var,
			);
		}
	}

	/**
	 * Check if a command exists.
	 *
	 * @param string $command Command name.
	 * @return bool True if command exists.
	 */
	protected function command_exists( $command ) {
		// Check if exec() function is available.
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		$return_var = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( 'command -v ' . escapeshellarg( $command ) . ' 2>/dev/null', $output, $return_var );
		return 0 === $return_var;
	}

	/**
	 * Parse PHPUnit test output to extract summary.
	 *
	 * @param string $output Test output.
	 * @return string Test summary.
	 */
	protected function parse_test_output( $output ) {
		// Look for PHPUnit summary line.
		if ( preg_match( '/OK \((\d+) tests?, (\d+) assertions?\)/', $output, $matches ) ) {
			return sprintf(
				/* translators: %1$d: number of tests, %2$d: number of assertions */
				__( 'Passed: %1$d tests, %2$d assertions', 'mcp-ai-wpoos-pro' ),
				absint( $matches[1] ),
				absint( $matches[2] )
			);
		}

		if ( preg_match( '/Tests: (\d+), Assertions: (\d+)/', $output, $matches ) ) {
			return sprintf(
				/* translators: %1$d: number of tests, %2$d: number of assertions */
				__( 'Tests: %1$d, Assertions: %2$d', 'mcp-ai-wpoos-pro' ),
				absint( $matches[1] ),
				absint( $matches[2] )
			);
		}

		if ( preg_match( '/FAILURES!/', $output ) ) {
			if ( preg_match( '/Tests: (\d+), Assertions: (\d+), Failures: (\d+)/', $output, $matches ) ) {
				return sprintf(
					/* translators: %1$d: total tests, %2$d: failed tests */
					__( 'Tests: %1$d, Failures: %2$d', 'mcp-ai-wpoos-pro' ),
					absint( $matches[1] ),
					absint( $matches[3] )
				);
			}
		}

		return __( 'Test execution completed. Check details for results.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * AJAX handler for getting performance metrics.
	 */
	public function ajax_get_metrics() {
		check_ajax_referer( 'wp_mcp_ai_performance', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			wp_send_json_error( array( 'message' => __( 'Performance monitoring not available in base version mode.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$component = isset( $_POST['component'] ) ? sanitize_key( $_POST['component'] ) : 'rest_api';

		try {
			$trends = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends( $component, '-7 days' );
			wp_send_json_success( $trends );
		} catch ( Exception $e ) {
			// Intentionally empty - error handled elsewhere.
			// Log the error for debugging.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WP_MCP_AI Performance Metrics Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Failed to get performance metrics: %s', 'mcp-ai-wpoos-pro' ),
						$e->getMessage()
					),
				)
			);
		}
	}

	/**
	 * AJAX handler for exporting test results.
	 */
	public function ajax_export_results() {
		check_ajax_referer( 'wp_mcp_ai_performance', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Load Performance Reporter if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Performance_Reporter' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-performance-reporter.php';
		}

		$format = isset( $_POST['format'] ) ? sanitize_key( $_POST['format'] ) : 'json';

		$report = WP_MCP_AI_Performance_Reporter::generate_report();

		if ( 'json' === $format ) {
			wp_send_json_success( $report );
		} else {
			// CSV export would be implemented here.
			wp_send_json_success( array( 'message' => __( 'CSV export coming soon.', 'mcp-ai-wpoos-pro' ) ) );
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
			'improving' => '↗️ ' . __( 'Improving', 'mcp-ai-wpoos-pro' ),
			'stable'    => '→ ' . __( 'Stable', 'mcp-ai-wpoos-pro' ),
			'degrading' => '↘️ ' . __( 'Degrading', 'mcp-ai-wpoos-pro' ),
			'no_data'   => '— ' . __( 'No Data', 'mcp-ai-wpoos-pro' ),
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
			'label'   => __( 'Unknown', 'mcp-ai-wpoos-pro' ),
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
			// Intentionally empty - error handled elsewhere.
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
				} catch ( Exception $log_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
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
		$label   = isset( $health['label'] ) ? sanitize_text_field( $health['label'] ) : __( 'Unknown', 'mcp-ai-wpoos-pro' );
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
					printf( esc_html__( 'Memory: %s%%', 'mcp-ai-wpoos-pro' ), esc_html( number_format( $memory_percent, 1 ) ) );
					?>
				</span>
				<span class="metric">
					<span class="dashicons dashicons-warning"></span>
					<?php
					/* translators: %s: error rate percentage */
					printf( esc_html__( 'Errors: %s%%', 'mcp-ai-wpoos-pro' ), esc_html( number_format( $error_rate, 1 ) ) );
					?>
				</span>
				<span class="metric">
					<span class="dashicons dashicons-clock"></span>
					<?php
					/* translators: %s: average response time in seconds */
					printf( esc_html__( 'Avg Response: %ss', 'mcp-ai-wpoos-pro' ), esc_html( number_format( $avg_response, 1 ) ) );
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Run lightweight performance check (no PHPUnit required).
	 *
	 * @param string $test_type Type of test to run.
	 * @return array Test results.
	 */
	protected function run_lightweight_check( $test_type ) {
		$checks       = array();
		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		// Following SoC: Wrap each check in error handling to prevent one check from breaking others.
		// Each check method is responsible for its own logic, this method orchestrates and handles errors.
		$check_methods = array();

		switch ( $test_type ) {
			case 'security':
				$check_methods = array( 'check_file_permissions', 'check_https', 'check_api_keys_configured' );
				break;

			case 'speed':
				$check_methods = array( 'check_database_queries', 'check_cache_status', 'check_rest_api_response' );
				break;

			case 'stress':
				$check_methods = array( 'check_memory_limit', 'check_max_execution_time', 'check_concurrent_requests' );
				break;

			case 'optimization':
				$check_methods = array( 'check_object_cache', 'check_autoload_size', 'check_transients' );
				break;

			default:
				return array(
					'success' => false,
					'message' => __( 'Unknown test type', 'mcp-ai-wpoos-pro' ),
				);
		}

		// Execute each check with individual error handling.
		foreach ( $check_methods as $method ) {
			try {
				if ( method_exists( $this, $method ) ) {
					$checks[] = $this->$method();
				} else {
					// Method doesn't exist - add a fail result.
					$checks[] = array(
						'name'    => ucwords( str_replace( '_', ' ', str_replace( 'check_', '', $method ) ) ),
						'status'  => 'fail',
						'message' => __( 'Check method not available', 'mcp-ai-wpoos-pro' ),
					);
				}
			} catch ( Throwable $e ) {
				// Intentionally empty - error handled elsewhere.
				// If a check throws an error, log it and add a fail result.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'WP_MCP_AI Performance Check Error in %s: %s', $method, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}

				$checks[] = array(
					'name'    => ucwords( str_replace( '_', ' ', str_replace( 'check_', '', $method ) ) ),
					'status'  => 'fail',
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Check failed: %s', 'mcp-ai-wpoos-pro' ),
						$e->getMessage()
					),
				);
			}
		}

		$end_time    = microtime( true );
		$end_memory  = memory_get_usage();
		$duration    = round( ( $end_time - $start_time ) * 1000, 2 );
		$memory_used = round( ( $end_memory - $start_memory ) / 1024 / 1024, 2 );

		$passed   = 0;
		$failed   = 0;
		$warnings = 0;

		foreach ( $checks as $check ) {
			if ( 'pass' === $check['status'] ) {
				++$passed;
			} elseif ( 'fail' === $check['status'] ) {
				++$failed;
			} else {
				++$warnings;
			}
		}

		$output = $this->format_check_results( $checks );

		return array(
			'success' => 0 === $failed,
			'message' => sprintf(
				/* translators: %1$s: test type, %2$d: passed checks, %3$d: failed checks */
				__( '%1$s check completed: %2$d passed, %3$d failed, %4$d warnings', 'mcp-ai-wpoos-pro' ),
				ucfirst( $test_type ),
				$passed,
				$failed,
				$warnings
			),
			'summary' => sprintf(
				/* translators: %1$s: duration, %2$s: memory */
				__( 'Duration: %1$sms | Memory: %2$sMB', 'mcp-ai-wpoos-pro' ),
				$duration,
				$memory_used
			),
			'output'  => $output,
		);
	}

	/**
	 * Format check results for display.
	 *
	 * @param array $checks Array of check results.
	 * @return string Formatted output.
	 */
	protected function format_check_results( $checks ) {
		$output = '';
		foreach ( $checks as $check ) {
			$icon = '✓';
			if ( 'fail' === $check['status'] ) {
				$icon = '✗';
			} elseif ( 'warning' === $check['status'] ) {
				$icon = '⚠';
			}

			$output .= sprintf(
				"%s %s: %s\n",
				$icon,
				$check['name'],
				$check['message']
			);
		}
		return $output;
	}

	/**
	 * Check file permissions.
	 *
	 * @return array Check result.
	 */
	protected function check_file_permissions() {
		try {
			$upload_dir = wp_upload_dir();

			// wp_upload_dir() may return an error in the 'error' key.
			if ( ! empty( $upload_dir['error'] ) ) {
				return array(
					'name'    => __( 'File Permissions', 'mcp-ai-wpoos-pro' ),
					'status'  => 'fail',
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Upload directory error: %s', 'mcp-ai-wpoos-pro' ),
						$upload_dir['error']
					),
				);
			}

			$writable = isset( $upload_dir['basedir'] ) && is_writable( $upload_dir['basedir'] );

			return array(
				'name'    => __( 'File Permissions', 'mcp-ai-wpoos-pro' ),
				'status'  => $writable ? 'pass' : 'fail',
				'message' => $writable
					? __( 'Upload directory is writable', 'mcp-ai-wpoos-pro' )
					: __( 'Upload directory is not writable', 'mcp-ai-wpoos-pro' ),
			);
		} catch ( Throwable $e ) {
			// Intentionally empty - error handled elsewhere.
			return array(
				'name'    => __( 'File Permissions', 'mcp-ai-wpoos-pro' ),
				'status'  => 'fail',
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Could not check file permissions: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Check HTTPS.
	 *
	 * @return array Check result.
	 */
	protected function check_https() {
		$is_https = is_ssl();

		return array(
			'name'    => __( 'HTTPS Status', 'mcp-ai-wpoos-pro' ),
			'status'  => $is_https ? 'pass' : 'warning',
			'message' => $is_https
				? __( 'Site is using HTTPS', 'mcp-ai-wpoos-pro' )
				: __( 'Site is not using HTTPS (recommended for security)', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Check API keys configured.
	 *
	 * @return array Check result.
	 */
	protected function check_api_keys_configured() {
		// Get settings from the centralized settings storage.
		// Following SoC: Delegate settings retrieval to WP_MCP_AI_Admin_Settings.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings   = WP_MCP_AI_Admin_Settings::get_settings();
			$has_openai = ! empty( $settings['openai_api_key'] );
			$has_gemini = ! empty( $settings['gemini_api_key'] );
		} else {
			// Fallback: Check legacy individual options (backward compatibility).
			$has_openai = ! empty( get_option( 'wp_mcp_ai_openai_api_key' ) );
			$has_gemini = ! empty( get_option( 'wp_mcp_ai_gemini_api_key' ) );
		}

		$configured = $has_openai || $has_gemini;

		return array(
			'name'    => __( 'API Keys', 'mcp-ai-wpoos-pro' ),
			'status'  => $configured ? 'pass' : 'warning',
			'message' => $configured
				? __( 'AI API keys are configured', 'mcp-ai-wpoos-pro' )
				: __( 'No AI API keys configured', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Check database queries.
	 *
	 * @return array Check result.
	 */
	protected function check_database_queries() {
		global $wpdb;

		$queries_before = $wpdb->num_queries;

		// Perform a simple query test.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( 'SELECT 1' );

		$queries_after = $wpdb->num_queries;
		$query_count   = $queries_after - $queries_before;

		return array(
			'name'    => __( 'Database Connectivity', 'mcp-ai-wpoos-pro' ),
			'status'  => $query_count > 0 ? 'pass' : 'fail',
			'message' => sprintf(
				/* translators: %d: query count */
				__( 'Database is responsive (%d test queries)', 'mcp-ai-wpoos-pro' ),
				$query_count
			),
		);
	}

	/**
	 * Check cache status.
	 *
	 * @return array Check result.
	 */
	protected function check_cache_status() {
		$has_object_cache = wp_using_ext_object_cache();

		return array(
			'name'    => __( 'Object Cache', 'mcp-ai-wpoos-pro' ),
			'status'  => $has_object_cache ? 'pass' : 'warning',
			'message' => $has_object_cache
				? __( 'External object cache is active', 'mcp-ai-wpoos-pro' )
				: __( 'No external object cache (consider Redis/Memcached)', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Check REST API response.
	 *
	 * @return array Check result.
	 */
	protected function check_rest_api_response() {
		$start    = microtime( true );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/types/post' ) );
		$duration = round( ( microtime( true ) - $start ) * 1000, 2 );

		// Guard against WP_Error objects.
		if ( is_wp_error( $response ) ) {
			return array(
				'name'    => __( 'REST API Speed', 'mcp-ai-wpoos-pro' ),
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'REST API unavailable: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				),
			);
		}

		$success = ! $response->is_error();

		return array(
			'name'    => __( 'REST API Speed', 'mcp-ai-wpoos-pro' ),
			'status'  => $success ? 'pass' : 'fail',
			'message' => $success
				? sprintf(
					/* translators: %s: response time */
					__( 'REST API responding in %sms', 'mcp-ai-wpoos-pro' ),
					$duration
				)
				: __( 'REST API error', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Check memory limit.
	 *
	 * @return array Check result.
	 */
	protected function check_memory_limit() {
		$memory_limit = ini_get( 'memory_limit' );
		$memory_int   = intval( $memory_limit );

		$status = 'pass';
		if ( $memory_int < 256 ) {
			$status = 'warning';
		}
		if ( $memory_int < 128 ) {
			$status = 'fail';
		}

		return array(
			'name'    => __( 'Memory Limit', 'mcp-ai-wpoos-pro' ),
			'status'  => $status,
			'message' => sprintf(
				/* translators: %s: memory limit */
				__( 'PHP memory limit: %s (recommended: 256M+)', 'mcp-ai-wpoos-pro' ),
				$memory_limit
			),
		);
	}

	/**
	 * Check max execution time.
	 *
	 * @return array Check result.
	 */
	protected function check_max_execution_time() {
		$max_execution = ini_get( 'max_execution_time' );

		$status = 'pass';
		if ( $max_execution > 0 && $max_execution < 60 ) {
			$status = 'warning';
		}

		return array(
			'name'    => __( 'Max Execution Time', 'mcp-ai-wpoos-pro' ),
			'status'  => $status,
			'message' => sprintf(
				/* translators: %s: execution time */
				__( 'Max execution time: %ss', 'mcp-ai-wpoos-pro' ),
				$max_execution === '0' ? 'unlimited' : $max_execution
			),
		);
	}

	/**
	 * Check concurrent request handling.
	 *
	 * @return array Check result.
	 */
	protected function check_concurrent_requests() {
		// Simple check - can we handle multiple operations.
		$operations = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			wp_cache_set( 'test_' . $i, $i, 'test', 60 );
			if ( wp_cache_get( 'test_' . $i, 'test' ) === $i ) {
				++$operations;
			}
		}

		return array(
			'name'    => __( 'Concurrent Operations', 'mcp-ai-wpoos-pro' ),
			'status'  => $operations >= 8 ? 'pass' : 'warning',
			'message' => sprintf(
				/* translators: %d: successful operations */
				__( 'Handled %d/10 concurrent cache operations', 'mcp-ai-wpoos-pro' ),
				$operations
			),
		);
	}

	/**
	 * Check object cache.
	 *
	 * @return array Check result.
	 */
	protected function check_object_cache() {
		return $this->check_cache_status();
	}

	/**
	 * Check autoload size.
	 *
	 * @return array Check result.
	 */
	protected function check_autoload_size() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$autoload_size = $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value))
			FROM {$wpdb->options}
			WHERE autoload = 'yes'"
		);

		$size_mb = round( $autoload_size / 1024 / 1024, 2 );

		$status = 'pass';
		if ( $size_mb > 1 ) {
			$status = 'warning';
		}
		if ( $size_mb > 3 ) {
			$status = 'fail';
		}

		return array(
			'name'    => __( 'Autoload Size', 'mcp-ai-wpoos-pro' ),
			'status'  => $status,
			'message' => sprintf(
				/* translators: %s: autoload size */
				__( 'Autoloaded options: %sMB (recommended: <1MB)', 'mcp-ai-wpoos-pro' ),
				$size_mb
			),
		);
	}

	/**
	 * Check transients.
	 *
	 * @return array Check result.
	 */
	protected function check_transients() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$transient_count = $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_%'"
		);

		$status = 'pass';
		if ( $transient_count > 1000 ) {
			$status = 'warning';
		}

		return array(
			'name'    => __( 'Transients', 'mcp-ai-wpoos-pro' ),
			'status'  => $status,
			'message' => sprintf(
				/* translators: %d: transient count */
				__( 'Transient count: %d', 'mcp-ai-wpoos-pro' ),
				$transient_count
			),
		);
	}
}
