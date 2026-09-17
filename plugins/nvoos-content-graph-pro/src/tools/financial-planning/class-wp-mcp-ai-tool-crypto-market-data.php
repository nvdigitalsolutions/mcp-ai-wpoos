<?php
/**
 * Financial toolkit ecosystem port (OpenTerminal lessons sub-cluster).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/financial-planning/class-wp-mcp-ai-tool-crypto-market-data.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base
 * Pro addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `WP_MCP_AI_PRO_PATH` is defined.
 *
 * What this file is: CoinGecko/Binance crypto board, quotes, and history.
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `mcp-ai-wpoos-pro` → `nvoos-content-graph-pro`; `WP_MCP_AI_PRO_PATH .
 * 'includes/'` path swaps → `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/'`.
 *
 * @package NvoosContentGraphPro
 * @since   1.1.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for fetching crypto market data.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Tool_Crypto_Market_Data implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'nvoos-content-graph-pro' );
		}

		return __( 'Crypto market data tool is not available.', 'nvoos-content-graph-pro' );
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
		return __( 'Crypto Market Data', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Fetch the crypto market board, per-symbol quotes, or OHLC history from keyless public endpoints (CoinGecko with Binance fallback). EDUCATIONAL ONLY - Data may be delayed. Not investment advice.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'The action to perform.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'board', 'quote', 'history' ),
				),
				'symbols'  => array(
					'type'        => 'array',
					'description' => __( 'Crypto symbols (BTC, ETH, SOL, ...). Required for "quote"; optional filter for "board".', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'currency' => array(
					'type'        => 'string',
					'description' => __( 'Quote currency for board/history (e.g. usd, eur).', 'nvoos-content-graph-pro' ),
					'default'     => 'usd',
				),
				'period'   => array(
					'type'        => 'string',
					'description' => __( 'Period for history.', 'nvoos-content-graph-pro' ),
					'enum'        => array( '1mo', '3mo', '6mo', '1y', '2y', '5y', 'max' ),
					'default'     => '1mo',
				),
				'limit'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum assets/rows to return.', 'nvoos-content-graph-pro' ),
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
		if ( ! file_exists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-market-data-providers.php' ) ) {
			return new WP_Error(
				'market_data_providers_not_found',
				__( 'Market data providers are not installed. Please ensure the pro addon is properly configured.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Market_Data_Providers' ) ) {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-market-data-providers.php';
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
				__( 'You do not have permission to fetch crypto market data.', 'nvoos-content-graph-pro' )
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
				__( 'Invalid action. Must be one of: board, quote, history.', 'nvoos-content-graph-pro' )
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
			'disclaimer' => __( 'EDUCATIONAL ONLY. Crypto data comes from public endpoints and may be delayed. Not investment advice.', 'nvoos-content-graph-pro' ),
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
						__( 'At least one symbol is required for the "history" action.', 'nvoos-content-graph-pro' )
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
						__( 'At least one symbol is required for the "quote" action.', 'nvoos-content-graph-pro' )
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
