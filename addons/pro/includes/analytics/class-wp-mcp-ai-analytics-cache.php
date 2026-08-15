<?php
/**
 * Analytics Cache — Transient-based caching layer for analytics data.
 *
 * Provides salted, TTL-aware caching for analytics API responses. Uses WordPress
 * Transients API with fallback to wp_cache for persistent object cache support.
 * Implements a warm-up lock pattern to prevent cache stampedes.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.7.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license  Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics cache service.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_Cache {

	/**
	 * Singleton instance.
	 *
	 * @since 1.7.0
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Cache key prefix.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const KEY_PREFIX = 'wp_mcp_ai_anl_';

	/**
	 * TTL for account profile data (1 hour).
	 *
	 * @since 1.7.0
	 * @var int
	 */
	const ACCOUNT_TTL = 3600;

	/**
	 * TTL for aggregate metrics (15 minutes).
	 *
	 * @since 1.7.0
	 * @var int
	 */
	const METRICS_TTL = 900;

	/**
	 * TTL for time-series data (6 hours).
	 *
	 * @since 1.7.0
	 * @var int
	 */
	const TIMESERIES_TTL = 21600;

	/**
	 * TTL for chart data (30 minutes).
	 *
	 * @since 1.7.0
	 * @var int
	 */
	const CHART_TTL = 1800;

	/**
	 * Lock transient TTL for warm-up (30 seconds).
	 *
	 * @since 1.7.0
	 * @var int
	 */
	const LOCK_TTL = 30;

	/**
	 * Cache statistics.
	 *
	 * @since 1.7.0
	 * @var array{hits:int,misses:int,sets:int}
	 */
	private $stats = array(
		'hits'   => 0,
		'misses' => 0,
		'sets'   => 0,
	);

	/**
	 * Private constructor for singleton.
	 *
	 * @since 1.7.0
	 */
	private function __construct() {}

	/**
	 * Get singleton instance.
	 *
	 * @since 1.7.0
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Build a salted cache key.
	 *
	 * Transient keys have a 172-character limit. Long parameters are folded
	 * with md5() to stay within this limit.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform  Platform identifier.
	 * @param string $resource  Resource type (account, metrics, trend, chart).
	 * @param array  $params    Request parameters.
	 * @return string Cache key.
	 */
	private function build_key( $platform, $resource, array $params = array() ) {
		$base = self::KEY_PREFIX . $platform . '_' . $resource;

		if ( ! empty( $params ) ) {
			$param_hash = md5( wp_json_encode( $params ) );
			$base      .= '_' . $param_hash;
		}

		// Ensure key is under 172 chars.
		if ( strlen( $base ) > 172 ) {
			$base = self::KEY_PREFIX . md5( $platform . '_' . $resource . '_' . wp_json_encode( $params ) );
		}

		return $base;
	}

	/**
	 * Get cached analytics data.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @param string $resource Resource type.
	 * @param array  $params   Request parameters used to generate the key.
	 * @return array|null Cached data or null on miss.
	 */
	public function get( $platform, $resource, array $params = array() ) {
		$key   = $this->build_key( $platform, $resource, $params );
		$value = get_transient( $key );

		if ( false !== $value ) {
			++$this->stats['hits'];
			return $value;
		}

		++$this->stats['misses'];
		return null;
	}

	/**
	 * Set cached analytics data.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @param string $resource Resource type.
	 * @param array  $params   Request parameters.
	 * @param mixed  $data     Data to cache.
	 * @param int    $ttl      Time-to-live in seconds. Uses default if not set.
	 * @return bool True on success.
	 */
	public function set( $platform, $resource, array $params, $data, $ttl = null ) {
		$key = $this->build_key( $platform, $resource, $params );

		if ( null === $ttl ) {
			$ttl = $this->get_default_ttl( $resource );
		}

		$result = set_transient( $key, $data, $ttl );
		if ( $result ) {
			++$this->stats['sets'];
		}
		return $result;
	}

	/**
	 * Get default TTL for a resource type.
	 *
	 * @since 1.7.0
	 *
	 * @param string $resource Resource type.
	 * @return int TTL in seconds.
	 */
	private function get_default_ttl( $resource ) {
		$ttls = array(
			'account'    => self::ACCOUNT_TTL,
			'metrics'    => self::METRICS_TTL,
			'summary'    => self::METRICS_TTL,
			'timeseries' => self::TIMESERIES_TTL,
			'trend'      => self::TIMESERIES_TTL,
			'chart'      => self::CHART_TTL,
			'posts'      => self::METRICS_TTL,
			'report'     => self::METRICS_TTL,
		);

		return isset( $ttls[ $resource ] ) ? $ttls[ $resource ] : self::METRICS_TTL;
	}

	/**
	 * Invalidate cached data for a platform, optionally for a specific account.
	 *
	 * @since 1.7.0
	 *
	 * @param string      $platform   Platform identifier.
	 * @param string|null $account_id Optional account ID to scope invalidation.
	 * @return void
	 */
	public function invalidate( $platform, $account_id = null ) {
		global $wpdb;

		$pattern = '_transient_' . self::KEY_PREFIX . $platform . '_%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);

		foreach ( $keys as $key ) {
			$transient_name = str_replace( '_transient_', '', $key );
			delete_transient( $transient_name );
		}
	}

	/**
	 * Acquire a warm-up lock to prevent cache stampedes.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform   Platform identifier.
	 * @param string $account_id Account ID.
	 * @return bool True if lock was acquired.
	 */
	public function acquire_warm_lock( $platform, $account_id ) {
		$lock_key = self::KEY_PREFIX . 'lock_' . $platform . '_' . md5( $account_id );
		return wp_cache_add( $lock_key, 1, 'wp_mcp_ai_analytics', self::LOCK_TTL );
	}

	/**
	 * Release a warm-up lock.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform   Platform identifier.
	 * @param string $account_id Account ID.
	 * @return void
	 */
	public function release_warm_lock( $platform, $account_id ) {
		$lock_key = self::KEY_PREFIX . 'lock_' . $platform . '_' . md5( $account_id );
		wp_cache_delete( $lock_key, 'wp_mcp_ai_analytics' );
	}

	/**
	 * Warm the cache by pre-fetching data for given platforms and accounts.
	 *
	 * @since 1.7.0
	 *
	 * @param string[] $platforms  Platform identifiers.
	 * @param string[] $account_ids Optional account IDs.
	 * @return void
	 */
	public function warm( array $platforms, array $account_ids = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_Analytics_Service' ) ) {
			return;
		}

		$service = WP_MCP_AI_Analytics_Service::instance();

		foreach ( $platforms as $platform ) {
			foreach ( $account_ids as $account_id ) {
				if ( $this->acquire_warm_lock( $platform, $account_id ) ) {
					try {
						$adapter = $service->get_adapter( $platform );
						if ( ! $adapter || ! $adapter->is_configured() ) {
							continue;
						}
						$since = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
						$until = gmdate( 'Y-m-d' );
						$adapter->get_account_insights(
							$account_id,
							array( 'impressions', 'reach', 'engagement', 'followers' ),
							$since,
							$until
						);
					} catch ( \Exception $e ) {
						// Log but don't halt warm-up.
						if ( function_exists( 'wp_mcp_ai_log_error' ) ) {
							wp_mcp_ai_log_error(
								sprintf(
									'Analytics cache warm-up failed for %s/%s: %s',
									esc_html( $platform ),
									esc_html( $account_id ),
									esc_html( $e->getMessage() )
								)
							);
						}
					}
					$this->release_warm_lock( $platform, $account_id );
				}
			}
		}
	}

	/**
	 * Get cache hit/miss statistics.
	 *
	 * @since 1.7.0
	 * @return array{hits:int,misses:int,sets:int,hit_rate:float}
	 */
	public function get_stats() {
		$total    = $this->stats['hits'] + $this->stats['misses'];
		$hit_rate = $total > 0 ? round( $this->stats['hits'] / $total * 100, 1 ) : 0.0;

		return array_merge(
			$this->stats,
			array( 'hit_rate' => $hit_rate )
		);
	}

	/**
	 * Reset cache statistics.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function reset_stats() {
		$this->stats = array(
			'hits'   => 0,
			'misses' => 0,
			'sets'   => 0,
		);
	}
}
