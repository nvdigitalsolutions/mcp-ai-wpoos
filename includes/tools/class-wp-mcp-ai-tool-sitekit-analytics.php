<?php
/**
 * Site Kit Analytics Tool
 *
 * Provides access to Google Analytics data through Site Kit.
 *
 * @package    WP_MCP_AI
 * @subpackage Tools
 * @since      1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Kit Analytics Tool Class
 *
 * Retrieves Google Analytics data including sessions, pageviews,
 * bounce rate, average session duration, and users.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_SiteKit_Analytics implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Check whether the tool can be registered.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'Google\\Site_Kit\\Plugin' );
	}

	/**
	 * Provide a message explaining why the tool is unavailable.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Google Site Kit plugin must be installed and configured to use Analytics tools.', 'mcp-ai-wpoos' );
	}

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
	 * Get tool name
	 *
	 * @since 1.2.0
	 * @return string Tool name
	 */
	public function get_name() {
		return __( 'Get Google Analytics', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool description
	 *
	 * @since 1.2.0
	 * @return string Tool description
	 */
	public function get_description() {
		return __( 'Retrieve Google Analytics data through Site Kit. Provides metrics like sessions, pageviews, bounce rate, and average session duration.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get parameters schema
	 *
	 * @since 1.2.0
	 * @return array Parameters schema
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'metric'     => array(
					'type'        => 'string',
					'description' => __( 'Metric to retrieve', 'mcp-ai-wpoos' ),
					'enum'        => array( 'sessions', 'pageviews', 'bounce_rate', 'avg_session_duration', 'users' ),
					'default'     => 'sessions',
				),
				'date_range' => array(
					'type'        => 'string',
					'description' => __( 'Date range for the query', 'mcp-ai-wpoos' ),
					'enum'        => array( 'last_7_days', 'last_28_days', 'last_90_days' ),
					'default'     => 'last_28_days',
				),
				'url'        => array(
					'type'        => 'string',
					'description' => __( 'Optional URL to filter analytics data', 'mcp-ai-wpoos' ),
					'format'      => 'uri',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool
	 *
	 * @since 1.2.0
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool result
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if Site Kit is available.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'sitekit_not_available',
				__( 'Google Site Kit is not active or configured', 'mcp-ai-wpoos' )
			);
		}

		// Check user permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to access Google Analytics data', 'mcp-ai-wpoos' )
			);
		}

		// Get Site Kit integration instance.
		if ( ! class_exists( 'WP_MCP_AI_SiteKit_Integration' ) ) {
			return new WP_Error(
				'integration_not_loaded',
				__( 'Site Kit integration is not properly loaded', 'mcp-ai-wpoos' )
			);
		}

		$sitekit = WP_MCP_AI_SiteKit_Integration::get_instance();

		// Parse arguments (Gate 1: sanitize at entry).
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

		// Handle errors — use WP_Error (canonical envelope).
		if ( is_wp_error( $response ) ) {
			return $response;
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
	 * @param array       $response   Site Kit API response.
	 * @param string      $metric     Requested metric.
	 * @param string      $date_range Date range.
	 * @param string|null $url        Optional URL filter.
	 * @return array Formatted response
	 */
	private function format_analytics_response( $response, $metric, $date_range, $url = null ) {
		// Gate 2: escape values in the returned data array.
		$formatted = array(
			'success'    => true,
			'metric'     => esc_html( $metric ),
			'date_range' => esc_html( $date_range ),
			'data'       => $response,
		);

		if ( $url ) {
			$formatted['filtered_by_url'] = esc_url( $url );
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
		$period = str_replace( '_', ' ', $date_range );

		return sprintf(
			/* translators: 1: metric name, 2: date range */
			__( 'Analytics data for %1$s over the %2$s', 'mcp-ai-wpoos' ),
			esc_html( $metric ),
			esc_html( $period )
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.2.0
	 * @return array<string> Capability flag strings.
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'requires-capability', 'requires-credentials', 'external-api', 'network-dependent' );
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.2.0
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'ecommerce_business',
			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
			'profession_tags'       => array( 'analytics_specialist', 'marketing_manager' ),
			'risk_level'            => 'info',
		);
	}
}
