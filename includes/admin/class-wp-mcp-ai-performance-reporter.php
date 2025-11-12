<?php
/**
 * Performance Reporter for WP oOS.
 *
 * @deprecated Use WP_MCP_AI_Performance_Service instead.
 * @see WP_MCP_AI_Performance_Service
 *
 * This class is maintained for backward compatibility only.
 * All functionality has been moved to the Performance Service.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performance Reporter class.
 *
 * @deprecated Use WP_MCP_AI_Performance_Service instead.
 */
class WP_MCP_AI_Performance_Reporter {

	/**
	 * Generate a comprehensive performance report.
	 *
	 * @deprecated Use WP_MCP_AI_Performance_Service::generate_report() instead.
	 *
	 * @param array $options Report options.
	 * @return array Performance report data.
	 */
	public static function generate_report( $options = array() ) {
		return WP_MCP_AI_Performance_Service::generate_report( $options );
	}

	/**
	 * Analyze a specific component.
	 *
	 * @deprecated Internal method - use WP_MCP_AI_Performance_Service instead.
	 *
	 * @param string $component   Component name.
	 * @param string $time_period Time period.
	 * @param array  $test_types  Test types to analyze.
	 * @return array Component analysis.
	 */
	protected static function analyze_component( $component, $time_period, $test_types ) {
		// Delegate to service using reflection since method is protected.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Performance_Service' );
		$method     = $reflection->getMethod( 'analyze_component' );
		$method->setAccessible( true );
		return $method->invokeArgs( null, array( $component, $time_period, $test_types ) );
	}

	/**
	 * Calculate overall health status.
	 *
	 * @deprecated Internal method - use WP_MCP_AI_Performance_Service instead.
	 *
	 * @param array $components Component data.
	 * @return string Overall health status.
	 */
	protected static function calculate_overall_health( $components ) {
		// Delegate to service using reflection since method is protected.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Performance_Service' );
		$method     = $reflection->getMethod( 'calculate_overall_health' );
		$method->setAccessible( true );
		return $method->invokeArgs( null, array( $components ) );
	}

	/**
	 * Generate report summary.
	 *
	 * @deprecated Internal method - use WP_MCP_AI_Performance_Service instead.
	 *
	 * @param array $report Report data.
	 * @return array Summary data.
	 */
	protected static function generate_summary( $report ) {
		// Delegate to service using reflection since method is protected.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Performance_Service' );
		$method     = $reflection->getMethod( 'generate_summary' );
		$method->setAccessible( true );
		return $method->invokeArgs( null, array( $report ) );
	}

	/**
	 * Generate component-specific recommendations.
	 *
	 * @deprecated Internal method - use WP_MCP_AI_Performance_Service instead.
	 *
	 * @param string $component      Component name.
	 * @param array  $component_data Component data.
	 * @return array Recommendations.
	 */
	protected static function generate_component_recommendations( $component, $component_data ) {
		// Delegate to service using reflection since method is protected.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Performance_Service' );
		$method     = $reflection->getMethod( 'generate_component_recommendations' );
		$method->setAccessible( true );
		return $method->invokeArgs( null, array( $component, $component_data ) );
	}

	/**
	 * Get performance alerts for admin dashboard.
	 *
	 * @deprecated Use WP_MCP_AI_Performance_Service::get_performance_alerts() instead.
	 *
	 * @param int $limit Maximum number of alerts to return.
	 * @return array Performance alerts.
	 */
	public static function get_performance_alerts( $limit = 5 ) {
		return WP_MCP_AI_Performance_Service::get_performance_alerts( $limit );
	}

	/**
	 * Get chart data for visualization.
	 *
	 * @deprecated Use WP_MCP_AI_Performance_Service::get_chart_data() instead.
	 *
	 * @param string $component  Component name.
	 * @param string $metric     Metric to chart.
	 * @param string $time_period Time period.
	 * @return array Chart data.
	 */
	public static function get_chart_data( $component, $metric = 'avg_response_time', $time_period = '-30 days' ) {
		return WP_MCP_AI_Performance_Service::get_chart_data( $component, $metric, $time_period );
	}

	/**
	 * Update performance baselines.
	 *
	 * @deprecated Use WP_MCP_AI_Performance_Service::update_baselines() instead.
	 *
	 * @return array Updated baselines.
	 */
	public static function update_baselines() {
		return WP_MCP_AI_Performance_Service::update_baselines();
	}

	/**
	 * Get current baselines.
	 *
	 * @deprecated Use WP_MCP_AI_Performance_Service::get_baselines() instead.
	 *
	 * @return array Performance baselines.
	 */
	public static function get_baselines() {
		return WP_MCP_AI_Performance_Service::get_baselines();
	}
}

