<?php
/**
 * Queue Manager for NV oOS.
 *
 * Provides queue-based tool execution management integrating with RabbitMQ
 * for enhanced agentic workflow processing.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue Manager class.
 *
 * Orchestrates tool execution between synchronous and queue-based modes,
 * manages parallel execution, and provides graceful degradation.
 */
class WP_MCP_AI_Queue_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Queue_Manager|null
	 */
	private static $instance = null;

	/**
	 * RabbitMQ client instance.
	 *
	 * @var WP_MCP_AI_RabbitMQ_Client|null
	 */
	private $rabbitmq = null;

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	private $tool_registry = null;

	/**
	 * Execution mode constants.
	 */
	const MODE_SYNC        = 'sync';
	const MODE_QUEUE       = 'queue';
	const MODE_QUEUE_ASYNC = 'queue_async';
	const MODE_PARALLEL    = 'parallel';

	/**
	 * Priority constants.
	 */
	const PRIORITY_HIGH   = 'high';
	const PRIORITY_NORMAL = 'normal';
	const PRIORITY_LOW    = 'async';

	/**
	 * Default timeout thresholds in milliseconds.
	 */
	const QUICK_TOOL_THRESHOLD = 2000;  // 2 seconds.
	const ASYNC_TOOL_THRESHOLD = 10000; // 10 seconds.

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Queue_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// Hook into tool execution.
		add_filter( 'wp_mcp_ai_before_tool_execute', array( $this, 'maybe_queue_tool_execution' ), 5, 3 );

		// Admin AJAX for queue status.
		add_action( 'wp_ajax_wp_mcp_ai_queue_status', array( $this, 'ajax_queue_status' ) );
	}

	/**
	 * Get RabbitMQ client.
	 *
	 * @return WP_MCP_AI_RabbitMQ_Client
	 */
	private function get_rabbitmq() {
		if ( null === $this->rabbitmq ) {
			if ( class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
				$this->rabbitmq = WP_MCP_AI_RabbitMQ_Client::get_instance();
			}
		}
		return $this->rabbitmq;
	}

	/**
	 * Get tool registry.
	 *
	 * @return WP_MCP_AI_Tool_Registry
	 */
	private function get_tool_registry() {
		if ( null === $this->tool_registry ) {
			$this->tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		}
		return $this->tool_registry;
	}

	/**
	 * Check if queue-based execution is available.
	 *
	 * @return bool Whether queue execution is available.
	 */
	public function is_queue_available() {
		$rabbitmq = $this->get_rabbitmq();
		return null !== $rabbitmq && $rabbitmq->is_available();
	}

	/**
	 * Determine the best execution mode for a tool.
	 *
	 * @param string $tool_name Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return string Execution mode constant.
	 */
	public function get_execution_mode( $tool_name, array $arguments, array $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$tool = $this->get_tool_registry()->get_tool( $tool_name );

		if ( null === $tool ) {
			return self::MODE_SYNC;
		}

		// Check if queue is available.
		if ( ! $this->is_queue_available() ) {
			return self::MODE_SYNC;
		}

		// Check tool capability flags.
		$flags = $this->get_tool_flags( $tool );

		// Queue-required tools must use queue.
		if ( in_array( 'queue-required', $flags, true ) ) {
			return self::MODE_QUEUE_ASYNC;
		}

		// Check if async flag is set.
		if ( in_array( 'async', $flags, true ) ) {
			return self::MODE_QUEUE_ASYNC;
		}

		// Check estimated execution time.
		$estimated_time = $this->estimate_execution_time( $tool_name, $arguments );

		if ( $estimated_time > self::ASYNC_TOOL_THRESHOLD ) {
			return self::MODE_QUEUE_ASYNC;
		}

		if ( $estimated_time > self::QUICK_TOOL_THRESHOLD ) {
			// Queue-preferred tools should use queue for longer operations.
			if ( in_array( 'queue-preferred', $flags, true ) ) {
				return self::MODE_QUEUE;
			}
		}

		// Default to sync for quick tools.
		return self::MODE_SYNC;
	}

	/**
	 * Get tool capability flags.
	 *
	 * @param WP_MCP_AI_Tool_Interface $tool Tool instance.
	 * @return array Capability flags.
	 */
	private function get_tool_flags( $tool ) {
		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			return $tool->get_capability_flags();
		}
		return array();
	}

	/**
	 * Estimate tool execution time based on historical data.
	 *
	 * @param string $tool_name Tool slug.
	 * @param array  $arguments Tool arguments (reserved for future use with argument-based estimation).
	 * @return int Estimated time in milliseconds.
	 */
	private function estimate_execution_time( $tool_name, array $arguments ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Check for cached estimates.
		$cache_key = 'wp_mcp_ai_tool_time_' . md5( $tool_name );
		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		// Default estimates based on tool categories.
		$estimates = array(
			// Quick tools (< 2s).
			'get_current_time'        => 100,
			'get_site_summary'        => 500,
			'get_user_info'           => 300,
			'count_tokens'            => 200,

			// Normal tools (2-10s).
			'search_content'          => 3000,
			'get_recent_posts'        => 2000,
			'search_attachments'      => 3000,
			'web_search'              => 5000,
			'get_woo_products'        => 3000,

			// Async tools (> 10s).
			'run_crawl4ai_job'        => 60000,
			'generate_openai_image'   => 30000,
			'generate_gemini_image'   => 30000,
			'generate_openai_speech'  => 15000,
			'generate_veo_video'      => 120000,
			'transcribe_openai_audio' => 20000,
		);

		if ( isset( $estimates[ $tool_name ] ) ) {
			return $estimates[ $tool_name ];
		}

		// Default estimate for unknown tools.
		return 5000;
	}

	/**
	 * Maybe intercept and queue tool execution.
	 *
	 * @param mixed  $result    Current result (null to continue).
	 * @param string $tool_name Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @return mixed Result or null to continue normal execution.
	 */
	public function maybe_queue_tool_execution( $result, $tool_name, $arguments ) {
		// If result is already set, don't interfere.
		if ( null !== $result ) {
			return $result;
		}

		$context = array(
			'user_id'      => get_current_user_id(),
			'assistant_id' => 0, // Will be set by caller.
		);

		$mode = $this->get_execution_mode( $tool_name, $arguments, $context );

		// Only queue for queue modes.
		if ( self::MODE_SYNC === $mode ) {
			return null; // Continue with normal execution.
		}

		// Queue the execution.
		$job_id = $this->queue_tool( $tool_name, $arguments, $context, $mode );

		if ( false === $job_id ) {
			// Queue failed, fall back to sync.
			return null;
		}

		// Return a deferred result structure.
		return array(
			'_deferred' => true,
			'job_id'    => $job_id,
			'tool_name' => $tool_name,
			'status'    => 'queued',
			'message'   => sprintf(
				/* translators: %s: tool name */
				__( 'Tool %s has been queued for execution.', 'wp-mcp-ai' ),
				$tool_name
			),
		);
	}

	/**
	 * Queue a tool for execution.
	 *
	 * @param string $tool_name Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @param string $mode      Execution mode.
	 * @return string|false Job ID or false on failure.
	 */
	public function queue_tool( $tool_name, array $arguments, array $context, $mode = self::MODE_QUEUE ) {
		$rabbitmq = $this->get_rabbitmq();

		if ( null === $rabbitmq || ! $rabbitmq->is_available() ) {
			return false;
		}

		// Determine priority from mode.
		$priority = self::PRIORITY_NORMAL;
		if ( self::MODE_QUEUE_ASYNC === $mode ) {
			$priority = self::PRIORITY_LOW;
		}

		// Check if tool should be high priority.
		$tool = $this->get_tool_registry()->get_tool( $tool_name );
		if ( null !== $tool ) {
			$flags = $this->get_tool_flags( $tool );
			if ( in_array( 'realtime', $flags, true ) ) {
				$priority = self::PRIORITY_HIGH;
			}
		}

		return $rabbitmq->queue_tool_execution( $tool_name, $arguments, $context, $priority );
	}

	/**
	 * Execute multiple tools in parallel using queues.
	 *
	 * @param array $tool_calls Array of tool calls (each with 'name', 'arguments').
	 * @param array $context    Execution context.
	 * @param int   $timeout    Maximum wait time in seconds.
	 * @return array Results keyed by tool call ID.
	 */
	public function execute_parallel( array $tool_calls, array $context, $timeout = 30 ) {
		if ( ! $this->is_queue_available() ) {
			// Fall back to sequential execution.
			return $this->execute_sequential( $tool_calls, $context );
		}

		$jobs    = array();
		$results = array();

		// Queue all tools.
		foreach ( $tool_calls as $call_id => $tool_call ) {
			$tool_name = isset( $tool_call['name'] ) ? $tool_call['name'] : '';
			$arguments = isset( $tool_call['arguments'] ) ? $tool_call['arguments'] : array();

			$job_id = $this->queue_tool( $tool_name, $arguments, $context );

			if ( false !== $job_id ) {
				$jobs[ $call_id ] = $job_id;
			} else {
				// Queue failed, execute immediately.
				$tool   = $this->get_tool_registry()->get_tool( $tool_name );
				$result = null !== $tool ? $tool->execute( $arguments, $context ) : null;

				$results[ $call_id ] = array(
					'result' => $result,
					'mode'   => 'sync_fallback',
				);
			}
		}

		// Wait for queued jobs to complete.
		if ( ! empty( $jobs ) ) {
			$queued_results = $this->await_results( $jobs, $timeout );

			foreach ( $queued_results as $call_id => $result ) {
				$results[ $call_id ] = array(
					'result' => $result,
					'mode'   => 'queue',
				);
			}
		}

		return $results;
	}

	/**
	 * Execute tools sequentially (fallback mode).
	 *
	 * @param array $tool_calls Array of tool calls.
	 * @param array $context    Execution context.
	 * @return array Results keyed by tool call ID.
	 */
	private function execute_sequential( array $tool_calls, array $context ) {
		$results = array();

		foreach ( $tool_calls as $call_id => $tool_call ) {
			$tool_name = isset( $tool_call['name'] ) ? $tool_call['name'] : '';
			$arguments = isset( $tool_call['arguments'] ) ? $tool_call['arguments'] : array();

			$tool   = $this->get_tool_registry()->get_tool( $tool_name );
			$result = null !== $tool ? $tool->execute( $arguments, $context ) : null;

			$results[ $call_id ] = array(
				'result' => $result,
				'mode'   => 'sync',
			);
		}

		return $results;
	}

	/**
	 * Wait for queued job results.
	 *
	 * @param array $jobs    Array of job IDs keyed by call ID.
	 * @param int   $timeout Maximum wait time in seconds.
	 * @return array Results keyed by call ID.
	 */
	private function await_results( array $jobs, $timeout ) {
		$rabbitmq      = $this->get_rabbitmq();
		$results       = array();
		$start_time    = microtime( true );
		$poll_interval = 100000; // 100ms in microseconds.
		$results_count = count( $results );
		$jobs_count    = count( $jobs );

		while ( $results_count < $jobs_count ) {
			$elapsed = microtime( true ) - $start_time;

			if ( $elapsed > $timeout ) {
				// Timeout - mark remaining as timed out.
				foreach ( $jobs as $call_id => $job_id ) {
					if ( ! isset( $results[ $call_id ] ) ) {
						$results[ $call_id ] = array(
							'error'   => 'timeout',
							'message' => __( 'Tool execution timed out.', 'wp-mcp-ai' ),
						);
					}
				}
				break;
			}

			// Check for completed jobs.
			foreach ( $jobs as $call_id => $job_id ) {
				if ( isset( $results[ $call_id ] ) ) {
					continue;
				}

				$result = $rabbitmq->get_job_result( $job_id );

				if ( null !== $result ) {
					$results[ $call_id ] = $result['result'];
				}
			}

			// Update count for loop condition.
			$results_count = count( $results );

			// Don't spin too fast.
			usleep( $poll_interval );
		}

		return $results;
	}

	/**
	 * Check if a tool should use parallel execution.
	 *
	 * @param string $tool_name Tool slug.
	 * @return bool Whether tool can be parallelized.
	 */
	public function can_parallelize( $tool_name ) {
		$tool = $this->get_tool_registry()->get_tool( $tool_name );

		if ( null === $tool ) {
			return false;
		}

		$flags = $this->get_tool_flags( $tool );

		// Check for parallelizable flag.
		if ( in_array( 'parallelizable', $flags, true ) ) {
			return true;
		}

		// Check for stateless flag (implies parallelizable).
		if ( in_array( 'stateless', $flags, true ) ) {
			return true;
		}

		// Check for read-only tools (generally safe to parallelize).
		if ( in_array( 'read', $flags, true ) && ! in_array( 'write', $flags, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array Queue statistics.
	 */
	public function get_queue_stats() {
		$rabbitmq = $this->get_rabbitmq();

		if ( null === $rabbitmq || ! $rabbitmq->is_available() ) {
			return array(
				'available' => false,
				'message'   => __( 'RabbitMQ is not available.', 'wp-mcp-ai' ),
			);
		}

		return $rabbitmq->get_queue_stats();
	}

	/**
	 * AJAX handler for queue status.
	 */
	public function ajax_queue_status() {
		check_ajax_referer( 'wp_mcp_ai_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-mcp-ai' ) ) );
		}

		$stats = $this->get_queue_stats();
		wp_send_json_success( $stats );
	}
}
