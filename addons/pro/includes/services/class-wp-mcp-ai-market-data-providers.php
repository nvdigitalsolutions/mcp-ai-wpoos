<?php
/**
 * Market Data Providers
 *
 * Keyless, public-endpoint market data providers used as fallbacks for the
 * yfinance microservice and as primary sources for the macro / screener /
 * options / earnings / economic-calendar / crypto tools.
 *
 * Every provider is a small, isolated method returning a normalized array or
 * WP_Error. None of them require an API key. All requests are time-boxed and
 * routed through a filterable HTTP seam so tests can substitute deterministic
 * responses without touching the network.
 *
 * Inspired by the provider fallback-chain architecture of OpenTerminal
 * (https://github.com/ErTasselli/OpenTerminal): a single provider hiccup
 * never takes the whole toolkit down.
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
 * Market Data Providers Class.
 */
class WP_MCP_AI_Market_Data_Providers {

	/**
	 * Provider slugs.
	 */
	const PROVIDER_YFINANCE      = 'yfinance';
	const PROVIDER_STOOQ         = 'stooq';
	const PROVIDER_NASDAQ        = 'nasdaq';
	const PROVIDER_COINGECKO     = 'coingecko';
	const PROVIDER_BINANCE       = 'binance';
	const PROVIDER_FRED          = 'fred';
	const PROVIDER_TRADINGVIEW   = 'tradingview';
	const PROVIDER_FOREX_FACTORY = 'forex_factory';

	/**
	 * Default request timeout in seconds.
	 */
	const TIMEOUT = 15;

	/**
	 * Maximum rows returned by history/normalized endpoints.
	 */
	const MAX_ROWS = 500;

	/**
	 * FRED series allowlist (no API key via fredgraph.csv).
	 */
	const FRED_SERIES = array(
		'DGS10',    // 10-year Treasury yield.
		'DGS2',     // 2-year Treasury yield.
		'DGS30',    // 30-year Treasury yield.
		'DGS3MO',   // 3-month Treasury yield.
		'T10Y2Y',   // 10y-2y yield spread.
		'VIXCLS',   // CBOE Volatility Index.
		'DFF',      // Federal funds rate.
		'CPIAUCSL', // CPI.
		'UNRATE',   // Unemployment rate.
		'SP500',    // S&P 500 index.
		'DJIA',     // Dow Jones Industrial Average.
		'NASDAQCOM', // NASDAQ Composite.
	);

	/**
	 * Get singleton instance.
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
	 * Validate a ticker symbol.
	 *
	 * Allows letters, digits, dots, hyphens, carets, equals and slashes
	 * (Stooq/Yahoo/Binance symbol alphabets). Rejects anything else.
	 *
	 * @param string $symbol Symbol to validate.
	 * @return bool
	 */
	public static function is_valid_symbol( $symbol ) {
		return (bool) preg_match( '/^[A-Z0-9.\-^=\/]{1,15}$/i', trim( (string) $symbol ) );
	}

	/**
	 * Normalize a period string into a day count.
	 *
	 * @param string $period Period (1mo, 3mo, 1y, etc.).
	 * @return int Days.
	 */
	public static function period_to_days( $period ) {
		$map = array(
			'5d'  => 5,
			'1mo' => 30,
			'3mo' => 91,
			'6mo' => 182,
			'1y'  => 365,
			'2y'  => 730,
			'5y'  => 1825,
			'max' => 3650,
		);

		$period = strtolower( sanitize_text_field( (string) $period ) );

		return isset( $map[ $period ] ) ? $map[ $period ] : 30;
	}

	/**
	 * Perform an HTTP GET with the filter seam + status guard.
	 *
	 * Filter `wp_mcp_ai_market_data_http_response` receives (null, $url, $headers)
	 * and may return an array with 'body' + 'code' keys, or a WP_Error, to
	 * short-circuit the network call.
	 *
	 * @param string $url     URL to fetch.
	 * @param array  $headers Extra headers.
	 * @param int    $timeout Timeout in seconds.
	 * @return array|WP_Error Array with 'body' and 'code' keys, or WP_Error.
	 */
	protected function http_get( $url, $headers = array(), $timeout = self::TIMEOUT ) {
		$seam = apply_filters( 'wp_mcp_ai_market_data_http_response', null, $url, $headers );
		if ( null !== $seam ) {
			if ( is_wp_error( $seam ) ) {
				return $seam;
			}

			$body = isset( $seam['body'] ) ? $seam['body'] : '';
			$code = isset( $seam['code'] ) ? absint( $seam['code'] ) : 200;

			return array(
				'body' => $body,
				'code' => $code,
			);
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => $timeout,
				'headers'    => $headers,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'body' => wp_remote_retrieve_body( $response ),
			'code' => wp_remote_retrieve_response_code( $response ),
		);
	}

