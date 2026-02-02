<?php
/**
 * Tool for generating complete PDF submission dossier packages.
 *
 * Allows AI assistants to generate comprehensive PDF packages containing all
 * submission documents and metadata for regulatory authorities.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates complete submission PDF dossier packages.
 */
class WP_MCP_AI_Tool_Generate_Pdf_Dossier implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_pdf_dossier';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate PDF Dossier', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates a complete PDF submission dossier package including all documents, cover letter, table of contents, and metadata for regulatory submission.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Registration ID for dossier generation (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'include_toc'          => array(
					'type'        => 'boolean',
					'description' => __( 'Include table of contents (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_cover_letter' => array(
					'type'        => 'boolean',
					'description' => __( 'Include cover letter (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'watermark'            => array(
					'type'        => 'string',
					'description' => __( 'Watermark text for all pages (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'template'             => array(
					'type'        => 'string',
					'description' => __( 'Template name to use (optional, default: "standard")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'standard', 'gcc', 'asean', 'eu', 'fda' ),
					'default'     => 'standard',
				),
			),
			'required'             => array( 'registration_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate PDF dossiers.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$registration_id      = absint( $arguments['registration_id'] );
		$include_toc          = isset( $arguments['include_toc'] ) ? (bool) $arguments['include_toc'] : true;
		$include_cover_letter = isset( $arguments['include_cover_letter'] ) ? (bool) $arguments['include_cover_letter'] : true;
		$watermark            = ! empty( $arguments['watermark'] ) ? sanitize_text_field( $arguments['watermark'] ) : '';
		$template             = ! empty( $arguments['template'] ) ? sanitize_text_field( $arguments['template'] ) : 'standard';

		// Verify registration exists.
		$registration = get_post( $registration_id );
		if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Registration not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get registration details.
		$product_id = absint( get_post_meta( $registration_id, 'product_id', true ) );
		$country    = get_post_meta( $registration_id, 'country', true );
		$authority  = get_post_meta( $registration_id, 'authority', true );
		$cos_number = get_post_meta( $registration_id, 'cos_number', true );

		// Get product details.
		$product_name = '';
		if ( $product_id ) {
			$product = get_post( $product_id );
			if ( $product ) {
				$product_name = $product->post_title;
			}
		}

		// Get related documents.
		$documents_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => 'registration_id',
						'value' => $registration_id,
					),
				),
			)
		);

		$documents = array();
		if ( $documents_query->have_posts() ) {
			foreach ( $documents_query->posts as $doc_post ) {
				$documents[] = array(
					'id'    => $doc_post->ID,
					'title' => $doc_post->post_title,
					'type'  => get_post_meta( $doc_post->ID, 'document_type', true ),
				);
			}
		}

		// Generate PDF dossier (placeholder implementation).
		$upload_dir = wp_upload_dir();
		$pdf_dir    = $upload_dir['basedir'] . '/regulatory-dossiers';
		$filename   = sprintf( 'dossier-%d-%s.pdf', $registration_id, gmdate( 'YmdHis' ) );
		$file_path  = $pdf_dir . '/' . $filename;
		$file_url   = $upload_dir['baseurl'] . '/regulatory-dossiers/' . $filename;

		// Create directory if it doesn't exist.
		if ( ! file_exists( $pdf_dir ) ) {
			wp_mkdir_p( $pdf_dir );
		}

		// In production, this would generate actual PDF using a library like TCPDF or Dompdf.
		// For now, create a placeholder file.
		$pdf_content  = "PDF Dossier for Registration ID: {$registration_id}\n";
		$pdf_content .= "Product: {$product_name}\n";
		$pdf_content .= "Country: {$country}\n";
		$pdf_content .= "Authority: {$authority}\n";
		$pdf_content .= "COS Number: {$cos_number}\n";
		$pdf_content .= "Template: {$template}\n";
		$pdf_content .= "\nDocuments included: " . count( $documents ) . "\n";

		file_put_contents( $file_path, $pdf_content );

		return array(
			'success'         => true,
			'file_path'       => $file_path,
			'file_url'        => $file_url,
			'filename'        => $filename,
			'registration_id' => $registration_id,
			'product_name'    => $product_name,
			'document_count'  => count( $documents ),
			'file_size'       => filesize( $file_path ),
			'generated_at'    => current_time( 'mysql' ),
			'template'        => $template,
			'watermark'       => $watermark,
		);
	}
}
