<?php
/**
 * REST API Cache Helper
 *
 * Provides caching capabilities for REST API endpoints to improve performance.
 * Uses WordPress Transients API for consistent cache management.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API Cache Helper class
 *
 * Handles caching of REST API responses to reduce server load and improve response times.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_REST_Cache {

	/**
	 * Cache group prefix for REST API responses
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'wp_mcp_ai_rest_';

	/**
	 * Default cache expiration time (5 minutes)
	 *
	 * @var int
	 */
	const DEFAULT_EXPIRATION = 5 * MINUTE_IN_SECONDS;

	/**
	 * Cache expiration for assistant list endpoint (30 minutes)
	 *
	 * @var int
	 */
	const ASSISTANT_LIST_EXPIRATION = 30 * MINUTE_IN_SECONDS;

	/**
	 * Cache expiration for assistant config endpoint (1 hour)
	 *
	 * @var int
	 */
	const ASSISTANT_CONFIG_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Get cached REST API response
	 *
	 * @param string $endpoint Endpoint identifier.
	 * @param array  $params   Request parameters for cache key generation.
	 * @return mixed|false Cached response or false if not found.
	 */
	public static function get_response( $endpoint, $params = array() ) {
		if ( ! self::is_caching_enabled() ) {
			return false;
		}

		$cache_key = self::build_cache_key( $endpoint, $params );
		return get_transient( $cache_key );
	}

	/**
	 * Set cached REST API response
	 *
	 * @param string $endpoint   Endpoint identifier.
	 * @param array  $params     Request parameters for cache key generation.
	 * @param mixed  $response   Response data to cache.
	 * @param int    $expiration Cache expiration in seconds.
	 * @return bool True on success, false on failure.
	 */
	public static function set_response( $endpoint, $params, $response, $expiration = self::DEFAULT_EXPIRATION ) {
		if ( ! self::is_caching_enabled() ) {
			return false;
		}

		$cache_key = self::build_cache_key( $endpoint, $params );
		return set_transient( $cache_key, $response, $expiration );
	}

	/**
	 * Delete cached REST API response
	 *
	 * @param string $endpoint Endpoint identifier.
	 * @param array  $params   Request parameters for cache key generation.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_response( $endpoint, $params = array() ) {
		$cache_key = self::build_cache_key( $endpoint, $params );
		return delete_transient( $cache_key );
	}

	/**
	 * Invalidate all caches for a specific endpoint
	 *
	 * @param string $endpoint Endpoint identifier.
	 * @return int Number of deleted cache entries.
	 */
	public static function invalidate_endpoint( $endpoint ) {
		global $wpdb;

		$pattern        = self::CACHE_PREFIX . sanitize_key( $endpoint ) . '_%';
		$option_pattern = '_transient_' . $wpdb->esc_like( $pattern ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$option_pattern
			)
		);

		return (int) $deleted;
	}

	/**
	 * Clear all REST API caches
	 *
	 * @return int Number of cache entries cleared.
	 */
	public static function clear_all_caches() {
		global $wpdb;

		$pattern        = self::CACHE_PREFIX . '%';
		$option_pattern = '_transient_' . $wpdb->esc_like( $pattern ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$option_pattern
			)
		);

		return (int) $deleted;
	}

	/**
	 * Build cache key from endpoint and parameters
	 *
	 * @param string $endpoint Endpoint identifier.
	 * @param array  $params   Request parameters.
	 * @return string Cache key.
	 */
	private static function build_cache_key( $endpoint, $params = array() ) {
		$endpoint_key = sanitize_key( $endpoint );

		if ( empty( $params ) ) {
			return self::CACHE_PREFIX . $endpoint_key;
		}

		// Sort params for consistent cache keys.
		ksort( $params );
		$params_hash = md5( wp_json_encode( $params ) );

		return self::CACHE_PREFIX . $endpoint_key . '_' . $params_hash;
	}

	/**
	 * Check if REST API caching is enabled
	 *
	 * @return bool True if caching is enabled.
	 */
	public static function is_caching_enabled() {
		// Allow disabling via constant.
		if ( defined( 'WP_MCP_AI_DISABLE_CACHE' ) && WP_MCP_AI_DISABLE_CACHE ) {
			return false;
		}

		// Allow disabling REST cache specifically.
		if ( defined( 'WP_MCP_AI_DISABLE_REST_CACHE' ) && WP_MCP_AI_DISABLE_REST_CACHE ) {
			return false;
		}

		/**
		 * Filter whether REST API caching is enabled.
		 *
		 * @param bool $enabled Whether caching is enabled (default: true).
		 */
		return apply_filters( 'wp_mcp_ai_rest_cache_enabled', true );
	}

	/**
	 * Get cache expiration time for specific endpoint
	 *
	 * @param string $endpoint Endpoint identifier.
	 * @return int Cache expiration in seconds.
	 */
	public static function get_expiration( $endpoint ) {
		$expiration = self::DEFAULT_EXPIRATION;

		switch ( $endpoint ) {
			case 'assistants':
			case 'assistants_list':
				$expiration = self::ASSISTANT_LIST_EXPIRATION;
				break;

			case 'assistant_config':
			case 'assistant_detail':
				$expiration = self::ASSISTANT_CONFIG_EXPIRATION;
				break;
		}

		/**
		 * Filter cache expiration time for REST endpoints.
		 *
		 * @param int    $expiration Cache expiration in seconds.
		 * @param string $endpoint   Endpoint identifier.
		 */
		return apply_filters( 'wp_mcp_ai_rest_cache_expiration', $expiration, $endpoint );
	}

	/**
	 * Add cache headers to REST response
	 *
	 * @param WP_REST_Response $response REST response object.
	 * @param int              $max_age  Cache max age in seconds.
	 * @return WP_REST_Response Modified response with cache headers.
	 */
	public static function add_cache_headers( $response, $max_age = null ) {
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		if ( null === $max_age ) {
			$max_age = self::DEFAULT_EXPIRATION;
		}

		$response->header( 'Cache-Control', 'public, max-age=' . $max_age );
		$response->header( 'Expires', gmdate( 'D, d M Y H:i:s \G\M\T', time() + $max_age ) );

		return $response;
	}

	/**
	 * Invalidate caches when assistant is saved
	 *
	 * Hooked to save_post_mcp_ai_assistant.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function invalidate_on_assistant_save( $post_id ) {
		self::invalidate_endpoint( 'assistants' );
		self::invalidate_endpoint( 'assistant_' . $post_id );
	}

	/**
	 * Invalidate caches when assistant is deleted
	 *
	 * Hooked to delete_post and wp_trash_post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function invalidate_on_assistant_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && 'mcp_ai_assistant' === $post->post_type ) {
			self::invalidate_endpoint( 'assistants' );
			self::invalidate_endpoint( 'assistant_' . $post_id );
		}
	}
}
