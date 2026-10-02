<?php
/**
 * Watchlist Sync Tool
 *
 * Per-user watchlist with unique-symbol constraint and bulk-quote actions.
 * Mirrors the watchlist model of the open-source OpenStock project
 * (per-user, unique symbol per user) as capability parity — no OpenStock
 * code is used; storage is WordPress user meta.
 *
 * @link    https://github.com/Open-Dev-Society/OpenStock (feature-parity reference)
 * @package WP_MCP_AI_Pro
 * @since 1.1.90
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for managing a per-user market watchlist.
 *
 * @since 1.1.90
 */
class WP_MCP_AI_Tool_Watchlist_Sync implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * User-meta key for the watchlist.
	 */
	const META_KEY = 'wp_mcp_ai_fin_watchlist';

	/**
	 * Maximum symbols per watchlist.
	 */
	const MAX_SYMBOLS = 100;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.90
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
	 * @since 1.1.90
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Watchlist sync tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.90
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'watchlist_sync';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.90
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Watchlist Sync', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.90
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Manage a per-user market watchlist: add, remove, and list symbols, or fetch current quotes for the whole list. Unique symbol per user. EDUCATIONAL ONLY - Data may be delayed. Not investment advice.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the usage guidance.
	 *
	 * @since 1.1.90
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Maintaining a user watchlist and getting a quick quote rollup for every symbol on it.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Deep quote/history for one symbol; use stock_data_fetcher. Alerts on thresholds belong to price_alerts.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'stock_data_fetcher', 'price_alerts', 'market_overview_widget', 'portfolio_transaction_log' ),
			'notes'           => __( 'action is required (list, add, remove, bulk_quote). Symbols are validated and de-duplicated; max 100 per user.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get the parameters schema.
	 *
	 * @since 1.1.90
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'  => array(
					'type'        => 'string',
					'description' => __( 'The action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list', 'add', 'remove', 'bulk_quote' ),
				),
				'symbols' => array(
					'type'        => 'array',
					'description' => __( 'Ticker symbols for the add or remove action (e.g. ["AAPL", "MSFT"]).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'symbol'  => array(
					'type'        => 'string',
					'description' => __( 'Single ticker symbol for the add or remove action.', 'mcp-ai-wpoos-pro' ),
				),
				'user_id' => array(
					'type'        => 'integer',
					'description' => __( 'Optional target user ID. Defaults to the current user. Requires user-editing capability when targeting another user.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.1.90
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'database-write',
			'external-api',
		);
	}

	/**
	 * Validate a ticker symbol.
	 *
	 * @since 1.1.90
	 *
	 * @param string $symbol Symbol.
	 * @return string|false Uppercased symbol or false.
	 */
	private function validate_symbol( $symbol ) {
		$symbol = strtoupper( trim( sanitize_text_field( $symbol ) ) );

		if ( '' === $symbol || ! preg_match( '/^[A-Z0-9.\-^=\/]{1,15}$/', $symbol ) ) {
			return false;
		}

		return $symbol;
	}

	/**
	 * Resolve the target user ID for a request.
	 *
	 * @since 1.1.90
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return int|WP_Error User ID or error.
	 */
	private function resolve_user_id( $arguments, $context ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be logged in to manage a watchlist.', 'mcp-ai-wpoos-pro' )
			);
		}

		$target_user_id = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : 0;

		if ( $target_user_id && $target_user_id !== $current_user_id ) {
			if ( ! user_can( $current_user_id, 'edit_users' ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to manage another user watchlist.', 'mcp-ai-wpoos-pro' )
				);
			}
			if ( ! get_userdata( $target_user_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_user',
					__( 'Target user does not exist.', 'mcp-ai-wpoos-pro' )
				);
			}
			return $target_user_id;
		}

		return $current_user_id;
	}

	/**
	 * Read the watchlist for a user.
	 *
	 * @since 1.1.90
	 *
	 * @param int $user_id User ID.
	 * @return array<string> Symbols.
	 */
	private function get_watchlist( $user_id ) {
		$symbols = get_user_meta( $user_id, self::META_KEY, true );

		return is_array( $symbols ) ? array_values( array_filter( array_map( 'strtoupper', $symbols ) ) ) : array();
	}

	/**
	 * Persist the watchlist for a user.
	 *
	 * @since 1.1.90
	 *
	 * @param int   $user_id User ID.
	 * @param array $symbols Symbols.
	 * @return void
	 */
	private function set_watchlist( $user_id, $symbols ) {
		update_user_meta( $user_id, self::META_KEY, array_values( array_filter( $symbols ) ) );
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.1.90
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';

		$valid_actions = array( 'list', 'add', 'remove', 'bulk_quote' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return new WP_Error(
				'invalid_action',
				__( 'Invalid action. Must be one of: list, add, remove, bulk_quote.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = $this->resolve_user_id( $arguments, $context );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to manage a watchlist.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Collect + sanitize symbol inputs (single or plural).
		$symbols = array();
		if ( isset( $arguments['symbols'] ) && is_array( $arguments['symbols'] ) ) {
			foreach ( $arguments['symbols'] as $symbol ) {
				$valid = $this->validate_symbol( $symbol );
				if ( false !== $valid ) {
					$symbols[] = $valid;
				}
			}
		}
		if ( isset( $arguments['symbol'] ) ) {
			$valid = $this->validate_symbol( $arguments['symbol'] );
			if ( false !== $valid ) {
				$symbols[] = $valid;
			}
		}
		$symbols = array_values( array_unique( $symbols ) );

		if ( ( 'add' === $action || 'remove' === $action ) && empty( $symbols ) ) {
			return new WP_Error(
				'missing_symbols',
				__( 'At least one valid symbol is required for this action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$watchlist = $this->get_watchlist( $user_id );

		switch ( $action ) {
			case 'list':
				return array(
					'success'    => true,
					'action'     => 'list',
					'user_id'    => $user_id,
					'symbols'    => array_map( 'esc_html', $watchlist ),
					'count'      => count( $watchlist ),
					'disclaimer' => __( 'EDUCATIONAL ONLY. Watchlist is informational and does not constitute investment advice.', 'mcp-ai-wpoos-pro' ),
				);

			case 'add':
				$before = count( $watchlist );
				foreach ( $symbols as $symbol ) {
					if ( ! in_array( $symbol, $watchlist, true ) ) {
						$watchlist[] = $symbol;
					}
				}
				if ( count( $watchlist ) > self::MAX_SYMBOLS ) {
					return new WP_Error(
						'watchlist_limit',
						/* translators: %d: maximum symbols */
						sprintf( __( 'Watchlist is limited to %d symbols.', 'mcp-ai-wpoos-pro' ), self::MAX_SYMBOLS )
					);
				}
				$this->set_watchlist( $user_id, $watchlist );

				return array(
					'success'     => true,
					'action'      => 'add',
					'user_id'     => $user_id,
					'added'       => array_map( 'esc_html', $symbols ),
					'count'       => count( $watchlist ),
					'was_already' => max( 0, count( $symbols ) - ( count( $watchlist ) - $before ) ),
					'disclaimer'  => __( 'EDUCATIONAL ONLY. Not investment advice.', 'mcp-ai-wpoos-pro' ),
				);

			case 'remove':
				$before    = count( $watchlist );
				$watchlist = array_values(
					array_filter(
						$watchlist,
						function ( $existing ) use ( $symbols ) {
							return ! in_array( $existing, $symbols, true );
						}
					)
				);
				$this->set_watchlist( $user_id, $watchlist );

				return array(
					'success'       => true,
					'action'        => 'remove',
					'user_id'       => $user_id,
					'removed'       => array_map( 'esc_html', $symbols ),
					'count'         => count( $watchlist ),
					'removed_count' => max( 0, $before - count( $watchlist ) ),
					'disclaimer'    => __( 'EDUCATIONAL ONLY. Not investment advice.', 'mcp-ai-wpoos-pro' ),
				);

			case 'bulk_quote':
				if ( empty( $watchlist ) ) {
					return array(
						'success'    => true,
						'action'     => 'bulk_quote',
						'user_id'    => $user_id,
						'quotes'     => array(),
						'count'      => 0,
						'disclaimer' => __( 'EDUCATIONAL ONLY. Watchlist is empty — add symbols first.', 'mcp-ai-wpoos-pro' ),
					);
				}

				$quotes = $this->fetch_bulk_quotes( array_slice( $watchlist, 0, 50 ) );
				if ( is_wp_error( $quotes ) ) {
					return $quotes;
				}

				return array(
					'success'    => true,
					'action'     => 'bulk_quote',
					'user_id'    => $user_id,
					'quotes'     => $quotes,
					'count'      => is_array( $quotes ) ? count( $quotes ) : 0,
					'disclaimer' => __( 'EDUCATIONAL ONLY. Prices may be delayed 15+ minutes. Not investment advice.', 'mcp-ai-wpoos-pro' ),
				);
		}

		return new WP_Error(
			'invalid_action',
			__( 'Invalid action.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Fetch current quotes for a list of symbols via the market-data service.
	 *
	 * @since 1.1.90
	 *
	 * @param array $tickers Ticker symbols.
	 * @return array|WP_Error Quote map keyed by ticker, or error.
	 */
	private function fetch_bulk_quotes( $tickers ) {
		$service_file = WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-yfinance-service.php';

		if ( ! file_exists( $service_file ) ) {
			return new WP_Error(
				'yfinance_not_found',
				__( 'Market data service is not installed.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_YFinance_Service' ) ) {
			require_once $service_file;
		}

		$service = WP_MCP_AI_YFinance_Service::get_instance();

		if ( ! $service->is_enabled() ) {
			return new WP_Error(
				'yfinance_disabled',
				__( 'Market data service is not enabled. Enable it in plugin settings or add a Finnhub API key.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = $service->get_batch_prices( $tickers );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$map = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;

		$quotes = array();
		foreach ( $tickers as $ticker ) {
			if ( isset( $map[ $ticker ] ) && is_array( $map[ $ticker ] ) ) {
				$quotes[] = array_merge(
					array( 'symbol' => esc_html( $ticker ) ),
					$map[ $ticker ]
				);
			}
		}

		return $quotes;
	}
}
