<?php
/**
 * Extract PDF Text Tool - Extract text content from PDF documents.
 *
 * Parses PDF files and extracts their text content for processing,
 * indexing, or analysis. Useful for document processing workflows.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the chat response trait from base plugin.
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Extract text from PDF documents.
 *
 * Parses PDF files and returns extracted text content.
 * Does not require AI processing - uses PDF parsing libraries.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Extract_PDF_Text implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'extract_pdf_text';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Extract PDF Text', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Extract text content from PDF documents. Parse PDF files and retrieve their text for processing, indexing, or analysis. Supports multi-page PDFs and maintains basic formatting.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the PDF file to extract text from.', 'mcp-ai-wpoos-pro' ),
				),
				'url'           => array(
					'type'        => 'string',
					'description' => __( 'URL of the PDF file to extract text from (alternative to attachment_id).', 'mcp-ai-wpoos-pro' ),
				),
				'max_pages'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of pages to extract. Default: all pages', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability', // read.
			'read-only',
			'local-only', // No AI required.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check user capability.
		if ( ! current_user_can( 'read' ) ) {
			return array(
				'error' => __( 'You do not have permission to access files.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get PDF file path.
		$file_path = null;

		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$file_path     = get_attached_file( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return array(
					'error' => __( 'PDF file not found.', 'mcp-ai-wpoos-pro' ),
				);
			}
		} elseif ( ! empty( $arguments['url'] ) ) {
			// Download URL to temp file.
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$temp_file = download_url( $arguments['url'] );

			if ( is_wp_error( $temp_file ) ) {
				return array(
					'error' => sprintf(
						/* translators: %s: error message */
						__( 'Failed to download PDF: %s', 'mcp-ai-wpoos-pro' ),
						$temp_file->get_error_message()
					),
				);
			}

			$file_path = $temp_file;
		} else {
			return array(
				'error' => __( 'Either attachment_id or url is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate it's a PDF.
		$mime_type = mime_content_type( $file_path );
		if ( 'application/pdf' !== $mime_type ) {
			if ( isset( $temp_file ) ) {
				@unlink( $temp_file );
			}
			return array(
				'error' => __( 'File is not a valid PDF document.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$max_pages = ! empty( $arguments['max_pages'] ) ? absint( $arguments['max_pages'] ) : 0;

		try {
			// Extract text from PDF.
			$text = $this->extract_text_from_pdf( $file_path, $max_pages );

			// Clean up temp file if we downloaded one.
			if ( isset( $temp_file ) ) {
				@unlink( $temp_file );
			}

			if ( is_wp_error( $text ) ) {
				return array(
					'error' => $text->get_error_message(),
				);
			}

			$word_count = str_word_count( $text );

			return $this->format_chat_response(
				array(
					'text'       => $text,
					'word_count' => $word_count,
					'char_count' => strlen( $text ),
				),
				sprintf(
					/* translators: %d: word count */
					__( 'Successfully extracted %d words from PDF.', 'mcp-ai-wpoos-pro' ),
					$word_count
				)
			);

		} catch ( Exception $e ) {
			// Clean up temp file if we downloaded one.
			if ( isset( $temp_file ) ) {
				@unlink( $temp_file );
			}

			return array(
				'error' => sprintf(
					/* translators: %s: error message */
					__( 'Failed to extract text from PDF: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Extract text from PDF file.
	 *
	 * @param string $file_path Path to PDF file.
	 * @param int    $max_pages Maximum pages to extract (0 = all).
	 * @return string|WP_Error Extracted text or error.
	 */
	protected function extract_text_from_pdf( $file_path, $max_pages = 0 ) {
		// Primary method: Try Node.js pdf-parse service (fast, reliable, pre-bundled).
		$node_result = $this->extract_with_node_service( $file_path, $max_pages );
		if ( ! is_wp_error( $node_result ) ) {
			return $node_result;
		}

		// Secondary method: Try pdftotext command-line tool (if available on system).
		$pdftotext = shell_exec( 'which pdftotext 2>/dev/null' );

		if ( ! empty( $pdftotext ) ) {
			$output_file = tempnam( sys_get_temp_dir(), 'txt_' );
			$cmd         = sprintf(
				'pdftotext %s %s %s 2>&1',
				$max_pages > 0 ? '-l ' . (int) $max_pages : '',
				escapeshellarg( $file_path ),
				escapeshellarg( $output_file )
			);

			exec( $cmd, $output, $return_code );

			if ( 0 === $return_code && file_exists( $output_file ) ) {
				$text = file_get_contents( $output_file );
				@unlink( $output_file );
				return $text;
			}
		}

		// Tertiary fallback: Use smalot/pdfparser (pure PHP, always available).
		if ( class_exists( '\Smalot\PdfParser\Parser' ) ) {
			try {
				$parser = new \Smalot\PdfParser\Parser();
				$pdf    = $parser->parseFile( $file_path );
				
				// Extract text from all pages or limited pages.
				if ( $max_pages > 0 ) {
					$text  = '';
					$pages = $pdf->getPages();
					$count = min( $max_pages, count( $pages ) );
					
					for ( $i = 0; $i < $count; $i++ ) {
						$text .= $pages[ $i ]->getText();
					}
				} else {
					$text = $pdf->getText();
				}
				
				return $text;
			} catch ( \Exception $e ) {
				// If all methods fail, return error with exception details.
				return new WP_Error(
					'extraction_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'PDF text extraction failed: %s', 'mcp-ai-wpoos-pro' ),
						$e->getMessage()
					)
				);
			}
		}

		// Return error if all methods failed.
		return new WP_Error(
			'extraction_failed',
			__( 'PDF text extraction failed. No extraction method available. Install Node.js dependencies (npm install), install poppler-utils (apt-get install poppler-utils), or run "composer install" in the pro addon directory.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Extract text using Node.js pdf-parse service.
	 *
	 * @param string $file_path Path to PDF file.
	 * @param int    $max_pages Maximum pages to extract (0 = all).
	 * @return string|WP_Error Extracted text or error.
	 */
	protected function extract_with_node_service( $file_path, $max_pages = 0 ) {
		// Check if Node.js service exists.
		$service_path = WP_MCP_AI_PRO_PATH . 'node-services/pdf-extract-service.js';
		if ( ! file_exists( $service_path ) ) {
			return new WP_Error( 'service_not_found', 'Node.js PDF extraction service not found.' );
		}

		// Prepare service arguments.
		$args = wp_json_encode(
			array(
				'filePath' => $file_path,
				'maxPages' => $max_pages,
			)
		);

		// Execute Node.js service.
		$cmd = sprintf(
			'node %s extract %s 2>&1',
			escapeshellarg( $service_path ),
			escapeshellarg( $args )
		);

		exec( $cmd, $output, $return_code );

		// Check for execution errors.
		if ( 0 !== $return_code ) {
			return new WP_Error(
				'node_service_failed',
				'Node.js PDF extraction service failed: ' . implode( "\n", $output )
			);
		}

		// Parse JSON response.
		$result = json_decode( implode( "\n", $output ), true );

		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'extraction_error', $result['error'] );
		}

		if ( ! isset( $result['text'] ) ) {
			return new WP_Error( 'invalid_response', 'Invalid response from Node.js service.' );
		}

		return $result['text'];
	}
}
