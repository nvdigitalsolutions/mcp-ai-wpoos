<?php
/**
 * WordPress-native tool helpers (D8 Cluster 2c port of the base plugin's
 * WP_MCP_AI_Tool_WordPress_Native trait — byte-identical hook names,
 * cache-key format, and filter/action signatures; per-mode seams).
 *
 * The base trait's caching delegates to WP_MCP_AI_Cache_Helper (object
 * cache group 'wp_mcp_ai' + transient fallback under the 'wp_mcp_ai_'
 * key prefix). This port keeps that contract: monolith installs delegate
 * to the base helper, standalone installs implement the same
 * object-cache + transient behaviour directly so the byte-identical
 * keys persist across install modes.
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared WordPress-native tool integration helpers.
 *
 * @since 1.0.4
 */
trait WordPressNativeTrait {

	/**
	 * Get cached result with tool-specific cache key.
	 *
	 * @param array $arguments Tool arguments used to generate cache key.
	 * @return mixed|false Cached value or false if not found.
	 */
	protected function get_cached_result( $arguments ) {
		$cache_key = $this->generate_cache_key( $arguments );

		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			return \WP_MCP_AI_Cache_Helper::get( $cache_key );
		}

		// Standalone: object cache first, then transient (base-identical keys).
		$full_key = 'wp_mcp_ai_' . $cache_key;
		$value    = wp_cache_get( $full_key, 'wp_mcp_ai' );

		if ( false !== $value ) {
			return $value;
		}

		return get_transient( $full_key );
	}

	/**
	 * Set cached result with tool-specific cache key.
	 *
	 * @param array $arguments  Tool arguments used to generate cache key.
	 * @param mixed $value      Value to cache.
	 * @param int   $expiration Cache expiration in seconds (default: 1 hour).
	 * @return bool True on success, false on failure.
	 */
	protected function set_cached_result( $arguments, $value, $expiration = HOUR_IN_SECONDS ) {
		$cache_key = $this->generate_cache_key( $arguments );

		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			return \WP_MCP_AI_Cache_Helper::set( $cache_key, $value, $expiration );
		}

		// Standalone: object cache + transient (base-identical keys).
		$full_key = 'wp_mcp_ai_' . $cache_key;
		$success  = wp_cache_set( $full_key, $value, 'wp_mcp_ai', $expiration );

		return set_transient( $full_key, $value, $expiration ) && $success;
	}

	/**
	 * Generate cache key from tool slug and arguments (base-identical).
	 *
	 * @param array $arguments Tool arguments.
	 * @return string Cache key.
	 */
	protected function generate_cache_key( $arguments ) {
		$args_hash = md5( wp_json_encode( $arguments ) );
		return 'tool_' . $this->getSlug() . '_' . $args_hash;
	}

	/**
	 * Whether this tool's results should be cached (base-identical rule).
	 *
	 * @return bool True if results should be cached.
	 */
	protected function should_cache() {
		$flags = $this->getCapabilityFlags();
		return in_array( 'cacheable', $flags, true );
	}

	/**
	 * Fire before-execution filter + action (base-identical hook names).
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed|null Null to continue, or an intercepted result.
	 */
	protected function do_before_execute( $arguments, $context ) {
		/**
		 * Filter before any tool execution (base-identical hook).
		 *
		 * Return a non-null value to intercept and skip execute().
		 *
		 * @param mixed  $pre       Null to continue, or an intercepted result.
		 * @param string $tool_slug Tool slug identifier.
		 * @param array  $arguments Tool arguments.
		 * @param array  $context   Execution context.
		 */
		$pre = apply_filters(
			'wp_mcp_ai_before_tool_execute',
			null,
			$this->getSlug(),
			$arguments,
			$context
		);

		if ( null !== $pre ) {
			return $pre;
		}

		/**
		 * Fires before specific tool execution (base-identical hook).
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 */
		do_action( "wp_mcp_ai_before_tool_execute_{$this->getSlug()}", $arguments, $context );

		return null;
	}

	/**
	 * Fire after-execution actions (base-identical hook names).
	 *
	 * @param mixed $result    Tool execution result.
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return void
	 */
	protected function do_after_execute( $result, $arguments, $context ) {
		/**
		 * Fires after any tool execution (base-identical hook).
		 *
		 * @param mixed  $result    Tool execution result.
		 * @param array  $arguments Tool arguments.
		 * @param array  $context   Execution context.
		 * @param string $tool_slug Tool slug identifier.
		 */
		do_action( 'wp_mcp_ai_after_tool_execute', $result, $arguments, $context, $this->getSlug() );

		/**
		 * Fires after specific tool execution (base-identical hook).
		 *
		 * @param mixed $result    Tool execution result.
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 */
		do_action( "wp_mcp_ai_after_tool_execute_{$this->getSlug()}", $result, $arguments, $context );
	}

	/**
	 * Apply WordPress filters to tool result (base-identical hook names).
	 *
	 * @param mixed $result    Tool execution result.
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed Filtered result.
	 */
	protected function apply_result_filter( $result, $arguments, $context ) {
		/**
		 * Filter tool execution result (base-identical hook).
		 *
		 * @param mixed  $result    Tool execution result.
		 * @param array  $arguments Tool arguments.
		 * @param array  $context   Execution context.
		 * @param string $tool_slug Tool slug identifier.
		 */
		$result = apply_filters( 'wp_mcp_ai_tool_result', $result, $arguments, $context, $this->getSlug() );

		/**
		 * Filter specific tool execution result (base-identical hook).
		 *
		 * @param mixed $result    Tool execution result.
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 */
		return apply_filters( "wp_mcp_ai_tool_result_{$this->getSlug()}", $result, $arguments, $context );
	}

	/**
	 * Track tool performance metrics (base-identical action + slow-log).
	 *
	 * @param float $start_time Execution start timestamp.
	 * @param array $arguments  Tool arguments.
	 * @return void
	 */
	protected function track_performance( $start_time, $arguments ) {
		$execution_time = microtime( true ) - $start_time;

		/**
		 * Fires when tool execution completes with performance metrics.
		 *
		 * @param string $tool_slug      Tool slug identifier.
		 * @param float  $execution_time Execution time in seconds.
		 * @param array  $arguments      Tool arguments.
		 */
		do_action( 'wp_mcp_ai_tool_performance', $this->getSlug(), $execution_time, $arguments );

		// Log slow executions (per-mode seam).
		if ( $execution_time > 5.0 ) {
			$this->log(
				'warning',
				sprintf(
					/* translators: 1: tool slug, 2: execution time */
					__( 'Slow tool execution: %1$s took %2$s seconds', 'nvoos-content-graph-ai' ),
					$this->getSlug(),
					number_format( $execution_time, 2 )
				),
				array(
					'execution_time' => $execution_time,
					'arguments'      => $arguments,
				)
			);
		}
	}

	/**
	 * Log tool execution for monitoring (per-mode seam: base logger in
	 * monolith installs, no-op standalone where the base log is absent).
	 *
	 * @param string $level   Log level (info, warning, error).
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return void
	 */
	protected function log( $level, $message, $context = array() ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log' ) ) {
			$context['tool'] = $this->getSlug();
			\WP_MCP_AI_Logger::log( $level, $message, $context );
		}
	}
}
