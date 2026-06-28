<?php
/**
 * Tool: qms_create_controlled_document
 *
 * Creates a new controlled-document record, stamping it with a document ID,
 * revision, and required metadata per ISO 9001 Clause 7.5.2.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create controlled document tool.
 */
class WP_MCP_AI_Tool_QMS_Create_Controlled_Document implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'qms_create_controlled_document';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'QMS: Create Controlled Document', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Create a new controlled-document record under ISO 9001 Clause 7.5 (Documented Information). Provide a stable document_id (e.g. SOP-001), revision (e.g. 1.0), title, content, document type, owner, reviewers, approvers, retention years, and disposition. The new record starts in `draft` state.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'document_id'      => array(
					'type'        => 'string',
					'description' => __( 'Stable controlled-doc ID (e.g. SOP-001). Required and used in audit trails.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 64,
				),
				'revision'         => array(
					'type'        => 'string',
					'description' => __( 'Semantic revision (e.g. 1.0).', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 16,
					'default'     => '1.0',
				),
				'title'            => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'content'          => array(
					'type'      => 'string',
					'maxLength' => 200000,
				),
				'doc_type_slug'    => array(
					'type'        => 'string',
					'description' => __( 'QMS doc type slug (policy, procedure, work-instruction, form, record, external).', 'mcp-ai-wpoos-pro' ),
				),
				'owner_id'         => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'reviewer_ids'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'approver_ids'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'effective_date'   => array(
					'type'    => 'string',
					'pattern' => '^\d{4}-\d{2}-\d{2}$',
				),
				'next_review_date' => array(
					'type'    => 'string',
					'pattern' => '^\d{4}-\d{2}-\d{2}$',
				),
				'retention_years'  => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100,
				),
				'disposition'      => array(
					'type'    => 'string',
					'enum'    => array( 'archive', 'destroy' ),
					'default' => 'archive',
				),
				'external_origin'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'source'     => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'identifier' => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
					),
					'additionalProperties' => false,
				),
				'template_id'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'change_summary'   => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
			),
			'required'             => array( 'document_id', 'title' ),
			'additionalProperties' => false,
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing', 'requires-capability' );
	}

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WP_MCP_AI_QMS_Capabilities' ) && WP_MCP_AI_QMS_Capabilities::is_enabled();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, WP_MCP_AI_QMS_Capabilities::CAP ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage controlled documents.', 'mcp-ai-wpoos-pro' ) );
		}

		$document_id = isset( $arguments['document_id'] ) ? sanitize_text_field( $arguments['document_id'] ) : '';
		$revision    = isset( $arguments['revision'] ) ? sanitize_text_field( $arguments['revision'] ) : '1.0';
		$title       = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$content     = isset( $arguments['content'] ) ? wp_kses_post( $arguments['content'] ) : '';

		if ( '' === $document_id || '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_qms_missing_required', __( 'document_id and title are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => WP_MCP_AI_QMS_Doc_Record_CPT::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
				'post_author'  => $user_id,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_qms_document_id', $document_id );
		update_post_meta( $post_id, '_qms_revision', $revision );
		update_post_meta( $post_id, '_qms_status', WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT );

		$owner_id = isset( $arguments['owner_id'] ) ? absint( $arguments['owner_id'] ) : $user_id;
		update_post_meta( $post_id, '_qms_owner_id', $owner_id );

		if ( ! empty( $arguments['reviewer_ids'] ) && is_array( $arguments['reviewer_ids'] ) ) {
			update_post_meta( $post_id, '_qms_reviewer_ids', array_values( array_unique( array_map( 'absint', $arguments['reviewer_ids'] ) ) ) );
		}
		if ( ! empty( $arguments['approver_ids'] ) && is_array( $arguments['approver_ids'] ) ) {
			update_post_meta( $post_id, '_qms_approver_ids', array_values( array_unique( array_map( 'absint', $arguments['approver_ids'] ) ) ) );
		}
		if ( ! empty( $arguments['effective_date'] ) ) {
			update_post_meta( $post_id, '_qms_effective_date', sanitize_text_field( $arguments['effective_date'] ) );
		}
		if ( ! empty( $arguments['next_review_date'] ) ) {
			update_post_meta( $post_id, '_qms_next_review_date', sanitize_text_field( $arguments['next_review_date'] ) );
		}
		if ( isset( $arguments['retention_years'] ) ) {
			update_post_meta( $post_id, '_qms_retention_years', max( 0, absint( $arguments['retention_years'] ) ) );
		}
		$disposition = isset( $arguments['disposition'] ) ? sanitize_key( $arguments['disposition'] ) : 'archive';
		if ( ! in_array( $disposition, array( 'archive', 'destroy' ), true ) ) {
			$disposition = 'archive';
		}
		update_post_meta( $post_id, '_qms_disposition', $disposition );

		if ( ! empty( $arguments['external_origin'] ) && is_array( $arguments['external_origin'] ) ) {
			$ext = array(
				'source'     => isset( $arguments['external_origin']['source'] ) ? sanitize_text_field( $arguments['external_origin']['source'] ) : '',
				'identifier' => isset( $arguments['external_origin']['identifier'] ) ? sanitize_text_field( $arguments['external_origin']['identifier'] ) : '',
			);
			update_post_meta( $post_id, '_qms_external_origin', $ext );
			// Auto-tag as external doc type.
			$ext_term = get_term_by( 'slug', 'external', WP_MCP_AI_QMS_Taxonomy::TAXONOMY );
			if ( $ext_term && ! is_wp_error( $ext_term ) ) {
				wp_set_object_terms( $post_id, array( (int) $ext_term->term_id ), WP_MCP_AI_QMS_Taxonomy::TAXONOMY, false );
			}
		}

		if ( ! empty( $arguments['doc_type_slug'] ) ) {
			$slug = sanitize_key( $arguments['doc_type_slug'] );
			$term = get_term_by( 'slug', $slug, WP_MCP_AI_QMS_Taxonomy::TAXONOMY );
			if ( $term && ! is_wp_error( $term ) ) {
				wp_set_object_terms( $post_id, array( (int) $term->term_id ), WP_MCP_AI_QMS_Taxonomy::TAXONOMY, false );
			}
		}

		if ( ! empty( $arguments['template_id'] ) ) {
			update_post_meta( $post_id, '_qms_template_id', absint( $arguments['template_id'] ) );
		}
		if ( ! empty( $arguments['change_summary'] ) ) {
			update_post_meta( $post_id, '_qms_change_summary', sanitize_textarea_field( $arguments['change_summary'] ) );
		}

		WP_MCP_AI_QMS_Doc_Record_CPT::recompute_hash( $post_id );

		WP_MCP_AI_QMS_Audit_Log::record(
			array(
				'event'      => 'created',
				'post_id'    => (int) $post_id,
				'doc_id'     => $document_id,
				'revision'   => $revision,
				'to_state'   => WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT,
				'after_hash' => (string) get_post_meta( $post_id, '_qms_content_hash', true ),
			)
		);

		return array(
			'success' => true,
			'post_id' => (int) $post_id,
			'record'  => WP_MCP_AI_QMS_Doc_Record_CPT::get_record( $post_id ),
			'message' => __( 'Controlled document created in draft state.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
