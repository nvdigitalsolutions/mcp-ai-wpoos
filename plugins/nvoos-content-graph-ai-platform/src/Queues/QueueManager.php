<?php
/**
 * Tool-execution queue manager for the Content Graph AI Platform addon.
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Queue_Manager` (Wave E2):
 * byte-identical mode/priority constants, execution-time thresholds,
 * capability-flag semantics, the deferred-result envelope, the
 * `wp_mcp_ai_before_tool_execute` filter (priority 5) and the
 * `wp_ajax_wp_mcp_ai_queue_status` action. Registered standalone-only by
 * `Plugin.php` — the base plugin owns the same filter/AJAX action in
 * monolith installs and double registration would double-queue tool
 * executions.
 *
 * Decoupling (documented, additive):
 * - The RabbitMQ client resolves per install mode: the base
 *   `WP_MCP_AI_RabbitMQ_Client` monolith / the AI addon's
 *   `RabbitMqClient` (D2d) standalone (same API surface).
 * - The tool registry resolves per install mode: the base
 *   `WP_MCP_AI_Tool_Registry` monolith / the nvoos-core registry via the
 *   AI addon's `CoreBridge` standalone (camelCase tools — flags and
 *   execution go through method_exists seams).
 * - Enablement keeps the byte-identical `WP_MCP_AI_RABBITMQ_ENABLED`
 *   constant + `wp_mcp_ai_rabbitmq_enabled` filter contract.
 *
 * @package NvoosContentGraphAiPlatform\Queues
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Queues;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates tool execution between synchronous and queue-based modes,
 * manages parallel execution, and degrades gracefully without RabbitMQ.
 *
 * @since 2.1.0
 */
class QueueManager {

	/**
	 * Singleton instance.
	 *
	 * @var QueueManager|null
	 */
	private static $instance = null;

	/**
	 * RabbitMQ client instance.
	 *
	 * @var object|null
	 */
	private $rabbitmq = null;

	/**
	 * Tool registry instance.
	 *
	 * @var object|null
	 */
	private $tool_registry = null;

	/**
	 * Execution mode constants — byte-identical to the base.
	 */
	const MODE_SYNC        = 'sync';
	const MODE_QUEUE       = 'queue';
	const MODE_QUEUE_ASYNC = 'queue_async';
	const MODE_PARALLEL    = 'parallel';

	/**
	 * Priority constants — byte-identical to the base.
	 */
	const PRIORITY_HIGH   = 'high';
	const PRIORITY_NORMAL = 'normal';
	const PRIORITY_LOW    = 'async';

	/**
	 * Default timeout thresholds in milliseconds — byte-identical.
	 */
	const QUICK_TOOL_THRESHOLD = 2000;  // 2 seconds.
	const ASYNC_TOOL_THRESHOLD = 10000; // 10 seconds.

	/**
	 * Feature flag constant for RabbitMQ-based queue management.
	 *
	 * Byte-identical to the base: set via wp-config.php or the
	 * `wp_mcp_ai_rabbitmq_enabled` filter. Default false — the RMQ path
	 * is opt-in to avoid surprise activation on sites without RabbitMQ.
	 */
	const FEATURE_FLAG = 'WP_MCP_AI_RABBITMQ_ENABLED';

