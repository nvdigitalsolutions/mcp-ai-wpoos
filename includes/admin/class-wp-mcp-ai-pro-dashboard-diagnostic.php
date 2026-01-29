<?php
/**
 * Pro Dashboard Diagnostic Tool
 *
 * Helps diagnose why charts are not showing on the Pro Dashboard.
 *
 * @package WP_MCP_AI
 * @since 1.5.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Dashboard_Diagnostic' ) ) {
	/**
	 * Diagnostic tool for Pro Dashboard charts.
	 */
	class WP_MCP_AI_Pro_Dashboard_Diagnostic {
		/**
		 * Parent page slug (Pro Dashboard).
		 */
		const PARENT_SLUG = 'nvoos-pro-dashboard';

		/**
		 * Diagnostic page slug.
		 */
		const PAGE_SLUG = 'nvoos-pro-dashboard-diagnostic';

		/**
		 * Run comprehensive diagnostics.
		 *
		 * @return array Diagnostic results.
		 */
		public static function run_diagnostics() {
			$results = array(
				'timestamp'      => current_time( 'mysql' ),
				'tests'          => array(),
				'overall_status' => 'unknown',
			);

			// Test 1: Check if Pro Dashboard class exists.
			$results['tests']['pro_dashboard_class'] = array(
				'name'    => 'Pro Dashboard Class',
				'status'  => class_exists( 'WP_MCP_AI_Pro_Dashboard' ) ? 'pass' : 'fail',
				'message' => class_exists( 'WP_MCP_AI_Pro_Dashboard' ) ? 'Class exists' : 'Class not found',
			);

			// Test 2: Check if Compliance Data class exists.
			$results['tests']['compliance_data_class'] = array(
				'name'    => 'Compliance Data Class',
				'status'  => class_exists( 'WP_MCP_AI_Compliance_Data' ) ? 'pass' : 'fail',
				'message' => class_exists( 'WP_MCP_AI_Compliance_Data' ) ? 'Class exists' : 'Class not found',
			);

			// Test 3: Check if REST API class exists.
			$results['tests']['rest_api_class'] = array(
				'name'    => 'REST API Class',
				'status'  => class_exists( 'WP_MCP_AI_Pro_Dashboard_REST' ) ? 'pass' : 'fail',
				'message' => class_exists( 'WP_MCP_AI_Pro_Dashboard_REST' ) ? 'Class exists' : 'Class not found',
			);

			// Test 4: Check if Chart.js file exists.
			$chart_js_path                    = WP_MCP_AI_PATH . 'assets/js/vendor/chart.min.js';
			$chart_js_exists                  = file_exists( $chart_js_path );
			$results['tests']['chartjs_file'] = array(
				'name'    => 'Chart.js File',
				'status'  => $chart_js_exists ? 'pass' : 'fail',
				'message' => $chart_js_exists ? 'File exists (' . size_format( filesize( $chart_js_path ) ) . ')' : 'File not found',
				'path'    => $chart_js_path,
			);

			// Test 5: Check if pro-dashboard.js file exists.
			$pro_dashboard_js_path                     = WP_MCP_AI_PATH . 'assets/js/pro-dashboard.js';
			$pro_dashboard_js_exists                   = file_exists( $pro_dashboard_js_path );
			$results['tests']['pro_dashboard_js_file'] = array(
				'name'    => 'Pro Dashboard JS File',
				'status'  => $pro_dashboard_js_exists ? 'pass' : 'fail',
				'message' => $pro_dashboard_js_exists ? 'File exists (' . size_format( filesize( $pro_dashboard_js_path ) ) . ')' : 'File not found',
				'path'    => $pro_dashboard_js_path,
			);

			// Test 6: Check if ISO 27001 controls can be loaded.
			if ( class_exists( 'WP_MCP_AI_Compliance_Data' ) ) {
				$controls                              = WP_MCP_AI_Compliance_Data::get_iso27001_controls();
				$controls_loaded                       = is_array( $controls ) && count( $controls ) > 0;
				$results['tests']['iso27001_controls'] = array(
					'name'    => 'ISO 27001 Controls Data',
					'status'  => $controls_loaded ? 'pass' : 'fail',
					'message' => $controls_loaded ? count( $controls ) . ' controls loaded' : 'No controls loaded',
					'count'   => $controls_loaded ? count( $controls ) : 0,
				);
			} else {
				$results['tests']['iso27001_controls'] = array(
					'name'    => 'ISO 27001 Controls Data',
					'status'  => 'fail',
					'message' => 'Compliance data class not available',
				);
			}

			// Test 7: Check if chart data can be generated.
			if ( class_exists( 'WP_MCP_AI_Pro_Dashboard' ) ) {
				$dashboard  = WP_MCP_AI_Pro_Dashboard::get_instance();
				$reflection = new ReflectionClass( $dashboard );

				try {
					$method = $reflection->getMethod( 'get_chart_data' );
					$method->setAccessible( true );
					$chart_data = $method->invoke( $dashboard );

					$chart_data_valid = is_array( $chart_data ) &&
										isset( $chart_data['controls'] ) &&
										isset( $chart_data['risks'] ) &&
										isset( $chart_data['metrics'] );

					$results['tests']['chart_data_generation'] = array(
						'name'    => 'Chart Data Generation',
						'status'  => $chart_data_valid ? 'pass' : 'fail',
						'message' => $chart_data_valid ? 'Chart data generated successfully' : 'Chart data invalid or empty',
						'data'    => $chart_data_valid ? $chart_data : null,
					);
				} catch ( Exception $e ) {
					$results['tests']['chart_data_generation'] = array(
						'name'    => 'Chart Data Generation',
						'status'  => 'fail',
						'message' => 'Error: ' . $e->getMessage(),
					);
				}
			}

			// Test 8: Check if scripts are registered.
			global $wp_scripts;
			if ( isset( $wp_scripts ) ) {
				$chartjs_registered       = isset( $wp_scripts->registered['chartjs'] );
				$pro_dashboard_registered = isset( $wp_scripts->registered['wp-mcp-ai-pro-dashboard'] );

				$results['tests']['scripts_registered'] = array(
					'name'          => 'Scripts Registered',
					'status'        => ( $chartjs_registered && $pro_dashboard_registered ) ? 'pass' : 'warning',
					'message'       => sprintf(
						'Chart.js: %s, Pro Dashboard: %s',
						$chartjs_registered ? 'registered' : 'not registered',
						$pro_dashboard_registered ? 'registered' : 'not registered'
					),
					'chartjs'       => $chartjs_registered,
					'pro_dashboard' => $pro_dashboard_registered,
				);
			}

			// Test 9: Check if REST API endpoint is registered.
			$rest_server         = rest_get_server();
			$routes              = $rest_server->get_routes();
			$endpoint_registered = isset( $routes['/mcp-ai/v1/pro/compliance/status'] );

			$results['tests']['rest_endpoint'] = array(
				'name'    => 'REST API Endpoint',
				'status'  => $endpoint_registered ? 'pass' : 'fail',
				'message' => $endpoint_registered ? 'Endpoint registered' : 'Endpoint not registered',
				'url'     => rest_url( 'mcp-ai/v1/pro/compliance/status' ),
			);

			// Test 10: Check WP_DEBUG status.
			$results['tests']['wp_debug'] = array(
				'name'    => 'WP_DEBUG Status',
				'status'  => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'info' : 'info',
				'message' => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'Enabled' : 'Disabled',
			);

			// Calculate overall status.
			$failed_tests = array_filter(
				$results['tests'],
				function ( $test ) {
					return 'fail' === $test['status'];
				}
			);

			if ( empty( $failed_tests ) ) {
				$results['overall_status'] = 'pass';
			} else {
				$results['overall_status'] = 'fail';
				$results['failed_count']   = count( $failed_tests );
			}

			return $results;
		}

		/**
		 * Render diagnostic results as HTML.
		 *
		 * @param array $results Diagnostic results.
		 * @return void
		 */
		public static function render_diagnostic_results( $results ) {
			?>
			<div class="wrap wp-mcp-ai-pro-dashboard-diagnostic">
				<h1><?php esc_html_e( 'Pro Dashboard Diagnostic Results', 'mcp-ai-wpoos' ); ?></h1>

				<div class="notice notice-<?php echo esc_attr( 'pass' === $results['overall_status'] ? 'success' : 'error' ); ?>">
					<p>
						<strong>
							<?php
							if ( 'pass' === $results['overall_status'] ) {
								esc_html_e( '✓ All tests passed! Charts should be working.', 'mcp-ai-wpoos' );
							} else {
								printf(
									/* translators: %d: Number of failed tests */
									esc_html__( '✗ %d test(s) failed. See details below.', 'mcp-ai-wpoos' ),
									isset( $results['failed_count'] ) ? absint( $results['failed_count'] ) : 0
								);
							}
							?>
						</strong>
					</p>
				</div>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 50px;"><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Test', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Details', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $results['tests'] as $test_key => $test ) : ?>
							<tr>
								<td style="text-align: center;">
									<?php
									if ( 'pass' === $test['status'] ) {
										echo '<span style="color: #46b450; font-size: 20px;">✓</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML badge.
									} elseif ( 'fail' === $test['status'] ) {
										echo '<span style="color: #dc3232; font-size: 20px;">✗</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML badge.
									} else {
										echo '<span style="color: #ffb900; font-size: 20px;">⚠</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML badge.
									}
									?>
								</td>
								<td><strong><?php echo esc_html( $test['name'] ); ?></strong></td>
								<td>
									<?php echo esc_html( $test['message'] ); ?>
									<?php if ( isset( $test['path'] ) ) : ?>
										<br><code><?php echo esc_html( $test['path'] ); ?></code>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Troubleshooting Steps', 'mcp-ai-wpoos' ); ?></h2>
				<ol>
					<li><strong><?php esc_html_e( 'Check Browser Console', 'mcp-ai-wpoos' ); ?></strong> - <?php esc_html_e( 'Open browser DevTools (F12) and check for JavaScript errors', 'mcp-ai-wpoos' ); ?></li>
					<li><strong><?php esc_html_e( 'Verify Script Loading', 'mcp-ai-wpoos' ); ?></strong> - <?php esc_html_e( 'In console, type: typeof Chart (should return "function")', 'mcp-ai-wpoos' ); ?></li>
					<li><strong><?php esc_html_e( 'Check Data Available', 'mcp-ai-wpoos' ); ?></strong> - <?php esc_html_e( 'In console, type: console.log(wpMcpAiProDashboard)', 'mcp-ai-wpoos' ); ?></li>
					<li><strong><?php esc_html_e( 'Clear Cache', 'mcp-ai-wpoos' ); ?></strong> - <?php esc_html_e( 'Clear browser cache and WordPress cache (if using caching plugin)', 'mcp-ai-wpoos' ); ?></li>
					<li><strong><?php esc_html_e( 'Test REST API', 'mcp-ai-wpoos' ); ?></strong> - <?php esc_html_e( 'Visit the REST endpoint URL shown above', 'mcp-ai-wpoos' ); ?></li>
				</ol>

				<h2><?php esc_html_e( 'Expected Console Output', 'mcp-ai-wpoos' ); ?></h2>
				<pre style="background: #f0f0f1; padding: 15px; border-left: 4px solid #0073aa;">
Pro Dashboard script loaded
jQuery version: 3.x.x
Dashboard config: Object { ajaxUrl: "...", restUrl: "...", chartData: {...} }
Document ready, initializing Pro Dashboard...
Initializing Pro Dashboard...
Chart.js loaded successfully
Initializing charts...
Controls chart initialized successfully
Metrics chart initialized successfully
Risk chart initialized successfully
Charts initialized: 3 failed: 0
Pro Dashboard initialization complete
</pre>

				<p>
					<em><?php esc_html_e( 'Generated:', 'mcp-ai-wpoos' ); ?> <?php echo esc_html( $results['timestamp'] ); ?></em>
				</p>
			</div>

			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for pro dashboard diagnostic layout and styling on this admin page only
			?>
			<style>
				.wp-mcp-ai-pro-dashboard-diagnostic h2 {
					margin-top: 30px;
				}
				.wp-mcp-ai-pro-dashboard-diagnostic ol li {
					margin-bottom: 10px;
				}
			</style>
			<?php
		}

		/**
		 * Add diagnostic page to Pro Dashboard menu.
		 */
		public static function add_diagnostic_page() {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Charts Diagnostic', 'mcp-ai-wpoos' ),
				__( 'Charts Diagnostic', 'mcp-ai-wpoos' ),
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_diagnostic_page' )
			);
		}

		/**
		 * Render diagnostic page.
		 */
		public static function render_diagnostic_page() {
			$results = self::run_diagnostics();
			self::render_diagnostic_results( $results );
		}
	}
}

// Add diagnostic page to admin menu.
add_action( 'admin_menu', array( 'WP_MCP_AI_Pro_Dashboard_Diagnostic', 'add_diagnostic_page' ), 100 );
