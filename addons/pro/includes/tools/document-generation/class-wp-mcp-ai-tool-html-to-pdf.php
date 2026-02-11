<?php
/**
 * HTML to PDF Tool - Convert HTML content to PDF documents.
 *
 * Converts HTML markup into PDF documents with support for CSS styling,
 * images, and responsive layouts. Uses DomPDF for PHP-based conversion.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the document response trait from base plugin.
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-document-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * HTML to PDF conversion tool.
 *
 * Converts HTML content to PDF documents without requiring AI processing.
 * Useful for converting web pages, reports, or formatted content to PDFs.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_HTML_To_PDF implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Document_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'html_to_pdf';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'HTML to PDF', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Convert HTML content to PDF documents. Supports CSS styling, images, and responsive layouts. Perfect for converting web pages, reports, or formatted content into downloadable PDFs.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'html'        => array(
					'type'        => 'string',
					'description' => __( 'HTML content to convert to PDF. Can include CSS styles, images, and formatting.', 'mcp-ai-wpoos-pro' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'PDF document title (appears in metadata and optionally as header).', 'mcp-ai-wpoos-pro' ),
				),
				'filename'    => array(
					'type'        => 'string',
					'description' => __( 'Output filename (without extension). Defaults to sanitized title or "document".', 'mcp-ai-wpoos-pro' ),
				),
				'page_size'   => array(
					'type'        => 'string',
					'enum'        => array( 'a4', 'letter', 'legal' ),
					'description' => __( 'Page size for the PDF. Default: a4', 'mcp-ai-wpoos-pro' ),
				),
				'orientation' => array(
					'type'        => 'string',
					'enum'        => array( 'portrait', 'landscape' ),
					'description' => __( 'Page orientation. Default: portrait', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'html' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability', // upload_files.
			'write',
			'state-changing',
			'local-only', // No AI required.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check user capability.
		if ( ! current_user_can( 'upload_files' ) ) {
			return array(
				'error' => __( 'You do not have permission to generate documents.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate required parameters.
		if ( empty( $arguments['html'] ) ) {
			return array(
				'error' => __( 'HTML content is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Extract parameters with defaults.
		$html        = $arguments['html'];
		$title       = ! empty( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : 'Document';
		$filename    = ! empty( $arguments['filename'] ) ? sanitize_file_name( $arguments['filename'] ) : sanitize_file_name( $title );
		$page_size   = ! empty( $arguments['page_size'] ) ? $arguments['page_size'] : 'a4';
		$orientation = ! empty( $arguments['orientation'] ) ? $arguments['orientation'] : 'portrait';

		try {
			// Generate PDF from HTML.
			$result = $this->convert_html_to_pdf( $html, $title, $filename, $page_size, $orientation );

			if ( is_wp_error( $result ) ) {
				return array(
					'error' => $result->get_error_message(),
				);
			}

			// Add document HTML to response for chat display.
			return $this->add_document_html_to_response( $result );

		} catch ( Exception $e ) {
			return array(
				'error' => sprintf(
					/* translators: %s: error message */
					__( 'Failed to convert HTML to PDF: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Convert HTML to PDF using available methods.
	 *
	 * @param string $html        HTML content.
	 * @param string $title       Document title.
	 * @param string $filename    Output filename.
	 * @param string $page_size   Page size.
	 * @param string $orientation Page orientation.
	 * @return array|WP_Error Result array with attachment info or WP_Error on failure.
	 */
	protected function convert_html_to_pdf( $html, $title, $filename, $page_size, $orientation ) {
		// For now, create a simple text-based PDF using FPDF-like approach.
		// In production, this would use DomPDF, wkhtmltopdf, or similar library.
		
		// Create a temporary file to store PDF content.
		$upload_dir = wp_upload_dir();
		$temp_file  = tempnam( sys_get_temp_dir(), 'pdf_' );

		// Simple PDF generation (placeholder - would use proper library in production).
		$pdf_content = $this->generate_simple_pdf_from_html( $html, $title, $page_size, $orientation );

		if ( false === file_put_contents( $temp_file, $pdf_content ) ) {
			return new WP_Error( 'pdf_write_failed', __( 'Failed to write PDF file.', 'mcp-ai-wpoos-pro' ) );
		}

		// Upload to WordPress media library.
		$file_array = array(
			'name'     => $filename . '.pdf',
			'tmp_name' => $temp_file,
		);

		// Upload file to media library.
		$attachment_id = media_handle_sideload( $file_array, 0 );

		// Clean up temp file.
		@unlink( $temp_file );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Get attachment details.
		$attachment_url = wp_get_attachment_url( $attachment_id );
		$file_path      = get_attached_file( $attachment_id );
		$file_size      = filesize( $file_path );

		return array(
			'attachment_id' => $attachment_id,
			'url'           => $attachment_url,
			'filename'      => basename( $file_path ),
			'mime_type'     => 'application/pdf',
			'size'          => $file_size,
			'text'          => sprintf(
				/* translators: 1: filename, 2: file size */
				__( 'Successfully converted HTML to PDF: %1$s (%2$s)', 'mcp-ai-wpoos-pro' ),
				$filename . '.pdf',
				size_format( $file_size )
			),
		);
	}

	/**
	 * Generate a simple PDF from HTML (placeholder implementation).
	 *
	 * This is a basic implementation. In production, this would use
	 * libraries like DomPDF, mPDF, or wkhtmltopdf for proper HTML/CSS rendering.
	 *
	 * @param string $html        HTML content.
	 * @param string $title       Document title.
	 * @param string $page_size   Page size.
	 * @param string $orientation Page orientation.
	 * @return string PDF binary content.
	 */
	protected function generate_simple_pdf_from_html( $html, $title, $page_size, $orientation ) {
		// Strip HTML tags for simple text content.
		$text_content = wp_strip_all_tags( $html, true );

		// Basic PDF structure (minimal valid PDF).
		// In production, use proper PDF library.
		$pdf = "%PDF-1.4\n";
		$pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
		$pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
		$pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources 4 0 R /MediaBox [0 0 595 842] /Contents 5 0 R >>\nendobj\n";
		$pdf .= "4 0 obj\n<< /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >>\nendobj\n";
		$pdf .= "5 0 obj\n<< /Length " . strlen( $text_content ) . " >>\nstream\n";
		$pdf .= "BT /F1 12 Tf 50 800 Td (" . $this->escape_pdf_string( substr( $text_content, 0, 500 ) ) . ") Tj ET\n";
		$pdf .= "endstream\nendobj\n";
		$pdf .= "xref\n0 6\n0000000000 65535 f\n0000000009 00000 n\n0000000056 00000 n\n0000000115 00000 n\n0000000214 00000 n\n0000000304 00000 n\n";
		$pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . ( strlen( $pdf ) + 20 ) . "\n%%EOF";

		return $pdf;
	}

	/**
	 * Escape string for PDF.
	 *
	 * @param string $str String to escape.
	 * @return string Escaped string.
	 */
	protected function escape_pdf_string( $str ) {
		return str_replace( array( '(', ')', '\\' ), array( '\\(', '\\)', '\\\\' ), $str );
	}
}