	/**
	 * Perform an HTTP POST with the filter seam + status guard.
	 *
	 * @param string $url     URL to post to.
	 * @param array  $body    JSON body.
	 * @param array  $headers Extra headers.
	 * @param int    $timeout Timeout in seconds.
	 * @return array|WP_Error Array with 'body' and 'code' keys, or WP_Error.
	 */
	protected function http_post( $url, $body, $headers = array(), $timeout = self::TIMEOUT ) {
		$seam = apply_filters( 'wp_mcp_ai_market_data_http_response', null, $url, $headers, 'POST', $body );
		if ( null !== $seam ) {
			if ( is_wp_error( $seam ) ) {
				return $seam;
			}

			$body_out = isset( $seam['body'] ) ? $seam['body'] : '';
			$code_out = isset( $seam['code'] ) ? absint( $seam['code'] ) : 200;

			return array(
				'body' => $body_out,
				'code' => $code_out,
			);
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array_merge(
					array( 'Content-Type' => 'application/json' ),
					$headers
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'body' => wp_remote_retrieve_body( $response ),
			'code' => wp_remote_retrieve_response_code( $response ),
		);
	}

	/**
	 * Decode a JSON HTTP response body.
	 *
	 * @param string $body Raw body.
	 * @return array|WP_Error Decoded array or WP_Error.
	 */
	protected function decode_json( $body ) {
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_market_data_invalid_json',
				__( 'Provider returned an unparseable response.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $decoded;
	}

	/**
	 * Browser-like headers used by the Nasdaq/TradingView public APIs.
	 *
	 * @return array
	 */
	protected function get_browser_headers() {
		return array(
			'Accept'          => 'application/json, text/plain, */*',
			'Accept-Language' => 'en-US,en;q=0.9',
			'Referer'         => 'https://www.nasdaq.com/',
			'Origin'          => 'https://www.nasdaq.com',
		);
	}

	/**
	 * Get the fallback chain for a data type.
	 *
	 * Order matters: first successful provider wins. The chains list only the
	 * locally dispatched providers; the yfinance microservice is injected as
	 * the primary by WP_MCP_AI_YFinance_Service before these run. Extensible
	 * via `wp_mcp_ai_market_data_fallback_chains`.
	 *
	 * @return array Map of type => provider slugs.
	 */
	public function get_fallback_chains() {
		$chains = array(
			'quote'   => array( self::PROVIDER_STOOQ, self::PROVIDER_NASDAQ ),
			'history' => array( self::PROVIDER_STOOQ ),
			'crypto'  => array( self::PROVIDER_COINGECKO, self::PROVIDER_BINANCE ),
		);

		/**
		 * Filter the provider fallback chains.
		 *
		 * @since 1.1.80
		 *
		 * @param array $chains Map of type => provider slugs.
		 */
		return apply_filters( 'wp_mcp_ai_market_data_fallback_chains', $chains );
	}

	// =========================================================================
	// Stooq (CSV, no key)
	// =========================================================================

	/**
	 * Fetch a quote from Stooq.
	 *
	 * @param string $symbol Ticker symbol.
	 * @return array|WP_Error Normalized quote or error.
	 */
	public function stooq_get_quote( $symbol ) {
		$symbol = strtoupper( sanitize_text_field( $symbol ) );

		if ( ! self::is_valid_symbol( $symbol ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_symbol', __( 'Invalid ticker symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$url      = 'https://stooq.com/q/l/?s=' . rawurlencode( $symbol ) . '&f=sd2t2ohlcv&h&e=csv';
		$response = $this->http_get( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] ) {
			return new WP_Error(
				'wp_mcp_ai_provider_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Stooq returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $response['code'] )
			);
		}

		$lines = preg_split( '/\r\n|\r|\n/', trim( $response['body'] ) );
		if ( empty( $lines ) || count( $lines ) < 2 ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Stooq returned no data for this symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		// The data line carries the symbol, date, time, OHLC, and volume cells.
		$cells = str_getcsv( $lines[1] );
		if ( count( $cells ) < 8 || 'N/D' === $cells[6] ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Stooq returned no data for this symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'symbol'        => $symbol,
			'current_price' => (float) $cells[6],
			'open'          => (float) $cells[3],
			'high'          => (float) $cells[4],
			'low'           => (float) $cells[5],
			'volume'        => (int) $cells[7],
			'date'          => sanitize_text_field( $cells[1] ),
			'source'        => self::PROVIDER_STOOQ,
		);
	}

	/**
	 * Fetch daily history from Stooq.
	 *
	 * @param string $symbol Ticker symbol.
	 * @param string $period Period (1mo, 3mo, 1y, ...).
	 * @return array|WP_Error Normalized history or error.
	 */
	public function stooq_get_history( $symbol, $period = '1mo' ) {
		$symbol = strtoupper( sanitize_text_field( $symbol ) );

		if ( ! self::is_valid_symbol( $symbol ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_symbol', __( 'Invalid ticker symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$days = self::period_to_days( $period );
		$from = gmdate( 'Ymd', strtotime( '- ' . $days . ' days' ) );
		$to   = gmdate( 'Ymd' );

		$url      = 'https://stooq.com/q/d/l/?s=' . rawurlencode( $symbol ) . '&d1=' . $from . '&d2=' . $to . '&i=d';
		$response = $this->http_get( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] ) {
			return new WP_Error(
				'wp_mcp_ai_provider_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Stooq returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $response['code'] )
			);
		}

		$lines = preg_split( '/\r\n|\r|\n/', trim( $response['body'] ) );
		if ( empty( $lines ) || count( $lines ) < 2 ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Stooq returned no history for this symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$rows = array();
		// Skip header row; Date,Open,High,Low,Close,Volume.
		$data_lines = array_slice( $lines, 1, self::MAX_ROWS );
		foreach ( $data_lines as $line ) {
			$cells = str_getcsv( $line );
			if ( count( $cells ) < 6 ) {
				continue;
			}
			$rows[] = array(
				'date'   => sanitize_text_field( $cells[0] ),
				'open'   => (float) $cells[1],
				'high'   => (float) $cells[2],
				'low'    => (float) $cells[3],
				'close'  => (float) $cells[4],
				'volume' => (int) $cells[5],
			);
		}

		if ( empty( $rows ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Stooq returned no history for this symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'symbol'   => $symbol,
			'period'   => sanitize_text_field( $period ),
			'interval' => '1d',
			'count'    => count( $rows ),
			'data'     => $rows,
			'source'   => self::PROVIDER_STOOQ,
		);
	}

	// =========================================================================
	// Nasdaq public APIs (no key, browser-like headers required)
	// =========================================================================

	/**
	 * Fetch a quote summary from the Nasdaq public API.
	 *
	 * @param string $symbol Ticker symbol.
	 * @return array|WP_Error Normalized quote or error.
	 */
	public function nasdaq_get_quote( $symbol ) {
		$symbol = strtoupper( sanitize_text_field( $symbol ) );

		if ( ! self::is_valid_symbol( $symbol ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_symbol', __( 'Invalid ticker symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$url      = 'https://api.nasdaq.com/api/quote/' . rawurlencode( $symbol ) . '/summary?assetclass=stocks';
		$response = $this->http_get( $url, $this->get_browser_headers() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] ) {
			return new WP_Error(
				'wp_mcp_ai_provider_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Nasdaq returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $response['code'] )
			);
		}

		$decoded = $this->decode_json( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$rows = isset( $decoded['data']['summaryData'] ) && is_array( $decoded['data']['summaryData'] )
			? $decoded['data']['summaryData']
			: array();

		$fields = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['label'], $row['value'] ) && is_string( $row['value'] ) ) {
				$fields[ sanitize_title( (string) $row['label'] ) ] = sanitize_text_field( $row['value'] );
			}
		}

		if ( empty( $fields ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Nasdaq returned no summary data for this symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$current_price = isset( $fields['last-sale-price'] ) ? (float) str_replace( '$', '', $fields['last-sale-price'] ) : null;

		if ( null === $current_price ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Nasdaq returned no quote for this symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'symbol'        => $symbol,
			'current_price' => $current_price,
			'volume'        => isset( $fields['volume'] ) ? (int) str_replace( ',', '', $fields['volume'] ) : null,
			'market_cap'    => isset( $fields['market-cap'] ) ? sanitize_text_field( $fields['market-cap'] ) : null,
			'name'          => isset( $decoded['data']['name'] ) ? sanitize_text_field( $decoded['data']['name'] ) : $symbol,
			'date'          => gmdate( 'Y-m-d' ),
			'source'        => self::PROVIDER_NASDAQ,
		);
	}

	/**
	 * Fetch an options chain from the Nasdaq public API.
	 *
	 * @param string $symbol Ticker symbol.
	 * @param int    $limit  Maximum rows per side.
	 * @return array|WP_Error Normalized chain or error.
	 */
	public function nasdaq_get_options_chain( $symbol, $limit = 60 ) {
		$symbol = strtoupper( sanitize_text_field( $symbol ) );

		if ( ! self::is_valid_symbol( $symbol ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_symbol', __( 'Invalid ticker symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$limit    = min( max( (int) $limit, 1 ), 200 );
		$url      = 'https://api.nasdaq.com/api/quote/' . rawurlencode( $symbol ) . '/option-chain?assetclass=stocks&limit=' . $limit . '&fromdate=all&todate=all';
		$response = $this->http_get( $url, $this->get_browser_headers() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] ) {
			return new WP_Error(
				'wp_mcp_ai_provider_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Nasdaq returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $response['code'] )
			);
		}

		$decoded = $this->decode_json( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$table = isset( $decoded['data']['table'] ) && is_array( $decoded['data']['table'] ) ? $decoded['data']['table'] : array();

		$chain = array(
			'calls' => array(),
			'puts'  => array(),
		);

		foreach ( $table as $row ) {
			foreach ( array(
				'calls' => 'call',
				'puts'  => 'put',
			) as $group_key => $side ) {
				if ( ! isset( $row[ $group_key ] ) || ! is_array( $row[ $group_key ] ) ) {
					continue;
				}
				foreach ( $row[ $group_key ] as $option ) {
					$chain[ $group_key ][] = array(
						'expiration'    => isset( $option['expiryGroup'] ) ? sanitize_text_field( $option['expiryGroup'] ) : '',
						'strike'        => isset( $option['strike'] ) ? (float) $option['strike'] : 0.0,
						'side'          => $side,
						'bid'           => isset( $option['bid'] ) ? (float) $option['bid'] : null,
						'ask'           => isset( $option['ask'] ) ? (float) $option['ask'] : null,
						'volume'        => isset( $option['volume'] ) ? (int) $option['volume'] : 0,
						'open_interest' => isset( $option['openInterest'] ) ? (int) $option['openInterest'] : 0,
						'in_the_money'  => isset( $option['inTheMoney'] ) ? (bool) $option['inTheMoney'] : false,
					);
				}
			}
		}

		if ( empty( $chain['calls'] ) && empty( $chain['puts'] ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Nasdaq returned no options data for this symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'symbol' => $symbol,
			'chain'  => $chain,
			'source' => self::PROVIDER_NASDAQ,
		);
	}

	/**
	 * Fetch the earnings calendar from the Nasdaq public API.
	 *
	 * @param string $date Date in YYYY-MM-DD format.
	 * @return array|WP_Error Normalized calendar or error.
	 */
	public function nasdaq_get_earnings_calendar( $date ) {
		$date = sanitize_text_field( $date );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
		}

		$url      = 'https://api.nasdaq.com/api/calendar/earnings?date=' . rawurlencode( $date );
		$response = $this->http_get( $url, $this->get_browser_headers() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] ) {
			return new WP_Error(
				'wp_mcp_ai_provider_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Nasdaq returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $response['code'] )
			);
		}

		$decoded = $this->decode_json( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$rows = isset( $decoded['data']['rows'] ) && is_array( $decoded['data']['rows'] ) ? $decoded['data']['rows'] : array();
		if ( empty( $rows ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Nasdaq returned no earnings events for this date.', 'mcp-ai-wpoos-pro' ) );
		}

		$events = array();
		foreach ( $rows as $row ) {
			$events[] = array(
				'symbol'       => isset( $row['symbol'] ) ? sanitize_text_field( $row['symbol'] ) : '',
				'company_name' => isset( $row['companyName'] ) ? sanitize_text_field( $row['companyName'] ) : '',
				'market_cap'   => isset( $row['marketCap'] ) ? sanitize_text_field( $row['marketCap'] ) : '',
				'timing'       => isset( $row['time'] ) ? sanitize_text_field( $row['time'] ) : '',
				'eps_forecast' => isset( $row['epsForecast'] ) ? sanitize_text_field( $row['epsForecast'] ) : '',
				'no_of_ests'   => isset( $row['noOfEsts'] ) ? sanitize_text_field( $row['noOfEsts'] ) : '',
				'date'         => $date,
			);
		}

		return array(
			'date'   => $date,
			'events' => $events,
			'count'  => count( $events ),
			'source' => self::PROVIDER_NASDAQ,
		);
	}

	// =========================================================================
	// FRED (no-key fredgraph.csv endpoint)
	// =========================================================================

	/**
	 * Fetch macro series from FRED via the keyless fredgraph.csv endpoint.
	 *
	 * @param array $series Series IDs (allowlisted).
	 * @param int   $limit  Maximum observations (newest first).
	 * @return array|WP_Error Normalized series or error.
	 */
	public function fred_get_series( $series, $limit = 250 ) {
		if ( empty( $series ) || ! is_array( $series ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_series', __( 'At least one series ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$clean = array();
		foreach ( $series as $id ) {
			$id = strtoupper( sanitize_text_field( $id ) );
			if ( in_array( $id, self::FRED_SERIES, true ) ) {
				$clean[] = $id;
			}
		}
		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_series',
				/* translators: %s: allowlisted series */
				sprintf( __( 'Series must be one of: %s.', 'mcp-ai-wpoos-pro' ), implode( ', ', self::FRED_SERIES ) )
			);
		}

		$limit    = min( max( (int) $limit, 10 ), self::MAX_ROWS );
		$url      = 'https://fred.stlouisfed.org/graph/fredgraph.csv?id=' . rawurlencode( implode( ',', $clean ) ) . '&cosd=1900-01-01';
		$response = $this->http_get( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] ) {
			return new WP_Error(
				'wp_mcp_ai_provider_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'FRED returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $response['code'] )
			);
		}

		$lines = preg_split( '/\r\n|\r|\n/', trim( $response['body'] ) );
		if ( count( $lines ) < 2 ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'FRED returned no observations.', 'mcp-ai-wpoos-pro' ) );
		}

		$header     = str_getcsv( $lines[0] );
		$rows       = array();
		$data_lines = array_slice( $lines, 1 );
		foreach ( $data_lines as $line ) {
			$cells = str_getcsv( $line );
			if ( count( $cells ) < 2 ) {
				continue;
			}
			$row = array( 'date' => sanitize_text_field( $cells[0] ) );
			foreach ( $header as $index => $series_id ) {
				if ( 0 === $index ) {
					continue;
				}
				$value             = isset( $cells[ $index ] ) ? $cells[ $index ] : '';
				$row[ $series_id ] = ( '' === $value || '.' === $value ) ? null : (float) $value;
			}
			$rows[] = $row;
		}

		$rows = array_slice( $rows, max( 0, count( $rows ) - $limit ) );

		return array(
			'series'       => $clean,
			'count'        => count( $rows ),
			'observations' => $rows,
			'source'       => self::PROVIDER_FRED,
		);
	}

	// =========================================================================
	// TradingView scanner (public POST API, no key)
	// =========================================================================

	/**
	 * Screen the US equity market via the TradingView scanner API.
	 *
	 * @param array $filters Filter map (sector, market_cap_min, change_min, volume_min).
	 * @param int   $limit   Maximum results (50-5000 rows via range).
	 * @return array|WP_Error Normalized screener results or error.
	 */
	public function tradingview_screen_stocks( $filters = array(), $limit = 50 ) {
		$filters = is_array( $filters ) ? $filters : array();
		$limit   = min( max( (int) $limit, 10 ), 500 );

		$filter_clauses = array(
			array(
				'left'      => 'type',
				'operation' => 'equal',
				'right'     => 'stock',
			),
		);

		$sector = isset( $filters['sector'] ) ? sanitize_text_field( $filters['sector'] ) : '';
		if ( '' !== $sector ) {
			$filter_clauses[] = array(
				'left'      => 'sector',
				'operation' => 'equal',
				'right'     => $sector,
			);
		}

		$market_cap_min = isset( $filters['market_cap_min'] ) ? (float) $filters['market_cap_min'] : 0.0;
		if ( $market_cap_min > 0 ) {
			$filter_clauses[] = array(
				'left'      => 'market_cap_basic',
				'operation' => 'egreater',
				'right'     => $market_cap_min,
			);
		}

		$change_min = isset( $filters['change_min'] ) ? (float) $filters['change_min'] : 0.0;
		if ( 0.0 !== $change_min ) {
			$filter_clauses[] = array(
				'left'      => 'change',
				'operation' => 'egreater',
				'right'     => $change_min,
			);
		}

		$volume_min = isset( $filters['volume_min'] ) ? (float) $filters['volume_min'] : 0.0;
		if ( $volume_min > 0 ) {
			$filter_clauses[] = array(
				'left'      => 'volume',
				'operation' => 'egreater',
				'right'     => $volume_min,
			);
		}

		$body = array(
			'filter'  => $filter_clauses,
			'options' => array( 'lang' => 'en' ),
			'markets' => array( 'america' ),
			'symbols' => array(
				'query'   => array( 'types' => array() ),
				'tickers' => array(),
			),
			'columns' => array( 'name', 'description', 'close', 'change', 'volume', 'market_cap', 'sector', 'Recommend.All' ),
			'sort'    => array(
				'sortBy'    => 'market_cap',
				'sortOrder' => 'desc',
			),
			'range'   => array( 0, $limit ),
		);

		$response = $this->http_post(
			'https://scanner.tradingview.com/america/scan',
			$body,
			array(
				'Accept'       => 'application/json',
				'Content-Type' => 'text/plain;charset=UTF-8',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] ) {
			return new WP_Error(
				'wp_mcp_ai_provider_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'TradingView scanner returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $response['code'] )
			);
		}

		$decoded = $this->decode_json( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$data = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();
		if ( empty( $data ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'TradingView scanner returned no matches.', 'mcp-ai-wpoos-pro' ) );
		}

		$results = array();
		foreach ( $data as $row ) {
			$results[] = array(
				'symbol'      => isset( $row['s'] ) ? sanitize_text_field( $row['s'] ) : '',
				'name'        => isset( $row['d'][0] ) ? sanitize_text_field( $row['d'][0] ) : '',
				'description' => isset( $row['d'][1] ) ? sanitize_text_field( $row['d'][1] ) : '',
				'close'       => isset( $row['d'][2] ) ? (float) $row['d'][2] : null,
				'change_pct'  => isset( $row['d'][3] ) ? (float) $row['d'][3] : null,
				'volume'      => isset( $row['d'][4] ) ? (float) $row['d'][4] : null,
				'market_cap'  => isset( $row['d'][5] ) ? (float) $row['d'][5] : null,
				'sector'      => isset( $row['d'][6] ) ? sanitize_text_field( $row['d'][6] ) : '',
			);
		}

		return array(
			'count'   => count( $results ),
			'results' => $results,
			'filters' => $filters,
			'source'  => self::PROVIDER_TRADINGVIEW,
		);
	}

	// =========================================================================
	// Forex Factory economic calendar (public JSON feed)
	// =========================================================================

	/**
	 * Fetch the economic calendar from the Forex Factory public feed.
	 *
	 * @return array|WP_Error Normalized calendar or error.
	 */
	public function forex_factory_get_calendar() {
		$url      = 'https://nfs.faireconomy.media/ff_calendar_thisweek.json';
		$response = $this->http_get( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] ) {
			return new WP_Error(
				'wp_mcp_ai_provider_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Forex Factory returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $response['code'] )
			);
		}

		$decoded = $this->decode_json( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		if ( empty( $decoded ) || ! is_array( $decoded ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Forex Factory returned no calendar events.', 'mcp-ai-wpoos-pro' ) );
		}

		$events = array();
		foreach ( $decoded as $item ) {
			$events[] = array(
				'title'    => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
				'country'  => isset( $item['country'] ) ? sanitize_text_field( $item['country'] ) : '',
				'date'     => isset( $item['date'] ) ? sanitize_text_field( $item['date'] ) : '',
				'time'     => isset( $item['time'] ) ? sanitize_text_field( $item['time'] ) : '',
				'impact'   => isset( $item['impact'] ) ? sanitize_text_field( $item['impact'] ) : '',
				'forecast' => isset( $item['forecast'] ) ? sanitize_text_field( $item['forecast'] ) : '',
				'previous' => isset( $item['previous'] ) ? sanitize_text_field( $item['previous'] ) : '',
			);
		}

		return array(
			'count'  => count( $events ),
			'events' => $events,
			'source' => self::PROVIDER_FOREX_FACTORY,
		);
	}

	// =========================================================================
	// CoinGecko + Binance (crypto, no key)
	// =========================================================================

	/**
	 * Map a common crypto symbol to its CoinGecko id and Binance pair base.
	 *
	 * @param string $symbol Crypto symbol (btc, eth, ...).
	 * @return array|false Map or false when unknown.
	 */
	public static function get_crypto_symbol_map( $symbol ) {
		$map = array(
			'BTC'   => array(
				'id'   => 'bitcoin',
				'base' => 'BTC',
			),
			'ETH'   => array(
				'id'   => 'ethereum',
				'base' => 'ETH',
			),
			'USDT'  => array(
				'id'   => 'tether',
				'base' => 'USDT',
			),
			'BNB'   => array(
				'id'   => 'binancecoin',
				'base' => 'BNB',
			),
			'SOL'   => array(
				'id'   => 'solana',
				'base' => 'SOL',
			),
			'XRP'   => array(
				'id'   => 'ripple',
				'base' => 'XRP',
			),
			'ADA'   => array(
				'id'   => 'cardano',
				'base' => 'ADA',
			),
			'DOGE'  => array(
				'id'   => 'dogecoin',
				'base' => 'DOGE',
			),
			'AVAX'  => array(
				'id'   => 'avalanche-2',
				'base' => 'AVAX',
			),
			'DOT'   => array(
				'id'   => 'polkadot',
				'base' => 'DOT',
			),
			'LINK'  => array(
				'id'   => 'chainlink',
				'base' => 'LINK',
			),
			'MATIC' => array(
				'id'   => 'matic-network',
				'base' => 'MATIC',
			),
			'LTC'   => array(
				'id'   => 'litecoin',
				'base' => 'LTC',
			),
			'TRX'   => array(
				'id'   => 'tron',
				'base' => 'TRX',
			),
			'SHIB'  => array(
				'id'   => 'shiba-inu',
				'base' => 'SHIB',
			),
		);

		$symbol = strtoupper( sanitize_text_field( (string) $symbol ) );

		return isset( $map[ $symbol ] ) ? $map[ $symbol ] : false;
	}

	/**
	 * Fetch the crypto market board from CoinGecko.
	 *
	 * @param string $currency Quote currency (usd, eur, ...).
	 * @param int    $limit    Maximum assets.
	 * @return array|WP_Error Normalized board or error.
	 */
	public function coingecko_get_markets( $currency = 'usd', $limit = 50 ) {
		$currency = strtolower( sanitize_text_field( $currency ) );
		if ( ! preg_match( '/^[a-z]{2,4}$/', $currency ) ) {
			$currency = 'usd';
		}
		$limit = min( max( (int) $limit, 10 ), 100 );

		$url      = 'https://api.coingecko.com/api/v3/coins/markets?vs_currency=' . rawurlencode( $currency ) . '&order=market_cap_desc&per_page=' . $limit . '&page=1&sparkline=false&price_change_percentage=24h,7d';
		$response = $this->http_get( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] ) {
			return new WP_Error(
				'wp_mcp_ai_provider_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'CoinGecko returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $response['code'] )
			);
		}

		$decoded = $this->decode_json( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		if ( empty( $decoded ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'CoinGecko returned no market data.', 'mcp-ai-wpoos-pro' ) );
		}

		$assets = array();
		foreach ( $decoded as $coin ) {
			$assets[] = array(
				'symbol'                      => isset( $coin['symbol'] ) ? strtoupper( sanitize_text_field( $coin['symbol'] ) ) : '',
				'name'                        => isset( $coin['name'] ) ? sanitize_text_field( $coin['name'] ) : '',
				'current_price'               => isset( $coin['current_price'] ) ? (float) $coin['current_price'] : null,
				'market_cap'                  => isset( $coin['market_cap'] ) ? (float) $coin['market_cap'] : null,
				'market_cap_rank'             => isset( $coin['market_cap_rank'] ) ? (int) $coin['market_cap_rank'] : null,
				'price_change_percentage_24h' => isset( $coin['price_change_percentage_24h'] ) ? (float) $coin['price_change_percentage_24h'] : null,
				'price_change_percentage_7d'  => isset( $coin['price_change_percentage_7d_in_currency'] ) ? (float) $coin['price_change_percentage_7d_in_currency'] : null,
				'high_24h'                    => isset( $coin['high_24h'] ) ? (float) $coin['high_24h'] : null,
				'low_24h'                     => isset( $coin['low_24h'] ) ? (float) $coin['low_24h'] : null,
				'total_volume'                => isset( $coin['total_volume'] ) ? (float) $coin['total_volume'] : null,
			);
		}

		return array(
			'count'    => count( $assets ),
			'currency' => $currency,
			'assets'   => $assets,
			'source'   => self::PROVIDER_COINGECKO,
		);
	}

	/**
	 * Fetch crypto history from CoinGecko.
	 *
	 * @param string $symbol Crypto symbol (btc, eth, ...).
	 * @param string $currency Quote currency.
	 * @param string $period   Period (1mo, 3mo, 1y, ...).
	 * @return array|WP_Error Normalized history or error.
	 */
	public function coingecko_get_history( $symbol, $currency = 'usd', $period = '1mo' ) {
		$map = self::get_crypto_symbol_map( $symbol );
		if ( false === $map ) {
			return new WP_Error( 'wp_mcp_ai_invalid_symbol', __( 'Unknown crypto symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$currency = strtolower( sanitize_text_field( $currency ) );
		if ( ! preg_match( '/^[a-z]{2,4}$/', $currency ) ) {
			$currency = 'usd';
		}

		$days = self::period_to_days( $period );
		$url  = 'https://api.coingecko.com/api/v3/coins/' . rawurlencode( $map['id'] ) . '/market_chart?vs_currency=' . rawurlencode( $currency ) . '&days=' . $days . '&interval=daily';

		$response = $this->http_get( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] ) {
			return new WP_Error(
				'wp_mcp_ai_provider_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'CoinGecko returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $response['code'] )
			);
		}

		$decoded = $this->decode_json( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$prices  = isset( $decoded['prices'] ) && is_array( $decoded['prices'] ) ? $decoded['prices'] : array();
		$volumes = isset( $decoded['total_volumes'] ) && is_array( $decoded['total_volumes'] ) ? $decoded['total_volumes'] : array();

		if ( empty( $prices ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'CoinGecko returned no history for this symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$rows = array();
		foreach ( $prices as $index => $point ) {
			$open   = isset( $prices[ max( 0, $index - 1 ) ][1] ) ? (float) $prices[ max( 0, $index - 1 ) ][1] : (float) $point[1];
			$rows[] = array(
				'date'   => gmdate( 'Y-m-d', (int) ( $point[0] / 1000 ) ),
				'open'   => $open,
				'high'   => (float) $point[1],
				'low'    => (float) $point[1],
				'close'  => (float) $point[1],
				'volume' => isset( $volumes[ $index ][1] ) ? (float) $volumes[ $index ][1] : null,
			);
		}

		return array(
			'symbol'   => strtoupper( sanitize_text_field( $symbol ) ),
			'currency' => $currency,
			'period'   => sanitize_text_field( $period ),
			'interval' => '1d',
			'count'    => count( $rows ),
			'data'     => $rows,
			'source'   => self::PROVIDER_COINGECKO,
		);
	}

	/**
	 * Fetch crypto candles from the Binance public klines API.
	 *
	 * @param string $symbol Crypto symbol (btc, eth, ...).
	 * @param string $period Period (1mo, 3mo, 1y, ...).
	 * @return array|WP_Error Normalized history or error.
	 */
	public function binance_get_history( $symbol, $period = '1mo' ) {
		$map = self::get_crypto_symbol_map( $symbol );
		if ( false === $map ) {
			return new WP_Error( 'wp_mcp_ai_invalid_symbol', __( 'Unknown crypto symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$days  = self::period_to_days( $period );
		$limit = min( max( $days, 30 ), 1000 );
		$url   = 'https://api.binance.com/api/v3/klines?symbol=' . rawurlencode( $map['base'] . 'USDT' ) . '&interval=1d&limit=' . $limit;

		$response = $this->http_get( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] ) {
			return new WP_Error(
				'wp_mcp_ai_provider_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Binance returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $response['code'] )
			);
		}

		$decoded = $this->decode_json( $response['body'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		if ( empty( $decoded ) ) {
			return new WP_Error( 'wp_mcp_ai_provider_no_data', __( 'Binance returned no candles for this symbol.', 'mcp-ai-wpoos-pro' ) );
		}

		$rows = array();
		foreach ( $decoded as $candle ) {
			$rows[] = array(
				'date'   => gmdate( 'Y-m-d', (int) ( $candle[0] / 1000 ) ),
				'open'   => (float) $candle[1],
				'high'   => (float) $candle[2],
				'low'    => (float) $candle[3],
				'close'  => (float) $candle[4],
				'volume' => (float) $candle[5],
			);
		}

		return array(
			'symbol'   => strtoupper( sanitize_text_field( $symbol ) ),
			'currency' => 'usdt',
			'period'   => sanitize_text_field( $period ),
			'interval' => '1d',
			'count'    => count( $rows ),
			'data'     => $rows,
			'source'   => self::PROVIDER_BINANCE,
		);
	}

	/**
	 * Run a provider chain and return the first successful result.
	 *
	 * @param string $type    Chain type (quote, history, crypto).
	 * @param array  $args    Arguments for the provider methods.
	 * @param string $primary Primary provider slug (may be empty).
	 * @return array{data: array, source: string, fallback_used: bool, errors: array}|WP_Error
	 */
	public function fetch_with_fallback( $type, $args = array(), $primary = '' ) {
		$chains = $this->get_fallback_chains();
		$chain  = isset( $chains[ $type ] ) ? $chains[ $type ] : array();

		if ( '' !== $primary ) {
			array_unshift( $chain, sanitize_key( $primary ) );
		}

		$chain  = array_values( array_unique( $chain ) );
		$errors = array();
		$tried  = 0;

		foreach ( $chain as $provider ) {
			++$tried;
			$result = $this->call_provider( $provider, $type, $args );

			if ( ! is_wp_error( $result ) && ! empty( $result ) ) {
				$result['source']        = $provider;
				$result['fallback_used'] = $tried > 1;

				return array(
					'data'          => $result,
					'source'        => $provider,
					'fallback_used' => $tried > 1,
					'errors'        => $errors,
				);
			}

			if ( is_wp_error( $result ) ) {
				$errors[ $provider ] = $result->get_error_message();
			} else {
				$errors[ $provider ] = __( 'Provider returned an empty result.', 'mcp-ai-wpoos-pro' );
			}
		}

		return new WP_Error(
			'wp_mcp_ai_market_data_all_providers_failed',
			/* translators: %s: provider error summary */
			sprintf( __( 'All market data providers failed: %s', 'mcp-ai-wpoos-pro' ), implode( ' | ', $errors ) )
		);
	}

	/**
	 * Dispatch a provider call.
	 *
	 * @param string $provider Provider slug.
	 * @param string $type     Chain type.
	 * @param array  $args     Arguments.
	 * @return array|WP_Error
	 */
	protected function call_provider( $provider, $type, $args ) {
		$symbol   = isset( $args['symbol'] ) ? sanitize_text_field( $args['symbol'] ) : '';
		$period   = isset( $args['period'] ) ? sanitize_text_field( $args['period'] ) : '1mo';
		$currency = isset( $args['currency'] ) ? sanitize_text_field( $args['currency'] ) : 'usd';

		switch ( $provider ) {
			case self::PROVIDER_STOOQ:
				if ( 'history' === $type ) {
					return $this->stooq_get_history( $symbol, $period );
				}
				return $this->stooq_get_quote( $symbol );

			case self::PROVIDER_NASDAQ:
				return $this->nasdaq_get_quote( $symbol );

			case self::PROVIDER_COINGECKO:
				// The 'crypto' chain type is history-shaped by contract.
				if ( 'history' === $type || 'crypto' === $type ) {
					return $this->coingecko_get_history( $symbol, $currency, $period );
				}
				return $this->coingecko_get_markets( $currency, isset( $args['limit'] ) ? (int) $args['limit'] : 50 );

			case self::PROVIDER_BINANCE:
				return $this->binance_get_history( $symbol, $period );

			default:
				return new WP_Error(
					'wp_mcp_ai_market_data_unknown_provider',
					/* translators: %s: provider slug */
					sprintf( __( 'Unknown market data provider: %s', 'mcp-ai-wpoos-pro' ), $provider )
				);
		}
	}
}
