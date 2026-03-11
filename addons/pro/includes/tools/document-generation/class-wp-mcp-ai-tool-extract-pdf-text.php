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

// Load required traits from base plugin.
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';

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
	use WP_MCP_AI_Attachment_File_Resolver;

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
		return __( 'Extract text content from PDF documents. Parse PDF files and retrieve their text for processing, indexing, or analysis. Supports multi-page PDFs and maintains basic formatting. Automatically detects scanned PDFs and applies OCR when needed (if enable_ocr parameter is true).', 'mcp-ai-wpoos-pro' );
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
				'file_id'       => $this->get_file_id_parameter_schema(
					__( 'AI provider file identifier (e.g., an OpenAI file ID such as "file-Nfe1VozHi3BxjiLwWzRKRC"). Used when the PDF lives inside a provider storage (vector store, Files API) rather than the WordPress media library.', 'mcp-ai-wpoos-pro' )
				),
				'url'           => array(
					'type'        => 'string',
					'description' => __( 'URL of the PDF file to extract text from (alternative to attachment_id or file_id).', 'mcp-ai-wpoos-pro' ),
				),
				'max_pages'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of pages to extract. Default: all pages', 'mcp-ai-wpoos-pro' ),
				),
				'enable_ocr'    => array(
					'type'        => 'boolean',
					'description' => __( 'Enable automatic OCR for scanned PDFs (image-only documents with no readable text). Default: true', 'mcp-ai-wpoos-pro' ),
				),
				'ocr_provider'  => array(
					'type'        => 'string',
					'enum'        => array( 'auto', 'openai', 'gemini', 'ollama', 'tesseract' ),
					'description' => __( 'OCR provider to use when OCR is needed. "auto" selects best available. Default: auto', 'mcp-ai-wpoos-pro' ),
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
				'success' => false,
				'error'   => 'permission_denied',
				'report'  => __( '❌ **Permission Denied**

You do not have permission to access files.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get PDF file path.
		$file_path = null;
		$temp_file = null;

		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$file_path     = get_attached_file( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return array(
					'success' => false,
					'error'   => 'file_not_found',
					'report'  => sprintf(
						/* translators: %d: attachment ID */
						__( '❌ **PDF File Not Found**

The PDF file with attachment ID %d could not be found.', 'mcp-ai-wpoos-pro' ),
						$attachment_id
					),
				);
			}
		} elseif ( ! empty( $arguments['file_id'] ) ) {
			// Resolve provider file ID (e.g., OpenAI "file-xxx") to a local path.
			$resolved = $this->resolve_file_id_to_temp_path( sanitize_text_field( $arguments['file_id'] ) );

			if ( is_wp_error( $resolved ) ) {
				return array(
					'success' => false,
					'error'   => $resolved->get_error_code(),
					'report'  => sprintf(
						/* translators: %s: error message */
						__( '❌ **Provider File Not Found**

%s', 'mcp-ai-wpoos-pro' ),
						$resolved->get_error_message()
					),
				);
			}

			$file_path = $resolved['path'];
			if ( $resolved['is_temp'] ) {
				$temp_file = $file_path;
			}
		} elseif ( ! empty( $arguments['url'] ) ) {
			// Validate the URL to prevent SSRF before downloading.
			$url    = esc_url_raw( $arguments['url'] );
			$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return array(
					'success' => false,
					'error'   => 'invalid_url',
					'report'  => __( '❌ **Invalid URL**

Only http and https URLs are supported.', 'mcp-ai-wpoos-pro' ),
				);
			}
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( empty( $host ) ) {
				return array(
					'success' => false,
					'error'   => 'invalid_url',
					'report'  => __( '❌ **Invalid URL**

Could not determine host from the provided URL.', 'mcp-ai-wpoos-pro' ),
				);
			}
			// Resolve the hostname and reject private / reserved IP ranges (SSRF guard).
			$resolved_ip = gethostbyname( $host );
			if ( $resolved_ip === $host && false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
				return array(
					'success' => false,
					'error'   => 'invalid_url',
					'report'  => __( '❌ **Invalid URL**

URL hostname could not be resolved.', 'mcp-ai-wpoos-pro' ),
				);
			}
			if ( false === filter_var( $resolved_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return array(
					'success' => false,
					'error'   => 'invalid_url',
					'report'  => __( '❌ **Invalid URL**

URL resolves to a private or reserved address and cannot be fetched.', 'mcp-ai-wpoos-pro' ),
				);
			}
			// Download URL to temp file.
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$temp_file = download_url( $url );

			if ( is_wp_error( $temp_file ) ) {
				return array(
					'success' => false,
					'error'   => 'download_failed',
					'report'  => sprintf(
						/* translators: %s: error message */
						__( '❌ **Download Failed**

Failed to download PDF from URL: %s', 'mcp-ai-wpoos-pro' ),
						$temp_file->get_error_message()
					),
				);
			}

			$file_path = $temp_file;
		} else {
			return array(
				'success' => false,
				'error'   => 'missing_input',
				'report'  => __( '❌ **Missing Input**

Either `attachment_id`, `file_id`, or `url` parameter is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate it's a PDF.
		$mime_type = mime_content_type( $file_path );
		if ( 'application/pdf' !== $mime_type ) {
			if ( null !== $temp_file ) {
				@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			return array(
				'success' => false,
				'error'   => 'invalid_file_type',
				'report'  => sprintf(
					/* translators: %s: detected MIME type */
					__( '❌ **Invalid File Type**

File is not a valid PDF document (detected: %s).', 'mcp-ai-wpoos-pro' ),
					$mime_type
				),
			);
		}

		$max_pages   = ! empty( $arguments['max_pages'] ) ? absint( $arguments['max_pages'] ) : 0;
		$enable_ocr  = isset( $arguments['enable_ocr'] ) ? (bool) $arguments['enable_ocr'] : true;
		$ocr_provider = ! empty( $arguments['ocr_provider'] ) ? sanitize_text_field( $arguments['ocr_provider'] ) : 'auto';

		try {
			// Extract text from PDF.
			$text = $this->extract_text_from_pdf( $file_path, $max_pages );

			// Check if we got minimal text (might be scanned).
			$used_ocr = false;
			if ( ! is_wp_error( $text ) && $enable_ocr ) {
				$clean_text = trim( preg_replace( '/\s+/', '', $text ) );
				
				// If very little text extracted, try OCR.
				if ( strlen( $clean_text ) < 50 ) {
					// Load OCR service if available.
					$ocr_service_path = WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';
					if ( file_exists( $ocr_service_path ) ) {
						require_once $ocr_service_path;
						
						$ocr_service = new WP_MCP_AI_OCR_Service();
						$ocr_options = array(
							'max_pages' => $max_pages > 0 ? $max_pages : 10, // Limit OCR to 10 pages by default.
							'provider'  => $ocr_provider,
							'dpi'       => 300,
						);
						
						$ocr_text = $ocr_service->extract_text_from_pdf( $file_path, $ocr_options );
						
						if ( ! is_wp_error( $ocr_text ) && strlen( $ocr_text ) > strlen( $text ) ) {
							$text     = $ocr_text;
							$used_ocr = true;
							
							WP_MCP_AI_Logger::log_event(
								'pdf_ocr_fallback',
								'Used OCR for scanned PDF',
								array(
									'provider'       => $ocr_provider,
									'standard_chars' => strlen( $clean_text ),
									'ocr_chars'      => strlen( $ocr_text ),
								)
							);
						}
					}
				}
			}

			// Clean up temp file if we downloaded one.
			if ( null !== $temp_file ) {
				@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			if ( is_wp_error( $text ) ) {
				return array(
					'success' => false,
					'error'   => 'extraction_failed',
					'report'  => sprintf(
						/* translators: %s: error message */
						__( '❌ **Extraction Failed**

%s', 'mcp-ai-wpoos-pro' ),
						$text->get_error_message()
					),
				);
			}

			$word_count = str_word_count( $text );
			$char_count = strlen( $text );

			// Build extraction report.
			if ( $used_ocr ) {
				$report = sprintf(
					/* translators: 1: word count, 2: OCR provider */
					__( '## ✅ PDF Text Extraction Complete (with OCR)

**Extracted:** %1$d words
**Method:** OCR (scanned PDF detected)
**Provider:** %2$s

---

✨ *Text extracted successfully from scanned PDF and is available for use.*', 'mcp-ai-wpoos-pro' ),
					$word_count,
					ucfirst( $ocr_provider )
				);
			} else {
				$report = sprintf(
					/* translators: %d: word count */
					__( '## ✅ PDF Text Extraction Complete

**Extracted:** %d words
**Method:** Standard (digital PDF)

---

✨ *Text extracted successfully and is available for use.*', 'mcp-ai-wpoos-pro' ),
					$word_count
				);
			}

			return array(
				'success'          => true,
				'report'           => $report,
				'extraction_data'  => array(
					'text'              => $text,
					'word_count'        => $word_count,
					'char_count'        => $char_count,
					'extraction_method' => $used_ocr ? 'ocr' : 'standard',
					'ocr_provider'      => $used_ocr ? $ocr_provider : null,
				),
			);

		} catch ( Exception $e ) {
			// Clean up temp file if we downloaded one.
			if ( null !== $temp_file ) {
				@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			return array(
				'success' => false,
				'error'   => 'exception',
				'report'  => sprintf(
					/* translators: %s: error message */
					__( '❌ **Unexpected Error**

Failed to extract text from PDF: %s', 'mcp-ai-wpoos-pro' ),
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
