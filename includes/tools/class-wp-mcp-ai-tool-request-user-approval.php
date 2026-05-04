<?php
/**
 * Tool: request_user_approval — Human-in-the-Loop approval gate.
 *
 * Pauses agentic execution and queues a pending approval request.
 * The AI calls this tool before any destructive or irreversible action
 * that requires explicit human confirmation. Execution resumes only when
 * an authorised user approves via the Approvals admin page or the chat UI.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queues a human-in-the-loop approval request and pauses agentic execution.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Tool_Request_User_Approval implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'request_user_approval';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Request User Approval', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Pause agentic execution and queue a pending approval request before performing a destructive or irreversible action. The action will not proceed until an authorised user approves or denies it via the Approvals admin page or the chat UI.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tool_to_approve' => array(
					'type'        => 'string',
					'description' => __( "Slug of the tool or action awaiting approval (e.g. 'delete_post').", 'mcp-ai-wpoos' ),
				),
				'arguments'       => array(
					'type'        => 'object',
					'description' => __( 'Arguments that will be passed to the tool if approved. Pass the exact arguments you are about to call the tool with.', 'mcp-ai-wpoos' ),
				),
				'reason'          => array(
					'type'        => 'string',
					'description' => __( 'Human-readable explanation of what this action will do and why approval is required.', 'mcp-ai-wpoos' ),
				),
				'session_id'      => array(
					'type'        => 'string',
					'description' => __( 'Chat session correlation ID (used to route approval responses back to the correct chat).', 'mcp-ai-wpoos' ),
				),
				'assistant_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Assistant post ID (used for routing). Normally inferred from context.', 'mcp-ai-wpoos' ),
				),
				'ttl'             => array(
					'type'        => 'integer',
					'description' => __( 'Seconds until this approval request expires. Default 86400 (24 hours). Minimum 60.', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array( 'tool_to_approve', 'reason' ),
		);
	}

	/**
	 * Queue a human-approval request and pause agentic execution.
	 *
	 * Steps:
	 * 1. Capability check (guest bypass honoured when assistant context is present).
	 * 2. Guard: WP_MCP_AI_Approval_Queue must exist.
	 * 3. Sanitize and validate inputs.
	 * 4. Infer assistant_id / requester_id from context when not supplied.
	 * 5. Enqueue via WP_MCP_AI_Approval_Queue::enqueue().
	 * 6. Fire wp_mcp_ai_approval_request_emitted action.
	 * 7. Return pending-approval result.
	 *
	 * @param array $arguments Tool arguments from the LLM.
	 * @param array $context   Execution context (user_id, assistant_id, guest_request…).
	 * @return array|WP_Error Pending-approval descriptor or WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {

		// ── 1. Capability check ───────────────────────────────────────────────
		// Allow guest_request when an assistant_id is present in context.
		$is_guest_request = ! empty( $context['guest_request'] ) && ! empty( $context['assistant_id'] );

		if ( ! $is_guest_request && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		// ── 2. Approval Queue guard ───────────────────────────────────────────
		if ( ! class_exists( 'WP_MCP_AI_Approval_Queue' ) ) {
			return new WP_Error(
				'approval_queue_unavailable',
				__( 'The Approval Queue service is not available. Ensure the plugin is fully loaded.', 'mcp-ai-wpoos' )
			);
		}

		// ── 3. Sanitize & validate required inputs ────────────────────────────
		$tool_to_approve = isset( $arguments['tool_to_approve'] )
			? sanitize_key( (string) $arguments['tool_to_approve'] )
			: '';

		$reason = isset( $arguments['reason'] )
			? sanitize_text_field( (string) $arguments['reason'] )
			: '';

		if ( '' === $tool_to_approve ) {
			return new WP_Error( 'missing_tool', __( 'tool_to_approve is required.', 'mcp-ai-wpoos' ) );
		}

		if ( '' === $reason ) {
			return new WP_Error( 'missing_reason', __( 'reason is required.', 'mcp-ai-wpoos' ) );
		}

		// ── 3b. Sanitize optional inputs ─────────────────────────────────────
		$session_id = isset( $arguments['session_id'] )
			? sanitize_text_field( (string) $arguments['session_id'] )
			: '';

		// tool_arguments is an opaque payload passed straight through to the queue
		// (it is JSON-encoded for storage; individual values are not executed here).
		$tool_arguments = isset( $arguments['arguments'] ) && is_array( $arguments['arguments'] )
			? $arguments['arguments']
			: array();

		$ttl = isset( $arguments['ttl'] )
			? max( 60, absint( $arguments['ttl'] ) )
			: WP_MCP_AI_Approval_Queue::DEFAULT_TTL_SECONDS;

		// ── 4. Infer assistant_id and requester_id ────────────────────────────
		// Prefer explicit argument, fall back to execution context.
		$assistant_id = isset( $arguments['assistant_id'] ) ? absint( $arguments['assistant_id'] ) : 0;
		if ( 0 === $assistant_id ) {
			$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
		}

		$requester_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// ── 5. Enqueue the approval request ──────────────────────────────────
		$approval_id = WP_MCP_AI_Approval_Queue::get_instance()->enqueue(
			array(
				'tool'         => $tool_to_approve,
				'arguments'    => $tool_arguments,
				'assistant_id' => $assistant_id,
				'requester_id' => $requester_id,
				'session_id'   => $session_id,
				'reason'       => $reason,
				'ttl'          => $ttl,
			)
		);

		if ( is_wp_error( $approval_id ) ) {
			return $approval_id;
		}

		// ── 6. Emit action ────────────────────────────────────────────────────
		/**
		 * Fires after a new approval request has been successfully queued.
		 *
		 * @param int    $approval_id     Approval record post ID.
		 * @param string $tool_to_approve Slug of the tool or action awaiting approval.
		 * @param array  $context         Original execution context.
		 */
		do_action( 'wp_mcp_ai_approval_request_emitted', $approval_id, $tool_to_approve, $context );

		// ── 7. Return result ──────────────────────────────────────────────────
		return array(
			'success'     => true,
			'approval_id' => $approval_id,
			'status'      => 'pending',
			'message'     => __( 'Approval request queued. Execution is paused until a human approves or denies this action.', 'mcp-ai-wpoos' ),
			'tool'        => $tool_to_approve,
			'reason'      => $reason,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'write', 'state-changing', 'requires-approval' );
	}
}
