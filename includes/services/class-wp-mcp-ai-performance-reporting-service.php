<?php
/**
 * Performance Reporting Service for NV oOS.
 *
 * Provides performance reporting and analysis capabilities extracted from the admin layer:
 * - Historical trend analysis
 * - Performance degradation alerts
 * - Metric visualization data
 * - Automated baseline updates
 * - Settings optimization recommendations
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performance Reporting Service class.
 *
 * This service provides business logic for performance analysis and reporting,
 * separated from the admin UI presentation layer.
 */
class WP_MCP_AI_Performance_Reporting_Service {

	/**
	 * Settings repository instance
	 *
	 * @var WP_MCP_AI_Settings_Repository
	 */
	private static $settings_repository;

	/**
	 * Get settings repository instance
	 *
	 * @return WP_MCP_AI_Settings_Repository Settings repository instance.
	 */
	private static function get_settings_repository() {
		if ( null === self::$settings_repository ) {
			self::$settings_repository = wp_mcp_ai_get_settings_repository();
		}
		return self::$settings_repository;
	}

	/**
	 * Set settings repository instance (for testing)
	 *
	 * @param WP_MCP_AI_Settings_Repository $repository Settings repository instance.
	 */
	public static function set_settings_repository( $repository ) {
		self::$settings_repository = $repository;
	}

	/**
	 * Generate a comprehensive performance report.
	 *
	 * @param array $options Report options.
	 * @return array Performance report data.
	 */
	public static function generate_report( $options = array() ) {
		$defaults = array(
			'time_period' => '-30 days',
			'components'  => array( 'rest_api', 'chat_ui', 'mcp_core', 'elementor', 'cpt_assistant', 'cpt_ai_peer' ),
			'test_types'  => array( 'stress', 'security', 'speed', 'optimization' ),
		);

		$options = wp_parse_args( $options, $defaults );

		$report = array(
			'generated_at'    => current_time( 'mysql' ),
			'time_period'     => $options['time_period'],
			'overall_health'  => 'unknown',
			'components'      => array(),
			'alerts'          => array(),
			'recommendations' => array(),
			'summary'         => array(),
		);

		// Analyze each component.
		foreach ( $options['components'] as $component ) {
			$component_data                     = self::analyze_component( $component, $options['time_period'], $options['test_types'] );
			$report['components'][ $component ] = $component_data;

			// Collect alerts.
			if ( ! empty( $component_data['alerts'] ) ) {
				$report['alerts'] = array_merge( $report['alerts'], $component_data['alerts'] );
			}

			// Collect recommendations.
			if ( ! empty( $component_data['recommendations'] ) ) {
				$report['recommendations'] = array_merge( $report['recommendations'], $component_data['recommendations'] );
			}
		}

		// Calculate overall health.
		$report['overall_health'] = self::calculate_overall_health( $report['components'] );

		// Generate summary.
		$report['summary'] = self::generate_summary( $report );

		return $report;
	}

	/**
	 * Analyze a specific component.
	 *
	 * @param string $component   Component name.
	 * @param string $time_period Time period.
	 * @param array  $test_types  Test types to analyze.
	 * @return array Component analysis.
	 */
	public static function analyze_component( $component, $time_period, $test_types ) {
		$component_data = array(
			'component'       => $component,
			'health_status'   => 'good',
			'trends'          => array(),
			'alerts'          => array(),
			'recommendations' => array(),
			'metrics'         => array(),
		);

		// Skip analysis if Performance Monitor CCT is not available (base version mode).
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			return $component_data;
		}

		foreach ( $test_types as $test_type ) {
			$trends = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends(
				$component,
				$time_period,
				$test_type
			);

			$component_data['trends'][ $test_type ] = $trends;

			// Check for degradation.
			if ( isset( $trends['trend'] ) && 'degrading' === $trends['trend'] ) {
				$component_data['alerts'][] = array(
					'severity'  => 'high',
					'component' => $component,
					'test_type' => $test_type,
					'message'   => sprintf(
						'Performance degradation detected in %s (%s tests)',
						$component,
						$test_type
					),
				);

				$component_data['health_status'] = 'warning';
			}

			// Collect metrics.
			if ( isset( $trends['avg_response_time'] ) ) {
				if ( ! isset( $component_data['metrics']['avg_response_time'] ) ) {
					$component_data['metrics']['avg_response_time'] = array();
				}
				$component_data['metrics']['avg_response_time'][ $test_type ] = $trends['avg_response_time'];
			}
		}

