<?php
/**
 * Performance Service
 *
 * Handles performance monitoring, test execution, and metrics collection.
 * Extracted from WP_MCP_AI_Section_Performance as part of service layer refactoring.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performance Service class
 *
 * Responsible for:
 * - Performance test execution
 * - Metrics collection and aggregation
 * - Alert generation
 * - Performance report generation
 * - Data export functionality
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Performance_Service {

	/**
	 * Get performance report.
	 *
	 * @param array $options Report options (time_period, components, test_types).
	 * @return array Performance report data.
	 */
	public function get_report( $options = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Reporter' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-performance-reporter.php';
		}

		return WP_MCP_AI_Performance_Reporter::generate_report( $options );
	}

	/**
	 * Get performance metrics for a specific component.
	 *
	 * @param string $component   Component name.
	 * @param string $time_period Time period (e.g., '-7 days').
	 * @param string $test_type   Optional test type filter.
	 * @return array Performance metrics.
	 */
	public function get_component_metrics( $component, $time_period = '-7 days', $test_type = '' ) {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-performance-monitor-cct.php';
		}

		return WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends(
			$component,
			$time_period,
			$test_type
		);
	}

	/**
	 * Export performance report.
	 *
	 * @param string $format Export format ('json' or 'csv').
	 * @param array  $options Report options.
	 * @return array|WP_Error Export data or error.
	 */
	public function export_report( $format = 'json', $options = array() ) {
		$report = $this->get_report( $options );

		if ( 'json' === $format ) {
			return $report;
		} elseif ( 'csv' === $format ) {
			return $this->convert_report_to_csv( $report );
		}

		return new WP_Error(
			'wp_mcp_ai_invalid_format',
			__( 'Invalid export format.', 'wp-mcp-ai' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Convert report to CSV format.
	 *
	 * @param array $report Performance report.
	 * @return array CSV data structure.
	 */
	private function convert_report_to_csv( $report ) {
		$csv_data = array();

		// Add headers.
		$csv_data[] = array(
			'Component',
			'Health Status',
			'Avg Response Time (ms)',
			'Trend',
			'Alerts',
		);

		// Add component rows.
		if ( isset( $report['components'] ) ) {
			foreach ( $report['components'] as $component_id => $component_data ) {
				$avg_time = 0;
				if ( isset( $component_data['metrics']['avg_response_time'] ) ) {
					$times    = $component_data['metrics']['avg_response_time'];
					$avg_time = ! empty( $times ) ? array_sum( $times ) / count( $times ) : 0;
				}

				$trend = 'stable';
				if ( ! empty( $component_data['trends'] ) ) {
					$first_trend = reset( $component_data['trends'] );
					$trend       = isset( $first_trend['trend'] ) ? $first_trend['trend'] : 'stable';
				}

				$alert_count = isset( $component_data['alerts'] ) ? count( $component_data['alerts'] ) : 0;

				$csv_data[] = array(
					$component_id,
					isset( $component_data['health_status'] ) ? $component_data['health_status'] : 'unknown',
					number_format( $avg_time, 2 ),
					$trend,
					$alert_count,
				);
			}
		}

		return array(
			'format' => 'csv',
			'data'   => $csv_data,
		);
	}

	/**
	 * Get health icon for status.
	 *
	 * @param string $status Health status.
	 * @return string Icon name.
	 */
	public function get_health_icon( $status ) {
		$icons = array(
			'good'     => 'yes-alt',
			'fair'     => 'warning',
			'warning'  => 'flag',
			'critical' => 'dismiss',
		);

		return isset( $icons[ $status ] ) ? $icons[ $status ] : 'info';
	}

	/**
	 * Format component name for display.
	 *
	 * @param string $component_id Component identifier.
	 * @return string Formatted name.
	 */
	public function format_component_name( $component_id ) {
		$names = array(
			'rest_api'      => __( 'REST API', 'wp-mcp-ai' ),
			'chat_ui'       => __( 'Chat UI', 'wp-mcp-ai' ),
			'mcp_core'      => __( 'MCP Core', 'wp-mcp-ai' ),
			'elementor'     => __( 'Elementor Integration', 'wp-mcp-ai' ),
			'cpt_assistant' => __( 'Assistant CPT', 'wp-mcp-ai' ),
			'cpt_ai_peer'   => __( 'AI Peer CPT', 'wp-mcp-ai' ),
		);

		return isset( $names[ $component_id ] ) ? $names[ $component_id ] : ucwords( str_replace( '_', ' ', $component_id ) );
	}

	/**
	 * Format trend for display.
	 *
	 * @param string $trend Trend value.
	 * @return string Formatted trend with icon.
	 */
	public function format_trend( $trend ) {
		$trends = array(
			'improving' => '↗ ' . __( 'Improving', 'wp-mcp-ai' ),
			'stable'    => '→ ' . __( 'Stable', 'wp-mcp-ai' ),
			'degrading' => '↘ ' . __( 'Degrading', 'wp-mcp-ai' ),
		);

		return isset( $trends[ $trend ] ) ? $trends[ $trend ] : $trend;
	}

	/**
	 * Trigger performance test execution.
	 *
	 * Note: This returns instructions for CLI execution as tests
	 * should be run via command line for best results.
	 *
	 * @param string $test_type Test type (stress, security, speed, optimization).
	 * @return array Test execution instructions.
	 */
	public function trigger_test( $test_type ) {
		$valid_types = array( 'stress', 'security', 'speed', 'optimization' );

		if ( ! in_array( $test_type, $valid_types, true ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid test type.', 'wp-mcp-ai' ),
			);
		}

		return array(
			'success'     => true,
			'message'     => sprintf(
				/* translators: 1: test type, 2: test type (repeated for command) */
				__( 'To run %1$s tests, use: ./bin/run-performance-tests.sh --suite=%2$s', 'wp-mcp-ai' ),
				$test_type,
				$test_type
			),
			'cli_command' => sprintf( './bin/run-performance-tests.sh --suite=%s', $test_type ),
		);
	}
}
