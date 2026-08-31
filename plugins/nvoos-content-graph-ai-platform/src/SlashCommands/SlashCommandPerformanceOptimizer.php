<?php
/**
 * Slash Command Performance Optimizer
 *
 * Provides caching and performance optimizations for slash commands.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performance Optimizer Class
 *
 * Implements caching, lazy loading, and performance monitoring
 * for the slash command system. Supports Redis/Memcached backends.
 *
 * @since 1.3.0
 */
class SlashCommandPerformanceOptimizer {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Cache expiration time (5 minutes).
	 *
	 * @var int
	 */
	const CACHE_EXPIRATION = 300;

	/**
	 * Cache group name.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'mcp_ai_slash_commands';

	/**
	 * Performance metrics.
	 *
	 * @var array
	 */
	protected $metrics = array();

	/**
	 * Cache adapter instance.
	 *
	 * @var WP_MCP_AI_Cache_Adapter|null
	 */
	protected $cache_adapter = null;

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		// Load cache adapter if available.
		if ( function_exists( 'wp_mcp_ai_get_cache_adapter' ) ) {
			$this->cache_adapter = wp_mcp_ai_get_cache_adapter();
		}
	}

	/**
	 * Start performance monitoring.
	 *
	 * @since 1.3.0
	 *
	 * @param string $operation Operation name.
	 * @return string Timer ID.
	 */
	public function start_timer( $operation ) {
		$timer_id                   = uniqid( $operation . '_', true );
		$this->metrics[ $timer_id ] = array(
			'operation' => $operation,
			'start'     => microtime( true ),
			'memory'    => memory_get_usage(),
		);
		return $timer_id;
	}

	/**
	 * Stop performance monitoring.
	 *
	 * @since 1.3.0
	 *
	 * @param string $timer_id Timer ID from start_timer().
	 * @return array Performance metrics.
	 */
	public function stop_timer( $timer_id ) {
		if ( ! isset( $this->metrics[ $timer_id ] ) ) {
			return array();
		}

		$this->metrics[ $timer_id ]['end']         = microtime( true );
		$this->metrics[ $timer_id ]['duration']    = $this->metrics[ $timer_id ]['end'] - $this->metrics[ $timer_id ]['start'];
		$this->metrics[ $timer_id ]['memory_used'] = memory_get_usage() - $this->metrics[ $timer_id ]['memory'];

		return $this->metrics[ $timer_id ];
	}

	/**
	 * Get performance metrics.
	 *
	 * @since 1.3.0
	 *
	 * @return array Performance metrics.
	 */
	public function get_metrics() {
		return $this->metrics;
	}

	/**
	 * Get cached command result.
	 *
	 * Tries Redis/Memcached first, falls back to WordPress object cache.
	 *
	 * @since 1.3.0
	 *
	 * @param string $command Command string.
	 * @param array  $args Command arguments.
	 * @return mixed|false Cached result or false if not found.
	 */
	public function get_cached_result( $command, $args ) {
		$cache_key = $this->generate_cache_key( $command, $args );

		// Try persistent cache first (Redis/Memcached).
		if ( $this->cache_adapter && $this->cache_adapter->is_available() ) {
			$cached = $this->cache_adapter->get( $cache_key );
			if ( false !== $cached ) {
				// Add cache hit indicator.
				if ( is_array( $cached ) ) {
					$cached['cached']        = true;
					$cached['cache_backend'] = $this->cache_adapter->get_backend();
					$cached['cache_time']    = isset( $cached['_cache_time'] ) ? $cached['_cache_time'] : null;
				}
				return $cached;
			}
		}

		// Fall back to WordPress object cache.
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			// Add cache hit indicator.
			if ( is_array( $cached ) ) {
				$cached['cached']        = true;
				$cached['cache_backend'] = 'WordPress';
				$cached['cache_time']    = isset( $cached['_cache_time'] ) ? $cached['_cache_time'] : null;
			}
		}

		return $cached;
	}

	/**
	 * Set cached command result.
	 *
	 * Stores in both Redis/Memcached and WordPress object cache for redundancy.
	 *
	 * @since 1.3.0
	 *
	 * @param string $command Command string.
	 * @param array  $args Command arguments.
	 * @param mixed  $result Command result.
	 * @param int    $expiration Cache expiration in seconds.
	 * @return bool True on success, false on failure.
	 */
	public function set_cached_result( $command, $args, $result, $expiration = null ) {
		if ( null === $expiration ) {
			$expiration = self::CACHE_EXPIRATION;
		}

		// Add cache timestamp.
		if ( is_array( $result ) ) {
			$result['_cache_time'] = current_time( 'mysql' );
		}

		$cache_key = $this->generate_cache_key( $command, $args );
		$success   = false;

		// Store in persistent cache (Redis/Memcached).
		if ( $this->cache_adapter && $this->cache_adapter->is_available() ) {
			$success = $this->cache_adapter->set( $cache_key, $result, $expiration );
		}

		// Also store in WordPress object cache as fallback.
		$wp_cache_success = wp_cache_set( $cache_key, $result, self::CACHE_GROUP, $expiration );

		return $success || $wp_cache_success;
	}

	/**
	 * Clear cached command results.
	 *
	 * @since 1.3.0
	 *
	 * @param string $command Optional. Specific command to clear. If empty, clears all.
	 * @return bool True on success.
	 */
	public function clear_cache( $command = '' ) {
		if ( empty( $command ) ) {
			// Clear all command cache.
			return wp_cache_flush_group( self::CACHE_GROUP );
		}

		// Clear specific command cache (would need to track all keys).
		// For now, just flush the group.
		return wp_cache_flush_group( self::CACHE_GROUP );
	}

	/**
	 * Generate cache key for command.
	 *
	 * @since 1.3.0
	 *
	 * @param string $command Command string.
	 * @param array  $args Command arguments.
	 * @return string Cache key.
	 */
	protected function generate_cache_key( $command, $args ) {
		// Normalize arguments for consistent caching.
		ksort( $args );
		$args_hash = md5( wp_json_encode( $args ) );
		return "cmd_{$command}_{$args_hash}";
	}

	/**
	 * Check if result should be cached.
	 *
	 * @since 1.3.0
	 *
	 * @param string $command Command name.
	 * @param array  $result Command result.
	 * @return bool True if should cache, false otherwise.
	 */
	public function should_cache( $command, $result ) {
		// Don't cache errors.
		if ( is_wp_error( $result ) ) {
			return false;
		}

		if ( is_array( $result ) && isset( $result['success'] ) && ! $result['success'] ) {
			return false;
		}

		// Don't cache write operations.
		$write_commands = array(
			'aitool-create',
			'content-draft',
			'doc-create',
			'social-post',
			'lead-add',
		);

		if ( in_array( $command, $write_commands, true ) ) {
			return false;
		}

		// Cache read operations.
		$cacheable_commands = array(
			'prompt-library',
			'help',
			'analytics-dashboard',
			'research-query',
			'tool-marketplace',
		);

		return in_array( $command, $cacheable_commands, true );
	}

	/**
	 * Optimize command loading with lazy initialization.
	 *
	 * @since 1.3.0
	 *
	 * @param array $commands All command definitions.
	 * @return array Optimized command definitions.
	 */
	public function optimize_command_loading( $commands ) {
		// For pro mode with many commands, defer loading of command definitions
		// until they're actually needed.

		if ( count( $commands ) < 100 ) {
			// Small command set, no optimization needed.
			return $commands;
		}

		// Create lazy-loaded command wrappers.
		$optimized = array();

		foreach ( $commands as $name => $config ) {
			// Keep essential metadata, defer full config loading.
			$optimized[ $name ] = array(
				'name'        => $name,
				'description' => isset( $config['description'] ) ? $config['description'] : '',
				'capability'  => isset( $config['capability'] ) ? $config['capability'] : 'edit_posts',
				'_lazy'       => true,
				'_config'     => $config,
			);
		}

		return $optimized;
	}

	/**
	 * Batch process multiple commands.
	 *
	 * Executes multiple commands efficiently with shared initialization.
	 *
	 * @since 1.3.0
	 *
	 * @param array $commands Array of command strings.
	 * @param array $context Execution context.
	 * @return array Results for all commands.
	 */
	public function batch_execute( $commands, $context = array() ) {
		$timer_id = $this->start_timer( 'batch_execute' );
		$results  = array();
		$handler  = wp_mcp_ai_get_slash_command_handler();

		if ( ! $handler ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'Command handler not available.', 'nvoos-content-graph-ai-platform' ) );
		}

		foreach ( $commands as $index => $command ) {
			$results[ $index ] = $handler->execute( $command, $context );
		}

		$metrics = $this->stop_timer( $timer_id );

		return array(
			'success' => true,
			'results' => $results,
			'metrics' => array(
				'total_commands' => count( $commands ),
				'duration'       => $metrics['duration'],
				'avg_duration'   => $metrics['duration'] / count( $commands ),
			),
		);
	}

	/**
	 * Get performance statistics.
	 *
	 * @since 1.3.0
	 *
	 * @return array Performance statistics.
	 */
	public function get_stats() {
		$stats = array(
			'cache_hits'   => 0,
			'cache_misses' => 0,
			'total_time'   => 0,
			'avg_time'     => 0,
			'peak_memory'  => 0,
		);

		foreach ( $this->metrics as $metric ) {
			if ( isset( $metric['duration'] ) ) {
				$stats['total_time'] += $metric['duration'];
			}

			if ( isset( $metric['memory_used'] ) && $metric['memory_used'] > $stats['peak_memory'] ) {
				$stats['peak_memory'] = $metric['memory_used'];
			}
		}

		if ( ! empty( $this->metrics ) ) {
			$stats['avg_time'] = $stats['total_time'] / count( $this->metrics );
		}

		return $stats;
	}

	/**
	 * Enable performance profiling.
	 *
	 * @since 1.3.0
	 *
	 * @param bool $enable True to enable, false to disable.
	 */
	public function enable_profiling( $enable = true ) {
		if ( $enable ) {
			add_action( 'wp_mcp_ai_slash_command_before_execute', array( $this, 'profile_command_start' ), 10, 2 );
			add_action( 'wp_mcp_ai_slash_command_after_execute', array( $this, 'profile_command_end' ), 10, 3 );
		} else {
			remove_action( 'wp_mcp_ai_slash_command_before_execute', array( $this, 'profile_command_start' ), 10 );
			remove_action( 'wp_mcp_ai_slash_command_after_execute', array( $this, 'profile_command_end' ), 10 );
		}
	}

	/**
	 * Profile command execution start.
	 *
	 * @since 1.3.0
	 *
	 * @param string $command Command name.
	 * @param array  $args Command arguments.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function profile_command_start( $command, $args ) {
		$this->start_timer( "command_{$command}" );
	}

	/**
	 * Profile command execution end.
	 *
	 * @since 1.3.0
	 *
	 * @param string $command Command name.
	 * @param array  $args Command arguments.
	 * @param mixed  $result Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function profile_command_end( $command, $args, $result ) {
		$timer_id = "command_{$command}_" . substr( md5( wp_json_encode( $args ) ), 0, 8 );
		$metrics  = $this->stop_timer( $timer_id );

		// Log slow commands.
		if ( isset( $metrics['duration'] ) && $metrics['duration'] > 2.0 ) {
			if ( function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log(
					"Slow command detected: {$command}",
					array(
						'command'  => $command,
						'duration' => $metrics['duration'],
						'memory'   => $metrics['memory_used'],
					),
					'warning'
				);
			}
		}
	}
}