	/**
	 * Whether the RabbitMQ path is enabled for this request (cached).
	 *
	 * @var bool|null
	 */
	private static $enabled = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return QueueManager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — hooks register only when enabled.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks (byte-identical hook surface).
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_filter( 'wp_mcp_ai_before_tool_execute', array( $this, 'maybe_queue_tool_execution' ), 5, 4 );
		add_action( 'wp_ajax_wp_mcp_ai_queue_status', array( $this, 'ajax_queue_status' ) );
	}

	/**
	 * Check if the RabbitMQ queue manager is enabled.
	 *
	 * Requires the WP_MCP_AI_RABBITMQ_ENABLED constant to be truthy;
	 * callers may also use the wp_mcp_ai_rabbitmq_enabled filter.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( null !== self::$enabled ) {
			return self::$enabled;
		}

		$enabled = defined( self::FEATURE_FLAG ) && constant( self::FEATURE_FLAG );

		/**
		 * Filter whether the RabbitMQ queue manager is enabled.
		 *
		 * @since 2.1.0
		 *
		 * @param bool $enabled Whether RabbitMQ is enabled.
		 */
		self::$enabled = (bool) apply_filters( 'wp_mcp_ai_rabbitmq_enabled', $enabled );
		return self::$enabled;
	}

	/**
	 * Resolve the RabbitMQ client (per-install-mode seam).
	 *
	 * @return object|null Client instance or null when unavailable.
	 */
	protected function resolve_rabbitmq_client() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			return \WP_MCP_AI_RabbitMQ_Client::get_instance();
		}

		if ( class_exists( 'NvoosContentGraphAi\Provider\RabbitMqClient' ) ) {
			return \NvoosContentGraphAi\Provider\RabbitMqClient::get_instance();
		}

		return null;
	}

	/**
	 * Get the RabbitMQ client (lazy).
	 *
	 * @return object|null
	 */
	protected function get_rabbitmq() {
		if ( null === $this->rabbitmq ) {
			$this->rabbitmq = $this->resolve_rabbitmq_client();
		}
		return $this->rabbitmq;
	}

	/**
	 * Get the tool registry (lazy, per-install-mode).
	 *
	 * @return object|null
	 */
	protected function get_tool_registry() {
		if ( null !== $this->tool_registry ) {
			return $this->tool_registry;
		}

		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->tool_registry = \WP_MCP_AI_Tool_Registry::get_instance();
		} else {
			$this->tool_registry = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		}

		return $this->tool_registry;
	}

	/**
	 * Resolve a tool by slug (per-install-mode seam).
	 *
	 * @param string $tool_name Tool slug.
	 * @return object|null
	 */
	protected function resolve_tool( $tool_name ) {
		$registry = $this->get_tool_registry();
		if ( null === $registry ) {
			return null;
		}

		$tool = method_exists( $registry, 'get_tool' )
			? $registry->get_tool( $tool_name )
			: $registry->get( (string) $tool_name );

		return is_object( $tool ) ? $tool : null;
	}

	/**
	 * Get a tool's capability flags (per-install-mode seam).
	 *
	 * @param object $tool Tool instance.
	 * @return array Capability flags.
	 */
	protected function tool_flags( $tool ): array {
		if ( $tool instanceof \WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = $tool->get_capability_flags();
			return is_array( $flags ) ? $flags : array();
		}

		if ( method_exists( $tool, 'getCapabilityFlags' ) ) {
			$flags = $tool->getCapabilityFlags();
			return is_array( $flags ) ? $flags : array();
		}

		return array();
	}

	/**
	 * Execute a tool synchronously (per-install-mode seam).
	 *
	 * @param object $tool      Tool instance.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return mixed
	 */
	protected function execute_tool_sync( $tool, array $arguments, array $context ) {
		if ( method_exists( $tool, 'execute' ) ) {
			return $tool->execute( $arguments, $context );
		}

		return null;
	}

	/**
	 * Check if queue-based execution is available.
	 *
	 * @return bool Whether queue execution is available.
	 */
	public function is_queue_available() {
		$rabbitmq = $this->get_rabbitmq();
		return null !== $rabbitmq && method_exists( $rabbitmq, 'is_available' ) && $rabbitmq->is_available();
	}

	/**
	 * Determine the best execution mode for a tool (byte-identical flow).
	 *
	 * @param string $tool_name Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return string Execution mode constant.
	 */
	public function get_execution_mode( $tool_name, array $arguments, array $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Parameter required by hook or interface signature but not used in this implementation.
		$tool = $this->resolve_tool( $tool_name );

		if ( null === $tool ) {
			return self::MODE_SYNC;
		}

		if ( ! $this->is_queue_available() ) {
			return self::MODE_SYNC;
		}

		$flags = $this->tool_flags( $tool );

		if ( in_array( 'queue-required', $flags, true ) ) {
			return self::MODE_QUEUE_ASYNC;
		}

		if ( in_array( 'async', $flags, true ) ) {
			return self::MODE_QUEUE_ASYNC;
		}

		$estimated_time = $this->estimate_execution_time( $tool_name, $arguments );

		if ( $estimated_time > self::ASYNC_TOOL_THRESHOLD ) {
			return self::MODE_QUEUE_ASYNC;
		}

		if ( $estimated_time > self::QUICK_TOOL_THRESHOLD ) {
			if ( in_array( 'queue-preferred', $flags, true ) ) {
				return self::MODE_QUEUE;
			}
		}

		return self::MODE_SYNC;
	}

	/**
	 * Estimate tool execution time based on historical data
	 * (byte-identical estimates table).
	 *
	 * @param string $tool_name Tool slug.
	 * @param array  $arguments Tool arguments (reserved for future use).
	 * @return int Estimated time in milliseconds.
	 */
	protected function estimate_execution_time( $tool_name, array $arguments ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Parameter required by hook or interface signature but not used in this implementation.
		$cache_key = 'wp_mcp_ai_tool_time_' . md5( (string) $tool_name );
		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

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

		return 5000;
	}

	/**
	 * Maybe intercept and queue tool execution.
	 *
	 * Hooked into {@see 'wp_mcp_ai_before_tool_execute'} at priority 5.
	 *
	 * @param mixed  $pre       Pre-execution result (null to continue).
	 * @param string $tool_name Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return mixed Result or null to continue normal execution.
	 */
	public function maybe_queue_tool_execution( $pre, $tool_name, $arguments, $context ) {
		if ( null !== $pre ) {
			return $pre;
		}

		if ( ! isset( $context['user_id'] ) ) {
			$context['user_id'] = get_current_user_id();
		}
		if ( ! isset( $context['assistant_id'] ) ) {
			$context['assistant_id'] = 0;
		}

		$mode = $this->get_execution_mode( $tool_name, $arguments, $context );

		if ( self::MODE_SYNC === $mode ) {
			return null; // Continue with normal execution.
		}

		$job_id = $this->queue_tool( $tool_name, $arguments, $context, $mode );

		if ( false === $job_id ) {
			return null;
		}

		return array(
			'_deferred' => true,
			'job_id'    => $job_id,
			'tool_name' => $tool_name,
			'status'    => 'queued',
			'message'   => sprintf(
				/* translators: %s: tool name */
				__( 'Tool %s has been queued for execution.', 'nvoos-content-graph-ai-platform' ),
				$tool_name
			),
		);
	}

	/**
	 * Queue a tool for execution (byte-identical priority mapping).
	 *
	 * @param string $tool_name Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @param string $mode      Execution mode.
	 * @return string|false Job ID or false on failure.
	 */
	public function queue_tool( $tool_name, array $arguments, array $context, $mode = self::MODE_QUEUE ) {
		$rabbitmq = $this->get_rabbitmq();

		if ( null === $rabbitmq || ! method_exists( $rabbitmq, 'is_available' ) || ! $rabbitmq->is_available() ) {
			return false;
		}

		$priority = self::PRIORITY_NORMAL;
		if ( self::MODE_QUEUE_ASYNC === $mode ) {
			$priority = self::PRIORITY_LOW;
		}

		$tool = $this->resolve_tool( $tool_name );
		if ( null !== $tool ) {
			$flags = $this->tool_flags( $tool );
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
			return $this->execute_sequential( $tool_calls, $context );
		}

		$jobs    = array();
		$results = array();

		foreach ( $tool_calls as $call_id => $tool_call ) {
			$tool_name = isset( $tool_call['name'] ) ? $tool_call['name'] : '';
			$arguments = isset( $tool_call['arguments'] ) && is_array( $tool_call['arguments'] ) ? $tool_call['arguments'] : array();

			$job_id = $this->queue_tool( $tool_name, $arguments, $context );

			if ( false !== $job_id ) {
				$jobs[ $call_id ] = $job_id;
			} else {
				$tool   = $this->resolve_tool( $tool_name );
				$result = null !== $tool ? $this->execute_tool_sync( $tool, $arguments, $context ) : null;

				$results[ $call_id ] = array(
					'result' => $result,
					'mode'   => 'sync_fallback',
				);
			}
		}

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
			$arguments = isset( $tool_call['arguments'] ) && is_array( $tool_call['arguments'] ) ? $tool_call['arguments'] : array();

			$tool   = $this->resolve_tool( $tool_name );
			$result = null !== $tool ? $this->execute_tool_sync( $tool, $arguments, $context ) : null;

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
		$results_count = 0;
		$jobs_count    = count( $jobs );

		while ( $results_count < $jobs_count ) {
			$elapsed = microtime( true ) - $start_time;

			if ( $elapsed > $timeout ) {
				foreach ( $jobs as $call_id => $job_id ) {
					if ( ! isset( $results[ $call_id ] ) ) {
						$results[ $call_id ] = array(
							'error'   => 'timeout',
							'message' => __( 'Tool execution timed out.', 'nvoos-content-graph-ai-platform' ),
						);
					}
				}
				break;
			}

			foreach ( $jobs as $call_id => $job_id ) {
				if ( isset( $results[ $call_id ] ) ) {
					continue;
				}

				$result = $rabbitmq->get_job_result( $job_id );

				if ( null !== $result ) {
					$results[ $call_id ] = $result['result'];
				}
			}

			// Update the counter for the loop condition.
			$results_count = count( $results );

			usleep( $poll_interval );
		}

		return $results;
	}

	/**
	 * Check if a tool should use parallel execution (byte-identical flags).
	 *
	 * @param string $tool_name Tool slug.
	 * @return bool Whether tool can be parallelized.
	 */
	public function can_parallelize( $tool_name ) {
		$tool = $this->resolve_tool( $tool_name );

		if ( null === $tool ) {
			return false;
		}

		$flags = $this->tool_flags( $tool );

		if ( in_array( 'parallelizable', $flags, true ) ) {
			return true;
		}

		if ( in_array( 'stateless', $flags, true ) ) {
			return true;
		}

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

		if ( null === $rabbitmq || ! method_exists( $rabbitmq, 'is_available' ) || ! $rabbitmq->is_available() ) {
			return array(
				'available' => false,
				'message'   => __( 'RabbitMQ is not available.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		return $rabbitmq->get_queue_stats();
	}

	/**
	 * AJAX handler for queue status (byte-identical action + nonce).
	 *
	 * @return void
	 */
	public function ajax_queue_status(): void {
		check_ajax_referer( 'wp_mcp_ai_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		$stats = $this->get_queue_stats();
		wp_send_json_success( $stats );
	}
}
