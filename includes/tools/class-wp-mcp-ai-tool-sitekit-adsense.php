<?php
/**
 * Site Kit AdSense Tool
 *
 * Provides access to Google AdSense data through Site Kit.
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
 * Site Kit AdSense Tool Class
 *
 * Retrieves Google AdSense earnings and performance data
 * through Site Kit integration.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_SiteKit_AdSense implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'Google Site Kit plugin must be installed and configured to use AdSense tools.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool slug
	 *
	 * @since 1.2.0
	 * @return string Tool slug
	 */
	public function get_slug() {
		return 'sitekit_get_adsense';
	}

	/**
	 * Get tool name
	 *
	 * @since 1.2.0
	 * @return string Tool name
	 */
	public function get_name() {
		return __( 'Get AdSense Data', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool description
	 *
	 * @since 1.2.0
	 * @return string Tool description
	 */
	public function get_description() {
		return __( 'Retrieve Google AdSense earnings and performance metrics including revenue, impressions, clicks, CTR, and RPM. Helps monitor site monetization performance.', 'mcp-ai-wpoos' );
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
				'metric'     => array(
					'type'        => 'string',
					'description' => __( 'Metric to retrieve', 'mcp-ai-wpoos' ),
					'enum'        => array( 'earnings', 'impressions', 'clicks', 'ctr', 'rpm', 'all' ),
					'default'     => 'all',
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
				__( 'You do not have permission to access AdSense data', 'mcp-ai-wpoos' )
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
		$metric     = isset( $arguments['metric'] ) ? sanitize_text_field( $arguments['metric'] ) : 'all';

		// Build Site Kit API request.
		$endpoint = '/wp-json/google-site-kit/v1/modules/adsense/data/report';
		$api_args = array(
			'startDate' => $this->map_date_range_start( $date_range ),
			'endDate'   => gmdate( 'Y-m-d' ),
			'metrics'   => $this->get_requested_metrics( $metric ),
		);

		// Make request to Site Kit.
		$response = $sitekit->make_sitekit_request( $endpoint, $api_args );

		// Handle errors.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Format response for AI assistant.
		return $this->format_adsense_response( $response, $metric, $date_range );
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
	 * Get requested metrics array
	 *
	 * @since 1.2.0
	 * @param string $metric Requested metric.
	 * @return array Metrics array
	 */
	private function get_requested_metrics( $metric ) {
		if ( 'all' === $metric ) {
			return array(
				'EARNINGS',
				'PAGE_VIEWS_RPM',
				'IMPRESSIONS',
				'CLICKS',
				'PAGE_VIEWS_CTR',
			);
		}

		$metric_map = array(
			'earnings'    => array( 'EARNINGS' ),
			'impressions' => array( 'IMPRESSIONS' ),
			'clicks'      => array( 'CLICKS' ),
			'ctr'         => array( 'PAGE_VIEWS_CTR' ),
			'rpm'         => array( 'PAGE_VIEWS_RPM' ),
		);

		return isset( $metric_map[ $metric ] ) ? $metric_map[ $metric ] : array( 'EARNINGS' );
	}

	/**
	 * Format AdSense response for AI
	 *
	 * @since 1.2.0
	 * @param array  $response   Site Kit API response.
	 * @param string $metric     Requested metric.
	 * @param string $date_range Date range.
	 * @return array Formatted response
	 */
	private function format_adsense_response( $response, $metric, $date_range ) {
		$formatted = array(
			'success'    => true,
			'metric'     => $metric,
			'date_range' => $date_range,
			'data'       => array(),
		);

		// Extract totals from response.
		if ( isset( $response['totals'] ) && is_array( $response['totals'] ) ) {
			$totals = $response['totals'];

			if ( isset( $totals['EARNINGS'] ) ) {
				$formatted['data']['earnings'] = array(
					'value'     => floatval( $totals['EARNINGS'] ),
					'currency'  => isset( $response['currency'] ) ? $response['currency'] : 'USD',
					'formatted' => $this->format_currency( floatval( $totals['EARNINGS'] ), isset( $response['currency'] ) ? $response['currency'] : 'USD' ),
				);
			}

			if ( isset( $totals['IMPRESSIONS'] ) ) {
				$formatted['data']['impressions'] = intval( $totals['IMPRESSIONS'] );
			}

			if ( isset( $totals['CLICKS'] ) ) {
				$formatted['data']['clicks'] = intval( $totals['CLICKS'] );
			}

			if ( isset( $totals['PAGE_VIEWS_CTR'] ) ) {
				$formatted['data']['ctr'] = round( floatval( $totals['PAGE_VIEWS_CTR'] ), 2 ) . '%';
			}

			if ( isset( $totals['PAGE_VIEWS_RPM'] ) ) {
				$formatted['data']['rpm'] = array(
					'value'     => floatval( $totals['PAGE_VIEWS_RPM'] ),
					'currency'  => isset( $response['currency'] ) ? $response['currency'] : 'USD',
					'formatted' => $this->format_currency( floatval( $totals['PAGE_VIEWS_RPM'] ), isset( $response['currency'] ) ? $response['currency'] : 'USD' ),
				);
			}
		}

		// Add summary.
		$formatted['summary'] = $this->generate_summary( $formatted['data'], $date_range );

		return $formatted;
	}

	/**
	 * Format currency value
	 *
	 * @since 1.2.0
	 * @param float  $value    Currency value.
	 * @param string $currency Currency code.
	 * @return string Formatted currency
	 */
	private function format_currency( $value, $currency ) {
		$symbols = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'JPY' => '¥',
		);

		$symbol = isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : $currency . ' ';

		return $symbol . number_format( $value, 2 );
	}

	/**
	 * Generate human-readable summary
	 *
	 * @since 1.2.0
	 * @param array  $data       AdSense data.
	 * @param string $date_range Date range.
	 * @return string Summary text
	 */
	private function generate_summary( $data, $date_range ) {
		if ( empty( $data ) ) {
			return sprintf(
				/* translators: %s: date range */
				__( 'No AdSense data found for the %s', 'mcp-ai-wpoos' ),
				str_replace( '_', ' ', $date_range )
			);
		}

		$parts = array();

		if ( isset( $data['earnings']['formatted'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: formatted earnings */
				__( 'Earnings: %s', 'mcp-ai-wpoos' ),
				$data['earnings']['formatted']
			);
		}

		if ( isset( $data['impressions'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: number of impressions */
				__( 'Impressions: %s', 'mcp-ai-wpoos' ),
				number_format( $data['impressions'] )
			);
		}

		if ( isset( $data['clicks'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: number of clicks */
				__( 'Clicks: %s', 'mcp-ai-wpoos' ),
				number_format( $data['clicks'] )
			);
		}

		if ( empty( $parts ) ) {
			return __( 'AdSense data retrieved successfully', 'mcp-ai-wpoos' );
		}

		return sprintf(
			/* translators: 1: date range, 2: metrics summary */
			__( 'AdSense performance for %1$s: %2$s', 'mcp-ai-wpoos' ),
			str_replace( '_', ' ', $date_range ),
			implode( ', ', $parts )
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
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'marketing_manager' ),
			'risk_level'            => 'info',
		);
	}
}
