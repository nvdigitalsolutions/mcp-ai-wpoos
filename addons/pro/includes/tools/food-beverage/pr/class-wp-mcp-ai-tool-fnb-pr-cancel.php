<?php
/**
 * F&B tool: fnb_pr_cancel — cancel a purchase request.
 *
 * Cancels a purchase request from any state (→ obsolete) with an audited
 * reason. ACT tier (off by default in the assistant packs).
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
 * Cancel a purchase request.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Pr_Cancel extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_pr_cancel';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Purchase Request: Cancel', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Cancel a purchase request from any state (→ obsolete). A reason is required and audited. Released requests keep their PO number in the audit trail.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Ending a request lifecycle — duplicate found, supplier change, or the order is no longer needed.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Sending a request back for edits — use fnb_pr_return.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'fnb_pr_return', 'fnb_pr_list', 'fnb_pr_audit' ),
			'notes'           => __( 'Cancellation is terminal and irreversible — the record stays in the audit trail.', 'mcp-ai-wpoos-pro' ),
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
					'maximum'     => WP_MCP_AI_Tool_Schema_Helper::MAX_SAFE_INTEGER,
					'description' => __( 'Purchase-request record ID.', 'mcp-ai-wpoos-pro' ),
				),
				'reason'  => array(
					'type'        => 'string',
					'description' => __( 'Required — cancellation reason (audited).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'post_id', 'reason' ),
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

		$reason = isset( $arguments['reason'] ) ? sanitize_textarea_field( (string) $arguments['reason'] ) : '';
		if ( '' === $reason ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_missing_reason', __( 'A cancellation reason is required (audited).', 'mcp-ai-wpoos-pro' ) );
		}

		$result = WP_MCP_AI_QMS_Workflow::transition(
			$post_id,
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_OBSOLETE,
			array(
				'actor_id' => $user_id,
				'reason'   => $reason,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'post_id' => $post_id,
			'status'  => WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_OBSOLETE,
		);
	}
}
