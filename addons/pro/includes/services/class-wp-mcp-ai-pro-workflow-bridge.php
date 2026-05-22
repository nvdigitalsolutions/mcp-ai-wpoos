<?php
/**
 * Pro Workflow Builder ↔ Base Orchestration Bridge.
 *
 * Wires the Pro visual workflow builder (which stores workflows as keys in the
 * `wp_mcp_ai_pro_workflows` WordPress option) into the base orchestration
 * primitives shipped in Phases 1, 2, 4 and 5:
 *
 *   - Phase 1 (Layer I) — runs the prompt-injection detector on agent-node
 *     prompts before they execute.
 *   - Phase 2 — enqueues a HITL approval request when a tool node targets a
 *     tool flagged with the `requires-approval` capability.
 *   - Phase 4 — mirrors every Pro node execution and final execution record
 *     into the base Workflow Run CPT so the base run timeline / replay tool
 *     can introspect Pro workflows.
 *
 * The bridge attaches via four WordPress hooks added to the Pro builder:
 *
 *   - filter `wp_mcp_ai_workflow_execute_agent`           (existing)
 *   - filter `wp_mcp_ai_pro_workflow_pre_execute_tool`    (added in Phase-7 integration)
 *   - action `wp_mcp_ai_pro_workflow_node_executed`       (added in Phase-7 integration)
 *   - action `wp_mcp_ai_pro_workflow_execution_saved`     (added in Phase-7 integration)
 *
 * Future enhancements (Engine V2, native CCT-backed Pro workflows, trigger
 * dispatch into the Pro builder) are tracked as TODOs in
 * `docs/orchestration-reference.md` §5.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges the Pro workflow builder to the base orchestration subsystem.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Pro_Workflow_Bridge {

	/**
	 * Transient key prefix mapping Pro workflow execution IDs to base run IDs.
	 *
	 * @var string
	 */
	const RUN_MAP_PREFIX = 'wp_mcp_ai_pro_run_map_';

	/**
	 * TTL for the execution-id → run-id map (seconds).
	 *
	 * @var int
	 */
	const RUN_MAP_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Pro_Workflow_Bridge|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Pro_Workflow_Bridge
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up hook listeners.
	 */
	private function __construct() {
		// Phase 1 — injection guardrail on agent prompts.
		add_filter( 'wp_mcp_ai_workflow_execute_agent', array( $this, 'guard_agent_prompt' ), 5, 4 );

		// Phase 2 — HITL approval gate on tool nodes.
		add_filter( 'wp_mcp_ai_pro_workflow_pre_execute_tool', array( $this, 'gate_tool_with_approval' ), 5, 4 );

		// Phase 4 — run-log mirror.
		add_action( 'wp_mcp_ai_pro_workflow_node_executed', array( $this, 'mirror_node_event' ), 10, 5 );
		add_action( 'wp_mcp_ai_pro_workflow_execution_saved', array( $this, 'finalize_run' ), 10, 1 );

		// Phase 8 — pluggable executor handoff for Pro string-keyed workflows.
		add_filter( 'wp_mcp_ai_workflow_executor', array( $this, 'handle_pro_workflow_dispatch' ), 10, 4 );

		// Phase 8 — hide base DAG Builder admin page in favour of the Pro builder.
		add_action( 'admin_menu', array( $this, 'maybe_remove_base_dag_builder' ), 99 );
	}

	/*
	------------------------------------------------------------------ *
	 * Phase 1 — Prompt-injection guardrail
	 * ------------------------------------------------------------------
	 */

	/**
	 * Block an agent node when its prompt is flagged by the injection detector.
	 *
	 * @param array|WP_Error|null $result   Existing filter value.
	 * @param string              $agent_id Agent identifier.
	 * @param string              $prompt   Prompt text after context substitution.
	 * @param array               $context  Execution context.
	 * @return array|WP_Error|null
	 */
	public function guard_agent_prompt( $result, $agent_id, $prompt, $context ) {
		// Respect any prior short-circuit decision.
		if ( null !== $result ) {
			return $result;
		}

		if ( ! class_exists( 'WP_MCP_AI_Prompt_Injection_Detector' ) ) {
			return $result;
		}

		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
		$analysis     = WP_MCP_AI_Prompt_Injection_Detector::analyze(
			(string) $prompt,
			$assistant_id,
			array(
				'source' => 'pro_workflow_agent_node',
				'agent'  => (string) $agent_id,
			)
		);

		if ( ! is_array( $analysis ) || empty( $analysis['flagged'] ) ) {
			return $result;
		}

		// Only block when the global block-on-detect setting is enabled OR
		// the analysis itself returned `block => true`.
		$should_block = ! empty( $analysis['block'] );
		if ( ! $should_block ) {
			$opt_block    = get_option( WP_MCP_AI_Prompt_Injection_Detector::OPTION_BLOCK_ON_DETECT, 0 );
			$should_block = (bool) $opt_block;
		}

		if ( ! $should_block ) {
			return $result;
		}

		return new WP_Error(
			'prompt_injection_detected',
			sprintf(
				/* translators: 1: severity, 2: family */
				__( 'Agent prompt blocked by injection guardrail (severity=%1$s, family=%2$s).', 'mcp-ai-wpoos' ),
				isset( $analysis['severity'] ) ? (string) $analysis['severity'] : 'unknown',
				isset( $analysis['family'] ) ? (string) $analysis['family'] : 'unknown'
			),
			array( 'analysis' => $analysis )
		);
	}

	/*
	------------------------------------------------------------------ *
	 * Phase 2 — HITL approval gate
	 * ------------------------------------------------------------------
	 */

	/**
	 * Enqueue a HITL approval request when a tool requires approval.
	 *
	 * @param array|WP_Error|null $short_circuit Existing pre-execute result.
	 * @param string              $tool_name     Tool slug.
	 * @param array               $arguments     Tool arguments.
	 * @param array               $context       Execution context.
	 * @return array|WP_Error|null
	 */
	public function gate_tool_with_approval( $short_circuit, $tool_name, $arguments, $context ) {
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) || ! class_exists( 'WP_MCP_AI_Approval_Queue' ) ) {
			return $short_circuit;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( $tool_name );

		if ( ! $tool ) {
			return $short_circuit;
		}

		// Detect requires-approval capability flag.
		$requires_approval = false;
		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags             = (array) $tool->get_capability_flags();
			$requires_approval = in_array( 'requires-approval', $flags, true );
		}

		/**
		 * Filter to force-require approval for a Pro workflow tool node.
		 *
		 * @since 1.6.0
		 *
		 * @param bool   $requires_approval Default from capability flags.
		 * @param string $tool_name         Tool slug.
		 * @param array  $arguments         Tool arguments.
		 * @param array  $context           Execution context.
		 */
		$requires_approval = (bool) apply_filters(
			'wp_mcp_ai_pro_workflow_tool_requires_approval',
			$requires_approval,
			$tool_name,
			$arguments,
			$context
		);

		if ( ! $requires_approval ) {
			return $short_circuit;
		}

		$queue       = WP_MCP_AI_Approval_Queue::get_instance();
		$approval_id = $queue->enqueue(
			array(
				'tool'         => $tool_name,
				'arguments'    => $arguments,
				'assistant_id' => isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0,
				'requester_id' => get_current_user_id(),
				'reason'       => __( 'Tool node from Pro workflow builder requires approval.', 'mcp-ai-wpoos' ),
			)
		);

		if ( is_wp_error( $approval_id ) ) {
			return $approval_id;
		}

		// Return a non-WP_Error short-circuit so the node reports as pending
		// rather than failed; the front-end can poll the approval queue.
		return array(
			'type'        => 'tool',
			'tool_name'   => $tool_name,
			'arguments'   => $arguments,
			'status'      => 'awaiting_approval',
			'approval_id' => (int) $approval_id,
			'message'     => __( 'Tool execution queued for human approval.', 'mcp-ai-wpoos' ),
		);
	}

	/*
	------------------------------------------------------------------ *
	 * Phase 4 — Run-log mirror
	 * ------------------------------------------------------------------
	 */

	/**
	 * Mirror a Pro node execution into the base Workflow Run CPT.
	 *
	 * The Pro builder uses string workflow IDs (sanitize_key form), whereas
	 * the base run-log expects an int workflow_id. We keep the int slot at 0
	 * and stash the string ID in the run context so consumers can correlate.
	 *
	 * @param string         $node_type   action|tool|agent.
	 * @param string         $node_id     Workflow-scoped node ID.
	 * @param string         $workflow_id Pro workflow ID (sanitize_key form).
	 * @param array|WP_Error $result      Execution result.
	 * @param array          $context     Execution context.
	 */
	public function mirror_node_event( $node_type, $node_id, $workflow_id, $result, $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Workflow_Run_CPT' ) ) {
			return;
		}

		// Only mirror when the front-end identifies the active execution. This
		// id is set in Pro builder JS and ties together node-execution events
		// with the final `ajax_save_workflow_execution` call.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This action only fires from inside ajax_execute_workflow_node() which already verified the nonce.
		$execution_id = isset( $_POST['execution_id'] ) ? sanitize_text_field( wp_unslash( $_POST['execution_id'] ) ) : '';

		if ( '' === $execution_id ) {
			return;
		}

		$run_id = $this->get_or_create_run( $execution_id, $workflow_id, $context );
		if ( ! $run_id ) {
			return;
		}

		$is_error = is_wp_error( $result );
		$type     = $is_error ? 'node_failed' : 'node_completed';

		WP_MCP_AI_Workflow_Run_CPT::append_event(
			$run_id,
			$type,
			// Fall back to a synthetic id only when the Pro builder did not pass a node_id;
			// this is unexpected but keeps the run-log consistent rather than dropping the event.
			'' !== $node_id ? $node_id : ( $node_type . '_' . wp_generate_uuid4() ),
			$node_type,
			$is_error
				? array( 'error' => $result->get_error_message() )
				: array( 'result' => is_array( $result ) ? $result : array( 'value' => $result ) )
		);

		WP_MCP_AI_Workflow_Run_CPT::set_status( $run_id, 'running' );
	}

	/**
	 * Finalize the base run when the Pro builder saves an execution record.
	 *
	 * @param array $execution Sanitized execution record from the Pro builder.
	 */
	public function finalize_run( $execution ) {
		if ( ! is_array( $execution ) || empty( $execution['id'] ) ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Workflow_Run_CPT' ) ) {
			return;
		}

		$run_id = $this->lookup_run( (string) $execution['id'] );
		if ( ! $run_id ) {
			return;
		}

		$status_key = isset( $execution['status'] ) ? (string) $execution['status'] : '';
		$status_map = array(
			'completed' => 'completed',
			'failed'    => 'failed',
			'cancelled' => 'cancelled',
			'unknown'   => 'failed',
		);
		$status     = isset( $status_map[ $status_key ] ) ? $status_map[ $status_key ] : 'completed';

		WP_MCP_AI_Workflow_Run_CPT::append_event(
			$run_id,
			'execution_summary',
			'__summary__',
			'workflow',
			array(
				'duration_ms'     => isset( $execution['duration'] ) ? (int) $execution['duration'] : 0,
				'node_count'      => isset( $execution['node_count'] ) ? (int) $execution['node_count'] : 0,
				'completed_nodes' => isset( $execution['completed_nodes'] ) ? (int) $execution['completed_nodes'] : 0,
				'failed_nodes'    => isset( $execution['failed_nodes'] ) ? (int) $execution['failed_nodes'] : 0,
			)
		);

		WP_MCP_AI_Workflow_Run_CPT::set_status( $run_id, $status );

		// Cleanup map.
		delete_transient( self::RUN_MAP_PREFIX . md5( (string) $execution['id'] ) );
	}

	/*
	------------------------------------------------------------------ *
	 * Internal helpers
	 * ------------------------------------------------------------------
	 */

	/**
	 * Map a Pro execution_id to a base run id, creating the run on first call.
	 *
	 * @param string $execution_id Pro execution UUID.
	 * @param string $workflow_id  Pro workflow string ID.
	 * @param array  $context      Node context.
	 * @return int 0 on failure.
	 */
	private function get_or_create_run( $execution_id, $workflow_id, $context ) {
		$existing = $this->lookup_run( $execution_id );
		if ( $existing ) {
			return $existing;
		}

		$run_id = WP_MCP_AI_Workflow_Run_CPT::create_run(
			0, // No int post-id mapping for Pro option-storage workflows.
			array(),
			array(),
			array(
				'source'           => 'pro_workflow_builder',
				'pro_workflow_id'  => (string) $workflow_id,
				'pro_execution_id' => (string) $execution_id,
				'assistant_id'     => isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0,
			)
		);

		if ( is_wp_error( $run_id ) || ! $run_id ) {
			return 0;
		}

		set_transient(
			self::RUN_MAP_PREFIX . md5( $execution_id ),
			(int) $run_id,
			self::RUN_MAP_TTL
		);

		return (int) $run_id;
	}

	/**
	 * Look up the base run id for a Pro execution_id.
	 *
	 * @param string $execution_id Pro execution UUID.
	 * @return int 0 if not mapped.
	 */
	private function lookup_run( $execution_id ) {
		$run_id = get_transient( self::RUN_MAP_PREFIX . md5( (string) $execution_id ) );
		return $run_id ? (int) $run_id : 0;
	}

	/*
	------------------------------------------------------------------ *
	 * Phase 8 — Pluggable executor + admin-menu unification
	 * ------------------------------------------------------------------
	 */

	/**
	 * Resolve string-keyed Pro workflow IDs through the dispatcher.
	 *
	 * The Pro builder stores workflows in WP option `wp_mcp_ai_pro_workflows`
	 * keyed by `sanitize_key()` strings; the React UI executes them per-node
	 * via AJAX, so there is no synchronous server-side `execute()` to call.
	 * Returning a `WP_Error` here gives callers (triggers, replay) a clear
	 * signal while leaving the door open for a future server-side traversal.
	 *
	 * @since 1.6.0
	 *
	 * @param array|WP_Error|null $result      Executor result, or null to defer.
	 * @param int|string          $workflow_id Workflow identifier.
	 * @param array               $input       Runtime input (unused).
	 * @param array               $context     Execution context (unused).
	 * @return array|WP_Error|null
	 */
	public function handle_pro_workflow_dispatch( $result, $workflow_id, $input, $context ) {
		// Defer if a previous filter already handled it, or if the workflow
		// looks numeric (base CPT post id).
		if ( null !== $result || is_numeric( $workflow_id ) || ! is_string( $workflow_id ) || '' === $workflow_id ) {
			return $result;
		}

		$workflows = get_option( 'wp_mcp_ai_pro_workflows', array() );
		if ( ! is_array( $workflows ) || ! isset( $workflows[ $workflow_id ] ) ) {
			return $result;
		}

		// Pro workflow exists, but the builder is client-driven — no server-
		// side execution path is available yet. Surface a clear error.
		return new WP_Error(
			'pro_workflow_client_only',
			sprintf(
				/* translators: %s: workflow ID */
				__( 'Pro workflow "%s" can only be executed from the Pro Workflow Builder admin UI; server-side dispatch is not yet supported.', 'mcp-ai-wpoos' ),
				$workflow_id
			),
			array( 'workflow_id' => $workflow_id )
		);
	}

	/**
	 * Hide the base "DAG Builder" submenu when the Pro Workflow Builder is
	 * available, so site owners see a single canonical authoring UI.
	 *
	 * Site owners can opt back in by:
	 *
	 *   add_filter( 'wp_mcp_ai_show_base_dag_builder', '__return_true' );
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function maybe_remove_base_dag_builder() {
		/**
		 * Whether to keep the base DAG Builder submenu visible alongside the
		 * Pro Workflow Builder. Defaults to false (Pro replaces the base UI).
		 *
		 * @since 1.6.0
		 *
		 * @param bool $show True to keep the base DAG Builder visible.
		 */
		if ( apply_filters( 'wp_mcp_ai_show_base_dag_builder', false ) ) {
			return;
		}

		remove_submenu_page( 'wp-mcp-ai-dashboard', 'wp-mcp-ai-dag-builder' );
	}
}
