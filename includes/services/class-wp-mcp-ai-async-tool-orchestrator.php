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
	 * Decision is based on:
	 * 1. Tool capability flags
	 * 2. Explicit async parameter in arguments
	 * 3. Global async execution setting
	 * 4. Whether tool has its own async mechanism (deferred-result)
	 *
	 * @param object $tool Tool instance implementing WP_MCP_AI_Tool_Interface.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context Execution context.
	 * @return bool True if tool should execute asynchronously.
	 */
	public function should_execute_async( $tool, array $arguments = array(), array $context = array() ) {
		// Check if tool has its own internal async mechanism - don't queue these
		if ( $this->has_self_async_mechanism( $tool ) ) {
			return false;
		}

		// Check if tool explicitly requests async execution.
		if ( isset( $arguments['async'] ) && true === $arguments['async'] ) {
			return true;
		}

		// Check if tool explicitly disables async execution.
		if ( isset( $arguments['async'] ) && false === $arguments['async'] ) {
			return false;
		}

		// Check global async execution setting.
		if ( ! $this->is_async_execution_enabled() ) {
			return false;
		}

		// Check tool capability flags.
		if ( ! $this->has_timeout_risk( $tool ) ) {
			return false;
		}

		// Check if tool is marked as background-only.
		if ( $this->is_background_only( $tool ) ) {
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
