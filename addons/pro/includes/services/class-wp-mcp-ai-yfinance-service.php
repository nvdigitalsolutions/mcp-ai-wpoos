<?php
/**
 * yfinance Service Helper
 *
 * Helper class for interacting with the yfinance microservice via Node.js client.
 * Provides WordPress transient caching, batch requests, and error handling.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * yfinance Service Helper Class
 */
class WP_MCP_AI_YFinance_Service {

	/**
	 * Cache group for transients
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'wp_mcp_ai_yfinance';

	/**
	 * Default cache TTL in seconds (15 minutes)
	 *
	 * @var int
	 */
	const DEFAULT_CACHE_TTL = 900;

	/**
	 * Get singleton instance
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
	 * Check if yfinance service is enabled
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_yfinance_service'] );
	}

	/**
	 * Get yfinance service URL
	 *
	 * @return string
	 */
	public function get_service_url() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return isset( $settings['yfinance_service_url'] )
			? trailingslashit( $settings['yfinance_service_url'] )
			: 'http://localhost:5000/';
	}

	/**
	 * Get cache TTL in seconds
	 *
	 * @return int
	 */
	public function get_cache_ttl() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$ttl      = isset( $settings['yfinance_cache_ttl'] ) ? absint( $settings['yfinance_cache_ttl'] ) : 0;

		return $ttl > 0 ? $ttl * 60 : self::DEFAULT_CACHE_TTL;
	}

	/**
	 * Get cache key for transient
	 *
	 * @param string $type   Cache type (ticker, price, batch, history).
	 * @param mixed  $params Parameters for cache key.
	 * @return string
	 */
	private function get_cache_key( $type, $params ) {
		if ( is_array( $params ) ) {
			$params = wp_json_encode( $params );
		}

		return self::CACHE_GROUP . '_' . $type . '_' . md5( $params );
	}

	/**
	 * Get cached data
	 *
	 * @param string $cache_key Cache key.
	 * @return mixed|false Cached data or false if not found/expired.
	 */
	private function get_cache( $cache_key ) {
		return get_transient( $cache_key );
	}

	/**
	 * Set cached data
	 *
	 * @param string $cache_key Cache key.
	 * @param mixed  $data      Data to cache.
	 * @param int    $ttl       Time to live in seconds.
	 * @return bool
	 */
	private function set_cache( $cache_key, $data, $ttl = null ) {
		if ( null === $ttl ) {
			$ttl = $this->get_cache_ttl();
		}

		return set_transient( $cache_key, $data, $ttl );
	}

	/**
	 * Delete cached data
	 *
	 * @param string $cache_key Cache key.
	 * @return bool
	 */
	private function delete_cache( $cache_key ) {
		return delete_transient( $cache_key );
	}

	/**
	 * Clear all yfinance caches
	 *
	 * @return int Number of caches cleared.
	 */
	public function clear_all_caches() {
		global $wpdb;

		$pattern = '_transient_' . self::CACHE_GROUP . '_%';
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);

		// Also clear timeout transients.
		$timeout_pattern = '_transient_timeout_' . self::CACHE_GROUP . '_%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$timeout_pattern
			)
		);

		return absint( $deleted );
	}

	/**
	 * Get ticker information
	 *
	 * @param string $ticker Ticker symbol.
	 * @param bool   $use_cache Whether to use cache.
	 * @return array|WP_Error
	 */
	public function get_ticker_info( $ticker, $use_cache = true ) {
		if ( empty( $ticker ) ) {
			return new WP_Error( 'invalid_ticker', __( 'Ticker symbol is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$ticker    = strtoupper( sanitize_text_field( $ticker ) );
		$cache_key = $this->get_cache_key( 'ticker', $ticker );

		// Check cache first.
		if ( $use_cache ) {
			$cached = $this->get_cache( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Call Node.js service via filter.
		$params = array(
			'ticker'      => $ticker,
			'service_url' => $this->get_service_url(),
		);

		$result = apply_filters( 'wp_mcp_ai_yfinance_ticker_info', false, $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Cache the result.
		if ( $use_cache && ! empty( $result ) ) {
			$this->set_cache( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Get current price for a ticker
	 *
	 * @param string $ticker Ticker symbol.
	 * @param string $period Period (1d, 5d, 1mo, etc.).
	 * @param bool   $use_cache Whether to use cache.
	 * @return array|WP_Error
	 */
	public function get_current_price( $ticker, $period = '1d', $use_cache = true ) {
		if ( empty( $ticker ) ) {
			return new WP_Error( 'invalid_ticker', __( 'Ticker symbol is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$ticker    = strtoupper( sanitize_text_field( $ticker ) );
		$cache_key = $this->get_cache_key( 'price', $ticker . '_' . $period );

		// Check cache first.
		if ( $use_cache ) {
			$cached = $this->get_cache( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Call Node.js service via filter.
		$params = array(
			'ticker'      => $ticker,
			'period'      => $period,
			'service_url' => $this->get_service_url(),
		);

		$result = apply_filters( 'wp_mcp_ai_yfinance_current_price', false, $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Cache the result.
		if ( $use_cache && ! empty( $result ) ) {
			$this->set_cache( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Get batch prices for multiple tickers
	 *
	 * @param array  $tickers Array of ticker symbols.
	 * @param string $period  Period (1d, 5d, 1mo, etc.).
	 * @param bool   $use_cache Whether to use cache.
	 * @return array|WP_Error
	 */
	public function get_batch_prices( $tickers, $period = '1d', $use_cache = true ) {
		if ( empty( $tickers ) || ! is_array( $tickers ) ) {
			return new WP_Error( 'invalid_tickers', __( 'Tickers array is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize tickers.
		$tickers = array_map( function( $ticker ) {
			return strtoupper( sanitize_text_field( $ticker ) );
		}, $tickers );

		// Limit to 50 tickers.
		if ( count( $tickers ) > 50 ) {
			return new WP_Error( 'too_many_tickers', __( 'Maximum 50 tickers per batch request.', 'mcp-ai-wpoos-pro' ) );
		}

		$cache_key = $this->get_cache_key( 'batch', implode( '_', $tickers ) . '_' . $period );

		// Check cache first.
		if ( $use_cache ) {
			$cached = $this->get_cache( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Call Node.js service via filter.
		$params = array(
			'tickers'     => $tickers,
			'period'      => $period,
			'service_url' => $this->get_service_url(),
		);

		$result = apply_filters( 'wp_mcp_ai_yfinance_batch_prices', false, $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Cache the result.
		if ( $use_cache && ! empty( $result ) ) {
			$this->set_cache( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Get price history for a ticker
	 *
	 * @param string $ticker   Ticker symbol.
	 * @param string $period   Period (1d, 5d, 1mo, 3mo, 6mo, 1y, etc.).
	 * @param string $interval Interval (1m, 5m, 15m, 30m, 1h, 1d, etc.).
	 * @param bool   $use_cache Whether to use cache.
	 * @return array|WP_Error
	 */
	public function get_price_history( $ticker, $period = '1mo', $interval = '1d', $use_cache = true ) {
		if ( empty( $ticker ) ) {
			return new WP_Error( 'invalid_ticker', __( 'Ticker symbol is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$ticker    = strtoupper( sanitize_text_field( $ticker ) );
		$cache_key = $this->get_cache_key( 'history', $ticker . '_' . $period . '_' . $interval );

		// Check cache first.
		if ( $use_cache ) {
			$cached = $this->get_cache( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Call Node.js service via filter.
		$params = array(
			'ticker'      => $ticker,
			'period'      => $period,
			'interval'    => $interval,
			'service_url' => $this->get_service_url(),
		);

		$result = apply_filters( 'wp_mcp_ai_yfinance_price_history', false, $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Cache the result (longer TTL for historical data).
		if ( $use_cache && ! empty( $result ) ) {
			$this->set_cache( $cache_key, $result, $this->get_cache_ttl() * 4 ); // 4x longer.
		}

		return $result;
	}

	/**
	 * Search for ticker symbols
	 *
	 * @param string $query Search query.
	 * @return array|WP_Error
	 */
	public function search_ticker( $query ) {
		if ( empty( $query ) ) {
			return new WP_Error( 'invalid_query', __( 'Search query is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$query = sanitize_text_field( $query );

		// Call Node.js service via filter.
		$params = array(
			'query'       => $query,
			'service_url' => $this->get_service_url(),
		);

		$result = apply_filters( 'wp_mcp_ai_yfinance_search_ticker', false, $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	/**
	 * Check health of yfinance service
	 *
	 * @return array
	 */
	public function check_health() {
		$params = array(
			'service_url' => $this->get_service_url(),
		);

		$result = apply_filters( 'wp_mcp_ai_yfinance_health_check', false, $params );

		return $result;
	}

	/**
	 * Enrich holdings with current prices
	 *
	 * @param array $holdings Array of holdings with ticker symbols.
	 * @return array Holdings with enriched price data.
	 */
	public function enrich_holdings_with_prices( $holdings ) {
		if ( empty( $holdings ) || ! is_array( $holdings ) ) {
			return $holdings;
		}

		// Extract tickers that need price fetching.
		$tickers_to_fetch = array();
		foreach ( $holdings as $holding ) {
			if ( empty( $holding['current_price'] ) && ! empty( $holding['ticker'] ) ) {
				$tickers_to_fetch[] = strtoupper( $holding['ticker'] );
			}
		}

		// If no tickers to fetch, return as-is.
		if ( empty( $tickers_to_fetch ) ) {
			return $holdings;
		}

		// Fetch prices in batch.
		$prices = $this->get_batch_prices( $tickers_to_fetch );

		if ( is_wp_error( $prices ) ) {
			// Log error but continue with manual prices.
			error_log( 'WP_MCP_AI: yfinance batch price fetch error: ' . $prices->get_error_message() );
			return $holdings;
		}

		// Update holdings with fetched prices.
		foreach ( $holdings as &$holding ) {
			$ticker = strtoupper( $holding['ticker'] );

			if ( empty( $holding['current_price'] ) && isset( $prices[ $ticker ] ) ) {
				$price_data = $prices[ $ticker ];

				if ( isset( $price_data['current_price'] ) ) {
					$holding['current_price'] = $price_data['current_price'];
					$holding['price_source']  = 'yfinance';
					$holding['price_date']    = $price_data['date'] ?? gmdate( 'Y-m-d' );
					$holding['price_open']    = $price_data['open'] ?? null;
					$holding['price_high']    = $price_data['high'] ?? null;
					$holding['price_low']     = $price_data['low'] ?? null;
					$holding['price_volume']  = $price_data['volume'] ?? null;
				}
			} elseif ( ! empty( $holding['current_price'] ) ) {
				$holding['price_source'] = 'manual';
			}
		}

		return $holdings;
	}
}
