<?php
/**
 * Site Kit Analytics Tool
 *
 * Provides access to Google Analytics data through Site Kit.
 *
 * @package    WP_MCP_AI
 * @subpackage Tools
 * @since      1.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Kit Analytics Tool Class
 *
 * Example implementation of a Site Kit integration tool.
 * This demonstrates the pattern for accessing Site Kit data.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_SiteKit_Analytics {

	/**
	 * Get tool slug
	 *
	 * @since 1.2.0
	 * @return string Tool slug
	 */
	public function get_slug() {
		return 'sitekit_get_analytics';
	}

	/**
	 * Get tool definition
	 *
	 * @since 1.2.0
	 * @return array Tool definition
	 */
	public function get_definition() {
		return array(
			'name'                => 'sitekit_get_analytics',
			'description'         => 'Retrieve Google Analytics data through Site Kit. Provides metrics like sessions, pageviews, bounce rate, and average session duration.',
			'required_capability' => 'manage_options',
			'category'            => 'analytics',
			'parameters'          => array(
				'type'       => 'object',
				'properties' => array(
					'metric'     => array(
						'type'        => 'string',
						'description' => 'Metric to retrieve',
						'enum'        => array( 'sessions', 'pageviews', 'bounce_rate', 'avg_session_duration', 'users' ),
						'default'     => 'sessions',
					),
					'date_range' => array(
						'type'        => 'string',
						'description' => 'Date range for the query',
						'enum'        => array( 'last_7_days', 'last_28_days', 'last_90_days' ),
						'default'     => 'last_28_days',
					),
					'url'        => array(
						'type'        => 'string',
						'description' => 'Optional URL to filter analytics data',
					),
				),
				'required'   => array(),
			),
		);
	}

	/**
	 * Execute the tool
	 *
	 * @since 1.2.0
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool result
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP_MCP_AI_Tool_Interface.
		// Get Site Kit integration instance.
		$sitekit = WP_MCP_AI_SiteKit_Integration::get_instance();

		// Check if Site Kit is available.
	if ( ! $sitekit->is_sitekit_available() ) {
		return array(
			'error'    => true,
			'message'  => __( 'Google Site Kit is not active or configured', 'mcp-ai-wpoos' ),
			'help_url' => 'https://sitekit.withgoogle.com/documentation/',
			'action'   => __( 'Please install and configure Google Site Kit plugin', 'mcp-ai-wpoos' ),
		);
	}

		// Check user permissions.
	if ( ! $sitekit->user_has_sitekit_access() ) {
		return array(
			'error'   => true,
			'message' => __( 'You do not have permission to access Google Analytics data', 'mcp-ai-wpoos' ),
		);
	}

		// Parse arguments.
		$metric     = isset( $arguments['metric'] ) ? sanitize_text_field( $arguments['metric'] ) : 'sessions';
		$date_range = isset( $arguments['date_range'] ) ? sanitize_text_field( $arguments['date_range'] ) : 'last_28_days';
		$url        = isset( $arguments['url'] ) ? esc_url_raw( $arguments['url'] ) : null;

		// Build Site Kit API request.
		$endpoint = '/wp-json/google-site-kit/v1/modules/analytics/data/';
		$api_args = array(
			'metrics'   => array( $this->map_metric_to_api( $metric ) ),
			'dateRange' => $this->map_date_range( $date_range ),
		);

		if ( $url ) {
			$api_args['url'] = $url;
		}

		// Make request to Site Kit.
		$response = $sitekit->make_sitekit_request( $endpoint, $api_args );

		// Handle errors.
		if ( is_wp_error( $response ) ) {
			return array(
				'error'   => true,
				'message' => $response->get_error_message(),
				'code'    => $response->get_error_code(),
			);
		}

		// Format response for AI assistant.
		return $this->format_analytics_response( $response, $metric, $date_range, $url );
}

	/**
	 * Map metric name to Site Kit API metric
	 *
	 * @since 1.2.0
	 * @param string $metric Metric name.
	 * @return string API metric name
	 */
private function map_metric_to_api( $metric ) {
	$metric_map = array(
		'sessions'             => 'ga:sessions',
		'pageviews'            => 'ga:pageviews',
		'bounce_rate'          => 'ga:bounceRate',
		'avg_session_duration' => 'ga:avgSessionDuration',
		'users'                => 'ga:users',
	);

	return isset( $metric_map[ $metric ] ) ? $metric_map[ $metric ] : 'ga:sessions';
}

	/**
	 * Map date range to Site Kit format
	 *
	 * @since 1.2.0
	 * @param string $range Date range.
	 * @return string Site Kit date range
	 */
private function map_date_range( $range ) {
	$range_map = array(
		'last_7_days'  => 'last-7-days',
		'last_28_days' => 'last-28-days',
		'last_90_days' => 'last-90-days',
	);

	return isset( $range_map[ $range ] ) ? $range_map[ $range ] : 'last-28-days';
}

	/**
	 * Format analytics response for AI
	 *
	 * @since 1.2.0
	 * @param array  $response   Site Kit API response.
	 * @param string $metric     Requested metric.
	 * @param string $date_range Date range.
	 * @param string $url        Optional URL filter.
	 * @return array Formatted response
	 */
private function format_analytics_response( $response, $metric, $date_range, $url = null ) {
	// Extract data from response (structure depends on Site Kit's actual API).
	// This is a simplified example - actual implementation would parse Site Kit's response format.

	$formatted = array(
		'success'    => true,
		'metric'     => $metric,
		'date_range' => $date_range,
		'data'       => $response,
	);

	if ( $url ) {
		$formatted['filtered_by_url'] = $url;
	}

	// Add human-readable summary.
	$formatted['summary'] = $this->generate_summary( $response, $metric, $date_range );

	return $formatted;
}

	/**
	 * Generate human-readable summary
	 *
	 * @since 1.2.0
	 * @param array  $data       Analytics data.
	 * @param string $metric     Metric name.
	 * @param string $date_range Date range.
	 * @return string Summary text
	 */
private function generate_summary( $data, $metric, $date_range ) {
	// This is a simplified example.
	// Real implementation would parse the actual data structure from Site Kit.

	$period = str_replace( '_', ' ', $date_range );

	return sprintf(
		/* translators: 1: metric name, 2: date range */
		__( 'Analytics data for %1$s over the %2$s', 'mcp-ai-wpoos' ),
		$metric,
		$period
	);
}
}
