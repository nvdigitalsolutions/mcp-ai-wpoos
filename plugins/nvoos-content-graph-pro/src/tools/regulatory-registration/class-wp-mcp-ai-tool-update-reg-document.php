<?php
/**
 * WP_MCP_AI_Tool_Update_Reg_Document (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/regulatory-registration/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
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
		return __( 'Update Regulatory Document', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates a document in the regulatory registration system. Only provided fields will be updated.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Document ID to update (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'title'         => array(
					'type'        => 'string',
					'description' => __( 'Document title (optional)', 'nvoos-content-graph-pro' ),
				),
				'document_type' => array(
					'type'        => 'string',
					'description' => __( 'Document type (optional)', 'nvoos-content-graph-pro' ),
				),
				'issue_date'    => array(
					'type'        => 'string',
					'description' => __( 'Document issue date (YYYY-MM-DD format, optional)', 'nvoos-content-graph-pro' ),
				),
				'expiry_date'   => array(
					'type'        => 'string',
					'description' => __( 'Document expiry date (YYYY-MM-DD format, optional)', 'nvoos-content-graph-pro' ),
				),
				'version'       => array(
					'type'        => 'string',
					'description' => __( 'Document version (optional)', 'nvoos-content-graph-pro' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Document status: draft, pending, approved, rejected (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'draft', 'pending', 'approved', 'rejected' ),
				),
				'notes'         => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'nvoos-content-graph-pro' ),
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
		// Validate required arguments.
		if ( empty( $arguments['document_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Document ID is required.', 'nvoos-content-graph-pro' ),
			);
		}

		$document_id = absint( $arguments['document_id'] );

		// Verify document exists.
		$document = get_post( $document_id );
		if ( ! $document || 'mcp_ai_reg_document' !== $document->post_type ) {
			return array(
				'success' => false,
				'error'   => __( 'Document not found.', 'nvoos-content-graph-pro' ),
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
				__( 'Document updated successfully. Updated fields: %s', 'nvoos-content-graph-pro' ),
				implode( ', ', $updated_fields )
			),
		);
	}
}
