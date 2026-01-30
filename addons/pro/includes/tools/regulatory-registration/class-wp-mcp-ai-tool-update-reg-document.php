<?php
/**
 * Tool for updating documents in the Regulatory Registration system.
 *
 * Allows AI assistants to update document metadata.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates a regulatory document.
 */
class WP_MCP_AI_Tool_Update_Reg_Document implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_reg_document';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Regulatory Document', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates a document in the regulatory registration system. Only provided fields will be updated.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'document_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Document ID to update (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'title'         => array(
					'type'        => 'string',
					'description' => __( 'Document title (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'document_type' => array(
					'type'        => 'string',
					'description' => __( 'Document type (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'issue_date'    => array(
					'type'        => 'string',
					'description' => __( 'Document issue date (YYYY-MM-DD format, optional)', 'mcp-ai-wpoos-pro' ),
				),
				'expiry_date'   => array(
					'type'        => 'string',
					'description' => __( 'Document expiry date (YYYY-MM-DD format, optional)', 'mcp-ai-wpoos-pro' ),
				),
				'version'       => array(
					'type'        => 'string',
					'description' => __( 'Document version (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Document status: draft, pending, approved, rejected (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'draft', 'pending', 'approved', 'rejected' ),
				),
				'notes'         => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'document_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-write',       // Writes to database.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['document_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Document ID is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$document_id = absint( $arguments['document_id'] );

		// Verify document exists.
		$document = get_post( $document_id );
		if ( ! $document || 'mcp_ai_reg_document' !== $document->post_type ) {
			return array(
				'success' => false,
				'error'   => __( 'Document not found.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$updated_fields = array();

		// Update title if provided.
		if ( isset( $arguments['title'] ) ) {
			wp_update_post(
				array(
					'ID'         => $document_id,
					'post_title' => sanitize_text_field( $arguments['title'] ),
				)
			);
			$updated_fields[] = 'title';
		}

		// Update notes if provided.
		if ( isset( $arguments['notes'] ) ) {
			wp_update_post(
				array(
					'ID'           => $document_id,
					'post_content' => sanitize_textarea_field( $arguments['notes'] ),
				)
			);
			$updated_fields[] = 'notes';
		}

		// Update status if provided.
		if ( isset( $arguments['status'] ) ) {
			$status_map = array(
				'draft'    => 'draft',
				'pending'  => 'pending',
				'approved' => 'publish',
				'rejected' => 'trash',
			);
			$new_status = $status_map[ $arguments['status'] ] ?? 'draft';
			wp_update_post(
				array(
					'ID'          => $document_id,
					'post_status' => $new_status,
				)
			);
			$updated_fields[] = 'status';
		}

		// Update metadata fields.
		$meta_fields = array( 'document_type', 'issue_date', 'expiry_date', 'version' );
		foreach ( $meta_fields as $field ) {
			if ( isset( $arguments[ $field ] ) ) {
				update_post_meta( $document_id, $field, sanitize_text_field( $arguments[ $field ] ) );
				$updated_fields[] = $field;
			}
		}

		// Update document type taxonomy if provided.
		if ( isset( $arguments['document_type'] ) ) {
			$doc_type_slug = sanitize_title( $arguments['document_type'] );
			wp_set_object_terms( $document_id, $doc_type_slug, 'mcp_ai_doc_type' );
		}

		return array(
			'success'        => true,
			'document_id'    => $document_id,
			'updated_fields' => $updated_fields,
			'message'        => sprintf(
				/* translators: %s: list of updated fields */
				__( 'Document updated successfully. Updated fields: %s', 'mcp-ai-wpoos-pro' ),
				implode( ', ', $updated_fields )
			),
		);
	}
}
