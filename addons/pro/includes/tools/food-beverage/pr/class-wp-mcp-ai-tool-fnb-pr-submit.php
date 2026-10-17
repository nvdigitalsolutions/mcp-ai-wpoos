<?php
/**
 * F&B tool: fnb_pr_submit — submit a purchase request for review.
 *
 * Submits a draft purchase request for review: draft → in_review. ACT tier
 * (off by default in the assistant packs). Submit-time preconditions (§A5)
 * run inside the QMS transition via the
 * `wp_mcp_ai_qms_state_preconditions` filter.
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
 * Submit a purchase request for review.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Pr_Submit extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_pr_submit';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Purchase Request: Submit', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Submit a draft purchase request for review (draft → in_review) with the named approver. Blocking checks: complete lines, positive total, supplier cut-off/delivery timing, and no open request for the same supplier and ingredient. Non-blocking flags: days-of-cover vs shelf life.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Moving a fully-specified draft into the approval workflow with a named approver.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Approving, releasing or cancelling; use fnb_pr_approve / fnb_pr_release / fnb_pr_cancel.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'fnb_pr_create', 'fnb_pr_approve', 'fnb_pr_return' ),
			'notes'           => __( 'The creator may submit; the approver must differ from the creator (segregation of duties).', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Purchase-request record ID.', 'mcp-ai-wpoos-pro' ),
				),
				'approver_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress user ID of the approver (required; cannot be the creator).', 'mcp-ai-wpoos-pro' ),
				),
				'reason'      => array(
					'type'        => 'string',
					'description' => __( 'Optional submission note (audited).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'post_id', 'approver_id' ),
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

		$approver_id = isset( $arguments['approver_id'] ) ? absint( $arguments['approver_id'] ) : 0;
		if ( $approver_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_missing_approver', __( 'approver_id is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! get_user_by( 'id', $approver_id ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_invalid_approver', __( 'The approver user does not exist.', 'mcp-ai-wpoos-pro' ) );
		}

		// Assign the approver and the QMS reviewer/approver lists so the
		// generic engine gates pass as well.
		update_post_meta( $post_id, WP_MCP_AI_Fnb_Pr_Service::META_APPROVER, $approver_id );
		update_post_meta( $post_id, '_qms_reviewer_ids', array( $approver_id ) );
		update_post_meta( $post_id, '_qms_approver_ids', array( $approver_id ) );

		// Pre-run the F&B gates to surface blocking errors and warnings.
		$pre_check = WP_MCP_AI_Fnb_Pr_Service::check_submit_preconditions( $post_id );
		if ( is_wp_error( $pre_check['gate'] ) ) {
			return $pre_check['gate'];
		}

		$result = WP_MCP_AI_QMS_Workflow::transition(
			$post_id,
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW,
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
			'status'   => WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW,
			'approver' => $approver_id,
			'warnings' => $pre_check['warnings'],
		);
	}
}
