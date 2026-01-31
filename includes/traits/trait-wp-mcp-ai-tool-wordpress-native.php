<?php
/**
 * Trait for WordPress-native AI tools with enhanced WordPress integration.
 *
 * Provides common functionality for tools that deeply integrate with
 * WordPress core features like hooks, caching, privacy, and performance.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress Native Tool Trait
 *
 * Adds WordPress-specific helper methods to tools for:
 * - Hook integration and registration
 * - Intelligent caching with cache groups
 * - Privacy compliance (GDPR)
 * - Performance tracking
 * - Background processing
 *
 * @since 1.0.0
 */
trait WP_MCP_AI_Tool_WordPress_Native {

	/**
	 * Register WordPress hooks for this tool.
	 *
	 * Override this method to register action/filter hooks
	 * that enable automatic tool execution on WordPress events.
	 *
	 * Example: Register save_post hook for auto-categorization.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function register_hooks() {
		// Override in child class to register hooks.
	}

	/**
	 * Get cached result with tool-specific cache key.
	 *
	 * Uses WordPress transient API with automatic cache key generation
	 * based on tool slug and arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array $arguments Tool arguments used to generate cache key.
	 * @return mixed|false Cached value or false if not found.
	 */
	protected function get_cached_result( $arguments ) {
		$cache_key = $this->generate_cache_key( $arguments );
		return WP_MCP_AI_Cache_Helper::get( $cache_key );
	}

	/**
	 * Set cached result with tool-specific cache key.
	 *
	 * @since 1.0.0
	 *
	 * @param array $arguments  Tool arguments used to generate cache key.
	 * @param mixed $value      Value to cache.
	 * @param int   $expiration Cache expiration in seconds (default: 1 hour).
	 * @return bool True on success, false on failure.
	 */
	protected function set_cached_result( $arguments, $value, $expiration = HOUR_IN_SECONDS ) {
		$cache_key = $this->generate_cache_key( $arguments );
		return WP_MCP_AI_Cache_Helper::set( $cache_key, $value, $expiration );
	}

	/**
	 * Invalidate cached results for this tool.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $arguments Optional. Specific arguments to invalidate.
	 *                              If null, invalidates all caches for this tool.
	 * @return bool True on success, false on failure.
	 */
	protected function invalidate_cache( $arguments = null ) {
		if ( null === $arguments ) {
			// Invalidate all caches for this tool.
			return WP_MCP_AI_Cache_Helper::delete_group( 'tool_' . $this->get_slug() );
		}

		$cache_key = $this->generate_cache_key( $arguments );
		return WP_MCP_AI_Cache_Helper::delete( $cache_key );
	}

	/**
	 * Generate cache key from tool slug and arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array $arguments Tool arguments.
	 * @return string Cache key.
	 */
	protected function generate_cache_key( $arguments ) {
		$args_hash = md5( wp_json_encode( $arguments ) );
		return 'tool_' . $this->get_slug() . '_' . $args_hash;
	}

	/**
	 * Check if this tool should be cached.
	 *
	 * Override this method to control caching behavior.
	 * Default: cache if tool has 'cacheable' capability flag.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if results should be cached.
	 */
	protected function should_cache() {
		if ( ! $this instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			return false;
		}

		$flags = $this->get_capability_flags();
		return in_array( 'cacheable', $flags, true );
	}

	/**
	 * Apply WordPress filters to tool result.
	 *
	 * Allows developers to modify tool results via filter hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $result    Tool execution result.
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed Filtered result.
	 */
	protected function apply_result_filter( $result, $arguments, $context ) {
		/**
		 * Filter tool execution result.
		 *
		 * Allows developers to modify the result of any tool execution.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed  $result    Tool execution result.
		 * @param array  $arguments Tool arguments.
		 * @param array  $context   Execution context.
		 * @param string $tool_slug Tool slug identifier.
		 */
		$result = apply_filters( 'wp_mcp_ai_tool_result', $result, $arguments, $context, $this->get_slug() );

		/**
		 * Filter specific tool execution result.
		 *
		 * Dynamic hook name includes the tool slug for targeted filtering.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed $result    Tool execution result.
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 */
		return apply_filters( "wp_mcp_ai_tool_result_{$this->get_slug()}", $result, $arguments, $context );
	}

