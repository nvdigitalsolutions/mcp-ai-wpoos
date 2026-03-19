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
	 * Option key prefix for per-endpoint key registries.
	 *
	 * @var string
	 */
	const REGISTRY_PREFIX = 'wp_mcp_ai_cache_reg_';

	/**
	 * Option key for the global endpoint registry (list of known endpoint keys).
	 *
	 * @var string
	 */
	const GLOBAL_REGISTRY_KEY = 'wp_mcp_ai_cache_endpoints';

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

		$cache_key    = self::build_cache_key( $endpoint, $params );
		$result       = set_transient( $cache_key, $response, $expiration );
		$endpoint_key = sanitize_key( $endpoint );

		if ( $result ) {
			self::register_cache_key( $endpoint_key, $cache_key );
		}

		return $result;
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
	 * Uses a per-endpoint key registry so the operation is compatible with
	 * SQLite-backed test environments where LIKE queries over the options table
	 * do not reliably return deleted-row counts.
	 *
	 * @param string $endpoint Endpoint identifier.
	 * @return int Number of deleted cache entries.
	 */
	public static function invalidate_endpoint( $endpoint ) {
		$endpoint_key = sanitize_key( $endpoint );
		$keys         = self::get_registered_keys( $endpoint_key );
		$deleted      = 0;

		foreach ( $keys as $cache_key ) {
			if ( delete_transient( $cache_key ) ) {
				++$deleted;
			}
		}

		delete_option( self::REGISTRY_PREFIX . $endpoint_key );

		return $deleted;
	}

	/**
	 * Clear all REST API caches
	 *
	 * Iterates the global endpoint registry and removes every tracked transient.
	 *
	 * @return int Number of cache entries cleared.
	 */
	public static function clear_all_caches() {
		$endpoint_keys = get_option( self::GLOBAL_REGISTRY_KEY, array() );

		if ( ! is_array( $endpoint_keys ) ) {
			$endpoint_keys = array();
		}

		$deleted = 0;

		foreach ( $endpoint_keys as $endpoint_key ) {
			$deleted += self::invalidate_endpoint( sanitize_key( $endpoint_key ) );
		}

		delete_option( self::GLOBAL_REGISTRY_KEY );

		return $deleted;
	}

	/**
	 * Register a cache key under its endpoint so it can be invalidated later.
	 *
	 * @param string $endpoint_key Sanitized endpoint identifier.
	 * @param string $cache_key    Transient key to register.
	 * @return void
	 */
	private static function register_cache_key( $endpoint_key, $cache_key ) {
		$registry_option = self::REGISTRY_PREFIX . $endpoint_key;
		$keys            = get_option( $registry_option, array() );

		if ( ! is_array( $keys ) ) {
			$keys = array();
		}

		if ( ! in_array( $cache_key, $keys, true ) ) {
			$keys[] = $cache_key;
			update_option( $registry_option, $keys, false );
			self::register_endpoint( $endpoint_key );
		}
	}

	/**
	 * Record an endpoint in the global registry.
	 *
	 * @param string $endpoint_key Sanitized endpoint identifier.
	 * @return void
	 */
	private static function register_endpoint( $endpoint_key ) {
		$endpoints = get_option( self::GLOBAL_REGISTRY_KEY, array() );

		if ( ! is_array( $endpoints ) ) {
			$endpoints = array();
		}

		if ( ! in_array( $endpoint_key, $endpoints, true ) ) {
			$endpoints[] = $endpoint_key;
			update_option( self::GLOBAL_REGISTRY_KEY, $endpoints, false );
		}
	}

	/**
	 * Retrieve all cache keys registered for an endpoint.
	 *
	 * @param string $endpoint_key Sanitized endpoint identifier.
	 * @return string[] List of transient keys.
	 */
	private static function get_registered_keys( $endpoint_key ) {
		$registry_option = self::REGISTRY_PREFIX . $endpoint_key;
		$keys            = get_option( $registry_option, array() );

		return is_array( $keys ) ? $keys : array();
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
	 * Invalidate caches when plugin settings are saved
	 *
	 * Hooked to update_option_{OPTION_NAME}.
	 *
	 * @return void
	 */
	public static function invalidate_on_settings_save() {
		self::invalidate_endpoint( 'assistants' );
		self::invalidate_endpoint( 'tools' );
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