		// Check error rates using Error Tracking Service.
		if ( function_exists( 'wp_mcp_ai_get_error_tracking_service' ) ) {
			$error_service = wp_mcp_ai_get_error_tracking_service();
			$time_seconds  = strtotime( $time_period ) ? abs( strtotime( $time_period ) - time() ) : 3600;
			$error_rate    = $error_service->get_error_rate( $component, $time_seconds );

			$component_data['metrics']['error_rate'] = $error_rate;

			// Add alert for high error rates.
			if ( $error_rate > 10 ) {
				$component_data['alerts'][]      = array(
					'severity'  => 'critical',
					'component' => $component,
					'test_type' => 'error_tracking',
					'message'   => sprintf(
						'Critical error rate detected in %s: %.2f%%',
						$component,
						$error_rate
					),
				);
				$component_data['health_status'] = 'critical';
			} elseif ( $error_rate > 5 ) {
				$component_data['alerts'][] = array(
					'severity'  => 'high',
					'component' => $component,
					'test_type' => 'error_tracking',
					'message'   => sprintf(
						'Elevated error rate in %s: %.2f%%',
						$component,
						$error_rate
					),
				);
				if ( 'good' === $component_data['health_status'] ) {
					$component_data['health_status'] = 'warning';
				}
			}
		}

		// Generate component-specific recommendations.
		$component_data['recommendations'] = self::generate_component_recommendations( $component, $component_data );

