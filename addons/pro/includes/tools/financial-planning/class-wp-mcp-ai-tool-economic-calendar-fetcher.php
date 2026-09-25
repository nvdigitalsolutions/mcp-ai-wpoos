<?php
/**
 * Economic Calendar Fetcher Tool
 *
 * Fetches the economic events calendar (Fed, ECB, CPI, NFP, ...) with
 * consensus forecast and previous reading from the keyless Forex Factory
 * public feed. Inspired by the OpenTerminal economic calendar widget.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.80
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for fetching the economic events calendar.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Tool_Economic_Calendar_Fetcher implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Cache TTL in seconds.
	 */
	const CACHE_TTL = 1800;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.80
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Economic calendar fetcher tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'economic_calendar_fetcher';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Economic Calendar Fetcher', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Fetch the economic events calendar (Fed, ECB, CPI, NFP and more) with consensus forecast and previous reading from keyless public feeds. Filter by country, currency, impact, and date. EDUCATIONAL ONLY - Data may be delayed. Not investment advice.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for this tool.
	 *
	 * @since 1.1.80
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'To list economic events (CPI, NFP, Fed, ECB) with forecast and previous readings.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'For company earnings dates; use earnings_calendar_fetcher instead.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'earnings_calendar_fetcher', 'macro_data_fetcher', 'financial_news_aggregator' ),
			'notes'           => __( 'Uses the keyless Forex Factory public feed. Filter by currency, country, impact, or date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get the parameters schema.
	 *
	 * @since 1.1.80
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'currency' => array(
					'type'        => 'string',
					'description' => __( 'Filter by currency code (e.g. "USD", "EUR").', 'mcp-ai-wpoos-pro' ),
				),
				'country'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by country name fragment (e.g. "United States").', 'mcp-ai-wpoos-pro' ),
				),
				'impact'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by impact level.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'High', 'Medium', 'Low', 'Holiday' ),
				),
				'date'     => array(
					'type'        => 'string',
					'description' => __( 'Only include events on this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'limit'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of events to return.', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
				),
			),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.1.80
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'computation',
			'external-api',
			'cacheable',
			'network-dependent',
		);
	}

	/**
	 * Get the market data providers instance.
	 *
	 * @since 1.1.80
	 *
	 * @return WP_MCP_AI_Market_Data_Providers|WP_Error
	 */
	private function get_providers() {
		if ( ! file_exists( WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-market-data-providers.php' ) ) {
			return new WP_Error(
				'market_data_providers_not_found',
				__( 'Market data providers are not installed. Please ensure the pro addon is properly configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Market_Data_Providers' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-market-data-providers.php';
		}

		return WP_MCP_AI_Market_Data_Providers::get_instance();
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.1.80
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to fetch the economic calendar.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$currency = isset( $arguments['currency'] ) ? strtoupper( sanitize_text_field( $arguments['currency'] ) ) : '';
		$country  = isset( $arguments['country'] ) ? sanitize_text_field( $arguments['country'] ) : '';
		$impact   = isset( $arguments['impact'] ) ? sanitize_text_field( $arguments['impact'] ) : '';
		$date     = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : '';
		$limit    = isset( $arguments['limit'] ) ? min( max( absint( $arguments['limit'] ), 1 ), 100 ) : 50;

		if ( '' !== $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_date',
				__( 'Date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' )
			);
		}

		$cache_key = 'wp_mcp_ai_econcal_' . md5( wp_json_encode( array( $currency, $country, $impact, $date, $limit ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$providers = $this->get_providers();
		if ( is_wp_error( $providers ) ) {
			return $providers;
		}

		$result = $providers->forex_factory_get_calendar();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$events = $result['events'];

		// Apply filters.
		if ( '' !== $currency ) {
			$events = array_values(
				array_filter(
					$events,
					function ( $event ) use ( $currency ) {
						return false !== stripos( $event['country'], $currency ) || false !== stripos( $event['title'], $currency );
					}
				)
			);
		}

		if ( '' !== $country ) {
			$events = array_values(
				array_filter(
					$events,
					function ( $event ) use ( $country ) {
						return false !== stripos( $event['country'], $country );
					}
				)
			);
		}

		if ( '' !== $impact ) {
			$events = array_values(
				array_filter(
					$events,
					function ( $event ) use ( $impact ) {
						return $impact === $event['impact'];
					}
				)
			);
		}

		if ( '' !== $date ) {
			$events = array_values(
				array_filter(
					$events,
					function ( $event ) use ( $date ) {
						return 0 === strpos( (string) $event['date'], $date );
					}
				)
			);
		}

		$events = array_slice( $events, 0, $limit );

		$envelope = array(
			'success'    => true,
			'count'      => count( $events ),
			'events'     => $events,
			'filters'    => array(
				'currency' => $currency,
				'country'  => $country,
				'impact'   => $impact,
				'date'     => $date,
			),
			'source'     => $result['source'],
			'from_cache' => false,
			'disclaimer' => __( 'EDUCATIONAL ONLY. Calendar data comes from public feeds and may be delayed or change. Actual releases may differ from forecasts. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);

		set_transient( $cache_key, $envelope, self::CACHE_TTL );

		return $envelope;
	}
}