	/**
	 * Fire WordPress action before tool execution.
	 *
	 * Allows developers to hook into tool execution lifecycle.
	 *
	 * @since 1.0.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return void
	 */
	protected function do_before_execute( $arguments, $context ) {
		/**
		 * Fires before any tool execution.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $arguments Tool arguments.
		 * @param array  $context   Execution context.
		 * @param string $tool_slug Tool slug identifier.
		 */
		do_action( 'wp_mcp_ai_before_tool_execute', $arguments, $context, $this->get_slug() );

		/**
		 * Fires before specific tool execution.
		 *
		 * Dynamic hook name includes the tool slug.
		 *
		 * @since 1.0.0
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 */
		do_action( "wp_mcp_ai_before_tool_execute_{$this->get_slug()}", $arguments, $context );
	}

	/**
	 * Fire WordPress action after tool execution.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $result    Tool execution result.
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return void
	 */
	protected function do_after_execute( $result, $arguments, $context ) {
		/**
		 * Fires after any tool execution.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed  $result    Tool execution result.
		 * @param array  $arguments Tool arguments.
		 * @param array  $context   Execution context.
		 * @param string $tool_slug Tool slug identifier.
		 */
		do_action( 'wp_mcp_ai_after_tool_execute', $result, $arguments, $context, $this->get_slug() );

		/**
		 * Fires after specific tool execution.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed $result    Tool execution result.
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 */
		do_action( "wp_mcp_ai_after_tool_execute_{$this->get_slug()}", $result, $arguments, $context );
	}

	/**
	 * Check if tool should export data for privacy request.
	 *
	 * Override this method to indicate whether the tool stores
	 * user-specific data that should be included in privacy exports.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if tool handles exportable user data.
	 */
	protected function has_privacy_data() {
		return false;
	}

	/**
	 * Export privacy data for user.
	 *
	 * Override this method to provide data for GDPR export requests.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID for data export.
	 * @return array Privacy export data.
	 */
	protected function export_privacy_data( $user_id  ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for user filtering. {
		return array();
	}

	/**
	 * Erase privacy data for user.
	 *
	 * Override this method to handle GDPR erasure requests.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID for data erasure.
	 * @return array Erasure result with 'items_removed', 'items_retained', 'messages'.
	 */
	protected function erase_privacy_data( $user_id  ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for user filtering. {
		return array(
			'items_removed'  => 0,
			'items_retained' => 0,
			'messages'       => array(),
		);
	}

	/**
	 * Schedule background task for tool execution.
	 *
	 * Uses WordPress cron system for long-running operations.
	 *
	 * @since 1.0.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	protected function schedule_background_task( $arguments, $context ) {
		if ( ! function_exists( 'as_schedule_single_action' ) && ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			return new WP_Error(
				'background_task_unavailable',
				__( 'Background task scheduling is not available.', 'mcp-ai-wpoos' )
			);
		}

		$hook = 'wp_mcp_ai_background_tool_' . $this->get_slug();

		// Try Action Scheduler first (if available).
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time(),
				$hook,
				array(
					'arguments' => $arguments,
					'context'   => $context,
				)
			);
			return true;
		}

		// Fallback to WordPress cron.
		if ( ! wp_next_scheduled( $hook, array( $arguments, $context ) ) ) {
			wp_schedule_single_event(
				time(),
				$hook,
				array(
					'arguments' => $arguments,
					'context'   => $context,
				)
			);
		}

		return true;
	}

	/**
	 * Log tool execution for monitoring.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level   Log level (info, warning, error).
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return void
	 */
	protected function log( $level, $message, $context = array() ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			$context['tool'] = $this->get_slug();
			WP_MCP_AI_Logger::log( $level, $message, $context );
		}
	}

	/**
	 * Track tool performance metrics.
	 *
	 * @since 1.0.0
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
		 * @since 1.0.0
		 *
		 * @param string $tool_slug      Tool slug identifier.
		 * @param float  $execution_time Execution time in seconds.
		 * @param array  $arguments      Tool arguments.
		 */
		do_action( 'wp_mcp_ai_tool_performance', $this->get_slug(), $execution_time, $arguments );

		// Log slow executions.
		if ( $execution_time > 5.0 ) {
			$this->log(
				'warning',
				sprintf(
					/* translators: %1$s: tool slug, %2$s: execution time */
					__( 'Slow tool execution: %1$s took %2$s seconds', 'mcp-ai-wpoos' ),
					$this->get_slug(),
					number_format( $execution_time, 2 )
				),
				array(
					'execution_time' => $execution_time,
					'arguments'      => $arguments,
				)
			);
		}
	}
}
