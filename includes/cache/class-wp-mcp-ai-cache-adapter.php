<?php
/**
 * Redis/Memcached Cache Adapter
 *
 * Provides persistent object caching with Redis or Memcached.
 *
 * @package WP_MCP_AI
 * @subpackage Cache
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache Adapter Class
 *
 * Detects and uses Redis or Memcached for persistent caching.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Cache_Adapter {

	/**
	 * Cache backend (redis, memcached, or none).
	 *
	 * @var string
	 */
	protected $backend = 'none';

	/**
	 * Redis instance.
	 *
	 * @var Redis|null
	 */
	protected $redis = null;

	/**
	 * Memcached instance.
	 *
	 * @var Memcached|null
	 */
	protected $memcached = null;

	/**
	 * Cache key prefix.
	 *
	 * @var string
	 */
	protected $prefix = 'mcp_ai_';

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		$this->detect_backend();
	}

	/**
	 * Detect available cache backend.
	 *
	 * @since 1.3.0
	 */
	protected function detect_backend() {
		// Try Redis first.
		if ( class_exists( 'Redis' ) ) {
			try {
				$this->redis = new Redis();
				
				// Try to connect to Redis.
				$host = defined( 'WP_REDIS_HOST' ) ? WP_REDIS_HOST : '127.0.0.1';
				$port = defined( 'WP_REDIS_PORT' ) ? WP_REDIS_PORT : 6379;
				
				if ( $this->redis->connect( $host, $port, 1 ) ) {
					$this->backend = 'redis';
					
					// Set key prefix.
					if ( defined( 'WP_REDIS_PREFIX' ) ) {
						$this->prefix = WP_REDIS_PREFIX;
					}
					
					return;
				}
			} catch ( Exception $e ) {
				$this->redis = null;
			}
		}

		// Try Memcached.
		if ( class_exists( 'Memcached' ) ) {
			try {
				$this->memcached = new Memcached();
				
				// Try to connect to Memcached.
				$host = defined( 'WP_MEMCACHED_HOST' ) ? WP_MEMCACHED_HOST : '127.0.0.1';
				$port = defined( 'WP_MEMCACHED_PORT' ) ? WP_MEMCACHED_PORT : 11211;
				
				$this->memcached->addServer( $host, $port );
				
				// Test connection.
				$stats = $this->memcached->getStats();
				if ( ! empty( $stats ) ) {
					$this->backend = 'memcached';
					return;
				}
			} catch ( Exception $e ) {
				$this->memcached = null;
			}
		}

		// No backend available.
		$this->backend = 'none';
	}

	/**
	 * Get backend type.
	 *
	 * @since 1.3.0
	 *
	 * @return string Backend type (redis, memcached, or none).
	 */
	public function get_backend() {
		return $this->backend;
	}

	/**
	 * Check if cache is available.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True if cache backend is available.
	 */
	public function is_available() {
		return $this->backend !== 'none';
	}

	/**
	 * Get cached value.
	 *
	 * @since 1.3.0
	 *
	 * @param string $key Cache key.
	 * @return mixed|false Cached value or false if not found.
	 */
	public function get( $key ) {
		$full_key = $this->prefix . $key;

		switch ( $this->backend ) {
			case 'redis':
				$value = $this->redis->get( $full_key );
				return $value !== false ? maybe_unserialize( $value ) : false;

			case 'memcached':
				$value = $this->memcached->get( $full_key );
				return $value !== false ? $value : false;

			default:
				return false;
		}
	}

	/**
	 * Set cached value.
	 *
	 * @since 1.3.0
	 *
	 * @param string $key Cache key.
	 * @param mixed  $value Value to cache.
	 * @param int    $expiration Expiration time in seconds. Default 300 (5 minutes).
	 * @return bool True on success, false on failure.
	 */
	public function set( $key, $value, $expiration = 300 ) {
		$full_key = $this->prefix . $key;

		switch ( $this->backend ) {
			case 'redis':
				return $this->redis->setex( $full_key, $expiration, maybe_serialize( $value ) );

			case 'memcached':
				return $this->memcached->set( $full_key, $value, $expiration );

			default:
				return false;
		}
	}

	/**
	 * Delete cached value.
	 *
	 * @since 1.3.0
	 *
	 * @param string $key Cache key.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $key ) {
		$full_key = $this->prefix . $key;

		switch ( $this->backend ) {
			case 'redis':
				return (bool) $this->redis->del( $full_key );

			case 'memcached':
				return $this->memcached->delete( $full_key );

			default:
				return false;
		}
	}

	/**
	 * Flush all cached values.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True on success, false on failure.
	 */
	public function flush() {
		switch ( $this->backend ) {
			case 'redis':
				// Delete keys with our prefix only.
				$keys = $this->redis->keys( $this->prefix . '*' );
				if ( ! empty( $keys ) ) {
					return (bool) $this->redis->del( $keys );
				}
				return true;

			case 'memcached':
				return $this->memcached->flush();

			default:
				return false;
		}
	}

	/**
	 * Get cache statistics.
	 *
	 * @since 1.3.0
	 *
	 * @return array Cache statistics.
	 */
	public function get_stats() {
		$stats = array(
			'backend' => $this->backend,
			'available' => $this->is_available(),
		);

		switch ( $this->backend ) {
			case 'redis':
				try {
					$info = $this->redis->info();
					$stats['memory_used'] = isset( $info['used_memory_human'] ) ? $info['used_memory_human'] : 'unknown';
					$stats['total_keys'] = isset( $info['db0'] ) ? $this->parse_redis_db_keys( $info['db0'] ) : 0;
					$stats['uptime'] = isset( $info['uptime_in_seconds'] ) ? $info['uptime_in_seconds'] : 0;
				} catch ( Exception $e ) {
					$stats['error'] = $e->getMessage();
				}
				break;

			case 'memcached':
				try {
					$memcached_stats = $this->memcached->getStats();
					if ( ! empty( $memcached_stats ) ) {
						$server_stats = reset( $memcached_stats );
						$stats['memory_used'] = isset( $server_stats['bytes'] ) ? size_format( $server_stats['bytes'] ) : 'unknown';
						$stats['total_keys'] = isset( $server_stats['curr_items'] ) ? $server_stats['curr_items'] : 0;
						$stats['uptime'] = isset( $server_stats['uptime'] ) ? $server_stats['uptime'] : 0;
					}
				} catch ( Exception $e ) {
					$stats['error'] = $e->getMessage();
				}
				break;
		}

		return $stats;
	}

	/**
	 * Parse Redis database key count.
	 *
	 * @since 1.3.0
	 *
	 * @param string $db_string Redis database string (e.g., "keys=123,expires=45").
	 * @return int Number of keys.
	 */
	protected function parse_redis_db_keys( $db_string ) {
		if ( preg_match( '/keys=(\d+)/', $db_string, $matches ) ) {
			return (int) $matches[1];
		}
		return 0;
	}

	/**
	 * Warm up cache with frequently accessed data.
	 *
	 * @since 1.3.0
	 *
	 * @return array Results of cache warmup.
	 */
	public function warmup() {
		if ( ! $this->is_available() ) {
			return array(
				'success' => false,
				'message' => __( 'Cache backend not available.', 'mcp-ai-wpoos' ),
			);
		}

		$warmed = 0;
		$failed = 0;

		// Cache workflow list.
		$orchestrator = wp_mcp_ai_get_workflow_orchestrator();
		if ( $orchestrator ) {
			$workflows = $orchestrator->get_workflows();
			if ( $this->set( 'workflows_list', $workflows, 3600 ) ) {
				$warmed++;
			} else {
				$failed++;
			}
		}

		// Cache available commands.
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( $handler ) {
			$commands = $handler->get_registered_commands();
			if ( $this->set( 'commands_list', $commands, 3600 ) ) {
				$warmed++;
			} else {
				$failed++;
			}
		}

		return array(
			'success' => $warmed > 0,
			'warmed'  => $warmed,
			'failed'  => $failed,
			'message' => sprintf(
				/* translators: 1: warmed count, 2: failed count */
				__( 'Cache warmup complete. Warmed: %1$d, Failed: %2$d', 'mcp-ai-wpoos' ),
				$warmed,
				$failed
			),
		);
	}
}

/**
 * Get cache adapter instance.
 *
 * @since 1.3.0
 *
 * @return WP_MCP_AI_Cache_Adapter Cache adapter instance.
 */
function wp_mcp_ai_get_cache_adapter() {
	static $adapter = null;

	if ( null === $adapter ) {
		$adapter = new WP_MCP_AI_Cache_Adapter();
	}

	return $adapter;
}
