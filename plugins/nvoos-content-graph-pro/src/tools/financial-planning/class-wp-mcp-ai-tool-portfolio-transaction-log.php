<?php
/**
 * Financial toolkit ecosystem port (OpenTerminal lessons sub-cluster).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/financial-planning/class-wp-mcp-ai-tool-portfolio-transaction-log.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base
 * Pro addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `WP_MCP_AI_PRO_PATH` is defined.
 *
 * What this file is: Buy/sell ledger with average cost + realized/unrealized P&L.
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
 * Tool for the portfolio transaction ledger.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Tool_Portfolio_Transaction_Log implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'Portfolio transaction log tool is not available.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'portfolio_transaction_log';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Portfolio Transaction Log', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Log buy/sell transactions and compute per-ticker average cost plus realized and unrealized P&L. Prices are fetched from public market data when available. EDUCATIONAL ONLY - Not investment advice.', 'nvoos-content-graph-pro' );
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
				'action'         => array(
					'type'        => 'string',
					'description' => __( 'The action to perform.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'add', 'list', 'remove', 'position_summary' ),
				),
				'ticker'         => array(
					'type'        => 'string',
					'description' => __( 'Ticker symbol (required for "add"; optional filter for "list"/"position_summary").', 'nvoos-content-graph-pro' ),
				),
				'side'           => array(
					'type'        => 'string',
					'description' => __( 'Transaction side (required for "add").', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'buy', 'sell' ),
				),
				'quantity'       => array(
					'type'        => 'number',
					'description' => __( 'Quantity (required for "add").', 'nvoos-content-graph-pro' ),
				),
				'price'          => array(
					'type'        => 'number',
					'description' => __( 'Execution price per unit (required for "add").', 'nvoos-content-graph-pro' ),
				),
				'fee'            => array(
					'type'        => 'number',
					'description' => __( 'Transaction fee (optional).', 'nvoos-content-graph-pro' ),
					'default'     => 0,
				),
				'executed_at'    => array(
					'type'        => 'string',
					'description' => __( 'Execution date YYYY-MM-DD (default: today).', 'nvoos-content-graph-pro' ),
				),
				'currency'       => array(
					'type'        => 'string',
					'description' => __( 'Currency code (default USD).', 'nvoos-content-graph-pro' ),
					'default'     => 'USD',
				),
				'transaction_id' => array(
					'type'        => 'integer',
					'description' => __( 'Transaction post ID (required for "remove").', 'nvoos-content-graph-pro' ),
				),
				'limit'          => array(
					'type'        => 'integer',
					'description' => __( 'Maximum rows for "list" (default 100).', 'nvoos-content-graph-pro' ),
					'default'     => 100,
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
			'database-read',
			'database-write',
		);
	}

	/**
	 * Load the transaction CPT class.
	 *
	 * @since 1.1.80
	 *
	 * @return true|WP_Error
	 */
	private function load_cpt() {
		if ( ! class_exists( 'WP_MCP_AI_Financial_Transaction_CPT' ) ) {
			$cpt_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-financial-transaction-cpt.php';
			if ( ! file_exists( $cpt_file ) ) {
				return new WP_Error(
					'transaction_cpt_not_found',
					__( 'Portfolio transaction storage is not installed.', 'nvoos-content-graph-pro' )
				);
			}
			require_once $cpt_file;
		}

		return true;
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
				__( 'You do not have permission to use the portfolio transaction log.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$loaded = $this->load_cpt();
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		$valid_actions = array( 'add', 'list', 'remove', 'position_summary' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return new WP_Error(
				'invalid_action',
				__( 'Invalid action. Must be one of: add, list, remove, position_summary.', 'nvoos-content-graph-pro' )
			);
		}

		switch ( $action ) {
			case 'add':
				return $this->execute_add( $arguments, $current_user_id );

			case 'list':
				return $this->execute_list( $arguments, $current_user_id );

			case 'remove':
				return $this->execute_remove( $arguments, $current_user_id );

			case 'position_summary':
				return $this->execute_position_summary( $arguments, $current_user_id );

			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'nvoos-content-graph-pro' )
				);
		}
	}

	/**
	 * Add a transaction.
	 *
	 * @since 1.1.80
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   Current user ID.
	 * @return array|WP_Error
	 */
	private function execute_add( $arguments, $user_id ) {
		$ticker   = isset( $arguments['ticker'] ) ? strtoupper( sanitize_text_field( $arguments['ticker'] ) ) : '';
		$side     = isset( $arguments['side'] ) ? sanitize_key( $arguments['side'] ) : '';
		$quantity = isset( $arguments['quantity'] ) ? (float) $arguments['quantity'] : 0.0;
		$price    = isset( $arguments['price'] ) ? (float) $arguments['price'] : 0.0;

		if ( empty( $ticker ) ) {
			return new WP_Error(
				'missing_ticker',
				__( 'Ticker symbol is required for the "add" action.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! in_array( $side, array( 'buy', 'sell' ), true ) ) {
			return new WP_Error(
				'invalid_side',
				__( 'Side must be "buy" or "sell".', 'nvoos-content-graph-pro' )
			);
		}

		if ( $quantity <= 0 ) {
			return new WP_Error(
				'invalid_quantity',
				__( 'Quantity must be a positive number.', 'nvoos-content-graph-pro' )
			);
		}

		if ( $price <= 0 ) {
			return new WP_Error(
				'invalid_price',
				__( 'Price must be a positive number.', 'nvoos-content-graph-pro' )
			);
		}

		$fee         = isset( $arguments['fee'] ) ? max( 0.0, (float) $arguments['fee'] ) : 0.0;
		$executed_at = isset( $arguments['executed_at'] ) ? sanitize_text_field( $arguments['executed_at'] ) : gmdate( 'Y-m-d' );
		$currency    = isset( $arguments['currency'] ) ? strtoupper( sanitize_text_field( $arguments['currency'] ) ) : 'USD';

		$post_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Financial_Transaction_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => sprintf( '%s %s %s @ %s', $side, $quantity, $ticker, $price ),
				'post_author' => $user_id,
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, WP_MCP_AI_Financial_Transaction_CPT::META_TICKER, $ticker );
		update_post_meta( $post_id, WP_MCP_AI_Financial_Transaction_CPT::META_SIDE, $side );
		update_post_meta( $post_id, WP_MCP_AI_Financial_Transaction_CPT::META_QUANTITY, $quantity );
		update_post_meta( $post_id, WP_MCP_AI_Financial_Transaction_CPT::META_PRICE, $price );
		update_post_meta( $post_id, WP_MCP_AI_Financial_Transaction_CPT::META_FEE, $fee );
		update_post_meta( $post_id, WP_MCP_AI_Financial_Transaction_CPT::META_EXECUTED_AT, $executed_at );
		update_post_meta( $post_id, WP_MCP_AI_Financial_Transaction_CPT::META_CURRENCY, $currency );

		return array(
			'success'        => true,
			'action'         => 'add',
			'transaction_id' => $post_id,
			'ticker'         => $ticker,
			'side'           => $side,
			'quantity'       => $quantity,
			'price'          => $price,
			'fee'            => $fee,
			'executed_at'    => $executed_at,
			'currency'       => $currency,
			'disclaimer'     => __( 'EDUCATIONAL ONLY. This ledger is a tracking aid. Not investment advice.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * List transactions.
	 *
	 * @since 1.1.80
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   Current user ID.
	 * @return array|WP_Error
	 */
	private function execute_list( $arguments, $user_id ) {
		$ticker = isset( $arguments['ticker'] ) ? strtoupper( sanitize_text_field( $arguments['ticker'] ) ) : '';
		$limit  = isset( $arguments['limit'] ) ? min( max( absint( $arguments['limit'] ), 1 ), 500 ) : 100;

		$transactions = WP_MCP_AI_Financial_Transaction_CPT::get_transactions( $user_id );

		if ( '' !== $ticker ) {
			$transactions = array_values(
				array_filter(
					$transactions,
					function ( $txn ) use ( $ticker ) {
						return $ticker === $txn['ticker'];
					}
				)
			);
		}

		// Newest first for display.
		$transactions = array_reverse( $transactions );
		$transactions = array_slice( $transactions, 0, $limit );

		return array(
			'success'      => true,
			'action'       => 'list',
			'count'        => count( $transactions ),
			'transactions' => $transactions,
			'disclaimer'   => __( 'EDUCATIONAL ONLY. This ledger is a tracking aid. Not investment advice.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Remove a transaction.
	 *
	 * @since 1.1.80
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   Current user ID.
	 * @return array|WP_Error
	 */
	private function execute_remove( $arguments, $user_id ) {
		$transaction_id = isset( $arguments['transaction_id'] ) ? absint( $arguments['transaction_id'] ) : 0;

		if ( 0 === $transaction_id ) {
			return new WP_Error(
				'missing_transaction_id',
				__( 'Transaction ID is required for the "remove" action.', 'nvoos-content-graph-pro' )
			);
		}

		$post = get_post( $transaction_id );

		if ( ! $post || WP_MCP_AI_Financial_Transaction_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'transaction_not_found',
				__( 'Transaction not found.', 'nvoos-content-graph-pro' )
			);
		}

		if ( (int) $post->post_author !== $user_id && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You can only remove your own transactions.', 'nvoos-content-graph-pro' )
			);
		}

		wp_delete_post( $transaction_id, true );

		return array(
			'success'        => true,
			'action'         => 'remove',
			'transaction_id' => $transaction_id,
		);
	}

	/**
	 * Compute position summaries with average cost and realized/unrealized P&L.
	 *
	 * @since 1.1.80
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   Current user ID.
	 * @return array|WP_Error
	 */
	private function execute_position_summary( $arguments, $user_id ) {
		$ticker = isset( $arguments['ticker'] ) ? strtoupper( sanitize_text_field( $arguments['ticker'] ) ) : '';

		$transactions = WP_MCP_AI_Financial_Transaction_CPT::get_transactions( $user_id );

		if ( '' !== $ticker ) {
			$transactions = array_values(
				array_filter(
					$transactions,
					function ( $txn ) use ( $ticker ) {
						return $ticker === $txn['ticker'];
					}
				)
			);
		}

		// Chronological execution order.
		usort(
			$transactions,
			function ( $a, $b ) {
				$time_a = strtotime( $a['executed_at'] );
				$time_b = strtotime( $b['executed_at'] );

				return $time_a - $time_b;
			}
		);

		$positions = array();

		foreach ( $transactions as $txn ) {
			$key = $txn['ticker'];
			if ( ! isset( $positions[ $key ] ) ) {
				$positions[ $key ] = array(
					'ticker'       => $key,
					'quantity'     => 0.0,
					'average_cost' => 0.0,
					'total_cost'   => 0.0,
					'realized_pnl' => 0.0,
					'currency'     => $txn['currency'],
				);
			}

			$position = &$positions[ $key ];

			if ( 'buy' === $txn['side'] ) {
				$new_cost                 = ( $txn['quantity'] * $txn['price'] ) + $txn['fee'];
				$new_quantity             = $position['quantity'] + $txn['quantity'];
				$position['total_cost']  += $new_cost;
				$position['quantity']     = $new_quantity;
				$position['average_cost'] = $position['total_cost'] / $new_quantity;
			} elseif ( 'sell' === $txn['side'] ) {
				$proceeds                  = ( $txn['quantity'] * $txn['price'] ) - $txn['fee'];
				$cost_basis                = $txn['quantity'] * $position['average_cost'];
				$position['realized_pnl'] += $proceeds - $cost_basis;
				$position['quantity']      = max( 0.0, $position['quantity'] - $txn['quantity'] );
				if ( $position['quantity'] <= 0.0 ) {
					$position['quantity']     = 0.0;
					$position['average_cost'] = 0.0;
					$position['total_cost']   = 0.0;
				}
			}

			unset( $position );
		}

		$totals = array(
			'realized_pnl'   => 0.0,
			'unrealized_pnl' => 0.0,
		);
		foreach ( $positions as $position ) {
			$totals['realized_pnl'] += $position['realized_pnl'];
		}

		// Fetch current prices for open positions (best effort).
		$open_tickers = array();
		foreach ( $positions as $position ) {
			if ( $position['quantity'] > 0 ) {
				$open_tickers[] = $position['ticker'];
			}
		}

		$price_map = array();
		if ( ! empty( $open_tickers ) ) {
			$price_map = $this->fetch_current_prices( $open_tickers );
		}

		foreach ( $positions as $key => &$position ) {
			$current_price = isset( $price_map[ $key ] ) ? $price_map[ $key ]['price'] : null;

			if ( null === $current_price ) {
				$position['unrealized_pnl'] = null;
				$position['price_source']   = 'unavailable';
				continue;
			}

			$position['current_price']  = $current_price;
			$position['price_source']   = $price_map[ $key ]['source'];
			$position['unrealized_pnl'] = $position['quantity'] * ( $current_price - $position['average_cost'] );
			$totals['unrealized_pnl']  += $position['unrealized_pnl'];
		}
		unset( $position );

		return array(
			'success'    => true,
			'action'     => 'position_summary',
			'positions'  => array_values( $positions ),
			'totals'     => $totals,
			'disclaimer' => __( 'EDUCATIONAL ONLY. P&L figures are estimates computed from logged transactions and delayed public prices. Not investment advice.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Fetch current prices for a set of tickers (best effort).
	 *
	 * @since 1.1.80
	 *
	 * @param array $tickers Tickers.
	 * @return array Map of ticker => array( price, source ).
	 */
	private function fetch_current_prices( $tickers ) {
		$map = array();

		$service_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-yfinance-service.php';
		if ( ! file_exists( $service_file ) ) {
			return $map;
		}

		if ( ! class_exists( 'WP_MCP_AI_YFinance_Service' ) ) {
			require_once $service_file;
		}

		$service = WP_MCP_AI_YFinance_Service::get_instance();

		if ( ! $service->is_enabled() ) {
			return $map;
		}

		$prices = $service->get_batch_prices( array_slice( $tickers, 0, 50 ) );
		if ( is_wp_error( $prices ) ) {
			return $map;
		}

		$price_map = isset( $prices['data'] ) && is_array( $prices['data'] ) ? $prices['data'] : $prices;

		foreach ( $tickers as $ticker ) {
			if ( isset( $price_map[ $ticker ] ) && isset( $price_map[ $ticker ]['current_price'] ) ) {
				$map[ $ticker ] = array(
					'price'  => (float) $price_map[ $ticker ]['current_price'],
					'source' => isset( $price_map[ $ticker ]['source'] ) ? $price_map[ $ticker ]['source'] : 'yfinance',
				);
			}
		}

		return $map;
	}
}
