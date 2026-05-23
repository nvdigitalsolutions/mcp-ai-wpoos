<?php
/**
 * HTML to PDF Tool - Convert HTML content to PDF documents.
 *
 * Converts HTML markup into PDF documents with support for CSS styling,
 * images, and responsive layouts. Uses DomPDF for PHP-based conversion.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
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
	 * Get required capability.
	 *
	 * @return string
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
		// Shell-tools constant and capability gate (F-EXEC-01 / R-S-02).
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			return array(
				'error' => __( 'Shell tools are disabled. Set define( \'WP_MCP_AI_ALLOW_SHELL_TOOLS\', true ) in wp-config.php to enable them.', 'mcp-ai-wpoos-pro' ),
			);
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'error' => __( 'You do not have permission to run shell commands.', 'mcp-ai-wpoos-pro' ),
			);
		}

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
		// Try DomPDF first (Composer dependency).
		if ( class_exists( '\Dompdf\Dompdf' ) ) {
			return $this->convert_with_dompdf( $html, $title, $filename, $page_size, $orientation );
		}

		// Fallback to command-line wkhtmltopdf.
		if ( wp_mcp_ai_find_binary( 'wkhtmltopdf' ) ) {
			return $this->convert_with_wkhtmltopdf( $html, $title, $filename, $page_size, $orientation );
		}

		// No suitable conversion method available.
		return new WP_Error(
			'no_converter',
			__( 'HTML to PDF conversion requires DomPDF (install via Composer: composer require dompdf/dompdf) or wkhtmltopdf command-line tool.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Convert HTML to PDF using DomPDF.
	 *
	 * @param string $html        HTML content.
	 * @param string $title       Document title.
	 * @param string $filename    Output filename.
	 * @param string $page_size   Page size.
	 * @param string $orientation Page orientation.
	 * @return array|WP_Error Result array or error.
	 */
	protected function convert_with_dompdf( $html, $title, $filename, $page_size, $orientation ) {
		try {
			$dompdf = new \Dompdf\Dompdf();
			$dompdf->loadHtml( $html );
			$dompdf->setPaper( $page_size, $orientation );
			$dompdf->render();

			// Get PDF content.
			$pdf_content = $dompdf->output();

			// Create temporary file.
			$temp_file = wp_mcp_ai_tempnam( 'pdf_', '.pdf' );
			if ( is_wp_error( $temp_file ) ) {
				return $temp_file;
			}
			if ( false === file_put_contents( $temp_file, $pdf_content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'pdf_write_failed', __( 'Failed to write PDF file.', 'mcp-ai-wpoos-pro' ) );
			}

			// Require media handling functions.
			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( ! function_exists( 'media_handle_sideload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			// Upload to WordPress media library.
			$file_array = array(
				'name'     => $filename . '.pdf',
				'tmp_name' => $temp_file,
			);

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

		} catch ( Exception $e ) {
			return new WP_Error(
				'dompdf_error',
				sprintf(
					/* translators: %s: error message */
					__( 'DomPDF conversion failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Convert HTML to PDF using wkhtmltopdf command-line tool.
	 *
	 * @param string $html        HTML content.
	 * @param string $title       Document title.
	 * @param string $filename    Output filename.
	 * @param string $page_size   Page size.
	 * @param string $orientation Page orientation.
	 * @return array|WP_Error Result array or error.
	 */
	protected function convert_with_wkhtmltopdf( $html, $title, $filename, $page_size, $orientation ) {
		// Create temp HTML file.
		$temp_html = wp_mcp_ai_tempnam( 'html_', '.html' );
		if ( is_wp_error( $temp_html ) ) {
			return $temp_html;
		}
		file_put_contents( $temp_html, $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		// Create temp PDF file.
		$temp_pdf = wp_mcp_ai_tempnam( 'pdf_', '.pdf' );
		if ( is_wp_error( $temp_pdf ) ) {
			@unlink( $temp_html ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $temp_pdf;
		}

		// Build command.
		$cmd = sprintf(
			'wkhtmltopdf --page-size %s --orientation %s %s %s 2>&1',
			escapeshellarg( strtoupper( $page_size ) ),
			escapeshellarg( ucfirst( $orientation ) ),
			escapeshellarg( $temp_html ),
			escapeshellarg( $temp_pdf )
		);

		$proc_result = wp_mcp_ai_run_shell( $cmd, dirname( $temp_html ) );
		$return_code = $proc_result['exit_code'];

		// Clean up temp HTML.
		@unlink( $temp_html ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( 0 !== $return_code || ! file_exists( $temp_pdf ) ) {
			@unlink( $temp_pdf ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error(
				'wkhtmltopdf_failed',
				__( 'wkhtmltopdf conversion failed.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Require media handling functions.
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Upload to WordPress media library.
		$file_array = array(
			'name'     => $filename . '.pdf',
			'tmp_name' => $temp_pdf,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		@unlink( $temp_pdf );

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
}
