<?php
/**
 * Async Tool Orchestrator Service
 *
 * Responsible for determining whether a tool should execute synchronously or asynchronously.
 * Handles the decision logic and delegates to appropriate execution mechanisms.
 *
 * Separation of Concerns:
 * - This class ONLY decides sync vs async routing
 * - Does NOT execute tools directly
 * - Does NOT manage cron jobs directly
 * - Does NOT format results for UI
 * - Does NOT log events (delegates to logger)
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
}

/**
 * Async Tool Orchestrator Service class
 *
 * Determines execution strategy for tools based on capability flags.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Async_Tool_Orchestrator {

	/**
	 * Capability flags that indicate a tool may timeout
	 *
	 * @var array
	 */
	protected $timeout_risk_flags = array(
		'async',             // Tool may take significant time.
		'long-running',      // Execution may take minutes or hours.
		'may-timeout',       // May exceed typical HTTP request timeout.
		'background-only',   // Must run in background to avoid timeouts.
	);

	/**
	 * Determine if a tool should execute asynchronously
	 *
	 * Decision hierarchy (Separation of Concerns):
	 * 1. Background-only flag - HIGHEST PRIORITY (system requirement, prevents HTTP timeouts)
	 * 2. Explicit user parameter (async=true/false) - User preference
	 * 3. Legacy compatibility (wait_for_completion=false) - Backwards compatibility
	 * 4. Agentic loop context - FORCE SYNC (LLM needs complete results, except background-only)
	 * 5. Global async setting disabled - FORCE SYNC
	 * 6. No timeout risk flags - FORCE SYNC (tools without async flags run sync)
	 * 7. Background preference flag - RUN ASYNC
	 *
	 * @param object $tool Tool instance implementing WP_MCP_AI_Tool_Interface.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context Execution context.
	 * @return bool True if tool should execute asynchronously.
	 */
	public function should_execute_async( $tool, array $arguments = array(), array $context = array() ) {
		// Priority 1: Background-only tools MUST run async regardless of other settings.
		// These tools take so long (60+ seconds) that they would cause HTTP timeouts.
		// if run synchronously. This takes highest priority to prevent timeouts.
		if ( $this->is_background_only( $tool ) ) {
			return true;
		}

		// Priority 2: Explicit async parameter from user/LLM.
		if ( isset( $arguments['async'] ) ) {
			return (bool) $arguments['async'];
		}

		// Priority 3: Legacy compatibility - respect wait_for_completion parameter.
		// If wait_for_completion=false, tool wants async (don't wait)
		// If wait_for_completion=true, tool wants sync (wait in same request)
		if ( isset( $arguments['wait_for_completion'] ) ) {
			return ! (bool) $arguments['wait_for_completion'];
		}

		// Priority 4: Agentic loop context - force synchronous execution.
		// When executing in an agentic loop, tools MUST complete synchronously so the LLM.
		// receives actual results (e.g., generated image URL) before generating its response.
		// Without this, the LLM would see only "pending" status and cannot produce meaningful output.
		// Exception: background-only tools (Priority 1) still run async even in agentic loops.
		if ( ! empty( $context['agentic_loop'] ) ) {
			return false;
		}

		// Priority 5: System intelligence - check global setting.
		if ( ! $this->is_async_execution_enabled() ) {
			return false;
		}

		// Priority 6: Check tool capability flags for timeout risk.
		if ( ! $this->has_timeout_risk( $tool ) ) {
			return false;
		}

		// Priority 7: Check if tool prefers background execution.
		if ( $this->prefers_background( $tool ) ) {
			return true;
		}

		/**
		 * Filter whether a specific tool should execute asynchronously.
		 *
		 * @param bool   $should_async Default async decision.
		 * @param object $tool         Tool instance.
		 * @param array  $arguments    Tool arguments.
		 * @param array  $context      Execution context.
		 */
		return apply_filters( 'wp_mcp_ai_should_execute_async', true, $tool, $arguments, $context );
	}

	/**
	 * Check if async execution is enabled globally
	 *
	 * @return bool True if async execution is enabled.
	 */
	protected function is_async_execution_enabled() {
		/**
		 * Filter whether async tool execution is enabled.
		 *
		 * @param bool $enabled Default: true.
		 */
		return apply_filters( 'wp_mcp_ai_async_execution_enabled', true );
	}



	/**
	 * Check if tool has timeout risk based on capability flags
	 *
	 * @param object $tool Tool instance.
	 * @return bool True if tool has timeout risk.
	 */
	protected function has_timeout_risk( $tool ) {
		if ( ! $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			return false;
		}

		$flags = $tool->get_capability_flags();

		if ( ! is_array( $flags ) ) {
			return false;
		}

		foreach ( $this->timeout_risk_flags as $risk_flag ) {
			if ( in_array( $risk_flag, $flags, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if tool prefers background execution
	 *
	 * @param object $tool Tool instance.
	 * @return bool True if tool prefers background.
	 */
	protected function prefers_background( $tool ) {
		if ( ! $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			return false;
		}

		$flags = $tool->get_capability_flags();

		if ( ! is_array( $flags ) ) {
			return false;
		}

		return in_array( 'background-preferred', $flags, true );
	}

	/**
	 * Check if tool must execute in background
	 *
	 * @param object $tool Tool instance.
	 * @return bool True if tool is background-only.
	 */
	protected function is_background_only( $tool ) {
		if ( ! $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			return false;
		}

		$flags = $tool->get_capability_flags();

		if ( ! is_array( $flags ) ) {
			return false;
		}

		return in_array( 'background-only', $flags, true );
	}

	/**
	 * Get execution strategy for a tool
	 *
	 * Returns metadata about how the tool should be executed.
	 *
	 * @param object $tool Tool instance.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context Execution context.
	 * @return array Strategy metadata.
	 */
	public function get_execution_strategy( $tool, array $arguments = array(), array $context = array() ) {
		$should_async = $this->should_execute_async( $tool, $arguments, $context );

		$strategy = array(
			'mode'              => $should_async ? 'async' : 'sync',
			'has_timeout_risk'  => $this->has_timeout_risk( $tool ),
			'background_only'   => $this->is_background_only( $tool ),
			'estimated_timeout' => $this->estimate_timeout( $tool ),
		);

		/**
		 * Filter the execution strategy for a tool.
		 *
		 * @param array  $strategy  Execution strategy metadata.
		 * @param object $tool      Tool instance.
		 * @param array  $arguments Tool arguments.
		 * @param array  $context   Execution context.
		 */
		return apply_filters( 'wp_mcp_ai_tool_execution_strategy', $strategy, $tool, $arguments, $context );
	}

	/**
	 * Estimate timeout for a tool in seconds
	 *
	 * @param object $tool Tool instance.
	 * @return int Estimated timeout in seconds.
	 */
	protected function estimate_timeout( $tool ) {
		// Default PHP max_execution_time is often 30 seconds.
		$default_timeout = 30;

		if ( ! $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			return $default_timeout;
		}

		$flags = $tool->get_capability_flags();

		if ( ! is_array( $flags ) ) {
			return $default_timeout;
		}

		// Background-only tasks likely need unlimited time.
		if ( in_array( 'background-only', $flags, true ) ) {
			return 0; // Unlimited.
		}

		// Long-running tasks may need several minutes.
		if ( in_array( 'long-running', $flags, true ) ) {
			return 300; // 5 minutes.
		}

		// Async tasks may need extended time.
		if ( in_array( 'async', $flags, true ) ) {
			return 120; // 2 minutes.
		}

		// May-timeout suggests it's close to the limit.
		if ( in_array( 'may-timeout', $flags, true ) ) {
			return 60; // 1 minute.
		}

		return $default_timeout;
	}

	/**
	 * Get list of tools that support async execution
	 *
	 * @param array $tools Array of tool instances.
	 * @return array Array of tool slugs that support async.
	 */
	public function get_async_capable_tools( array $tools ) {
		$async_tools = array();

		foreach ( $tools as $tool ) {
			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				continue;
			}

			if ( $this->has_timeout_risk( $tool ) ) {
				$async_tools[] = $tool->get_slug();
			}
		}

		return $async_tools;
	}
}
