<?php
/**
 * WP_MCP_AI_Tool_Upload_Reg_Document (ecosystem port - Wave F2, regulatory-registration tool batch).
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
 * Uploads a regulatory document.
 */
class WP_MCP_AI_Tool_Upload_Reg_Document implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'upload_reg_document';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Upload Regulatory Document', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Uploads a document to the regulatory registration system and attaches it to a product or registration. Supports file URL or base64 data.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'           => array(
					'type'        => 'string',
					'description' => __( 'Document title (required)', 'nvoos-content-graph-pro' ),
				),
				'document_type'   => array(
					'type'        => 'string',
					'description' => __( 'Document type: loa, fsc, coa, gmp, iso, msds, pif, cpsr, cpnp, artwork, formula, stability, other (required)', 'nvoos-content-graph-pro' ),
				),
				'file_url'        => array(
					'type'        => 'string',
					'description' => __( 'URL of file to upload (required if file_data not provided)', 'nvoos-content-graph-pro' ),
				),
				'file_data'       => array(
					'type'        => 'string',
					'description' => __( 'Base64 encoded file data (required if file_url not provided)', 'nvoos-content-graph-pro' ),
				),
				'file_name'       => array(
					'type'        => 'string',
					'description' => __( 'File name (required if using file_data)', 'nvoos-content-graph-pro' ),
				),
				'product_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Product ID to attach document to (optional, must provide product_id or registration_id)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'registration_id' => array(
					'type'        => 'integer',
					'description' => __( 'Registration ID to attach document to (optional, must provide product_id or registration_id)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'issue_date'      => array(
					'type'        => 'string',
					'description' => __( 'Document issue date (YYYY-MM-DD format, optional)', 'nvoos-content-graph-pro' ),
				),
				'expiry_date'     => array(
					'type'        => 'string',
					'description' => __( 'Document expiry date (YYYY-MM-DD format, optional)', 'nvoos-content-graph-pro' ),
				),
				'version'         => array(
					'type'        => 'string',
					'description' => __( 'Document version (optional, default: 1.0)', 'nvoos-content-graph-pro' ),
					'default'     => '1.0',
				),
				'notes'           => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'title', 'document_type' ),
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
			'file-upload',          // Handles file uploads.
			'security-sensitive',   // Handles file uploads which require validation.
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
		if ( empty( $arguments['title'] ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Document title is required.', 'nvoos-content-graph-pro' )
			);
		}

		if ( empty( $arguments['document_type'] ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Document type is required.', 'nvoos-content-graph-pro' )
			);
		}

		// Must have either file_url or file_data.
		if ( empty( $arguments['file_url'] ) && empty( $arguments['file_data'] ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Either file_url or file_data must be provided.', 'nvoos-content-graph-pro' )
			);
		}

		// Must have either product_id or registration_id.
		if ( empty( $arguments['product_id'] ) && empty( $arguments['registration_id'] ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Either product_id or registration_id must be provided.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate product or registration exists.
		if ( ! empty( $arguments['product_id'] ) ) {
			$product = get_post( $arguments['product_id'] );
			if ( ! $product || 'mcp_ai_reg_product' !== $product->post_type ) {
				return new WP_Error(
					'tool_error',
					__( 'Invalid product ID.', 'nvoos-content-graph-pro' )
				);
			}
		}

		if ( ! empty( $arguments['registration_id'] ) ) {
			$registration = get_post( $arguments['registration_id'] );
			if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
				return new WP_Error(
					'tool_error',
					__( 'Invalid registration ID.', 'nvoos-content-graph-pro' )
				);
			}
		}

		// Handle file upload.
		$file_url = '';
		if ( ! empty( $arguments['file_url'] ) ) {
			$file_url = esc_url_raw( $arguments['file_url'] );
		} elseif ( ! empty( $arguments['file_data'] ) && ! empty( $arguments['file_name'] ) ) {
			// Handle base64 upload.
			$upload_result = $this->handle_base64_upload( $arguments['file_data'], $arguments['file_name'] );
			if ( is_wp_error( $upload_result ) ) {
				return new WP_Error(
					'tool_error',
					$upload_result->get_error_message()
				);
			}
			$file_url = $upload_result['url'];
		}

		// Create document post.
		$document_data = array(
			'post_title'   => sanitize_text_field( $arguments['title'] ),
			'post_type'    => 'mcp_ai_reg_document',
			'post_status'  => 'publish',
			'post_content' => ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '',
		);

		$document_id = wp_insert_post( $document_data );

		if ( is_wp_error( $document_id ) ) {
			return new WP_Error(
				'tool_error',
				$document_id->get_error_message()
			);
		}

		// Save metadata.
		if ( ! empty( $arguments['product_id'] ) ) {
			update_post_meta( $document_id, 'product_id', absint( $arguments['product_id'] ) );
		}

		if ( ! empty( $arguments['registration_id'] ) ) {
			update_post_meta( $document_id, 'registration_id', absint( $arguments['registration_id'] ) );
		}

		update_post_meta( $document_id, 'document_type', sanitize_text_field( $arguments['document_type'] ) );
		update_post_meta( $document_id, 'file_url', $file_url );
		update_post_meta( $document_id, 'version', sanitize_text_field( $arguments['version'] ?? '1.0' ) );

		if ( ! empty( $arguments['issue_date'] ) ) {
			update_post_meta( $document_id, 'issue_date', sanitize_text_field( $arguments['issue_date'] ) );
		}

		if ( ! empty( $arguments['expiry_date'] ) ) {
			update_post_meta( $document_id, 'expiry_date', sanitize_text_field( $arguments['expiry_date'] ) );
		}

		// Set document type taxonomy.
		$doc_type_slug = sanitize_title( $arguments['document_type'] );
		wp_set_object_terms( $document_id, $doc_type_slug, 'mcp_ai_doc_type' );

		return array(
			'success'     => true,
			'document_id' => $document_id,
			'file_url'    => $file_url,
			'message'     => __( 'Document uploaded successfully.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Handle base64 file upload.
	 *
	 * @param string $file_data Base64 encoded file data.
	 * @param string $file_name File name.
	 * @return array|WP_Error Upload result or error.
	 */
	private function handle_base64_upload( $file_data, $file_name ) {
		// Decode base64 data.
		$decoded_data = base64_decode( $file_data, true );
		if ( false === $decoded_data ) {
			return new WP_Error( 'invalid_base64', __( 'Invalid base64 data.', 'nvoos-content-graph-pro' ) );
		}

		// Validate file name.
		$file_name = sanitize_file_name( $file_name );
		if ( empty( $file_name ) ) {
			return new WP_Error( 'invalid_filename', __( 'Invalid file name.', 'nvoos-content-graph-pro' ) );
		}

		// Check file type.
		$allowed_types = array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png' );
		$file_ext      = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $file_ext, $allowed_types, true ) ) {
			return new WP_Error( 'invalid_filetype', __( 'File type not allowed. Allowed types: PDF, DOC, DOCX, XLS, XLSX, JPG, JPEG, PNG.', 'nvoos-content-graph-pro' ) );
		}

		// Get upload directory.
		$upload_dir  = wp_upload_dir();
		$upload_path = $upload_dir['path'] . '/' . $file_name;
		$upload_url  = $upload_dir['url'] . '/' . $file_name;

		// Save file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Legitimate file upload.
		$saved = file_put_contents( $upload_path, $decoded_data );
		if ( false === $saved ) {
			return new WP_Error( 'upload_failed', __( 'Failed to save file.', 'nvoos-content-graph-pro' ) );
		}

		return array(
			'file' => $upload_path,
			'url'  => $upload_url,
			'type' => mime_content_type( $upload_path ),
		);
	}
}
