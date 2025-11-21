<?php
/**
 * Tool Execution Orchestrator Service
 *
 * Manages tool execution routing between synchronous and asynchronous modes.
 * Prevents PHP timeouts by detecting long-running tools and queueing them
 * immediately via cron instead of executing synchronously.
 *
 * Separation of Concerns:
 * - This class ONLY decides sync vs async execution mode
 * - Does NOT execute tools directly (delegates to registry or async executor)
 * - Does NOT manage cron scheduling (async executor handles that)
 * - Does NOT format results (chat service handles that)
 *
 * Architecture:
 * - Chat Service → Orchestrator → Tool Registry (sync) OR Async Executor (async)
 * - Orchestrator checks tool capability flags to determine execution mode
 * - Long-running tools are queued immediately without calling tool->execute()
 * - Fast tools are executed synchronously as before
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool Execution Orchestrator Service class
 *
 * Routes tool execution based on capability flags and execution context.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Execution_Orchestrator {

	/**
	 * Tool registry instance
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $registry = null;

	/**
	 * Async executor instance
	 *
	 * @var WP_MCP_AI_Tool_Async_Executor|null
	 */
	protected $async_executor = null;

	/**
	 * Capability flags that indicate a tool should be executed asynchronously
	 *
	 * @var array
	 */
	const ASYNC_CAPABILITY_FLAGS = array(
		'long-running',
		'may-timeout',
		'async',
	);

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Tool_Registry|null         $registry Tool registry instance.
	 * @param WP_MCP_AI_Tool_Async_Executor|null   $async_executor Async executor instance.
	 */
	public function __construct( $registry = null, $async_executor = null ) {
		$this->registry       = $registry;
		$this->async_executor = $async_executor;
	}

	/**
	 * Execute a tool with orchestration
	 *
	 * Determines whether to execute synchronously or asynchronously based on:
	 * - Tool capability flags
	 * - Execution context
	 * - Force async parameter
	 *
	 * @param string $tool_slug Tool slug to execute.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error Tool result or async job info.
	 */
	public function execute_tool( $tool_slug, array $arguments = array(), array $context = array() ) {
		$tool_slug = sanitize_key( $tool_slug );

		// Get tool registry.
		$registry = $this->get_registry();
		if ( ! $registry ) {
			return new WP_Error(
				'wp_mcp_ai_registry_unavailable',
				__( 'Tool registry is not available.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Check if tool exists.
		if ( ! $registry->is_tool_registered( $tool_slug ) ) {
			return new WP_Error(
				'wp_mcp_ai_tool_not_found',
				sprintf(
					/* translators: %s: tool slug */
					__( 'Tool "%s" not found.', 'wp-mcp-ai' ),
					$tool_slug
				),
				array( 'status' => 404 )
			);
		}

		// Determine execution mode.
		$should_execute_async = $this->should_execute_async( $tool_slug, $arguments, $context );

		// Log orchestration decision.
		WP_MCP_AI_Logger::log_event(
			'tool_orchestration',
			sprintf( 'Tool "%s" orchestrated for %s execution', $tool_slug, $should_execute_async ? 'async' : 'sync' ),
			array(
				'tool_slug' => $tool_slug,
				'mode'      => $should_execute_async ? 'async' : 'sync',
			)
		);

		// Execute asynchronously if needed.
		if ( $should_execute_async ) {
			return $this->execute_async( $tool_slug, $arguments, $context );
		}

		// Execute synchronously.
		return $registry->execute_tool( $tool_slug, $arguments, $context );
	}

	/**
	 * Determine if a tool should be executed asynchronously
	 *
	 * Checks:
	 * 1. Auto-async setting in orchestration configuration
	 * 2. Force async flag in context
	 * 3. Tool capability flags (long-running, may-timeout, async)
	 * 4. Explicit async parameter in arguments
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return bool True if tool should be executed asynchronously.
	 */
	protected function should_execute_async( $tool_slug, array $arguments, array $context ) {
		// Check if async is forced in context.
		if ( isset( $context['force_async'] ) && $context['force_async'] ) {
			return true;
		}

		// Check if async is explicitly disabled in context.
		if ( isset( $context['force_sync'] ) && $context['force_sync'] ) {
			return false;
		}

		// Check if auto-async is enabled in settings.
		$settings              = get_option( 'wp_mcp_ai_settings', array() );
		$auto_async_enabled    = isset( $settings['enable_auto_async_execution'] ) ? (bool) $settings['enable_auto_async_execution'] : true;
		$cron_orchestration_enabled = isset( $settings['enable_cron_orchestration'] ) ? (bool) $settings['enable_cron_orchestration'] : true;

		// If auto-async is disabled, only respect explicit async requests.
		if ( ! $auto_async_enabled || ! $cron_orchestration_enabled ) {
			// Check if async is explicitly requested in tool arguments.
			if ( isset( $arguments['async'] ) && $arguments['async'] ) {
				return true;
			}
			return false;
		}

		// Auto-async is enabled - check if tool has async capability flags.
		$registry = $this->get_registry();
		if ( $registry ) {
			$flags = $registry->get_tool_capability_flags( $tool_slug );

			foreach ( self::ASYNC_CAPABILITY_FLAGS as $async_flag ) {
				if ( in_array( $async_flag, $flags, true ) ) {
					// Tool has async capability flag - execute asynchronously.
					return true;
				}
			}
		}

		// Check if async is requested in tool arguments.
		if ( isset( $arguments['async'] ) && $arguments['async'] ) {
			return true;
		}

		// Default to synchronous execution.
		return false;
	}

	/**
	 * Execute a tool asynchronously
	 *
	 * Queues the tool for background execution via WordPress cron.
	 * Returns a job_id immediately without waiting for execution.
	 * Falls back to synchronous execution if async executor is unavailable.
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error Async job info or sync result.
	 */
	protected function execute_async( $tool_slug, array $arguments, array $context ) {
		// Get async executor.
		$executor = $this->get_async_executor();
		if ( ! $executor ) {
			// Failsafe: Fall back to synchronous execution if async is unavailable.
			WP_MCP_AI_Logger::log_event(
				'tool_orchestration_fallback',
				sprintf( 'Tool "%s" falling back to sync execution (async executor unavailable)', $tool_slug ),
				array( 'tool_slug' => $tool_slug )
			);
			
			$registry = $this->get_registry();
			if ( ! $registry ) {
				return new WP_Error(
					'wp_mcp_ai_registry_unavailable',
					__( 'Tool registry is not available.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}
			
			return $registry->execute_tool( $tool_slug, $arguments, $context );
		}

		// Queue the tool for async execution.
		$job_id = $executor->queue_tool( $tool_slug, $arguments, $context );

		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		// Return async job info.
		return array(
			'async'   => true,
			'job_id'  => $job_id,
			'id'      => $job_id, // Include 'id' for provider compatibility.
			'status'  => 'pending',
			'message' => sprintf(
				/* translators: %s: tool name */
				__( '%s started in background. Use the job_id to check status.', 'wp-mcp-ai' ),
				$tool_slug
			),
		);
	}

	/**
	 * Get tool registry instance (lazy loaded)
	 *
	 * @return WP_MCP_AI_Tool_Registry|null
	 */
	protected function get_registry() {
		if ( null === $this->registry ) {
			if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
			}
		}

		return $this->registry;
	}

	/**
	 * Get async executor instance (lazy loaded)
	 *
	 * @return WP_MCP_AI_Tool_Async_Executor|null
	 */
	protected function get_async_executor() {
		if ( null === $this->async_executor ) {
			if ( class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
				try {
					$this->async_executor = new WP_MCP_AI_Tool_Async_Executor();
				} catch ( Exception $e ) {
					WP_MCP_AI_Logger::log_error(
						'Failed to instantiate async executor',
						array( 'error' => $e->getMessage() )
					);
					return null;
				}
			}
		}

		return $this->async_executor;
	}

	/**
	 * Check if a tool is long-running
	 *
	 * Helper method to check if a tool has async capability flags.
	 *
	 * @param string $tool_slug Tool slug.
	 * @return bool True if tool is long-running.
	 */
	public function is_long_running_tool( $tool_slug ) {
		$registry = $this->get_registry();
		if ( ! $registry ) {
			return false;
		}

		$flags = $registry->get_tool_capability_flags( $tool_slug );

		foreach ( self::ASYNC_CAPABILITY_FLAGS as $async_flag ) {
			if ( in_array( $async_flag, $flags, true ) ) {
				return true;
			}
		}

		return false;
	}
}
