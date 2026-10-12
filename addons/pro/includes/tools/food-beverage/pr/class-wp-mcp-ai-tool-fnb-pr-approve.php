<?php
/**
 * F&B tool: fnb_pr_approve — approve a purchase request.
 *
 * Approves a purchase request: in_review → approved. ACT tier (off by default
 * in the assistant packs). The approver must differ from the creator
 * (segregation of duties) and the request must carry a budget line (§A5).
 * The generic QMS approver-list and the F&B gates both run inside the
 * transition.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../class-wp-mcp-ai-fnb-tool-base.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-pr-service.php';

/**
 * Approve a purchase request.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Pr_Approve extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_pr_approve';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Purchase Request: Approve', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Approve an in-review purchase request. Blocking checks: you are not the creator, and a budget line is set. Non-blocking: the request pushing the budget line over (M-20 warning).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Approving a reviewed request as the assigned approver (or an administrator).', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Approving your own request (segregation of duties), or releasing; use fnb_pr_release after approval.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'fnb_pr_submit', 'fnb_pr_return', 'fnb_pr_release' ),
			'notes'           => __( 'Approval freezes the content hash — any later edit forces a return to draft.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'Purchase-request record ID.', 'mcp-ai-wpoos-pro' ),
				),
				'reason'  => array(
					'type'        => 'string',
					'description' => __( 'Optional approval note (audited).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'post_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_qms';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'state-changing', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$error = $this->guard();
		if ( $error ) {
			return $error;
		}

		if ( ! WP_MCP_AI_Fnb_Pr_Service::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_no_qms', __( 'The QMS engine is not available — enable the Document Generation toolkit with QMS compliance.', 'mcp-ai-wpoos-pro' ) );
		}

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_invalid_id', __( 'post_id is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! WP_MCP_AI_Fnb_Pr_Service::is_pr_record( $post_id ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_invalid_record', __( 'Not an F&B purchase-request record.', 'mcp-ai-wpoos-pro' ) );
		}

		// Role check at the tool layer for a clear, immediate error.
		$approver = (int) get_post_meta( $post_id, WP_MCP_AI_Fnb_Pr_Service::META_APPROVER, true );
		if ( ! user_can( $user_id, 'manage_options' ) && $approver !== $user_id ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_not_approver', __( 'Only the assigned approver (or an administrator) may approve this request.', 'mcp-ai-wpoos-pro' ) );
		}

		update_post_meta( $post_id, WP_MCP_AI_Fnb_Pr_Service::META_APPROVER, $user_id );

		// Pre-run the F&B gates to surface blocking errors and warnings.
		$pre_check = WP_MCP_AI_Fnb_Pr_Service::check_approve_preconditions( $post_id, $user_id );
		if ( is_wp_error( $pre_check['gate'] ) ) {
			return $pre_check['gate'];
		}

		$result = WP_MCP_AI_QMS_Workflow::transition(
			$post_id,
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED,
			array(
				'actor_id' => $user_id,
				'reason'   => isset( $arguments['reason'] ) ? sanitize_textarea_field( (string) $arguments['reason'] ) : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'  => true,
			'post_id'  => $post_id,
			'status'   => WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED,
			'approver' => $user_id,
			'warnings' => $pre_check['warnings'],
		);
	}
}
