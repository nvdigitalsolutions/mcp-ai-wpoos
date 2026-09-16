<?php
/**
 * Financial toolkit ecosystem port (OpenTerminal lessons sub-cluster).
 *
 * Ported from the base Pro addon's `addons/pro/includes/services/class-wp-mcp-ai-yfinance-service.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base
 * Pro addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `WP_MCP_AI_PRO_PATH` is defined.
 *
 * What this file is: RE-PORT: fallback chains + stale-while-revalidate caching + API-key pass-through.
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
 * YFinance Service Helper Class
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
	 * Stale-copy TTL in seconds (7 days). The stale copy survives fresh-TTL
	 * expiry and is only served when the live fetch fails.
	 *
	 * @var int
	 */
	const STALE_TTL = 604800;

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
	 * Get the market data providers helper.
	 *
	 * @return WP_MCP_AI_Market_Data_Providers
	 */
	protected function get_providers() {
		static $providers = null;

		if ( null === $providers ) {
			$providers = WP_MCP_AI_Market_Data_Providers::get_instance();
		}

		return $providers;
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
	 * Get the microservice API key.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_api_key() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$api_key  = isset( $settings['yfinance_api_key'] ) ? (string) $settings['yfinance_api_key'] : '';

		/**
		 * Filter the yfinance microservice API key.
		 *
		 * @since 1.1.80
		 *
		 * @param string $api_key API key.
		 */
		return apply_filters( 'wp_mcp_ai_yfinance_api_key', $api_key );
	}

	/**
	 * Whether the keyless fallback providers are enabled.
	 *
	 * Defaults to enabled (the yfinance microservice is optional). Set
	 * `yfinance_fallback_providers` to a falsy value in wp_mcp_ai_settings
	 * to disable, or use the filter.
	 *
	 * @since 1.1.80
	 *
	 * @return bool
	 */
	public function fallbacks_enabled() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$enabled  = ! isset( $settings['yfinance_fallback_providers'] ) || ! empty( $settings['yfinance_fallback_providers'] );

		/**
		 * Filter whether the keyless fallback providers run after a
		 * microservice failure.
		 *
		 * @since 1.1.80
		 *
		 * @param bool $enabled Whether fallbacks are enabled.
		 */
		return (bool) apply_filters( 'wp_mcp_ai_yfinance_fallbacks_enabled', $enabled );
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
	 * Get the stale-copy TTL in seconds.
	 *
	 * @since 1.1.80
	 *
	 * @return int
	 */
	public function get_stale_ttl() {
		/**
		 * Filter the stale-copy TTL.
		 *
		 * @since 1.1.80
		 *
		 * @param int $ttl TTL in seconds.
		 */
		return (int) apply_filters( 'wp_mcp_ai_yfinance_stale_ttl', self::STALE_TTL );
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
	 * Get the stale (last-known-good) copy for a cache key.
	 *
	 * @since 1.1.80
	 *
	 * @param string $cache_key Fresh cache key.
	 * @return array|false Stale envelope {ts, data} or false.
	 */
	private function get_stale( $cache_key ) {
		return get_transient( $cache_key . '_stale' );
	}

	/**
	 * Store the stale (last-known-good) copy for a cache key.
	 *
	 * @since 1.1.80
	 *
	 * @param string $cache_key Fresh cache key.
	 * @param mixed  $data      Data payload.
	 * @return bool
	 */
	private function set_stale( $cache_key, $data ) {
		return set_transient(
			$cache_key . '_stale',
			array(
				'ts'   => time(),
				'data' => $data,
			),
			$this->get_stale_ttl()
		);
	}

	/**
	 * Stamp a cached payload with the cache marker.
	 *
	 * @param mixed $payload Cached payload.
	 * @return mixed
	 */
	private function mark_from_cache( $payload ) {
		if ( is_array( $payload ) ) {
			$payload['from_cache'] = true;
			$payload['stale']      = false;
		}

		return $payload;
	}

	/**
	 * Build the stale-serve envelope for a failed fetch.
	 *
	 * @since 1.1.80
	 *
	 * @param array $stale Stale envelope {ts, data}.
	 * @return array Payload with stale markers.
	 */
	private function mark_stale( $stale ) {
		$data = isset( $stale['data'] ) ? $stale['data'] : array();

		if ( is_array( $data ) ) {
			$data['stale']                  = true;
			$data['from_cache']             = true;
			$data['stale_while_revalidate'] = true;
			$data['data_age_seconds']       = max( 0, time() - (int) ( isset( $stale['ts'] ) ? $stale['ts'] : time() ) );
		}

		return $data;
	}

	/**
	 * Cache a successful fetch (fresh + stale copies).
	 *
	 * @since 1.1.80
	 *
	 * @param string $cache_key Cache key.
	 * @param mixed  $result    Result payload.
	 * @param int    $ttl       Fresh TTL in seconds (null = default).
	 */
	private function cache_success( $cache_key, $result, $ttl = null ) {
		$this->set_cache( $cache_key, $result, $ttl );
		$this->set_stale( $cache_key, $result );
	}

	/**
	 * Resolve a failed primary fetch through the fallback chain or the stale copy.
	 *
	 * @since 1.1.80
	 *
	 * @param string         $cache_key   Fresh cache key.
	 * @param string         $type        Chain type (quote, history, crypto).
	 * @param array          $args        Provider arguments.
	 * @param WP_Error|mixed $primary_error Primary fetch error (may be empty).
	 * @return array|WP_Error Resolved payload or combined error.
	 */
	private function resolve_failure( $cache_key, $type, $args, $primary_error ) {
		if ( ! $this->fallbacks_enabled() ) {
			$stale = $this->get_stale( $cache_key );
			if ( false !== $stale ) {
				return $this->mark_stale( $stale );
			}

			if ( is_wp_error( $primary_error ) ) {
				return $primary_error;
			}

			return new WP_Error(
				'wp_mcp_ai_market_data_fetch_failed',
				__( 'Failed to fetch market data.', 'nvoos-content-graph-pro' )
			);
		}

		$fallback = $this->get_providers()->fetch_with_fallback( $type, $args );

		if ( ! is_wp_error( $fallback ) && ! empty( $fallback['data'] ) ) {
			$data = $fallback['data'];
			$this->cache_success( $cache_key, $data );

			return $data;
		}

		$stale = $this->get_stale( $cache_key );
		if ( false !== $stale ) {
			return $this->mark_stale( $stale );
		}

		$messages = array();
		if ( is_wp_error( $primary_error ) ) {
			$messages[] = $primary_error->get_error_message();
		}
		if ( is_wp_error( $fallback ) ) {
			$messages[] = $fallback->get_error_message();
		}

		return new WP_Error(
			'wp_mcp_ai_market_data_fetch_failed',
			empty( $messages )
				? __( 'Failed to fetch market data.', 'nvoos-content-graph-pro' )
				: implode( ' | ', $messages )
		);
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
			return new WP_Error( 'invalid_ticker', __( 'Ticker symbol is required.', 'nvoos-content-graph-pro' ) );
		}

		$ticker    = strtoupper( sanitize_text_field( $ticker ) );
		$cache_key = $this->get_cache_key( 'ticker', $ticker );

		// Check cache first.
		if ( $use_cache ) {
			$cached = $this->get_cache( $cache_key );
			if ( false !== $cached ) {
				return $this->mark_from_cache( $cached );
			}
		}

		// Call Node.js service via filter.
		$params = array(
			'ticker'      => $ticker,
			'service_url' => $this->get_service_url(),
			'api_key'     => $this->get_api_key(),
		);

		$result = apply_filters( 'wp_mcp_ai_yfinance_ticker_info', false, $params );

		if ( is_wp_error( $result ) || empty( $result ) ) {
			$fallback = $this->resolve_failure(
				$cache_key,
				'quote',
				array( 'symbol' => $ticker ),
				$result
			);

			return $fallback;
		}

		if ( ! isset( $result['source'] ) ) {
			$result['source'] = 'yfinance';
		}

		// Cache the result.
		if ( $use_cache ) {
			$this->cache_success( $cache_key, $result );
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
			return new WP_Error( 'invalid_ticker', __( 'Ticker symbol is required.', 'nvoos-content-graph-pro' ) );
		}

		$ticker    = strtoupper( sanitize_text_field( $ticker ) );
		$cache_key = $this->get_cache_key( 'price', $ticker . '_' . $period );

		// Check cache first.
		if ( $use_cache ) {
			$cached = $this->get_cache( $cache_key );
			if ( false !== $cached ) {
				return $this->mark_from_cache( $cached );
			}
		}

		// Call Node.js service via filter.
		$params = array(
			'ticker'      => $ticker,
			'period'      => $period,
			'service_url' => $this->get_service_url(),
			'api_key'     => $this->get_api_key(),
		);

		$result = apply_filters( 'wp_mcp_ai_yfinance_current_price', false, $params );

		if ( is_wp_error( $result ) || empty( $result ) ) {
			$fallback = $this->resolve_failure(
				$cache_key,
				'quote',
				array(
					'symbol' => $ticker,
					'period' => $period,
				),
				$result
			);

			return $fallback;
		}

		if ( ! isset( $result['source'] ) ) {
			$result['source'] = 'yfinance';
		}

		// Cache the result.
		if ( $use_cache ) {
			$this->cache_success( $cache_key, $result );
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
			return new WP_Error( 'invalid_tickers', __( 'Tickers array is required.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize tickers.
		$tickers = array_map(
			function ( $ticker ) {
				return strtoupper( sanitize_text_field( $ticker ) );
			},
			$tickers
		);

		// Limit to 50 tickers.
		if ( count( $tickers ) > 50 ) {
			return new WP_Error( 'too_many_tickers', __( 'Maximum 50 tickers per batch request.', 'nvoos-content-graph-pro' ) );
		}

		$cache_key = $this->get_cache_key( 'batch', implode( '_', $tickers ) . '_' . $period );

		// Check cache first.
		if ( $use_cache ) {
			$cached = $this->get_cache( $cache_key );
			if ( false !== $cached ) {
				return $this->mark_from_cache( $cached );
			}
		}

		// Call Node.js service via filter.
		$params = array(
			'tickers'     => $tickers,
			'period'      => $period,
			'service_url' => $this->get_service_url(),
			'api_key'     => $this->get_api_key(),
		);

		$result = apply_filters( 'wp_mcp_ai_yfinance_batch_prices', false, $params );

		if ( is_wp_error( $result ) || empty( $result ) ) {
			$fallback = $this->resolve_batch_failure( $cache_key, $tickers, $result );

			return $fallback;
		}

		if ( ! isset( $result['source'] ) ) {
			$result['source'] = 'yfinance';
		}

		// Cache the result.
		if ( $use_cache ) {
			$this->cache_success( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Resolve a failed batch fetch: per-ticker fallback quotes or stale copy.
	 *
	 * @since 1.1.80
	 *
	 * @param string         $cache_key Fresh cache key.
	 * @param array          $tickers   Tickers.
	 * @param WP_Error|mixed $primary_error Primary error.
	 * @return array|WP_Error
	 */
	private function resolve_batch_failure( $cache_key, $tickers, $primary_error ) {
		if ( $this->fallbacks_enabled() ) {
			$providers = $this->get_providers();
			$data      = array();
			$errors    = array();

			foreach ( $tickers as $ticker ) {
				$quote = $providers->stooq_get_quote( $ticker );

				if ( is_wp_error( $quote ) ) {
					$errors[ $ticker ] = $quote->get_error_message();
					continue;
				}

				$data[ $ticker ] = $quote;
			}

			if ( ! empty( $data ) ) {
				$result = array(
					'count'         => count( $data ),
					'data'          => $data,
					'partial'       => ! empty( $errors ),
					'errors'        => $errors,
					'source'        => 'stooq',
					'fallback_used' => true,
				);

				$this->cache_success( $cache_key, $result );

				return $result;
			}
		}

		$stale = $this->get_stale( $cache_key );
		if ( false !== $stale ) {
			return $this->mark_stale( $stale );
		}

		return new WP_Error(
			'wp_mcp_ai_market_data_fetch_failed',
			is_wp_error( $primary_error )
				? $primary_error->get_error_message()
				: __( 'Failed to fetch batch prices.', 'nvoos-content-graph-pro' )
		);
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
			return new WP_Error( 'invalid_ticker', __( 'Ticker symbol is required.', 'nvoos-content-graph-pro' ) );
		}

		$ticker    = strtoupper( sanitize_text_field( $ticker ) );
		$cache_key = $this->get_cache_key( 'history', $ticker . '_' . $period . '_' . $interval );

		// Check cache first.
		if ( $use_cache ) {
			$cached = $this->get_cache( $cache_key );
			if ( false !== $cached ) {
				return $this->mark_from_cache( $cached );
			}
		}

		// Call Node.js service via filter.
		$params = array(
			'ticker'      => $ticker,
			'period'      => $period,
			'interval'    => $interval,
			'service_url' => $this->get_service_url(),
			'api_key'     => $this->get_api_key(),
		);

		$result = apply_filters( 'wp_mcp_ai_yfinance_price_history', false, $params );

		if ( is_wp_error( $result ) || empty( $result ) ) {
			$fallback = $this->resolve_failure(
				$cache_key,
				'history',
				array(
					'symbol'   => $ticker,
					'period'   => $period,
					'interval' => $interval,
				),
				$result
			);

			return $fallback;
		}

		if ( ! isset( $result['source'] ) ) {
			$result['source'] = 'yfinance';
		}

		// Cache the result (longer TTL for historical data).
		if ( $use_cache ) {
			$this->cache_success( $cache_key, $result, $this->get_cache_ttl() * 4 ); // 4x longer.
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
			return new WP_Error( 'invalid_query', __( 'Search query is required.', 'nvoos-content-graph-pro' ) );
		}

		$query = sanitize_text_field( $query );

		// Call Node.js service via filter.
		$params = array(
			'query'       => $query,
			'service_url' => $this->get_service_url(),
			'api_key'     => $this->get_api_key(),
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
			'api_key'     => $this->get_api_key(),
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

		// Normalize both legacy and fallback batch shapes.
		$price_map    = isset( $prices['data'] ) && is_array( $prices['data'] ) ? $prices['data'] : $prices;
		$batch_source = isset( $prices['source'] ) ? $prices['source'] : 'yfinance';

		// Update holdings with fetched prices.
		foreach ( $holdings as &$holding ) {
			$ticker = strtoupper( $holding['ticker'] );

			if ( empty( $holding['current_price'] ) && isset( $price_map[ $ticker ] ) ) {
				$price_data = $price_map[ $ticker ];

				if ( isset( $price_data['current_price'] ) ) {
					$holding['current_price'] = $price_data['current_price'];
					$holding['price_source']  = isset( $price_data['source'] ) ? $price_data['source'] : $batch_source;
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
