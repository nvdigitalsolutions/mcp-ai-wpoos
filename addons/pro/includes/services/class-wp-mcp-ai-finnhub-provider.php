<?php
/**
 * Finnhub Market Data Provider
 *
 * Optional primary market-data provider for the Financial Planner toolkit.
 * Supplies quotes, OHLCV history, symbol search, batch quotes, company
 * profiles, and market news from the Finnhub API (https://finnhub.io) —
 * the same data backbone used by the open-source OpenStock project
 * (https://github.com/Open-Dev-Society/OpenStock).
 *
 * The provider plugs into the existing yfinance filter seam at priority 5,
 * ahead of the Node.js yfinance client (priority 10), which passes through
 * any non-false result. When the provider is disabled or fails, it returns
 * false so the Node client and the keyless fallback chain run unchanged.
 *
 * License note: no OpenStock code is used here. Finnhub is integrated
 * independently under its own terms; each site operator brings their own
 * API key (free tier: 60 requests/minute per key).
 *
 * @link    https://finnhub.io/docs/api
 * @credit  Finnhub (https://finnhub.io) — market data provider used under BYO-key terms.
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
 * Finnhub market data provider.
 *
 * @since 1.1.90
 */
class WP_MCP_AI_Finnhub_Provider {

	/**
	 * Finnhub API base URL.
	 */
	const API_BASE = 'https://finnhub.io/api/v1';

	/**
	 * Transient cache group.
	 */
	const CACHE_GROUP = 'wp_mcp_ai_finnhub';

	/**
	 * Default fresh-cache TTL in seconds (cached mode: 15 minutes).
	 */
	const TTL_CACHED = 900;

	/**
	 * Fresh-cache TTL in seconds for realtime mode (60 seconds — respects
	 * the free-tier rate limit of 60 requests/minute per key).
	 */
	const TTL_REALTIME = 60;

	/**
	 * Stale-copy TTL in seconds (7 days).
	 */
	const STALE_TTL = 604800;

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 15;

	/**
	 * Default news window in days.
	 */
	const NEWS_WINDOW_DAYS = 7;

	/**
	 * Default news result limit.
	 */
	const NEWS_LIMIT = 10;

