<?php
/**
 * Cache Service
 *
 * Provides Symfony Cache integration with automatic adapter selection.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Cache;

use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Class WP_MCP_AI_Cache_Service
 *
 * Wraps Symfony Cache with automatic adapter detection and tag support.
 */
class WP_MCP_AI_Cache_Service {

	/**
	 * Cache instance.
	 *
	 * @var CacheInterface
	 */
	private $cache;

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Cache_Service|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Cache_Service
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->cache = $this->create_adapter();
	}

	/**
	 * Create the best available cache adapter.
	 *
	 * @return CacheInterface
	 */
	private function create_adapter() {
		// Try Redis if available and configured.
		if ( $this->is_redis_available() ) {
			try {
				$redis = new \Redis();
				$host  = defined( 'WP_REDIS_HOST' ) ? WP_REDIS_HOST : '127.0.0.1';
				$port  = defined( 'WP_REDIS_PORT' ) ? WP_REDIS_PORT : 6379;

				if ( $redis->connect( $host, $port ) ) {
					return new RedisAdapter( $redis, 'wp_mcp_ai' );
				}
			} catch ( \Exception $e ) {
				// Fall through to next adapter.
				error_log( 'WP MCP AI: Redis connection failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		// Try APCu if available.
		if ( function_exists( 'apcu_enabled' ) && apcu_enabled() ) {
			try {
				return new ApcuAdapter( 'wp_mcp_ai' );
			} catch ( \Exception $e ) {
				// Fall through to filesystem.
				error_log( 'WP MCP AI: APCu initialization failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		// Fall back to filesystem cache.
		$cache_dir = WP_CONTENT_DIR . '/cache/wp-mcp-ai';
		if ( ! is_dir( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		return new FilesystemAdapter( 'wp_mcp_ai', 0, $cache_dir );
	}

	/**
	 * Check if Redis is available.
	 *
	 * @return bool
	 */
	private function is_redis_available() {
		return class_exists( 'Redis' ) && ( defined( 'WP_REDIS_HOST' ) || defined( 'WP_REDIS_ENABLED' ) );
	}

	/**
	 * Get or set a cache value with automatic stampede protection.
	 *
	 * @param string   $key      Cache key.
	 * @param callable $callback Callback to generate value if not cached.
	 * @param int      $ttl      Time to live in seconds.
	 * @param array    $tags     Cache tags for invalidation.
	 * @return mixed Cached value.
	 */
	public function get_or_set( $key, callable $callback, $ttl = 3600, array $tags = array() ) {
		return $this->cache->get(
			$key,
			function ( ItemInterface $item ) use ( $callback, $ttl, $tags ) {
				$item->expiresAfter( $ttl );
				if ( ! empty( $tags ) ) {
					$item->tag( $tags );
				}
				return $callback();
			}
		);
	}

	/**
	 * Get a value from cache.
	 *
	 * @param string $key     Cache key.
	 * @param mixed  $default Default value if not found.
	 * @return mixed Cached value or default.
	 */
	public function get( $key, $default = null ) {
		$item = $this->cache->getItem( $key );

		if ( ! $item->isHit() ) {
			return $default;
		}

		return $item->get();
	}

	/**
	 * Set a cache value.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to cache.
	 * @param int    $ttl   Time to live in seconds.
	 * @param array  $tags  Cache tags for invalidation.
	 * @return bool Success status.
	 */
	public function set( $key, $value, $ttl = 3600, array $tags = array() ) {
		$item = $this->cache->getItem( $key );
		$item->set( $value );
		$item->expiresAfter( $ttl );

		if ( ! empty( $tags ) ) {
			$item->tag( $tags );
		}

		return $this->cache->save( $item );
	}

	/**
	 * Delete a cache key.
	 *
	 * @param string $key Cache key.
	 * @return bool Success status.
	 */
	public function delete( $key ) {
		return $this->cache->delete( $key );
	}

	/**
	 * Invalidate cache by tags.
	 *
	 * @param array $tags Tags to invalidate.
	 * @return bool Success status.
	 */
	public function invalidate_tags( array $tags ) {
		if ( method_exists( $this->cache, 'invalidateTags' ) ) {
			return $this->cache->invalidateTags( $tags );
		}
		return false;
	}

	/**
	 * Clear all cache.
	 *
	 * @return bool Success status.
	 */
	public function clear() {
		return $this->cache->clear();
	}

	/**
	 * Get the current adapter type.
	 *
	 * @return string Adapter type (redis|apcu|filesystem).
	 */
	public function get_adapter_type() {
		if ( $this->cache instanceof RedisAdapter ) {
			return 'redis';
		} elseif ( $this->cache instanceof ApcuAdapter ) {
			return 'apcu';
		} else {
			return 'filesystem';
		}
	}
}