		return $component_data;
	}

	/**
	 * Calculate overall health status.
	 *
	 * @param array $components Component data.
	 * @return string Overall health status.
	 */
	public static function calculate_overall_health( $components ) {
		$status_counts = array(
			'critical' => 0,
			'warning'  => 0,
			'good'     => 0,
		);

		foreach ( $components as $component_data ) {
			$status = isset( $component_data['health_status'] ) ? $component_data['health_status'] : 'good';
			if ( isset( $status_counts[ $status ] ) ) {
				++$status_counts[ $status ];
			}
		}

		if ( $status_counts['critical'] > 0 ) {
			return 'critical';
		}

		if ( $status_counts['warning'] > count( $components ) / 2 ) {
			return 'warning';
		}

		if ( $status_counts['warning'] > 0 ) {
			return 'fair';
		}

		return 'good';
	}

	/**
	 * Generate report summary.
	 *
	 * @param array $report Report data.
	 * @return array Summary data.
	 */
	public static function generate_summary( $report ) {
		$summary = array(
			'total_components'      => count( $report['components'] ),
			'total_alerts'          => count( $report['alerts'] ),
			'total_recommendations' => count( $report['recommendations'] ),
			'critical_alerts'       => 0,
			'high_alerts'           => 0,
			'medium_alerts'         => 0,
		);

		// Count alerts by severity.
		foreach ( $report['alerts'] as $alert ) {
			$severity = isset( $alert['severity'] ) ? $alert['severity'] : 'medium';
			$key      = $severity . '_alerts';

			if ( isset( $summary[ $key ] ) ) {
				++$summary[ $key ];
			}
		}

		return $summary;
	}

	/**
	 * Generate component-specific recommendations.
	 *
	 * @param string $component      Component name.
	 * @param array  $component_data Component data.
	 * @return array Recommendations.
	 */
	public static function generate_component_recommendations( $component, $component_data ) {
		$recommendations = array();

		// Check response times.
		if ( isset( $component_data['metrics']['avg_response_time'] ) ) {
			$avg_times = $component_data['metrics']['avg_response_time'];
			$max_time  = max( $avg_times );

			if ( $max_time > 2000 ) {
				$recommendations[] = array(
					'severity'  => 'high',
					'component' => $component,
					'action'    => 'Enable caching and optimize database queries',
					'reason'    => sprintf( 'Response times exceed 2 seconds (%.2f ms)', $max_time ),
				);
			} elseif ( $max_time > 1000 ) {
				$recommendations[] = array(
					'severity'  => 'medium',
					'component' => $component,
					'action'    => 'Consider enabling optimization features',
					'reason'    => sprintf( 'Response times above optimal (%.2f ms)', $max_time ),
				);
			}
		}

		// Check for degrading trends.
		foreach ( $component_data['trends'] as $test_type => $trends ) {
			if ( isset( $trends['trend'] ) && 'degrading' === $trends['trend'] ) {
				$recommendations[] = array(
					'severity'  => 'high',
					'component' => $component,
					'action'    => sprintf( 'Investigate %s performance degradation', $test_type ),
					'reason'    => 'Performance is degrading over time',
				);
			}
		}

		return $recommendations;
	}

	/**
	 * Get performance alerts for admin dashboard.
	 *
	 * @param int $limit Maximum number of alerts to return.
	 * @return array Performance alerts.
	 */
	public static function get_performance_alerts( $limit = 5 ) {
		$report = self::generate_report( array( 'time_period' => '-7 days' ) );

		$alerts = isset( $report['alerts'] ) ? $report['alerts'] : array();

		// Sort by severity.
		usort(
			$alerts,
			function ( $a, $b ) {
				$severity_order = array(
					'critical' => 0,
					'high'     => 1,
					'medium'   => 2,
					'low'      => 3,
				);
				$a_order        = isset( $severity_order[ $a['severity'] ] ) ? $severity_order[ $a['severity'] ] : 99;
				$b_order        = isset( $severity_order[ $b['severity'] ] ) ? $severity_order[ $b['severity'] ] : 99;
				return $a_order - $b_order;
			}
		);

		return array_slice( $alerts, 0, $limit );
	}

	/**
	 * Get chart data for visualization.
	 *
	 * @param string $component  Component name.
	 * @param string $metric     Metric to chart.
	 * @param string $time_period Time period.
	 * @return array Chart data.
	 */
	public static function get_chart_data( $component, $metric = 'avg_response_time', $time_period = '-30 days' ) {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			return array(
				'labels' => array(),
				'data'   => array(),
			);
		}

		$since_timestamp = strtotime( $time_period );

		$args = array(
			'component' => sanitize_key( $component ),
			'tested_at' => array(
				'type'  => 'DATE',
				'value' => array( gmdate( 'Y-m-d H:i:s', $since_timestamp ), current_time( 'mysql' ) ),
			),
		);

		$items = WP_MCP_AI_Performance_Monitor_CCT::query_items( $args );

		if ( empty( $items ) ) {
			return array(
				'labels' => array(),
				'data'   => array(),
			);
		}

		$chart_data = array(
			'labels' => array(),
			'data'   => array(),
		);

		foreach ( $items as $item ) {
			if ( isset( $item['tested_at'] ) && isset( $item[ $metric ] ) ) {
				$chart_data['labels'][] = gmdate( 'Y-m-d H:i', strtotime( $item['tested_at'] ) );
				$chart_data['data'][]   = floatval( $item[ $metric ] );
			}
		}

		return $chart_data;
	}

	/**
	 * Update performance baselines.
	 *
	 * Automatically updates baseline metrics for comparison.
	 *
	 * @return array Updated baselines.
	 */
	public static function update_baselines() {
		$components = array( 'rest_api', 'chat_ui', 'mcp_core', 'elementor' );
		$baselines  = array();

		foreach ( $components as $component ) {
			$trends = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends(
				$component,
				'-90 days'
			);

			$baselines[ $component ] = array(
				'avg_response_time' => isset( $trends['avg_response_time'] ) ? $trends['avg_response_time'] : 0,
				'avg_memory_usage'  => isset( $trends['avg_memory_usage'] ) ? $trends['avg_memory_usage'] : 0,
				'avg_db_queries'    => isset( $trends['avg_db_queries'] ) ? $trends['avg_db_queries'] : 0,
				'updated_at'        => current_time( 'mysql' ),
			);
		}

		self::get_settings_repository()->update( 'performance_baselines', $baselines );

		return $baselines;
	}

	/**
	 * Get current baselines.
	 *
	 * @return array Performance baselines.
	 */
	public static function get_baselines() {
		$baselines = self::get_settings_repository()->get( 'performance_baselines', array() );

		if ( empty( $baselines ) ) {
			// Generate baselines if they don't exist.
			$baselines = self::update_baselines();
		}

		return $baselines;
	}
}
