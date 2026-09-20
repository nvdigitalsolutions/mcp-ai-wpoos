<?php
/**
 * Options Chain Fetcher Tool
 *
 * Fetches an options chain (calls and puts with strike, bid/ask, volume,
 * open interest, ITM flag) from the keyless Nasdaq public API. Inspired by
 * the OpenTerminal options-chain widget.
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
 * Tool for fetching options chains.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Tool_Options_Chain_Fetcher implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

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

		return __( 'Options chain fetcher tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'options_chain_fetcher';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Options Chain Fetcher', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Fetch an options chain from keyless public market data: calls and puts with strike, bid/ask, volume, open interest, and in-the-money flags, filterable by expiration. EDUCATIONAL ONLY - Data may be delayed. Not investment advice.', 'mcp-ai-wpoos-pro' );
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
			'when_to_use'     => __( 'Pulling calls and puts with strike, bid/ask, volume, and open interest for one ticker, filterable by expiration.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Underlying stock quotes; use stock_data_fetcher. Broad market scans belong to market_screener.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'stock_data_fetcher', 'market_screener', 'price_alerts' ),
			'notes'           => __( 'Requires ticker; expiration accepts YYYY-MM-DD and limit caps rows per side. Keyless public data, may be delayed.', 'mcp-ai-wpoos-pro' ),
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
				'ticker'     => array(
					'type'        => 'string',
					'description' => __( 'Ticker symbol for the options chain.', 'mcp-ai-wpoos-pro' ),
				),
				'expiration' => array(
					'type'        => 'string',
					'description' => __( 'Filter to a single expiration (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'limit'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum rows per side (1-200).', 'mcp-ai-wpoos-pro' ),
					'default'     => 60,
				),
			),
			'required'   => array( 'ticker' ),
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
				__( 'You do not have permission to fetch options chains.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$ticker     = isset( $arguments['ticker'] ) ? strtoupper( sanitize_text_field( $arguments['ticker'] ) ) : '';
		$expiration = isset( $arguments['expiration'] ) ? sanitize_text_field( $arguments['expiration'] ) : '';
		$limit      = isset( $arguments['limit'] ) ? min( max( absint( $arguments['limit'] ), 1 ), 200 ) : 60;

		if ( empty( $ticker ) ) {
			return new WP_Error(
				'missing_ticker',
				__( 'Ticker symbol is required for the options chain.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( '' !== $expiration && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expiration ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_date',
				__( 'Expiration must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' )
			);
		}

		$cache_key = 'wp_mcp_ai_options_' . md5( wp_json_encode( array( $ticker, $expiration, $limit ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$providers = $this->get_providers();
		if ( is_wp_error( $providers ) ) {
			return $providers;
		}

		$result = $providers->nasdaq_get_options_chain( $ticker, $limit );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$chain = $result['chain'];

		if ( '' !== $expiration ) {
			foreach ( array( 'calls', 'puts' ) as $side ) {
				$chain[ $side ] = array_values(
					array_filter(
						$chain[ $side ],
						function ( $option ) use ( $expiration ) {
							return 0 === strpos( (string) $option['expiration'], $expiration );
						}
					)
				);
			}
		}

		$envelope = array(
			'success'     => true,
			'ticker'      => $ticker,
			'expiration'  => $expiration,
			'calls_count' => count( $chain['calls'] ),
			'puts_count'  => count( $chain['puts'] ),
			'calls'       => $chain['calls'],
			'puts'        => $chain['puts'],
			'source'      => $result['source'],
			'from_cache'  => false,
			'disclaimer'  => __( 'EDUCATIONAL ONLY. Options data comes from public endpoints, may be delayed, and is not execution-grade. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);

		set_transient( $cache_key, $envelope, self::CACHE_TTL );

		return $envelope;
	}
}
