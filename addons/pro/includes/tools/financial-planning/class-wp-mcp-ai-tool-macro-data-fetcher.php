<?php
/**
 * Macro Data Fetcher Tool
 *
 * Fetches macro market data — Treasury yield curve, VIX, fed funds rate,
 * CPI, unemployment, major index proxies — from FRED via the keyless
 * fredgraph.csv endpoint. Inspired by the OpenTerminal macro dashboard.
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
 * Tool for fetching macro market data from FRED.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Tool_Macro_Data_Fetcher implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Cache TTL in seconds.
	 */
	const CACHE_TTL = 3600;

	/**
	 * FRED series allowlist (mirrors WP_MCP_AI_Market_Data_Providers).
	 */
	const FRED_SERIES = array(
		'DGS10',
		'DGS2',
		'DGS30',
		'DGS3MO',
		'T10Y2Y',
		'VIXCLS',
		'DFF',
		'CPIAUCSL',
		'UNRATE',
		'SP500',
		'DJIA',
		'NASDAQCOM',
	);

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

		return __( 'Macro data fetcher tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'macro_data_fetcher';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Macro Data Fetcher', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Fetch macro market data from FRED without an API key: Treasury yields (2Y/10Y/30Y), yield-curve spread, VIX, fed funds rate, CPI, unemployment, and major index proxies. EDUCATIONAL ONLY - Data may be delayed. Not investment advice.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the usage guidance.
	 *
	 * @since 1.1.83
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Fetching Treasury yields, yield-curve spread, VIX, fed funds rate, CPI, and unemployment from FRED.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Company quotes or options; use stock_data_fetcher or options_chain_fetcher. Events belong to economic_calendar_fetcher.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'economic_calendar_fetcher', 'stock_data_fetcher', 'market_screener' ),
			'notes'           => __( 'Keyless public FRED endpoint; only allowlisted series IDs are accepted and results are cached for one hour.', 'mcp-ai-wpoos-pro' ),
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
				'series' => array(
					'type'        => 'array',
					'description' => __( 'FRED series IDs to fetch (allowlisted).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => self::FRED_SERIES,
					),
					'default'     => array( 'DGS10', 'DGS2', 'T10Y2Y', 'VIXCLS', 'DFF' ),
				),
				'limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of observations (newest first, 10-500).', 'mcp-ai-wpoos-pro' ),
					'default'     => 250,
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
				__( 'You do not have permission to fetch macro data.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$series = isset( $arguments['series'] ) && is_array( $arguments['series'] )
			? array_map( 'sanitize_text_field', $arguments['series'] )
			: array( 'DGS10', 'DGS2', 'T10Y2Y', 'VIXCLS', 'DFF' );
		$limit  = isset( $arguments['limit'] ) ? min( max( absint( $arguments['limit'] ), 10 ), 500 ) : 250;

		$cache_key = 'wp_mcp_ai_macro_' . md5( wp_json_encode( array( $series, $limit ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$providers = $this->get_providers();
		if ( is_wp_error( $providers ) ) {
			return $providers;
		}

		$result = $providers->fred_get_series( $series, $limit );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Build a compact latest-values summary per series.
		$latest = array();
		foreach ( $result['observations'] as $observation ) {
			foreach ( $result['series'] as $series_id ) {
				if ( isset( $observation[ $series_id ] ) && null !== $observation[ $series_id ] && ! isset( $latest[ $series_id ] ) ) {
					$latest[ $series_id ] = array(
						'value' => (float) $observation[ $series_id ],
						'date'  => $observation['date'],
					);
				}
			}
		}

		$envelope = array(
			'success'      => true,
			'series'       => $result['series'],
			'latest'       => $latest,
			'count'        => $result['count'],
			'observations' => $result['observations'],
			'source'       => $result['source'],
			'from_cache'   => false,
			'disclaimer'   => __( 'EDUCATIONAL ONLY. Macro data comes from public FRED endpoints and may be delayed or revised. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);

		set_transient( $cache_key, $envelope, self::CACHE_TTL );

		return $envelope;
	}
}
