<?php
/**
 * Market Screener Tool
 *
 * Screens the whole US equity market (sector, market cap, % change, volume)
 * via the keyless TradingView scanner API. Inspired by the OpenTerminal
 * full-market screener widget.
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
 * Tool for screening the US equity market.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Tool_Market_Screener implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Cache TTL in seconds.
	 */
	const CACHE_TTL = 900;

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

		return __( 'Market screener tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'market_screener';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Market Screener', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Screen the entire US equity market by sector, market cap, daily % change, and volume using keyless public market data. Returns a sortable result set. EDUCATIONAL ONLY - Data may be delayed. Not investment advice.', 'mcp-ai-wpoos-pro' );
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
			'when_to_use'     => __( 'Scanning the whole US equity market by sector, market cap, daily change, and volume to shortlist candidates.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Quote or history for one known ticker; use stock_data_fetcher. Macro conditions belong to macro_data_fetcher.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'stock_data_fetcher', 'macro_data_fetcher', 'financial_news_aggregator' ),
			'notes'           => __( 'Keyless public endpoint with a 15-minute cache; negative change_min finds losers and limit caps the result count.', 'mcp-ai-wpoos-pro' ),
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
				'sector'         => array(
					'type'        => 'string',
					'description' => __( 'Sector filter (e.g. "Technology", "Finance", "Health Technology").', 'mcp-ai-wpoos-pro' ),
				),
				'market_cap_min' => array(
					'type'        => 'number',
					'description' => __( 'Minimum market capitalization in USD.', 'mcp-ai-wpoos-pro' ),
				),
				'change_min'     => array(
					'type'        => 'number',
					'description' => __( 'Minimum daily % change (use negative values to find losers).', 'mcp-ai-wpoos-pro' ),
				),
				'volume_min'     => array(
					'type'        => 'number',
					'description' => __( 'Minimum daily volume.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'          => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results (10-500).', 'mcp-ai-wpoos-pro' ),
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
				__( 'You do not have permission to use the market screener.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$filters = array(
			'sector'         => isset( $arguments['sector'] ) ? sanitize_text_field( $arguments['sector'] ) : '',
			'market_cap_min' => isset( $arguments['market_cap_min'] ) ? (float) $arguments['market_cap_min'] : 0.0,
			'change_min'     => isset( $arguments['change_min'] ) ? (float) $arguments['change_min'] : 0.0,
			'volume_min'     => isset( $arguments['volume_min'] ) ? (float) $arguments['volume_min'] : 0.0,
		);
		$limit   = isset( $arguments['limit'] ) ? min( max( absint( $arguments['limit'] ), 10 ), 500 ) : 50;

		$cache_key = 'wp_mcp_ai_screener_' . md5( wp_json_encode( array( $filters, $limit ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$providers = $this->get_providers();
		if ( is_wp_error( $providers ) ) {
			return $providers;
		}

		$result = $providers->tradingview_screen_stocks( $filters, $limit );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$envelope = array(
			'success'    => true,
			'count'      => $result['count'],
			'results'    => $result['results'],
			'filters'    => $filters,
			'source'     => $result['source'],
			'from_cache' => false,
			'disclaimer' => __( 'EDUCATIONAL ONLY. Screener results come from public endpoints and may be delayed or incomplete. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);

		set_transient( $cache_key, $envelope, self::CACHE_TTL );

		return $envelope;
	}
}
