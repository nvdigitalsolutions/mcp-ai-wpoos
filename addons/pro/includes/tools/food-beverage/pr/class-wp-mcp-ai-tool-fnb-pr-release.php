<?php
/**
 * F&B tool: fnb_pr_release — release a purchase request.
 *
 * Releases a purchase request: approved → released, assigning the PO number.
 * ACT tier (off by default in the assistant packs). The content-hash freeze
 * gate runs inside the transition; totals above the auto-approval threshold
 * need a finance co-sign (finance_cosign=true from a second actor).
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
 * Release a purchase request.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Pr_Release extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_pr_release';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Purchase Request: Release', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Release an approved purchase request and assign its PO number. Blocking checks: the content is unchanged since approval (hash freeze) and, when configured, totals above the auto-approval threshold carry a finance co-sign. Nothing is sent to a supplier — release only assigns the PO number.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Releasing an approved request so its PO number becomes official.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Approving, amending or sending anything to a supplier — supplier delivery stays out of scope.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'fnb_pr_approve', 'fnb_pr_supersede', 'fnb_pr_audit' ),
			'notes'           => __( 'Pass finance_cosign=true as a second actor when the total exceeds the auto-approval threshold.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Purchase-request record ID.', 'mcp-ai-wpoos-pro' ),
				),
				'finance_cosign' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Finance co-sign for totals above the auto-approval threshold (recorded with the actor ID).', 'mcp-ai-wpoos-pro' ),
				),
				'reason'         => array(
					'type'        => 'string',
					'description' => __( 'Optional release note (audited).', 'mcp-ai-wpoos-pro' ),
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

		// Record the finance co-sign when requested.
		if ( ! empty( $arguments['finance_cosign'] ) ) {
			update_post_meta( $post_id, WP_MCP_AI_Fnb_Pr_Service::META_FINANCE, $user_id );
		}

		// Releaser + PO number + effective date, then the transition (which
		// re-runs the hash-freeze and threshold gates).
		update_post_meta( $post_id, WP_MCP_AI_Fnb_Pr_Service::META_RELEASER, $user_id );

		$document_id = (string) get_post_meta( $post_id, '_qms_document_id', true );
		if ( '' === (string) get_post_meta( $post_id, WP_MCP_AI_Fnb_Pr_Service::META_PO, true ) && '' !== $document_id ) {
			update_post_meta( $post_id, WP_MCP_AI_Fnb_Pr_Service::META_PO, $document_id );
		}
		if ( '' === (string) get_post_meta( $post_id, '_qms_effective_date', true ) ) {
			update_post_meta( $post_id, '_qms_effective_date', current_time( 'Y-m-d' ) );
		}

		$result = WP_MCP_AI_QMS_Workflow::transition(
			$post_id,
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED,
			array(
				'actor_id' => $user_id,
				'reason'   => isset( $arguments['reason'] ) ? sanitize_textarea_field( (string) $arguments['reason'] ) : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$pr = WP_MCP_AI_Fnb_Pr_Service::get_pr( $post_id );

		return array(
			'success'   => true,
			'post_id'   => $post_id,
			'status'    => WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED,
			'po_number' => is_wp_error( $pr ) ? '' : $pr['po_number'],
			'releaser'  => $user_id,
			'note'      => __( 'Released. The PO number is assigned; nothing has been sent to the supplier.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
