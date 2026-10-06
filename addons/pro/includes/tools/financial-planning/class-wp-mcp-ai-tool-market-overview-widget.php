<?php
/**
 * Market Overview Widget Tool
 *
 * Builds TradingView embed widget URLs (chart, heatmap, ticker tape,
 * timeline, screener) — the same charting surface used by the open-source
 * OpenStock project — plus a top-movers summary computed from batch quotes.
 * Capability parity: no OpenStock or TradingView code is bundled; widgets
 * are third-party iframes embedded under TradingView's own terms.
 *
 * @link    https://www.tradingview.com/widget/ (embeddable widgets)
 * @credit  TradingView — embeddable market widgets (third-party iframe embeds).
 *
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
 * Tool for market overview widget URLs and top-mover summaries.
 *
 * @since 1.1.90
 */
class WP_MCP_AI_Tool_Market_Overview_Widget implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * TradingView widget base URLs (fixed allowlist).
	 */
	const CHART_BASE    = 'https://s.tradingview.com/widgetembed/';
	const HEATMAP_BASE  = 'https://s.tradingview.com/embed-widget/stock-market/';
	const TICKERS_BASE  = 'https://s.tradingview.com/embed-widget/tickers/';
	const TIMELINE_BASE = 'https://s.tradingview.com/embed-widget/timeline/';
	const SCREENER_BASE = 'https://s.tradingview.com/embed-widget/screener/';

	/**
	 * Default top-movers tickers (liquid US equities).
	 */
	const DEFAULT_TICKERS = array( 'AAPL', 'MSFT', 'GOOGL', 'AMZN', 'NVDA', 'META', 'TSLA', 'BRK.B' );

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

		return __( 'Market overview widget tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.90
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'market_overview_widget';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.90
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Market Overview Widget', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.90
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Build TradingView embed widget URLs (chart, heatmap, ticker tape, news timeline, screener) for market overview surfaces, plus a top-movers summary from batch quotes. EDUCATIONAL ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' );
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
			'when_to_use'     => __( 'When a chart, heatmap, or quote tape should be rendered in the admin market-overview page, or a quick top-movers rollup is needed.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Raw OHLCV series or indicators; use stock_data_fetcher. Alerts belong to price_alerts.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'stock_data_fetcher', 'watchlist_sync', 'market_screener', 'financial_news_aggregator' ),
			'notes'           => __( 'action is required (widget, summary). Widget URLs are third-party iframes; embed them inside an allowlisted frame context.', 'mcp-ai-wpoos-pro' ),
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
				'action' => array(
					'type'        => 'string',
					'description' => __( 'The action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'widget', 'summary' ),
				),
				'widget' => array(
					'type'        => 'string',
					'description' => __( 'Widget type (required for "widget" action).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'chart', 'heatmap', 'tickers', 'timeline', 'screener' ),
				),
				'symbol' => array(
					'type'        => 'string',
					'description' => __( 'Ticker symbol (required for chart, tickers, and timeline widgets). Optional for heatmap and screener.', 'mcp-ai-wpoos-pro' ),
				),
				'theme'  => array(
					'type'        => 'string',
					'description' => __( 'Widget theme.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'dark', 'light' ),
					'default'     => 'dark',
				),
				'limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum movers returned by the summary action (1-20).', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
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
			'external-api',
			'cacheable',
			'network-dependent',
		);
	}

	/**
	 * Validate a widget symbol (bare ticker or EXCHANGE:TICKER).
	 *
	 * @since 1.1.90
	 *
	 * @param string $symbol Symbol.
	 * @return string|false Sanitized symbol or false.
	 */
	private function validate_widget_symbol( $symbol ) {
		$symbol = strtoupper( trim( sanitize_text_field( $symbol ) ) );

		if ( '' === $symbol || ! preg_match( '/^[A-Z0-9.\-]{1,15}(:[A-Z0-9.\-]{1,15})?$/', $symbol ) ) {
			return false;
		}

		return $symbol;
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the market overview widget.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';

		$valid_actions = array( 'widget', 'summary' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return new WP_Error(
				'invalid_action',
				__( 'Invalid action. Must be one of: widget, summary.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( 'widget' === $action ) {
			return $this->execute_widget( $arguments );
		}

		return $this->execute_summary( $arguments );
	}

	/**
	 * Build a widget embed URL.
	 *
	 * @since 1.1.90
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function execute_widget( $arguments ) {
		$widget = isset( $arguments['widget'] ) ? sanitize_key( $arguments['widget'] ) : '';
		$theme  = isset( $arguments['theme'] ) && 'light' === sanitize_key( $arguments['theme'] ) ? 'light' : 'dark';
		$symbol = isset( $arguments['symbol'] ) ? $this->validate_widget_symbol( $arguments['symbol'] ) : false;

		$valid_widgets = array( 'chart', 'heatmap', 'tickers', 'timeline', 'screener' );
		if ( ! in_array( $widget, $valid_widgets, true ) ) {
			return new WP_Error(
				'invalid_widget',
				__( 'Invalid widget type. Must be one of: chart, heatmap, tickers, timeline, screener.', 'mcp-ai-wpoos-pro' )
			);
		}

		$needs_symbol = in_array( $widget, array( 'chart', 'tickers', 'timeline' ), true );
		if ( $needs_symbol && false === $symbol ) {
			return new WP_Error(
				'missing_symbol',
				__( 'A valid ticker symbol is required for this widget type.', 'mcp-ai-wpoos-pro' )
			);
		}

		$url = '';

		switch ( $widget ) {
			case 'chart':
				$url = add_query_arg(
					array(
						'symbol'   => $symbol,
						'interval' => 'D',
						'theme'    => $theme,
						'locale'   => 'en',
					),
					self::CHART_BASE
				);
				break;

			case 'heatmap':
				$url = add_query_arg(
					array(
						'exchange' => 'US',
						'theme'    => $theme,
					),
					self::HEATMAP_BASE
				);
				break;

			case 'tickers':
				$url = add_query_arg(
					array(
						'symbols' => $symbol,
						'theme'   => $theme,
					),
					self::TICKERS_BASE
				);
				break;

			case 'timeline':
				$url = add_query_arg(
					array(
						'symbol'   => $symbol,
						'interval' => 'D',
						'theme'    => $theme,
					),
					self::TIMELINE_BASE
				);
				break;

			case 'screener':
				$url = add_query_arg(
					array(
						'market' => 'america',
						'theme'  => $theme,
					),
					self::SCREENER_BASE
				);
				break;
		}

		return array(
			'success'     => true,
			'action'      => 'widget',
			'widget'      => $widget,
			'symbol'      => false !== $symbol ? esc_html( $symbol ) : '',
			'embed_url'   => esc_url_raw( $url ),
			'iframe_hint' => '<iframe src="' . esc_url( $url ) . '" width="100%" height="480" frameborder="0" allowtransparency="true" scrolling="no"></iframe>',
			'provider'    => 'tradingview',
			'disclaimer'  => __( 'EDUCATIONAL ONLY. TradingView widgets are third-party iframes provided under TradingView terms. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Build a top-movers summary from batch quotes.
	 *
	 * @since 1.1.90
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function execute_summary( $arguments ) {
		$limit = isset( $arguments['limit'] ) ? min( max( absint( $arguments['limit'] ), 1 ), 20 ) : 10;

		// Prefer the user's watchlist when non-empty; fall back to defaults.
		$tickers = array();
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$watchlist = get_user_meta( $user_id, 'wp_mcp_ai_fin_watchlist', true );
			if ( is_array( $watchlist ) && ! empty( $watchlist ) ) {
				$tickers = array_values( array_filter( array_map( 'strtoupper', $watchlist ) ) );
			}
		}
		if ( empty( $tickers ) ) {
			$tickers = self::DEFAULT_TICKERS;
		}
		$tickers = array_slice( $tickers, 0, 20 );

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

		$movers = array();
		foreach ( $tickers as $ticker ) {
			if ( ! isset( $map[ $ticker ] ) || ! is_array( $map[ $ticker ] ) ) {
				continue;
			}
			$movers[] = array(
				'symbol'         => esc_html( $ticker ),
				'current_price'  => isset( $map[ $ticker ]['current_price'] ) ? (float) $map[ $ticker ]['current_price'] : 0.0,
				'change_percent' => isset( $map[ $ticker ]['change_percent'] ) ? (float) $map[ $ticker ]['change_percent'] : 0.0,
			);
		}

		usort(
			$movers,
			function ( $a, $b ) {
				return abs( $b['change_percent'] ) <=> abs( $a['change_percent'] );
			}
		);

		$source = isset( $result['source'] ) ? sanitize_text_field( $result['source'] ) : 'market-data';

		return array(
			'success'    => true,
			'action'     => 'summary',
			'movers'     => array_slice( $movers, 0, $limit ),
			'count'      => count( array_slice( $movers, 0, $limit ) ),
			'source'     => $source,
			'disclaimer' => __( 'EDUCATIONAL ONLY. Prices may be delayed 15+ minutes. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
