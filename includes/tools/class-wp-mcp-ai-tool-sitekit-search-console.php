<?php
/**
 * Site Kit Search Console Tool
 *
 * Provides access to Google Search Console data through Site Kit.
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
 * Site Kit Search Console Tool Class
 *
 * Retrieves Google Search Console data including keywords, rankings,
 * impressions, and clicks through Site Kit integration.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_SiteKit_Search_Console implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

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
		return __( 'Google Site Kit plugin must be installed and configured to use Search Console tools.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool slug
	 *
	 * @since 1.2.0
	 * @return string Tool slug
	 */
	public function get_slug() {
		return 'sitekit_get_search_console';
	}

	/**
	 * Get tool name
	 *
	 * @since 1.2.0
	 * @return string Tool name
	 */
	public function get_name() {
		return __( 'Get Search Console Data', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool description
	 *
	 * @since 1.2.0
	 * @return string Tool description
	 */
	public function get_description() {
		return __( 'Retrieve Google Search Console data including search queries, impressions, clicks, CTR, and average position. Helps analyze organic search performance and identify keyword opportunities.', 'mcp-ai-wpoos' );
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
				'date_range' => array(
					'type'        => 'string',
					'description' => __( 'Date range for the query', 'mcp-ai-wpoos' ),
					'enum'        => array( 'last_7_days', 'last_28_days', 'last_90_days' ),
					'default'     => 'last_28_days',
				),
				'dimension'  => array(
					'type'        => 'string',
					'description' => __( 'Dimension to group results by', 'mcp-ai-wpoos' ),
					'enum'        => array( 'query', 'page', 'country', 'device' ),
					'default'     => 'query',
				),
				'url'        => array(
					'type'        => 'string',
					'description' => __( 'Optional URL to filter results', 'mcp-ai-wpoos' ),
					'format'      => 'uri',
				),
				'limit'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
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
				__( 'You do not have permission to access Search Console data', 'mcp-ai-wpoos' )
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

		// Parse arguments.
		$date_range = isset( $arguments['date_range'] ) ? sanitize_text_field( $arguments['date_range'] ) : 'last_28_days';
		$dimension  = isset( $arguments['dimension'] ) ? sanitize_text_field( $arguments['dimension'] ) : 'query';
		$url        = isset( $arguments['url'] ) ? esc_url_raw( $arguments['url'] ) : null;
		$limit      = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;

		// Build Site Kit API request.
		$endpoint = '/wp-json/google-site-kit/v1/modules/search-console/data/searchanalytics';
		$api_args = array(
			'startDate'  => $this->map_date_range_start( $date_range ),
			'endDate'    => gmdate( 'Y-m-d' ),
			'dimensions' => array( $dimension ),
			'limit'      => $limit,
		);

		if ( $url ) {
			$api_args['url'] = $url;
		}

		// Make request to Site Kit.
		$response = $sitekit->make_sitekit_request( $endpoint, $api_args );

		// Handle errors.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Format response for AI assistant.
		return $this->format_search_console_response( $response, $dimension, $date_range, $url, $limit );
	}

	/**
	 * Map date range to start date
	 *
	 * @since 1.2.0
	 * @param string $range Date range.
	 * @return string Start date in Y-m-d format
	 */
	private function map_date_range_start( $range ) {
		$days_ago = 28;

		switch ( $range ) {
			case 'last_7_days':
				$days_ago = 7;
				break;
			case 'last_28_days':
				$days_ago = 28;
				break;
			case 'last_90_days':
				$days_ago = 90;
				break;
		}

		return gmdate( 'Y-m-d', strtotime( "-{$days_ago} days" ) );
	}

	/**
	 * Format search console response for AI
	 *
	 * @since 1.2.0
	 * @param array  $response   Site Kit API response.
	 * @param string $dimension  Grouping dimension.
	 * @param string $date_range Date range.
	 * @param string $url        Optional URL filter.
	 * @param int    $limit      Result limit.
	 * @return array Formatted response
	 */
	private function format_search_console_response( $response, $dimension, $date_range, $url = null, $limit = 10 ) {
		$formatted = array(
			'success'    => true,
			'dimension'  => $dimension,
			'date_range' => $date_range,
			'limit'      => $limit,
		);

		if ( $url ) {
			$formatted['filtered_by_url'] = $url;
		}

		// Extract and format the data.
		if ( isset( $response['rows'] ) && is_array( $response['rows'] ) ) {
			$formatted['results'] = array();

			foreach ( $response['rows'] as $row ) {
				$formatted_row = array();

				// Add dimension value (query, page, country, or device).
				if ( isset( $row['keys'][0] ) ) {
					$formatted_row[ $dimension ] = $row['keys'][0];
				}

				// Add metrics.
				$formatted_row['clicks']      = isset( $row['clicks'] ) ? intval( $row['clicks'] ) : 0;
				$formatted_row['impressions'] = isset( $row['impressions'] ) ? intval( $row['impressions'] ) : 0;
				$formatted_row['ctr']         = isset( $row['ctr'] ) ? round( $row['ctr'] * 100, 2 ) . '%' : '0%';
				$formatted_row['position']    = isset( $row['position'] ) ? round( $row['position'], 1 ) : 0;

				$formatted['results'][] = $formatted_row;
			}

			$formatted['total_results'] = count( $formatted['results'] );
		} else {
			$formatted['results']       = array();
			$formatted['total_results'] = 0;
		}

		// Add summary.
		$formatted['summary'] = $this->generate_summary( $formatted['results'], $dimension, $date_range );

		return $formatted;
	}

	/**
	 * Generate human-readable summary
	 *
	 * @since 1.2.0
	 * @param array  $results    Search Console results.
	 * @param string $dimension  Grouping dimension.
	 * @param string $date_range Date range.
	 * @return string Summary text
	 */
	private function generate_summary( $results, $dimension, $date_range ) {
		if ( empty( $results ) ) {
			return sprintf(
				/* translators: 1: dimension name, 2: date range */
				__( 'No Search Console data found for %1$s in the %2$s', 'mcp-ai-wpoos' ),
				$dimension,
				str_replace( '_', ' ', $date_range )
			);
		}

		$total_clicks      = array_sum( wp_list_pluck( $results, 'clicks' ) );
		$total_impressions = array_sum( wp_list_pluck( $results, 'impressions' ) );

		return sprintf(
			/* translators: 1: total results, 2: dimension name, 3: date range, 4: total clicks, 5: total impressions */
			__( 'Found %1$d %2$s in the %3$s with %4$d total clicks and %5$d total impressions', 'mcp-ai-wpoos' ),
			count( $results ),
			$dimension === 'query' ? __( 'queries', 'mcp-ai-wpoos' ) : $dimension . 's',
			str_replace( '_', ' ', $date_range ),
			$total_clicks,
			$total_impressions
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.2.0
	 * @return int Capability flags.
	 */
	public function get_capability_flags() {
		return WP_MCP_AI_Tool_Capability_Flags_Interface::CAPABILITY_CAN_USE_IF_ADMIN;
	}

/**
 * Get extended tool definition including toolkit metadata.
 *
 * @since 1.1.0
 *
 * @return array Tool definition with metadata.
 */
public function get_definition() {
	return array(
		'name'                  => $this->get_name(),
		'description'           => $this->get_description(),
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
		'profession_tags'       => array( 'seo_specialist', 'marketing_manager' ),
		'risk_level'            => 'info',
	);
}

}
