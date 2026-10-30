<?php
/**
 * F&B tool: fnb_pr_supersede — supersede a released purchase request.
 *
 * Supersedes a released purchase request: released → superseded, creating a
 * new draft revision of the same document ID. ACT tier (off by default in
 * the assistant packs).
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
 * Supersede a released purchase request.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Pr_Supersede extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_pr_supersede';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Purchase Request: Supersede', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Supersede a released purchase request and open a new draft revision (same document ID, bumped revision) for amendments. The released revision stays in the audit trail.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Replacing a released request with a revised revision (line or price changes after release).', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Cancelling outright — use fnb_pr_cancel.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'fnb_pr_release', 'fnb_pr_submit', 'fnb_pr_list' ),
			'notes'           => __( 'The new revision starts in draft and must go through submit → approve → release again.', 'mcp-ai-wpoos-pro' ),
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
					'description' => __( 'Released purchase-request record ID.', 'mcp-ai-wpoos-pro' ),
				),
				'reason'  => array(
					'type'        => 'string',
					'description' => __( 'Optional supersession reason (audited).', 'mcp-ai-wpoos-pro' ),
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

		$old = WP_MCP_AI_Fnb_Pr_Service::get_pr( $post_id );
		if ( is_wp_error( $old ) ) {
			return $old;
		}

		// Create the successor draft revision.
		$revision = WP_MCP_AI_Fnb_Pr_Service::bump_revision( (string) get_post_meta( $post_id, '_qms_revision', true ) );

		$new_post_id = wp_insert_post(
			array(
				'post_type'    => WP_MCP_AI_QMS_Doc_Record_CPT::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => sprintf( '[R-04] Purchase request %s rev %s — Draft', $old['document_id'], $revision ),
				'post_content' => WP_MCP_AI_Fnb_Pr_Service::render_r04_content(
					$old['supplier_id'],
					$old['lines'],
					$old['total'],
					$old['order_by'],
					$old['needed_by'],
					$old['budget_line'],
					/* translators: %s: superseded revision */
					sprintf( __( 'Supersedes revision %s.', 'mcp-ai-wpoos-pro' ), get_post_meta( $post_id, '_qms_revision', true ) )
				),
				'post_author'  => $user_id,
			),
			true
		);
		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		update_post_meta( $new_post_id, '_qms_document_id', $old['document_id'] );
		update_post_meta( $new_post_id, '_qms_revision', $revision );
		update_post_meta( $new_post_id, '_qms_status', WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT );
		update_post_meta( $new_post_id, '_qms_owner_id', $user_id );
		update_post_meta( $new_post_id, '_qms_supersedes', $post_id );

		update_post_meta( $new_post_id, WP_MCP_AI_Fnb_Pr_Service::META_SUPPLIER, $old['supplier_id'] );
		update_post_meta( $new_post_id, WP_MCP_AI_Fnb_Pr_Service::META_LINES, wp_json_encode( $old['lines'] ) );
		update_post_meta( $new_post_id, WP_MCP_AI_Fnb_Pr_Service::META_TOTAL, $old['total'] );
		update_post_meta( $new_post_id, WP_MCP_AI_Fnb_Pr_Service::META_ORDER_BY, $old['order_by'] );
		update_post_meta( $new_post_id, WP_MCP_AI_Fnb_Pr_Service::META_NEEDED_BY, $old['needed_by'] );
		update_post_meta( $new_post_id, WP_MCP_AI_Fnb_Pr_Service::META_BUDGET, $old['budget_line'] );

		$term_object = get_term_by( 'slug', WP_MCP_AI_Fnb_Pr_Service::DOC_KIND, WP_MCP_AI_QMS_Taxonomy::TAXONOMY );
		if ( $term_object && ! is_wp_error( $term_object ) ) {
			wp_set_object_terms( $new_post_id, array( (int) $term_object->term_id ), WP_MCP_AI_QMS_Taxonomy::TAXONOMY, false );
		}

		WP_MCP_AI_QMS_Doc_Record_CPT::recompute_hash( $new_post_id );

		update_post_meta( $post_id, '_qms_superseded_by', $new_post_id );

		$result = WP_MCP_AI_QMS_Workflow::transition(
			$post_id,
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_SUPERSEDED,
			array(
				'actor_id' => $user_id,
				'reason'   => isset( $arguments['reason'] ) ? sanitize_textarea_field( (string) $arguments['reason'] ) : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( class_exists( 'WP_MCP_AI_QMS_Audit_Log' ) ) {
			WP_MCP_AI_QMS_Audit_Log::record(
				array(
					'event'    => 'fnb_pr_superseded_new',
					'actor_id' => $user_id,
					'post_id'  => (int) $new_post_id,
					'doc_id'   => $old['document_id'],
					'revision' => $revision,
					'to_state' => WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT,
				)
			);
		}

		return array(
			'success'           => true,
			'post_id'           => $post_id,
			'successor_post_id' => (int) $new_post_id,
			'revision'          => $revision,
			'status'            => WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_SUPERSEDED,
		);
	}
}
