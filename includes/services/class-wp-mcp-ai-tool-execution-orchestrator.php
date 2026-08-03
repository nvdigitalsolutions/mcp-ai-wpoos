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
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
	 * Load monitor instance
	 *
	 * @var WP_MCP_AI_Tool_Load_Monitor|null
	 */
	protected $load_monitor = null;

	/**
	 * Depth scheduler instance.
	 *
	 * @var WP_MCP_AI_Orchestration_Depth_Scheduler|null
	 */
	protected $depth_scheduler = null;

	/**
	 * Speculative executor instance.
	 *
	 * @var WP_MCP_AI_Speculative_Tool_Executor|null
	 */
	protected $speculative_executor = null;

	/**
	 * Acceptance tracker instance.
	 *
	 * @var WP_MCP_AI_Tool_Chain_Acceptance_Tracker|null
	 */
	protected $acceptance_tracker = null;

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
	 * Capacity thresholds for routing decisions.
	 *
	 * These are now filterable via `wp_mcp_ai_capacity_thresholds`.
	 * Constants are kept as defaults for backward compatibility.
	 *
	 * @since 1.2.0
	 */
	const CAPACITY_THRESHOLD_CRITICAL = 15;  // Queue if capacity < 15%.
	const CAPACITY_THRESHOLD_WARNING  = 30;  // Consider queueing if capacity < 30%.
	const UTILIZATION_THRESHOLD_HIGH  = 0.85; // High utilization threshold.

	/**
	 * Get filterable capacity thresholds.
	 *
	 * @since 1.2.0
	 * @return array Thresholds array.
	 */
	protected function get_capacity_thresholds() {
		$defaults = array(
			'critical'      => self::CAPACITY_THRESHOLD_CRITICAL,
			'high_util'     => self::CAPACITY_THRESHOLD_WARNING,
			'utilization'   => self::UTILIZATION_THRESHOLD_HIGH,
			'slow_tool_sec' => 5.0,
		);

		/**
		 * Filter capacity thresholds for load-based routing.
		 *
		 * @since 1.2.0
		 *
		 * @param array $defaults Default thresholds.
		 */
		return apply_filters( 'wp_mcp_ai_capacity_thresholds', $defaults );
	}

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Tool_Registry|null                 $registry Tool registry instance.
	 * @param WP_MCP_AI_Tool_Async_Executor|null           $async_executor Async executor instance.
	 * @param WP_MCP_AI_Tool_Load_Monitor|null             $load_monitor Load monitor instance.
	 * @param WP_MCP_AI_Orchestration_Depth_Scheduler|null $depth_scheduler Depth scheduler instance.
	 * @param WP_MCP_AI_Speculative_Tool_Executor|null     $speculative_executor Speculative executor instance.
	 * @param WP_MCP_AI_Tool_Chain_Acceptance_Tracker|null $acceptance_tracker Acceptance tracker instance.
	 */
	public function __construct( $registry = null, $async_executor = null, $load_monitor = null, $depth_scheduler = null, $speculative_executor = null, $acceptance_tracker = null ) {
		$this->registry             = $registry;
		$this->async_executor       = $async_executor;
		$this->load_monitor         = $load_monitor;
		$this->depth_scheduler      = $depth_scheduler;
		$this->speculative_executor = $speculative_executor;
		$this->acceptance_tracker   = $acceptance_tracker;
	}

	/**
	 * Execute a tool with orchestration
	 *
	 * Determines whether to execute synchronously or asynchronously based on:
	 * - Tool capability flags
	 * - Execution context
	 * - Force async parameter
	 * - System capacity and load (NEW - Phase 2.2)
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
				__( 'Tool registry is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// Check if tool exists.
		if ( ! $registry->is_tool_registered( $tool_slug ) ) {
			return new WP_Error(
				'wp_mcp_ai_tool_not_found',
				sprintf(
					/* translators: %s: tool slug */
					__( 'Tool "%s" not found.', 'mcp-ai-wpoos' ),
					$tool_slug
				),
				array( 'status' => 404 )
			);
		}

		// Record execution start for load monitoring.
		$monitor = $this->get_load_monitor();
		if ( $monitor ) {
			$monitor->record_execution_start( $tool_slug, $context );
		}

		$start_time = microtime( true );

		// Determine execution mode with capacity awareness.
		$should_execute_async = $this->should_execute_async( $tool_slug, $arguments, $context );

		// Log orchestration decision.
		$load_metrics = $monitor ? $monitor->get_load_metrics( $tool_slug ) : array();
		WP_MCP_AI_Logger::log_event(
			'tool_orchestration',
			sprintf( 'Tool "%s" orchestrated for %s execution', $tool_slug, $should_execute_async ? 'async' : 'sync' ),
			array(
				'tool_slug'      => $tool_slug,
				'mode'           => $should_execute_async ? 'async' : 'sync',
				'capacity_score' => isset( $load_metrics['capacity_score'] ) ? $load_metrics['capacity_score'] : null,
				'utilization'    => isset( $load_metrics['utilization'] ) ? $load_metrics['utilization'] : null,
			)
		);

		// Execute asynchronously if needed.
		if ( $should_execute_async ) {
			$result = $this->execute_async( $tool_slug, $arguments, $context );

			// Record completion for async (as queued).
			if ( $monitor && ! is_wp_error( $result ) ) {
				$duration = microtime( true ) - $start_time;
				$monitor->record_execution_complete( $tool_slug, $duration, true, $context );
			}

			return $result;
		}

		// Execute synchronously.
		$result = $registry->execute_tool( $tool_slug, $arguments, $context );

		// Record completion for load monitoring.
		if ( $monitor ) {
			$duration = microtime( true ) - $start_time;
			$success  = ! is_wp_error( $result );
			$monitor->record_execution_complete( $tool_slug, $duration, $success, $context );
		}

		return $result;
	}

	/**
	 * Execute a tool with orchestration depth scheduling
	 *
	 * Routes tool execution through the depth scheduler, optionally using
	 * speculative execution when chain predictions are available and the
	 * current tier supports verification.
	 *
	 * @param string $tool_slug Tool slug to execute.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error Tool result enriched with orchestration metadata.
	 */
	public function execute_with_depth( $tool_slug, array $arguments = array(), array $context = array() ) {
		$tool_slug = sanitize_key( $tool_slug );

		// Get tool registry.
		$registry = $this->get_registry();
		if ( ! $registry ) {
			return new WP_Error(
				'wp_mcp_ai_registry_unavailable',
				__( 'Tool registry is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// Check if tool exists.
		if ( ! $registry->is_tool_registered( $tool_slug ) ) {
			return new WP_Error(
				'wp_mcp_ai_tool_not_found',
				sprintf(
					/* translators: %s: tool slug */
					__( 'Tool "%s" not found.', 'mcp-ai-wpoos' ),
					$tool_slug
				),
				array( 'status' => 404 )
			);
		}

		// Get the depth scheduler (lazy-load if null).
		$depth_scheduler = $this->get_depth_scheduler();
		if ( ! $depth_scheduler ) {
			// No depth scheduler available; fall through to standard execution.
			return $this->execute_tool( $tool_slug, $arguments, $context );
		}

		// Determine orchestration tier based on capacity and confidence from context.
		$capacity   = isset( $context['capacity'] ) ? (float) $context['capacity'] : 0.0;
		$confidence = isset( $context['confidence'] ) ? (float) $context['confidence'] : 0.0;
		$tier       = $depth_scheduler->determine_tier( $capacity, $confidence );

		// Get tier configuration.
		$tier_config = $depth_scheduler->get_tier_config( $tier );

		// Check if speculative execution should be attempted.
		$verification_enabled = isset( $tier_config['verification_enabled'] ) && $tier_config['verification_enabled'];
		$chain_prediction     = isset( $context['chain_prediction'] ) ? $context['chain_prediction'] : null;

		if ( $verification_enabled && null !== $chain_prediction ) {
			$speculative_executor = $this->get_speculative_executor();

			if ( $speculative_executor ) {
				// Execute speculatively with the predicted chain.
				$block_size = isset( $tier_config['block_size'] ) ? (int) $tier_config['block_size'] : 1;
				$result     = $speculative_executor->execute_speculative_block(
					$tool_slug,
					$arguments,
					$chain_prediction,
					$block_size,
					$context
				);

				// Record acceptance via acceptance tracker.
				$acceptance_tracker = $this->get_acceptance_tracker();
				if ( $acceptance_tracker && ! is_wp_error( $result ) ) {
					$acceptance_tracker->record_acceptance(
						$tool_slug,
						$chain_prediction,
						$result,
						$tier,
						$context
					);
				}

				// Enrich result with orchestration metadata.
				if ( is_array( $result ) ) {
					$result['orchestration_tier'] = $tier;
				}

				return $result;
			}
		}

		// Fall through to standard execution.
		$result = $this->execute_tool( $tool_slug, $arguments, $context );

		// Enrich result with orchestration metadata.
		if ( is_array( $result ) ) {
			$result['orchestration_tier'] = $tier;
		}

		return $result;
	}

	/**
	 * Determine if a tool should be executed asynchronously
	 *
	 * Checks (in order):
	 * 1. Force async/sync flags in context
	 * 2. System capacity and load (NEW - Phase 2.2)
	 * 3. Auto-async setting in orchestration configuration
	 * 4. Tool capability flags (long-running, may-timeout, async)
	 * 5. Explicit async parameter in arguments
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

		// Check system capacity (NEW - Phase 2.2).
		$capacity_routing_decision = $this->check_capacity_routing( $tool_slug, $context );
		if ( null !== $capacity_routing_decision ) {
			// Capacity-based routing made a decision.
			return $capacity_routing_decision;
		}

		// Check if auto-async is enabled in settings.
		$settings                   = get_option( 'wp_mcp_ai_settings', array() );
		$auto_async_enabled         = isset( $settings['enable_auto_async_execution'] ) ? (bool) $settings['enable_auto_async_execution'] : true;
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
	 * Check capacity-based routing decision
	 *
	 * Uses Little's Law metrics to determine if tool should be queued.
	 * Returns null if capacity-based routing should not override other factors.
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $context Execution context.
	 * @return bool|null True for async, false for sync, null for no decision.
	 */
	protected function check_capacity_routing( $tool_slug, array $context ) {
		// Check if capacity-aware routing is enabled.
		$settings               = get_option( 'wp_mcp_ai_settings', array() );
		$capacity_aware_enabled = isset( $settings['enable_capacity_aware_routing'] )
			? (bool) $settings['enable_capacity_aware_routing']
			: true; // Enabled by default.

		if ( ! $capacity_aware_enabled ) {
			return null; // Capacity routing disabled.
		}

		$monitor = $this->get_load_monitor();
		if ( ! $monitor ) {
			return null; // Load monitor not available.
		}

		// Get current load metrics for the tool.
		$metrics = $monitor->get_load_metrics( $tool_slug );

		$thresholds = $this->get_capacity_thresholds();

		// Check if system is under critical load.
		if ( isset( $metrics['capacity_score'] ) && $metrics['capacity_score'] < $thresholds['critical'] ) {
			// Critical capacity - queue non-critical tools.
			$is_critical_request = isset( $context['priority'] ) && 'critical' === $context['priority'];
			$is_agent_request    = isset( $context['agent_role'] );

			// Allow critical requests and agent requests through.
			if ( $is_critical_request || $is_agent_request ) {
				return null; // Let other factors decide.
			}

			// Queue non-critical tools.
			WP_MCP_AI_Logger::log_event(
				'capacity_routing',
				sprintf( 'Tool "%s" queued due to critical capacity (score: %.1f)', $tool_slug, $metrics['capacity_score'] ),
				array(
					'tool_slug'      => $tool_slug,
					'capacity_score' => $metrics['capacity_score'],
					'reason'         => 'critical_capacity',
				)
			);

			return true; // Queue it.
		}

		// Check if tool is showing high utilization.
		if ( isset( $metrics['utilization'] ) && $metrics['utilization'] > $thresholds['utilization'] ) {
			// High utilization - consider agent role priority.
			$agent_role = isset( $context['agent_role'] ) ? $context['agent_role'] : null;

			if ( 'executor' === $agent_role ) {
				// Executor agents get priority - allow sync execution.
				return null;
			}

			// Queue for other requests.
			WP_MCP_AI_Logger::log_event(
				'capacity_routing',
				sprintf( 'Tool "%s" queued due to high utilization (%.2f)', $tool_slug, $metrics['utilization'] ),
				array(
					'tool_slug'   => $tool_slug,
					'utilization' => $metrics['utilization'],
					'reason'      => 'high_utilization',
				)
			);

			return true; // Queue it.
		}

		// Check system-wide capacity.
		$system_metrics = $monitor->get_system_load_metrics();
		if ( isset( $system_metrics['health_status'] ) && 'critical' === $system_metrics['health_status'] ) {
			// System is critical - be conservative.
			$is_critical_request = isset( $context['priority'] ) && 'critical' === $context['priority'];

			if ( ! $is_critical_request ) {
				WP_MCP_AI_Logger::log_event(
					'capacity_routing',
					sprintf( 'Tool "%s" queued due to critical system health', $tool_slug ),
					array(
						'tool_slug'     => $tool_slug,
						'health_status' => 'critical',
						'reason'        => 'system_critical',
					)
				);

				return true; // Queue it.
			}
		}

		// No capacity-based routing decision needed.
		return null;
	}

	/**
	 * Get depth scheduler instance (lazy loaded)
	 *
	 * @return WP_MCP_AI_Orchestration_Depth_Scheduler|null
	 */
	protected function get_depth_scheduler() {
		if ( null === $this->depth_scheduler ) {
			if ( class_exists( 'WP_MCP_AI_Orchestration_Depth_Scheduler' ) ) {
				$this->depth_scheduler = new WP_MCP_AI_Orchestration_Depth_Scheduler();
			}
		}

		return $this->depth_scheduler;
	}

	/**
	 * Get speculative executor instance (lazy loaded)
	 *
	 * @return WP_MCP_AI_Speculative_Tool_Executor|null
	 */
	protected function get_speculative_executor() {
		if ( null === $this->speculative_executor ) {
			if ( class_exists( 'WP_MCP_AI_Speculative_Tool_Executor' ) ) {
				$this->speculative_executor = new WP_MCP_AI_Speculative_Tool_Executor();
			}
		}

		return $this->speculative_executor;
	}

	/**
	 * Get acceptance tracker instance (lazy loaded)
	 *
	 * @return WP_MCP_AI_Tool_Chain_Acceptance_Tracker|null
	 */
	protected function get_acceptance_tracker() {
		if ( null === $this->acceptance_tracker ) {
			if ( class_exists( 'WP_MCP_AI_Tool_Chain_Acceptance_Tracker' ) ) {
				$this->acceptance_tracker = new WP_MCP_AI_Tool_Chain_Acceptance_Tracker();
			}
		}

		return $this->acceptance_tracker;
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
					__( 'Tool registry is not available.', 'mcp-ai-wpoos' ),
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
			'status'  => 'pending',
			'message' => sprintf(
				/* translators: %s: tool name */
				__( '%s started in background. Use the job_id to check status.', 'mcp-ai-wpoos' ),
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

	/**
	 * Set the depth scheduler instance
	 *
	 * Allows injecting a pre-configured depth scheduler after construction.
	 *
	 * @param WP_MCP_AI_Orchestration_Depth_Scheduler $scheduler Depth scheduler instance.
	 */
	public function set_depth_scheduler( $scheduler ) {
		$this->depth_scheduler = $scheduler;
	}
}
