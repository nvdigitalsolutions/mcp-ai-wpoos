<?php
/**
 * Tool for uploading documents in the Regulatory Registration system.
 *
 * Allows AI assistants to upload and attach documents to products or registrations.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		return __( 'Upload Regulatory Document', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Uploads a document to the regulatory registration system and attaches it to a product or registration. Supports file URL or base64 data.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'            => array(
					'type'        => 'string',
					'description' => __( 'Document title (required)', 'mcp-ai-wpoos-pro' ),
				),
				'document_type'    => array(
					'type'        => 'string',
					'description' => __( 'Document type: loa, fsc, coa, gmp, iso, msds, pif, cpsr, cpnp, artwork, formula, stability, other (required)', 'mcp-ai-wpoos-pro' ),
				),
				'file_url'         => array(
					'type'        => 'string',
					'description' => __( 'URL of file to upload (required if file_data not provided)', 'mcp-ai-wpoos-pro' ),
				),
				'file_data'        => array(
					'type'        => 'string',
					'description' => __( 'Base64 encoded file data (required if file_url not provided)', 'mcp-ai-wpoos-pro' ),
				),
				'file_name'        => array(
					'type'        => 'string',
					'description' => __( 'File name (required if using file_data)', 'mcp-ai-wpoos-pro' ),
				),
				'product_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Product ID to attach document to (optional, must provide product_id or registration_id)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'registration_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Registration ID to attach document to (optional, must provide product_id or registration_id)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'issue_date'       => array(
					'type'        => 'string',
					'description' => __( 'Document issue date (YYYY-MM-DD format, optional)', 'mcp-ai-wpoos-pro' ),
				),
				'expiry_date'      => array(
					'type'        => 'string',
					'description' => __( 'Document expiry date (YYYY-MM-DD format, optional)', 'mcp-ai-wpoos-pro' ),
				),
				'version'          => array(
					'type'        => 'string',
					'description' => __( 'Document version (optional, default: 1.0)', 'mcp-ai-wpoos-pro' ),
					'default'     => '1.0',
				),
				'notes'            => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'mcp-ai-wpoos-pro' ),
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
	public function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( $arguments, $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['title'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Document title is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( empty( $arguments['document_type'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Document type is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Must have either file_url or file_data.
		if ( empty( $arguments['file_url'] ) && empty( $arguments['file_data'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Either file_url or file_data must be provided.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Must have either product_id or registration_id.
		if ( empty( $arguments['product_id'] ) && empty( $arguments['registration_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Either product_id or registration_id must be provided.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate product or registration exists.
		if ( ! empty( $arguments['product_id'] ) ) {
			$product = get_post( $arguments['product_id'] );
			if ( ! $product || 'mcp_ai_reg_product' !== $product->post_type ) {
				return array(
					'success' => false,
					'error'   => __( 'Invalid product ID.', 'mcp-ai-wpoos-pro' ),
				);
			}
		}

		if ( ! empty( $arguments['registration_id'] ) ) {
			$registration = get_post( $arguments['registration_id'] );
			if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
				return array(
					'success' => false,
					'error'   => __( 'Invalid registration ID.', 'mcp-ai-wpoos-pro' ),
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
				return array(
					'success' => false,
					'error'   => $upload_result->get_error_message(),
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
			return array(
				'success' => false,
				'error'   => $document_id->get_error_message(),
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
			'message'     => __( 'Document uploaded successfully.', 'mcp-ai-wpoos-pro' ),
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
			return new WP_Error( 'invalid_base64', __( 'Invalid base64 data.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate file name.
		$file_name = sanitize_file_name( $file_name );
		if ( empty( $file_name ) ) {
			return new WP_Error( 'invalid_filename', __( 'Invalid file name.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check file type.
		$allowed_types = array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png' );
		$file_ext      = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $file_ext, $allowed_types, true ) ) {
			return new WP_Error( 'invalid_filetype', __( 'File type not allowed. Allowed types: PDF, DOC, DOCX, XLS, XLSX, JPG, JPEG, PNG.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get upload directory.
		$upload_dir = wp_upload_dir();
		$upload_path = $upload_dir['path'] . '/' . $file_name;
		$upload_url = $upload_dir['url'] . '/' . $file_name;

		// Save file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Legitimate file upload.
		$saved = file_put_contents( $upload_path, $decoded_data );
		if ( false === $saved ) {
			return new WP_Error( 'upload_failed', __( 'Failed to save file.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'file' => $upload_path,
			'url'  => $upload_url,
			'type' => mime_content_type( $upload_path ),
		);
	}
}
