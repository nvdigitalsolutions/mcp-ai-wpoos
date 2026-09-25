<?php
/**
 * Crypto Market Data Tool
 *
 * Fetches the crypto board, per-symbol quotes, and OHLC history from keyless
 * public endpoints (CoinGecko with a Binance fallback chain). Inspired by
 * the OpenTerminal crypto board widget.
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
 * Tool for fetching crypto market data.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Tool_Crypto_Market_Data implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Cache TTL in seconds.
	 */
	const CACHE_TTL = 600;

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

		return __( 'Crypto market data tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'crypto_market_data';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Crypto Market Data', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Fetch the crypto market board, per-symbol quotes, or OHLC history from keyless public endpoints (CoinGecko with Binance fallback). EDUCATIONAL ONLY - Data may be delayed. Not investment advice.', 'mcp-ai-wpoos-pro' );
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
			'when_to_use'     => __( 'To fetch the crypto market board, per-symbol quotes, or OHLC history from keyless public endpoints.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'For stocks, ETFs, or economic events; use stock_data_fetcher or economic_calendar_fetcher instead.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'stock_data_fetcher', 'market_sentiment_analyzer', 'price_alerts' ),
			'notes'           => __( 'Action enum: board, quote, history. quote and history require symbols. Data is cached for 10 minutes and may be delayed.', 'mcp-ai-wpoos-pro' ),
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
				'action'   => array(
					'type'        => 'string',
					'description' => __( 'The action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'board', 'quote', 'history' ),
				),
				'symbols'  => array(
					'type'        => 'array',
					'description' => __( 'Crypto symbols (BTC, ETH, SOL, ...). Required for "quote"; optional filter for "board".', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'currency' => array(
					'type'        => 'string',
					'description' => __( 'Quote currency for board/history (e.g. usd, eur).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'usd',
				),
				'period'   => array(
					'type'        => 'string',
					'description' => __( 'Period for history.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( '1mo', '3mo', '6mo', '1y', '2y', '5y', 'max' ),
					'default'     => '1mo',
				),
				'limit'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum assets/rows to return.', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
				),
			),
			'required'   => array( 'action' ),
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
				__( 'You do not have permission to fetch crypto market data.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		$valid_actions = array( 'board', 'quote', 'history' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return new WP_Error(
				'invalid_action',
				__( 'Invalid action. Must be one of: board, quote, history.', 'mcp-ai-wpoos-pro' )
			);
		}

		$symbols  = isset( $arguments['symbols'] ) && is_array( $arguments['symbols'] )
			? array_map( 'sanitize_text_field', $arguments['symbols'] )
			: array();
		$currency = isset( $arguments['currency'] ) ? sanitize_text_field( $arguments['currency'] ) : 'usd';
		$period   = isset( $arguments['period'] ) ? sanitize_text_field( $arguments['period'] ) : '1mo';
		$limit    = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;

		$cache_key = 'wp_mcp_ai_crypto_' . md5( wp_json_encode( array( $action, $symbols, $currency, $period, $limit ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$providers = $this->get_providers();
		if ( is_wp_error( $providers ) ) {
			return $providers;
		}

		$envelope = array(
			'success'    => true,
			'action'     => $action,
			'from_cache' => false,
			'disclaimer' => __( 'EDUCATIONAL ONLY. Crypto data comes from public endpoints and may be delayed. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);

		switch ( $action ) {
			case 'board':
				$result = $providers->coingecko_get_markets( $currency, min( max( $limit, 10 ), 100 ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$assets = $result['assets'];

				// Optional symbol filter.
				if ( ! empty( $symbols ) ) {
					$wanted = array_map( 'strtoupper', $symbols );
					$assets = array_values(
						array_filter(
							$assets,
							function ( $asset ) use ( $wanted ) {
								return in_array( $asset['symbol'], $wanted, true );
							}
						)
					);
				}

				$envelope['count']    = count( $assets );
				$envelope['assets']   = $assets;
				$envelope['source']   = $result['source'];
				$envelope['currency'] = $result['currency'];
				break;

			case 'history':
				if ( empty( $symbols ) ) {
					return new WP_Error(
						'missing_symbols',
						__( 'At least one symbol is required for the "history" action.', 'mcp-ai-wpoos-pro' )
					);
				}

				$histories = array();
				$errors    = array();

				foreach ( array_slice( $symbols, 0, 10 ) as $symbol ) {
					$result = $providers->fetch_with_fallback(
						'crypto',
						array(
							'symbol'   => $symbol,
							'currency' => $currency,
							'period'   => $period,
						)
					);

					if ( is_wp_error( $result ) ) {
						$errors[ $symbol ] = $result->get_error_message();
						continue;
					}

					$histories[ strtoupper( $symbol ) ] = $result['data'];
				}

				$envelope['count']     = count( $histories );
				$envelope['histories'] = $histories;
				$envelope['errors']    = $errors;
				break;

			case 'quote':
				if ( empty( $symbols ) ) {
					return new WP_Error(
						'missing_symbols',
						__( 'At least one symbol is required for the "quote" action.', 'mcp-ai-wpoos-pro' )
					);
				}

				$quotes = array();
				$errors = array();

				foreach ( array_slice( $symbols, 0, 10 ) as $symbol ) {
					$result = $providers->fetch_with_fallback(
						'crypto',
						array(
							'symbol'   => $symbol,
							'currency' => $currency,
							'period'   => '5d',
						)
					);

					if ( is_wp_error( $result ) ) {
						$errors[ $symbol ] = $result->get_error_message();
						continue;
					}

					$rows  = isset( $result['data']['data'] ) ? $result['data']['data'] : array();
					$last  = end( $rows );
					$first = reset( $rows );

					$quotes[ strtoupper( $symbol ) ] = array(
						'symbol'        => strtoupper( $symbol ),
						'current_price' => is_array( $last ) && isset( $last['close'] ) ? (float) $last['close'] : null,
						'open'          => is_array( $first ) && isset( $first['open'] ) ? (float) $first['open'] : null,
						'date'          => is_array( $last ) && isset( $last['date'] ) ? $last['date'] : '',
						'currency'      => isset( $result['data']['currency'] ) ? $result['data']['currency'] : $currency,
						'source'        => $result['source'],
					);
				}

				$envelope['count']  = count( $quotes );
				$envelope['quotes'] = $quotes;
				$envelope['errors'] = $errors;
				break;
		}

		set_transient( $cache_key, $envelope, self::CACHE_TTL );

		return $envelope;
	}
}
