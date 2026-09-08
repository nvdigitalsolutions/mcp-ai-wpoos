<?php
/**
 * Financial planning tool (ecosystem port — Wave F2, financial tools batch B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/financial-planning/class-wp-mcp-ai-tool-stock-data-fetcher.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps where the
 * base references path constants (yfinance service require).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for fetching stock market data via YFinance service.
 *
 * Supports:
 * - Ticker symbol search
 * - Real-time quote retrieval
 * - Historical OHLCV data with configurable periods and intervals
 * - Batch quotes for multiple tickers
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Stock_Data_Fetcher implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if financial planner toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'nvoos-content-graph-pro' );
		}

		return __( 'Stock data fetcher tool is not available.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'stock_data_fetcher';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Stock Data Fetcher', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Fetch stock market data via the YFinance service. Search tickers, get real-time quotes, retrieve historical OHLCV data, and batch-fetch prices for multiple symbols. EDUCATIONAL ONLY - Data may be delayed 15+ minutes. Not investment advice.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @since 1.1.0
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
					'enum'        => array( 'search', 'quote', 'history', 'batch_quotes' ),
				),
				'query'    => array(
					'type'        => 'string',
					'description' => __( 'Search query for ticker search (required for "search" action).', 'nvoos-content-graph-pro' ),
				),
				'ticker'   => array(
					'type'        => 'string',
					'description' => __( 'Ticker symbol (required for "quote" and "history" actions).', 'nvoos-content-graph-pro' ),
				),
				'tickers'  => array(
					'type'        => 'array',
					'description' => __( 'Array of ticker symbols (required for "batch_quotes" action).', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'period'   => array(
					'type'        => 'string',
					'description' => __( 'Data period for historical data.', 'nvoos-content-graph-pro' ),
					'enum'        => array( '1d', '5d', '1mo', '3mo', '6mo', '1y', '2y', '5y', 'ytd', 'max' ),
					'default'     => '1mo',
				),
				'interval' => array(
					'type'        => 'string',
					'description' => __( 'Data interval for historical data.', 'nvoos-content-graph-pro' ),
					'enum'        => array( '1m', '5m', '15m', '30m', '1h', '1d', '1wk', '1mo' ),
					'default'     => '1d',
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.1.0
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
	 * Execute the tool.
	 *
	 * @since 1.1.0
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
				__( 'You do not have permission to fetch stock data.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		$valid_actions = array( 'search', 'quote', 'history', 'batch_quotes' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return new WP_Error(
				'invalid_action',
				__( 'Invalid action. Must be one of: search, quote, history, batch_quotes.', 'nvoos-content-graph-pro' )
			);
		}

		$service = $this->get_yfinance_service();
		if ( is_wp_error( $service ) ) {
			return $service;
		}

		$period   = isset( $arguments['period'] ) ? sanitize_text_field( $arguments['period'] ) : '1mo';
		$interval = isset( $arguments['interval'] ) ? sanitize_text_field( $arguments['interval'] ) : '1d';

		switch ( $action ) {
			case 'search':
				return $this->execute_search( $service, $arguments );

			case 'quote':
				return $this->execute_quote( $service, $arguments, $period );

			case 'history':
				return $this->execute_history( $service, $arguments, $period, $interval );

			case 'batch_quotes':
				return $this->execute_batch_quotes( $service, $arguments, $period );

			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'nvoos-content-graph-pro' )
				);
		}
	}

	/**
	 * Get the YFinance service instance.
	 *
	 * @since 1.1.0
	 *
	 * @return WP_MCP_AI_YFinance_Service|WP_Error Service instance or error.
	 */
	private function get_yfinance_service() {
		if ( ! file_exists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-yfinance-service.php' ) ) {
			return new WP_Error(
				'yfinance_not_found',
				__( 'YFinance service is not installed. Please ensure the pro addon is properly configured.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_YFinance_Service' ) ) {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-yfinance-service.php';
		}

		$service = WP_MCP_AI_YFinance_Service::get_instance();

		if ( ! $service->is_enabled() ) {
			return new WP_Error(
				'yfinance_disabled',
				__( 'YFinance service is not enabled. Please enable it in Settings → NV oOS → YFinance Service.', 'nvoos-content-graph-pro' )
			);
		}

		return $service;
	}

	/**
	 * Execute ticker search action.
	 *
	 * @since 1.1.0
	 *
	 * @param WP_MCP_AI_YFinance_Service $service   YFinance service instance.
	 * @param array                      $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function execute_search( $service, $arguments ) {
		$query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';

		if ( empty( $query ) ) {
			return new WP_Error(
				'missing_query',
				__( 'Search query is required for the "search" action.', 'nvoos-content-graph-pro' )
			);
		}

		$results = $service->search_ticker( $query );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		return array(
			'success'    => true,
			'action'     => 'search',
			'query'      => $query,
			'results'    => $results,
			'count'      => is_array( $results ) ? count( $results ) : 0,
			'disclaimer' => __( 'EDUCATIONAL ONLY. Ticker search results are for informational purposes. Data may be delayed. Verify all information before making financial decisions. Not investment advice.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Execute quote action for a single ticker.
	 *
	 * @since 1.1.0
	 *
	 * @param WP_MCP_AI_YFinance_Service $service   YFinance service instance.
	 * @param array                      $arguments Tool arguments.
	 * @param string                     $period    Data period.
	 * @return array|WP_Error
	 */
	private function execute_quote( $service, $arguments, $period ) {
		$ticker = isset( $arguments['ticker'] ) ? strtoupper( sanitize_text_field( $arguments['ticker'] ) ) : '';

		if ( empty( $ticker ) ) {
			return new WP_Error(
				'missing_ticker',
				__( 'Ticker symbol is required for the "quote" action.', 'nvoos-content-graph-pro' )
			);
		}

		$result = $service->get_current_price( $ticker, $period );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'    => true,
			'action'     => 'quote',
			'ticker'     => $ticker,
			'data'       => $result,
			'period'     => $period,
			'disclaimer' => __( 'EDUCATIONAL ONLY. Price data may be delayed 15 minutes or more. Not investment advice. Consult a licensed financial advisor before making investment decisions.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Execute history action for a single ticker.
	 *
	 * @since 1.1.0
	 *
	 * @param WP_MCP_AI_YFinance_Service $service   YFinance service instance.
	 * @param array                      $arguments Tool arguments.
	 * @param string                     $period    Data period.
	 * @param string                     $interval  Data interval.
	 * @return array|WP_Error
	 */
	private function execute_history( $service, $arguments, $period, $interval ) {
		$ticker = isset( $arguments['ticker'] ) ? strtoupper( sanitize_text_field( $arguments['ticker'] ) ) : '';

		if ( empty( $ticker ) ) {
			return new WP_Error(
				'missing_ticker',
				__( 'Ticker symbol is required for the "history" action.', 'nvoos-content-graph-pro' )
			);
		}

		$result = $service->get_price_history( $ticker, $period, $interval );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'    => true,
			'action'     => 'history',
			'ticker'     => $ticker,
			'period'     => $period,
			'interval'   => $interval,
			'data'       => $result,
			'disclaimer' => __( 'EDUCATIONAL ONLY. Historical data may be delayed or incomplete. Past performance does not guarantee future results. Not investment advice.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Execute batch quotes action for multiple tickers.
	 *
	 * @since 1.1.0
	 *
	 * @param WP_MCP_AI_YFinance_Service $service   YFinance service instance.
	 * @param array                      $arguments Tool arguments.
	 * @param string                     $period    Data period.
	 * @return array|WP_Error
	 */
	private function execute_batch_quotes( $service, $arguments, $period ) {
		$tickers = isset( $arguments['tickers'] ) && is_array( $arguments['tickers'] ) ? $arguments['tickers'] : array();
		$tickers = array_map(
			function ( $t ) {
				return strtoupper( sanitize_text_field( $t ) );
			},
			$tickers
		);
		$tickers = array_filter( $tickers );

		if ( empty( $tickers ) ) {
			return new WP_Error(
				'missing_tickers',
				__( 'At least one ticker symbol is required for the "batch_quotes" action.', 'nvoos-content-graph-pro' )
			);
		}

		if ( count( $tickers ) > 50 ) {
			return new WP_Error(
				'too_many_tickers',
				__( 'Maximum 50 tickers allowed per batch request.', 'nvoos-content-graph-pro' )
			);
		}

		$result = $service->get_batch_prices( $tickers, $period );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'      => true,
			'action'       => 'batch_quotes',
			'tickers'      => $tickers,
			'ticker_count' => count( $tickers ),
			'period'       => $period,
			'data'         => $result,
			'disclaimer'   => __( 'EDUCATIONAL ONLY. Price data may be delayed 15 minutes or more. Not investment advice. Consult a licensed financial advisor before making investment decisions.', 'nvoos-content-graph-pro' ),
		);
	}
}
