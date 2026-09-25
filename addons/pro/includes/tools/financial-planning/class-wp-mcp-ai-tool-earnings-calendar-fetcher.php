<?php
/**
 * Earnings Calendar Fetcher Tool
 *
 * Fetches the earnings calendar (EPS forecast, market cap, timing) from the
 * keyless Nasdaq public API. Inspired by the OpenTerminal earnings calendar
 * widget with forecast-vs-actual history.
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
 * Tool for fetching the earnings calendar.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Tool_Earnings_Calendar_Fetcher implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Cache TTL in seconds.
	 */
	const CACHE_TTL = 3600;

	/**
	 * Maximum dates scanned when days_ahead is requested.
	 */
	const MAX_DATES = 7;

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

		return __( 'Earnings calendar fetcher tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'earnings_calendar_fetcher';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Earnings Calendar Fetcher', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Fetch the earnings calendar (company, EPS forecast, market cap, reporting timing) from keyless public market data. Filter by date or symbol. EDUCATIONAL ONLY - Data may be delayed. Not investment advice.', 'mcp-ai-wpoos-pro' );
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
			'when_to_use'     => __( 'To list upcoming company earnings reports with EPS forecasts and reporting timing.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'For macro events like CPI or Fed meetings; use economic_calendar_fetcher instead.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'economic_calendar_fetcher', 'stock_data_fetcher', 'financial_news_aggregator' ),
			'notes'           => __( 'Uses a keyless public Nasdaq endpoint. date must be YYYY-MM-DD; days_ahead scans up to 7 days.', 'mcp-ai-wpoos-pro' ),
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
				'date'       => array(
					'type'        => 'string',
					'description' => __( 'Earnings date in YYYY-MM-DD format (default: today).', 'mcp-ai-wpoos-pro' ),
				),
				'days_ahead' => array(
					'type'        => 'integer',
					'description' => __( 'Scan this many days ahead instead of a single date (1-7).', 'mcp-ai-wpoos-pro' ),
				),
				'symbol'     => array(
					'type'        => 'string',
					'description' => __( 'Filter results to a single ticker symbol.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'      => array(
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
				__( 'You do not have permission to fetch the earnings calendar.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$symbol     = isset( $arguments['symbol'] ) ? strtoupper( sanitize_text_field( $arguments['symbol'] ) ) : '';
		$date       = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : gmdate( 'Y-m-d' );
		$days_ahead = isset( $arguments['days_ahead'] ) ? min( max( absint( $arguments['days_ahead'] ), 1 ), self::MAX_DATES ) : 0;
		$limit      = isset( $arguments['limit'] ) ? min( max( absint( $arguments['limit'] ), 1 ), 100 ) : 50;

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_date',
				__( 'Date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' )
			);
		}

		$cache_key = 'wp_mcp_ai_earncal_' . md5( wp_json_encode( array( $symbol, $date, $days_ahead, $limit ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$providers = $this->get_providers();
		if ( is_wp_error( $providers ) ) {
			return $providers;
		}

		// Build the date list.
		$dates = array( $date );
		if ( $days_ahead > 1 ) {
			for ( $i = 1; $i < $days_ahead; $i++ ) {
				$dates[] = gmdate( 'Y-m-d', strtotime( $date . ' +' . $i . ' days' ) );
			}
		}

		$events = array();
		$errors = array();

		foreach ( $dates as $scan_date ) {
			$result = $providers->nasdaq_get_earnings_calendar( $scan_date );
			if ( is_wp_error( $result ) ) {
				$errors[ $scan_date ] = $result->get_error_message();
				continue;
			}
			$events = array_merge( $events, $result['events'] );
		}

		if ( empty( $events ) && ! empty( $errors ) ) {
			return new WP_Error(
				'wp_mcp_ai_market_data_fetch_failed',
				/* translators: %s: provider error summary */
				sprintf( __( 'Failed to fetch the earnings calendar: %s', 'mcp-ai-wpoos-pro' ), implode( ' | ', $errors ) )
			);
		}

		if ( '' !== $symbol ) {
			$events = array_values(
				array_filter(
					$events,
					function ( $event ) use ( $symbol ) {
						return $symbol === $event['symbol'];
					}
				)
			);
		}

		$events = array_slice( $events, 0, $limit );

		$envelope = array(
			'success'    => true,
			'count'      => count( $events ),
			'events'     => $events,
			'dates'      => $dates,
			'errors'     => $errors,
			'symbol'     => $symbol,
			'from_cache' => false,
			'disclaimer' => __( 'EDUCATIONAL ONLY. Earnings data comes from public endpoints and may be delayed or incomplete. Forecasts are consensus estimates. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);

		set_transient( $cache_key, $envelope, self::CACHE_TTL );

		return $envelope;
	}
}