	/**
	 * Get singleton instance.
	 *
	 * @since 1.1.90
	 *
	 * @return self
	 */
	public static function get_instance() {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Register the yfinance filter handlers at priority 5 (ahead of the
	 * Node.js client at priority 10).
	 *
	 * @since 1.1.90
	 *
	 * @return void
	 */
	public static function register_filters() {
		add_filter( 'wp_mcp_ai_yfinance_current_price', array( __CLASS__, 'filter_current_price' ), 5, 2 );
		add_filter( 'wp_mcp_ai_yfinance_price_history', array( __CLASS__, 'filter_price_history' ), 5, 2 );
		add_filter( 'wp_mcp_ai_yfinance_search_ticker', array( __CLASS__, 'filter_search_ticker' ), 5, 2 );
		add_filter( 'wp_mcp_ai_yfinance_batch_prices', array( __CLASS__, 'filter_batch_prices' ), 5, 2 );

		// Shorten the shared yfinance-service cache TTL in realtime mode.
		add_filter( 'wp_mcp_ai_yfinance_cache_ttl', array( __CLASS__, 'filter_cache_ttl' ), 5 );
	}

	/**
	 * Check if the provider is enabled (toolkit enabled + at least one key).
	 *
	 * @since 1.1.90
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return false;
		}

		return count( self::get_keys() ) > 0;
	}

	/**
	 * Get the configured Finnhub API keys.
	 *
	 * Reads the toolkit option first, then the global settings option.
	 * Filterable via `wp_mcp_ai_finnhub_api_keys` (constants/env bridging).
	 *
	 * @since 1.1.90
	 *
	 * @return array<string> API keys.
	 */
	public static function get_keys() {
		$keys = array();

		$toolkit_settings = get_option( 'wp_mcp_ai_financial_planner_settings', array() );
		$global_settings  = get_option( 'wp_mcp_ai_settings', array() );

		$raw = isset( $toolkit_settings['finnhub_api_keys'] )
			? $toolkit_settings['finnhub_api_keys']
			: ( isset( $global_settings['finnhub_api_keys'] ) ? $global_settings['finnhub_api_keys'] : '' );

		/**
		 * Filter the Finnhub API keys (comma-separated string or array).
		 *
		 * @since 1.1.90
		 *
		 * @param string|array $raw Raw keys value.
		 */
		$raw = apply_filters( 'wp_mcp_ai_finnhub_api_keys', $raw );

		$list = is_array( $raw ) ? $raw : explode( ',', (string) $raw );

		foreach ( $list as $key ) {
			$key = trim( sanitize_text_field( $key ) );
			if ( '' !== $key && ! in_array( $key, $keys, true ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Get the data mode: `cached` (default) or `realtime`.
	 *
	 * @since 1.1.90
	 *
	 * @return string Data mode.
	 */
	public static function get_data_mode() {
		$toolkit_settings = get_option( 'wp_mcp_ai_financial_planner_settings', array() );
		$global_settings  = get_option( 'wp_mcp_ai_settings', array() );

		$mode = isset( $toolkit_settings['finnhub_data_mode'] )
			? sanitize_key( $toolkit_settings['finnhub_data_mode'] )
			: ( isset( $global_settings['finnhub_data_mode'] ) ? sanitize_key( $global_settings['finnhub_data_mode'] ) : 'cached' );

		/**
		 * Filter the Finnhub data mode.
		 *
		 * @since 1.1.90
		 *
		 * @param string $mode Data mode (cached|realtime).
		 */
		$mode = apply_filters( 'wp_mcp_ai_finnhub_data_mode', $mode );

		return 'realtime' === $mode ? 'realtime' : 'cached';
	}

	/**
	 * Shorten the shared yfinance cache TTL in realtime mode.
	 *
	 * @since 1.1.90
	 *
	 * @param int $ttl Current TTL in seconds.
	 * @return int TTL in seconds.
	 */
	public static function filter_cache_ttl( $ttl ) {
		if ( ! self::is_enabled() || 'realtime' !== self::get_data_mode() ) {
			return $ttl;
		}

		return self::TTL_REALTIME;
	}

	/**
	 * Validate a ticker symbol.
	 *
	 * @since 1.1.90
	 *
	 * @param string $symbol Symbol to validate.
	 * @return bool
	 */
	public static function is_valid_symbol( $symbol ) {
		return (bool) preg_match( '/^[A-Z0-9.\-^=\/]{1,15}$/i', trim( (string) $symbol ) );
	}

	/**
	 * Filter handler: current price.
	 *
	 * @since 1.1.90
	 *
	 * @param array|false $result Previous result.
	 * @param array       $params Request parameters (ticker, period).
	 * @return array|false Finnhub payload or false (pass-through).
	 */
	public static function filter_current_price( $result, $params ) {
		if ( false !== $result || ! self::is_enabled() ) {
			return $result;
		}

		$ticker = isset( $params['ticker'] ) ? strtoupper( sanitize_text_field( $params['ticker'] ) ) : '';

		if ( ! self::is_valid_symbol( $ticker ) ) {
			return false;
		}

		$payload = self::get_instance()->get_quote( $ticker );

		return is_wp_error( $payload ) ? false : $payload;
	}

	/**
	 * Filter handler: price history.
	 *
	 * @since 1.1.90
	 *
	 * @param array|false $result Previous result.
	 * @param array       $params Request parameters (ticker, period, interval).
	 * @return array|false Finnhub payload or false (pass-through).
	 */
	public static function filter_price_history( $result, $params ) {
		if ( false !== $result || ! self::is_enabled() ) {
			return $result;
		}

		$ticker = isset( $params['ticker'] ) ? strtoupper( sanitize_text_field( $params['ticker'] ) ) : '';
		$period = isset( $params['period'] ) ? sanitize_text_field( $params['period'] ) : '1mo';

		if ( ! self::is_valid_symbol( $ticker ) ) {
			return false;
		}

		$payload = self::get_instance()->get_history( $ticker, $period );

		return is_wp_error( $payload ) ? false : $payload;
	}

	/**
	 * Filter handler: ticker search.
	 *
	 * @since 1.1.90
	 *
	 * @param array|false $result Previous result.
	 * @param array       $params Request parameters (query).
	 * @return array|false Finnhub results or false (pass-through).
	 */
	public static function filter_search_ticker( $result, $params ) {
		if ( false !== $result || ! self::is_enabled() ) {
			return $result;
		}

		$query = isset( $params['query'] ) ? sanitize_text_field( $params['query'] ) : '';

		if ( '' === $query ) {
			return false;
		}

		$payload = self::get_instance()->search_symbol( $query );

		return is_wp_error( $payload ) ? false : $payload;
	}

	/**
	 * Filter handler: batch prices.
	 *
	 * @since 1.1.90
	 *
	 * @param array|false $result Previous result.
	 * @param array       $params Request parameters (tickers).
	 * @return array|false Finnhub payload or false (pass-through).
	 */
	public static function filter_batch_prices( $result, $params ) {
		if ( false !== $result || ! self::is_enabled() ) {
			return $result;
		}

		$tickers = isset( $params['tickers'] ) && is_array( $params['tickers'] ) ? $params['tickers'] : array();
		$tickers = array_filter(
			array_map(
				function ( $ticker ) {
					return strtoupper( sanitize_text_field( $ticker ) );
				},
				$tickers
			)
		);
		$tickers = array_slice( array_values( $tickers ), 0, 50 );

		if ( empty( $tickers ) ) {
			return false;
		}

		$payload = self::get_instance()->get_batch_quotes( $tickers );

		return is_wp_error( $payload ) ? false : $payload;
	}

	/**
	 * Fetch a normalized quote for a symbol.
	 *
	 * @since 1.1.90
	 *
	 * @param string $symbol Ticker symbol.
	 * @return array|WP_Error Normalized quote or error.
	 */
	public function get_quote( $symbol ) {
		$symbol = strtoupper( sanitize_text_field( $symbol ) );

		if ( ! self::is_valid_symbol( $symbol ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_symbol', __( 'Invalid ticker symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$cache_key = self::CACHE_GROUP . '_quote_' . md5( $symbol );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$data = $this->request_json( '/quote', array( 'symbol' => $symbol ) );

		if ( is_wp_error( $data ) ) {
			return $this->serve_stale( $cache_key, $data );
		}

		if ( ! isset( $data['c'] ) || ! is_numeric( $data['c'] ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Finnhub returned no data for this symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$payload = array(
			'symbol'         => $symbol,
			'current_price'  => (float) $data['c'],
			'open'           => isset( $data['o'] ) ? (float) $data['o'] : 0.0,
			'high'           => isset( $data['h'] ) ? (float) $data['h'] : 0.0,
			'low'            => isset( $data['l'] ) ? (float) $data['l'] : 0.0,
			'prev_close'     => isset( $data['pc'] ) ? (float) $data['pc'] : 0.0,
			'change'         => isset( $data['d'] ) ? (float) $data['d'] : 0.0,
			'change_percent' => isset( $data['dp'] ) ? (float) $data['dp'] : 0.0,
			'date'           => isset( $data['t'] ) ? gmdate( 'Y-m-d', absint( $data['t'] ) ) : gmdate( 'Y-m-d' ),
			'currency'       => 'USD',
			'source'         => 'finnhub',
			'delayed'        => true,
			'from_cache'     => false,
		);

		$this->cache_success( $cache_key, $payload );

		return $payload;
	}

	/**
	 * Fetch normalized daily history for a symbol.
	 *
	 * @since 1.1.90
	 *
	 * @param string $symbol Ticker symbol.
	 * @param string $period Period (1mo, 3mo, 1y, ...).
	 * @return array|WP_Error Normalized history or error.
	 */
	public function get_history( $symbol, $period = '1mo' ) {
		$symbol = strtoupper( sanitize_text_field( $symbol ) );
		$period = sanitize_text_field( $period );

		if ( ! self::is_valid_symbol( $symbol ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_symbol', __( 'Invalid ticker symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$cache_key = self::CACHE_GROUP . '_history_' . md5( $symbol . '_' . $period );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$days = 30;
		$map  = array(
			'5d'  => 5,
			'1mo' => 30,
			'3mo' => 91,
			'6mo' => 182,
			'1y'  => 365,
			'2y'  => 730,
			'5y'  => 1825,
			'max' => 3650,
		);
		if ( isset( $map[ strtolower( $period ) ] ) ) {
			$days = $map[ strtolower( $period ) ];
		}

		$from = strtotime( '- ' . $days . ' days' );
		$to   = time();

		$data = $this->request_json(
			'/stock/candle',
			array(
				'symbol'     => $symbol,
				'resolution' => 'D',
				'from'       => $from,
				'to'         => $to,
			)
		);

		if ( is_wp_error( $data ) ) {
			return $this->serve_stale( $cache_key, $data );
		}

		// `s` (status) is a string and `t` (timestamps) an array by contract;
		// guard both so malformed payloads degrade to no-data instead of
		// strtolower()/count() TypeErrors on PHP 8.
		if ( empty( $data['t'] ) || ! is_array( $data['t'] ) || 'ok' !== strtolower( isset( $data['s'] ) && is_string( $data['s'] ) ? $data['s'] : '' ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Finnhub returned no history for this symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$rows = array();
		$max  = min( count( $data['t'] ), 500 );

		for ( $i = 0; $i < $max; $i++ ) {
			if ( ! isset( $data['t'][ $i ], $data['c'][ $i ] ) ) {
				continue;
			}
			$rows[] = array(
				'date'   => gmdate( 'Y-m-d', absint( $data['t'][ $i ] ) ),
				'open'   => isset( $data['o'][ $i ] ) ? (float) $data['o'][ $i ] : 0.0,
				'high'   => isset( $data['h'][ $i ] ) ? (float) $data['h'][ $i ] : 0.0,
				'low'    => isset( $data['l'][ $i ] ) ? (float) $data['l'][ $i ] : 0.0,
				'close'  => (float) $data['c'][ $i ],
				'volume' => isset( $data['v'][ $i ] ) ? (int) $data['v'][ $i ] : 0,
			);
		}

		if ( empty( $rows ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Finnhub returned no history for this symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$payload = array(
			'symbol'     => $symbol,
			'period'     => $period,
			'interval'   => '1d',
			'count'      => count( $rows ),
			'data'       => $rows,
			'source'     => 'finnhub',
			'delayed'    => true,
			'from_cache' => false,
		);

		$this->cache_success( $cache_key, $payload );

		return $payload;
	}

	/**
	 * Search symbols via Finnhub.
	 *
	 * @since 1.1.90
	 *
	 * @param string $query Search query.
	 * @return array|WP_Error Array of {symbol, description, type} or error.
	 */
	public function search_symbol( $query ) {
		$query = sanitize_text_field( $query );

		if ( '' === $query ) {
			return new WP_Error( 'wp_mcp_ai_missing_query', __( 'Search query is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$cache_key = self::CACHE_GROUP . '_search_' . md5( $query );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$data = $this->request_json( '/search', array( 'q' => $query ) );

		if ( is_wp_error( $data ) ) {
			return $this->serve_stale( $cache_key, $data );
		}

		$raw_results = isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : array();
		$results     = array();

		foreach ( array_slice( $raw_results, 0, 25 ) as $item ) {
			if ( empty( $item['symbol'] ) ) {
				continue;
			}
			$results[] = array(
				'symbol'      => strtoupper( sanitize_text_field( $item['symbol'] ) ),
				'description' => isset( $item['description'] ) ? sanitize_text_field( $item['description'] ) : '',
				'type'        => isset( $item['type'] ) ? sanitize_text_field( $item['type'] ) : '',
			);
		}

		if ( empty( $results ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Finnhub returned no search results.', 'mcp-ai-wpoos-pro' ) );
		}

		$this->cache_success( $cache_key, $results );

		return $results;
	}

	/**
	 * Fetch normalized batch quotes for multiple tickers.
	 *
	 * @since 1.1.90
	 *
	 * @param array $tickers Ticker symbols (max 50).
	 * @return array|WP_Error Payload with data map keyed by ticker, or error.
	 */
	public function get_batch_quotes( $tickers ) {
		$tickers = array_slice( array_values( array_filter( $tickers ) ), 0, 50 );

		if ( empty( $tickers ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_tickers', __( 'Tickers array is required.', 'mcp-ai-wpoos-pro' ) );
		}

		sort( $tickers );

		$cache_key = self::CACHE_GROUP . '_batch_' . md5( implode( ',', $tickers ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$params = array();
		foreach ( $tickers as $ticker ) {
			$params['symbol'] = isset( $params['symbol'] ) ? $params['symbol'] . ',' . $ticker : $ticker;
		}

		$data = $this->request_json( '/quote', $params );

		if ( is_wp_error( $data ) ) {
			return $this->serve_stale( $cache_key, $data );
		}

		$list = is_array( $data ) && isset( $data['c'] ) ? array( $data ) : $data;

		if ( ! is_array( $list ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Finnhub returned no batch data.', 'mcp-ai-wpoos-pro' ) );
		}

		$map = array();
		foreach ( $list as $item ) {
			if ( empty( $item['symbol'] ) || ! isset( $item['c'] ) ) {
				continue;
			}
			$symbol         = strtoupper( sanitize_text_field( $item['symbol'] ) );
			$map[ $symbol ] = array(
				'current_price'  => (float) $item['c'],
				'open'           => isset( $item['o'] ) ? (float) $item['o'] : 0.0,
				'high'           => isset( $item['h'] ) ? (float) $item['h'] : 0.0,
				'low'            => isset( $item['l'] ) ? (float) $item['l'] : 0.0,
				'prev_close'     => isset( $item['pc'] ) ? (float) $item['pc'] : 0.0,
				'change'         => isset( $item['d'] ) ? (float) $item['d'] : 0.0,
				'change_percent' => isset( $item['dp'] ) ? (float) $item['dp'] : 0.0,
				'source'         => 'finnhub',
				'delayed'        => true,
			);
		}

		if ( empty( $map ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Finnhub returned no batch data.', 'mcp-ai-wpoos-pro' ) );
		}

		$payload = array(
			'data'       => $map,
			'source'     => 'finnhub',
			'delayed'    => true,
			'from_cache' => false,
		);

		$this->cache_success( $cache_key, $payload );

		return $payload;
	}

	/**
	 * Fetch a company profile for a symbol.
	 *
	 * @since 1.1.90
	 *
	 * @param string $symbol Ticker symbol.
	 * @return array|WP_Error Normalized profile or error.
	 */
	public function get_company_profile( $symbol ) {
		$symbol = strtoupper( sanitize_text_field( $symbol ) );

		if ( ! self::is_valid_symbol( $symbol ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_symbol', __( 'Invalid ticker symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$cache_key = self::CACHE_GROUP . '_profile_' . md5( $symbol );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$data = $this->request_json( '/stock/profile2', array( 'symbol' => $symbol ) );

		if ( is_wp_error( $data ) ) {
			return $this->serve_stale( $cache_key, $data );
		}

		if ( empty( $data['name'] ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Finnhub returned no profile for this symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$payload = array(
			'symbol'             => $symbol,
			'name'               => sanitize_text_field( $data['name'] ),
			'exchange'           => isset( $data['exchange'] ) ? sanitize_text_field( $data['exchange'] ) : '',
			'currency'           => isset( $data['currency'] ) ? sanitize_text_field( $data['currency'] ) : 'USD',
			'industry'           => isset( $data['finnhubIndustry'] ) ? sanitize_text_field( $data['finnhubIndustry'] ) : '',
			'ipo_date'           => isset( $data['ipo'] ) ? sanitize_text_field( $data['ipo'] ) : '',
			'market_cap_musd'    => isset( $data['marketCapitalization'] ) ? (float) $data['marketCapitalization'] : 0.0,
			'shares_outstanding' => isset( $data['shareOutstanding'] ) ? (float) $data['shareOutstanding'] : 0.0,
			'weburl'             => isset( $data['weburl'] ) ? esc_url_raw( $data['weburl'] ) : '',
			'source'             => 'finnhub',
			'delayed'            => true,
			'from_cache'         => false,
		);

		$this->cache_success( $cache_key, $payload );

		return $payload;
	}

	/**
	 * Fetch recent company news for a symbol.
	 *
	 * @since 1.1.90
	 *
	 * @param string $symbol Ticker symbol.
	 * @param int    $limit  Result limit (1-20).
	 * @return array|WP_Error Normalized headlines or error.
	 */
	public function get_news( $symbol, $limit = self::NEWS_LIMIT ) {
		$symbol = strtoupper( sanitize_text_field( $symbol ) );
		$limit  = min( max( absint( $limit ), 1 ), 20 );

		if ( ! self::is_valid_symbol( $symbol ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_symbol', __( 'Invalid ticker symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$cache_key = self::CACHE_GROUP . '_news_' . md5( $symbol . '_' . $limit );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$data = $this->request_json(
			'/company-news',
			array(
				'symbol' => $symbol,
				'from'   => gmdate( 'Y-m-d', strtotime( '- ' . self::NEWS_WINDOW_DAYS . ' days' ) ),
				'to'     => gmdate( 'Y-m-d' ),
			)
		);

		if ( is_wp_error( $data ) ) {
			return $this->serve_stale( $cache_key, $data );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Finnhub returned no news for this symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$items = array();
		foreach ( array_slice( $data, 0, $limit ) as $item ) {
			$items[] = array(
				'headline' => isset( $item['headline'] ) ? sanitize_text_field( $item['headline'] ) : '',
				'summary'  => isset( $item['summary'] ) ? sanitize_text_field( $item['summary'] ) : '',
				'source'   => isset( $item['source'] ) ? sanitize_text_field( $item['source'] ) : '',
				'url'      => isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : '',
				'datetime' => isset( $item['datetime'] ) ? gmdate( 'Y-m-d H:i', absint( $item['datetime'] ) ) : '',
			);
		}

		$payload = array(
			'symbol'     => $symbol,
			'count'      => count( $items ),
			'items'      => $items,
			'source'     => 'finnhub',
			'from_cache' => false,
		);

		$this->cache_success( $cache_key, $payload );

		return $payload;
	}

	/**
	 * Perform a Finnhub GET request with key rotation.
	 *
	 * On 401/403/429 the next configured key is tried. All requests flow
	 * through the shared `wp_mcp_ai_market_data_http_response` seam so tests
	 * can substitute deterministic responses without touching the network.
	 *
	 * @since 1.1.90
	 *
	 * @param string $path   API path (leading slash, no query string).
	 * @param array  $params Query parameters (token appended automatically).
	 * @return array|WP_Error Decoded JSON body or error.
	 */
	protected function request_json( $path, $params = array() ) {
		$keys = self::get_keys();

		if ( empty( $keys ) ) {
			return new WP_Error( 'wp_mcp_ai_finnhub_no_key', __( 'No Finnhub API key configured.', 'mcp-ai-wpoos-pro' ) );
		}

		$last_error = null;

		foreach ( $keys as $key ) {
			$query = array_merge( $params, array( 'token' => $key ) );
			$url   = add_query_arg( $query, self::API_BASE . $path );

			$seam = apply_filters( 'wp_mcp_ai_market_data_http_response', null, $url, array() );

			if ( null !== $seam ) {
				if ( is_wp_error( $seam ) ) {
					return $seam;
				}

				$body = isset( $seam['body'] ) ? $seam['body'] : '';
				$code = isset( $seam['code'] ) ? absint( $seam['code'] ) : 200;
			} else {
				$response = wp_remote_get(
					$url,
					array(
						'timeout'    => self::TIMEOUT,
						'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
					)
				);

				if ( is_wp_error( $response ) ) {
					$last_error = $response;
					continue;
				}

				$body = wp_remote_retrieve_body( $response );
				$code = wp_remote_retrieve_response_code( $response );
			}

			if ( in_array( $code, array( 401, 403, 429 ), true ) ) {
				$last_error = new WP_Error(
					'wp_mcp_ai_finnhub_rate_limited',
					/* translators: %d: HTTP status code */
					sprintf( __( 'Finnhub rejected the request with HTTP %d. Rotating key.', 'mcp-ai-wpoos-pro' ), $code )
				);
				continue;
			}

			if ( 200 !== $code ) {
				return new WP_Error(
					'wp_mcp_ai_provider_http_error',
					/* translators: %d: HTTP status code */
					sprintf( __( 'Finnhub returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $code )
				);
			}

			$decoded = json_decode( $body, true );

			if ( null === $decoded ) {
				return new WP_Error(
					'wp_mcp_ai_market_data_invalid_json',
					__( 'Finnhub returned an unparseable response.', 'mcp-ai-wpoos-pro' )
				);
			}

			return $decoded;
		}

		if ( $last_error instanceof WP_Error ) {
			return $last_error;
		}

		return new WP_Error(
			'wp_mcp_ai_market_data_fetch_failed',
			__( 'Failed to fetch market data from Finnhub.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Get the fresh-cache TTL for the current data mode.
	 *
	 * @since 1.1.90
	 *
	 * @return int TTL in seconds.
	 */
	protected function get_fresh_ttl() {
		return 'realtime' === self::get_data_mode() ? self::TTL_REALTIME : self::TTL_CACHED;
	}

	/**
	 * Cache a successful fetch (fresh + stale copies).
	 *
	 * @since 1.1.90
	 *
	 * @param string $cache_key Cache key.
	 * @param mixed  $payload   Payload.
	 * @return void
	 */
	protected function cache_success( $cache_key, $payload ) {
		set_transient( $cache_key, $payload, $this->get_fresh_ttl() );
		set_transient(
			$cache_key . '_stale',
			array(
				'ts'   => time(),
				'data' => $payload,
			),
			self::STALE_TTL
		);
	}

	/**
	 * Serve the stale (last-known-good) copy when a live fetch fails.
	 *
	 * @since 1.1.90
	 *
	 * @param string   $cache_key Cache key.
	 * @param WP_Error $error     Live-fetch error (used when no stale copy).
	 * @return array|WP_Error Stale payload or the original error.
	 */
	protected function serve_stale( $cache_key, $error ) {
		$stale = get_transient( $cache_key . '_stale' );

		if ( false !== $stale && isset( $stale['data'] ) && is_array( $stale['data'] ) ) {
			$data                           = $stale['data'];
			$data['stale']                  = true;
			$data['from_cache']             = true;
			$data['stale_while_revalidate'] = true;
			$data['data_age_seconds']       = max( 0, time() - (int) ( isset( $stale['ts'] ) ? $stale['ts'] : time() ) );

			return $data;
		}

		return $error;
	}
}
