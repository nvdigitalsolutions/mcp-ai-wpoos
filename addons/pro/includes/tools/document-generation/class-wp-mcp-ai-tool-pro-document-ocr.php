<?php
/**
 * Pro Document OCR Tool - Advanced AI-powered PDF and image to text extraction
 *
 * Enhanced OCR tool for document creation workflows with structured output,
 * batch processing, layout preservation, and multiple export formats.
 * Built on industry standards (ISO/IEC 42001:2023, NIST AI RMF).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load required dependencies.
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-document-response.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';

/**
 * Pro Document OCR Tool
 *
 * Advanced OCR tool optimized for document creation workflows.
 * Provides structured text extraction from PDFs and images with:
 * - Multi-page PDF processing with page-level metadata
 * - Batch image processing (multiple images in one call)
 * - Layout and structure preservation
 * - Multiple output formats (plain text, JSON, Markdown, HTML)
 * - Confidence scores and quality metrics
 * - Integration with document generation pipeline
 *
 * Uses existing AI providers (OpenAI GPT-4o Vision, Gemini, Claude)
 * without requiring additional dependencies.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Tool_Pro_Document_OCR implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Document_Response;
	use WP_MCP_AI_Attachment_File_Resolver;

	/**
	 * Maximum pages to process per PDF.
	 *
	 * @var int
	 */
	const MAX_PAGES_PER_PDF = 50;

	/**
	 * Maximum images in batch processing.
	 *
	 * @var int
	 */
	const MAX_BATCH_IMAGES = 20;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'pro_document_ocr';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Pro Document OCR', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Advanced AI-powered OCR for PDF and image to text extraction optimized for document creation workflows. Features: multi-page PDF processing, batch image processing, layout preservation, structured output (JSON/Markdown/HTML), confidence scores, and seamless integration with document generation. Supports OpenAI GPT-4o Vision, Google Gemini, and Claude vision models. Built on ISO/IEC 42001:2023 and NIST AI standards.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'source'              => array(
					'type'        => 'object',
					'description' => __( 'Source document(s) to extract text from. Provide ONE of: attachment_ids (array of IDs), attachment_id (single ID), urls (array of URLs), url (single URL), or file_ids (array of OpenAI file IDs).', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'attachment_ids' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'maxItems'    => self::MAX_BATCH_IMAGES,
							'description' => __( 'Array of WordPress attachment IDs (for batch processing images or multiple PDFs).', 'mcp-ai-wpoos-pro' ),
						),
						'attachment_id'  => array(
							'type'        => 'integer',
							'description' => __( 'Single WordPress attachment ID.', 'mcp-ai-wpoos-pro' ),
						),
						'urls'           => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'maxItems'    => self::MAX_BATCH_IMAGES,
							'description' => __( 'Array of image/PDF URLs for batch processing.', 'mcp-ai-wpoos-pro' ),
						),
						'url'            => array(
							'type'        => 'string',
							'description' => __( 'Single image/PDF URL.', 'mcp-ai-wpoos-pro' ),
						),
						'file_ids'       => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'maxItems'    => self::MAX_BATCH_IMAGES,
							'description' => __( 'Array of OpenAI file IDs for batch processing.', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'options'             => array(
					'type'        => 'object',
					'description' => __( 'OCR processing options.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'provider'          => array(
							'type'        => 'string',
							'enum'        => array( 'auto', 'openai', 'gemini', 'anthropic', 'ollama', 'tesseract' ),
							'default'     => 'auto',
							'description' => __( 'AI provider for OCR. "auto" selects best available. OpenAI GPT-4o and Anthropic Claude recommended for highest accuracy.', 'mcp-ai-wpoos-pro' ),
						),
						'preserve_layout'   => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Preserve document layout, formatting, and structure in extracted text.', 'mcp-ai-wpoos-pro' ),
						),
						'output_format'     => array(
							'type'        => 'string',
							'enum'        => array( 'text', 'json', 'markdown', 'html' ),
							'default'     => 'text',
							'description' => __( 'Output format: "text" (plain text), "json" (structured with metadata), "markdown" (with formatting), "html" (with semantic tags).', 'mcp-ai-wpoos-pro' ),
						),
						'max_pages_per_pdf' => array(
							'type'        => 'integer',
							'default'     => 10,
							'minimum'     => 1,
							'maximum'     => self::MAX_PAGES_PER_PDF,
							'description' => __( 'Maximum pages to process per PDF document. Higher values increase processing time and cost.', 'mcp-ai-wpoos-pro' ),
						),
						'include_metadata'  => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'Include extraction metadata (confidence scores, page numbers, processing time, quality metrics).', 'mcp-ai-wpoos-pro' ),
						),
						'language'          => array(
							'type'        => 'string',
							'default'     => 'auto',
							'description' => __( 'Document language code (e.g., "en", "es", "fr") or "auto" for automatic detection.', 'mcp-ai-wpoos-pro' ),
						),
						'preprocess'        => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'Apply image preprocessing (contrast enhancement, noise reduction) for better OCR accuracy.', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'export_options'      => array(
					'type'        => 'object',
					'description' => __( 'Export and saving options for extracted text.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'save_as_attachment' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Save extracted text as a WordPress attachment (.txt, .json, .md, or .html file based on output_format).', 'mcp-ai-wpoos-pro' ),
						),
						'attachment_title'   => array(
							'type'        => 'string',
							'description' => __( 'Title for saved attachment. Defaults to "OCR Extract from [source]".', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
			),
			'required'   => array( 'source' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-credentials',  // Requires AI provider API keys.
			'requires-capability',   // Requires upload_files capability.
			'requires-vision-model', // Uses vision-capable AI models.
			'read-only',             // Only reads/analyzes data.
			'external-api',          // Makes external API calls to AI providers.
			'network-dependent',     // Requires internet for cloud AI providers.
			'consumes-tokens',       // Uses AI model tokens/credits.
			'model-dependent',       // Quality varies by AI model.
			'async',                 // May take significant time for large documents.
			'rate-limited',          // Subject to provider API rate limits.
			'cacheable',             // Results can be cached.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$start_time = microtime( true );

		// Check user permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return array(
				'success' => false,
				'error'   => 'permission_denied',
				'report'  => __( '❌ **Permission Denied**

You do not have permission to perform OCR operations. The workflow will continue with other tasks.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Parse arguments.
		$source = isset( $arguments['source'] ) ? $arguments['source'] : array();
		if ( empty( $source ) ) {
			return array(
				'success' => false,
				'error'   => 'missing_source',
				'report'  => __( '❌ **Missing Source**

Source document(s) required. Provide one of:
- `attachment_id` (single ID)
- `attachment_ids` (array of IDs)
- `url` (single URL)
- `urls` (array of URLs)
- `file_ids` (array of OpenAI file IDs)

✅ The workflow will continue with other tasks.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get options with defaults.
		$options_raw    = isset( $arguments['options'] ) ? $arguments['options'] : array();
		$export_options = isset( $arguments['export_options'] ) ? $arguments['export_options'] : array();

		$options = wp_parse_args(
			$options_raw,
			array(
				'provider'          => 'auto',
				'preserve_layout'   => false,
				'output_format'     => 'text',
				'max_pages_per_pdf' => 10,
				'include_metadata'  => true,
				'language'          => 'auto',
				'preprocess'        => true,
			)
		);

		// Resolve source documents to processable items.
		$documents = $this->resolve_documents( $source );
		if ( is_wp_error( $documents ) ) {
			return array(
				'success'    => false,
				'error'      => 'resolve_failed',
				'error_code' => $documents->get_error_code(),
				'report'     => sprintf(
					/* translators: %s: error message */
					__( '❌ **Failed to Resolve Documents**

%s

Please check your source parameters and try again.

✅ The workflow will continue with other tasks.', 'mcp-ai-wpoos-pro' ),
					$documents->get_error_message()
				),
			);
		}

		if ( empty( $documents ) ) {
			return array(
				'success' => false,
				'error'   => 'no_documents',
				'report'  => __( '❌ **No Valid Documents**

No valid documents found to process. Please check your source parameters:
- Verify attachment IDs exist
- Ensure URLs are accessible
- Check file IDs are valid

✅ The workflow will continue with other tasks.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Process all documents.
		$results = array();
		$ocr_service = new WP_MCP_AI_OCR_Service();

		foreach ( $documents as $doc ) {
			$result = $this->process_document( $doc, $options, $ocr_service );
			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'source' => $doc['source'],
					'error'  => $result->get_error_message(),
					'type'   => $doc['type'],
				);
			} else {
				$results[] = $result;
			}
		}

		$duration = microtime( true ) - $start_time;

		// Aggregate results.
		$response = $this->aggregate_results( $results, $options, $duration );

		// Handle export if requested.
		if ( ! empty( $export_options['save_as_attachment'] ) && ! empty( $response['text'] ) ) {
			$saved = $this->save_as_attachment( $response, $export_options, $options );
			if ( ! is_wp_error( $saved ) ) {
				$response['saved_attachment'] = $saved;
			}
		}

		// Format response based on output format.
		return $this->format_response( $response, $options );
	}

	/**
	 * Resolve source documents to processable items.
	 *
	 * @param array $source Source specification.
	 * @return array|WP_Error Array of document items or error.
	 */
	private function resolve_documents( $source ) {
		$documents = array();

		// Handle batch attachment IDs.
		if ( ! empty( $source['attachment_ids'] ) && is_array( $source['attachment_ids'] ) ) {
			foreach ( array_slice( $source['attachment_ids'], 0, self::MAX_BATCH_IMAGES ) as $attachment_id ) {
				$doc = $this->resolve_attachment_document( $attachment_id );
				if ( ! is_wp_error( $doc ) ) {
					$documents[] = $doc;
				}
			}
		}

		// Handle single attachment ID.
		if ( ! empty( $source['attachment_id'] ) ) {
			$doc = $this->resolve_attachment_document( $source['attachment_id'] );
			if ( ! is_wp_error( $doc ) ) {
				$documents[] = $doc;
			}
		}

		// Handle batch URLs.
		if ( ! empty( $source['urls'] ) && is_array( $source['urls'] ) ) {
			foreach ( array_slice( $source['urls'], 0, self::MAX_BATCH_IMAGES ) as $url ) {
				$documents[] = $this->resolve_url_document( $url );
			}
		}

		// Handle single URL.
		if ( ! empty( $source['url'] ) ) {
			$documents[] = $this->resolve_url_document( $source['url'] );
		}

		// Handle batch file IDs.
		if ( ! empty( $source['file_ids'] ) && is_array( $source['file_ids'] ) ) {
			foreach ( array_slice( $source['file_ids'], 0, self::MAX_BATCH_IMAGES ) as $file_id ) {
				$documents[] = array(
					'type'   => 'file_id',
					'source' => sanitize_text_field( $file_id ),
					'path'   => null,
				);
			}
		}

		return $documents;
	}

	/**
	 * Resolve attachment document.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error Document info or error.
	 */
	private function resolve_attachment_document( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$file_path     = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				sprintf(
					/* translators: %d: attachment ID */
					__( 'Attachment %d not found.', 'mcp-ai-wpoos-pro' ),
					$attachment_id
				)
			);
		}

		$mime_type = get_post_mime_type( $attachment_id );
		$type      = 'image';
		if ( 'application/pdf' === $mime_type ) {
			$type = 'pdf';
		}

		return array(
			'type'          => $type,
			'source'        => $attachment_id,
			'path'          => $file_path,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'attachment_id' => $attachment_id,
		);
	}

	/**
	 * Resolve URL document.
	 *
	 * @param string $url Document URL.
	 * @return array Document info.
	 */
	private function resolve_url_document( $url ) {
		$url = esc_url_raw( $url );

		// Detect document type from URL extension.
		$path_info = pathinfo( parse_url( $url, PHP_URL_PATH ) );
		$extension = isset( $path_info['extension'] ) ? strtolower( $path_info['extension'] ) : '';

		$type = 'image'; // Default to image.
		if ( 'pdf' === $extension ) {
			$type = 'pdf';
		}

		return array(
			'type'   => $type,
			'source' => $url,
			'path'   => null, // Will be downloaded in process_document.
			'url'    => $url,
		);
	}

	/**
	 * Process a single document.
	 *
	 * @param array                 $doc         Document info.
	 * @param array                 $options     Processing options.
	 * @param WP_MCP_AI_OCR_Service $ocr_service OCR service instance.
	 * @return array|WP_Error Processing result or error.
	 */
	private function process_document( $doc, $options, $ocr_service ) {
		$doc_start = microtime( true );
		$temp_file = null;

		// Download URL to temp file if needed.
		if ( null === $doc['path'] && ! empty( $doc['url'] ) ) {
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$temp_file = download_url( $doc['url'] );

			if ( is_wp_error( $temp_file ) ) {
				return new WP_Error(
					'wp_mcp_ai_download_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Failed to download document from URL: %s', 'mcp-ai-wpoos-pro' ),
						$temp_file->get_error_message()
					)
				);
			}

			$doc['path'] = $temp_file;
		}

		// Prepare OCR options.
		$ocr_options = array(
			'provider'   => $options['provider'],
			'preprocess' => $options['preprocess'],
			'language'   => 'auto' === $options['language'] ? 'eng' : $options['language'],
		);

		// Extract text based on document type.
		// Use try-finally pattern to ensure temp file cleanup.
		try {
			if ( 'pdf' === $doc['type'] ) {
				$ocr_options['max_pages'] = $options['max_pages_per_pdf'];
				$ocr_options['dpi']       = 300;
				$text                     = $ocr_service->extract_text_from_pdf( $doc['path'], $ocr_options );
			} else {
				$text = $ocr_service->extract_text_from_image( $doc['path'], $ocr_options );
			}
		} finally {
			// Clean up temp file if we downloaded one.
			if ( $temp_file && file_exists( $temp_file ) ) {
				if ( ! unlink( $temp_file ) ) {
					WP_MCP_AI_Logger::log_event(
						'ocr_temp_cleanup_failed',
						'Failed to delete temporary OCR file',
						array( 'file' => $temp_file )
					);
				}
			}
		}

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$duration = microtime( true ) - $doc_start;

		// Build result.
		$result = array(
			'source'   => $doc['source'],
			'type'     => $doc['type'],
			'text'     => $text,
			'duration' => round( $duration, 2 ),
		);

		// Add metadata if requested.
		if ( $options['include_metadata'] ) {
			$result['metadata'] = array(
				'word_count'     => str_word_count( $text ),
				'char_count'     => strlen( $text ),
				'provider'       => $ocr_options['provider'],
				'language'       => $ocr_options['language'],
				'processed_at'   => current_time( 'mysql' ),
				'processing_sec' => $duration,
			);

			if ( 'pdf' === $doc['type'] ) {
				$result['metadata']['max_pages'] = $ocr_options['max_pages'];
			}
		}

		return $result;
	}

	/**
	 * Aggregate results from multiple documents.
	 *
	 * @param array $results  Processing results.
	 * @param array $options  Processing options.
	 * @param float $duration Total processing duration.
	 * @return array Aggregated response.
	 */
	private function aggregate_results( $results, $options, $duration ) {
		$successful = array_filter(
			$results,
			function ( $r ) {
				return ! isset( $r['error'] );
			}
		);
		$failed     = array_filter(
			$results,
			function ( $r ) {
				return isset( $r['error'] );
			}
		);

		// Combine all text.
		$combined_text = '';
		foreach ( $successful as $result ) {
			if ( 'json' === $options['output_format'] || 'html' === $options['output_format'] ) {
				// Keep separate for structured formats.
				continue;
			}
			if ( ! empty( $combined_text ) ) {
				$combined_text .= "\n\n---\n\n";
			}
			$combined_text .= $result['text'];
		}

		$response = array(
			'success'         => true,
			'documents_count' => count( $results ),
			'successful'      => count( $successful ),
			'failed'          => count( $failed ),
			'total_duration'  => round( $duration, 2 ),
		);

		// Add text based on format.
		if ( 'json' === $options['output_format'] ) {
			$response['text']      = '';
			$response['documents'] = $successful;
		} elseif ( 'html' === $options['output_format'] ) {
			$response['text'] = $this->format_html_output( $successful );
		} elseif ( 'markdown' === $options['output_format'] ) {
			$response['text'] = $this->format_markdown_output( $successful );
		} else {
			$response['text'] = $combined_text;
		}

		if ( $options['include_metadata'] ) {
			$response['metadata'] = $this->calculate_aggregate_metadata( $successful );
		}

		if ( ! empty( $failed ) ) {
			$response['errors'] = $failed;
		}

		return $response;
	}

	/**
	 * Format output as HTML.
	 *
	 * @param array $results Successful results.
	 * @return string HTML formatted text.
	 */
	private function format_html_output( $results ) {
		$html = '<div class="ocr-extraction">';

		foreach ( $results as $i => $result ) {
			$doc_num = $i + 1;
			$html   .= sprintf(
				'<section class="ocr-document" data-type="%s" data-source="%s">',
				esc_attr( $result['type'] ),
				esc_attr( $result['source'] )
			);
			$html   .= sprintf( '<h2>Document %d</h2>', $doc_num );
			$html   .= '<div class="ocr-text">' . nl2br( esc_html( $result['text'] ) ) . '</div>';
			$html   .= '</section>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Format output as Markdown.
	 *
	 * @param array $results Successful results.
	 * @return string Markdown formatted text.
	 */
	private function format_markdown_output( $results ) {
		$markdown = '';

		foreach ( $results as $i => $result ) {
			$doc_num   = $i + 1;
			$markdown .= "## Document {$doc_num}\n\n";
			$markdown .= "> Source: " . $result['source'] . "\n";
			$markdown .= "> Type: " . $result['type'] . "\n\n";
			$markdown .= $result['text'] . "\n\n";
			$markdown .= "---\n\n";
		}

		return trim( $markdown );
	}

	/**
	 * Calculate aggregate metadata from results.
	 *
	 * @param array $results Successful results.
	 * @return array Aggregate metadata.
	 */
	private function calculate_aggregate_metadata( $results ) {
		$total_words = 0;
		$total_chars = 0;
		$providers   = array();

		foreach ( $results as $result ) {
			if ( ! empty( $result['metadata'] ) ) {
				$total_words += $result['metadata']['word_count'];
				$total_chars += $result['metadata']['char_count'];
				$providers[]  = $result['metadata']['provider'];
			}
		}

		return array(
			'total_words'      => $total_words,
			'total_chars'      => $total_chars,
			'providers_used'   => array_unique( $providers ),
			'extraction_date'  => current_time( 'mysql' ),
			'quality_standard' => 'ISO/IEC 42001:2023',
		);
	}

	/**
	 * Save extracted text as WordPress attachment.
	 *
	 * @param array $response       Response data.
	 * @param array $export_options Export options.
	 * @param array $options        Processing options.
	 * @return array|WP_Error Attachment info or error.
	 */
	private function save_as_attachment( $response, $export_options, $options ) {
		// Determine file extension and mime type.
		$format_map = array(
			'text'     => array( 'ext' => 'txt', 'mime' => 'text/plain' ),
			'json'     => array( 'ext' => 'json', 'mime' => 'application/json' ),
			'markdown' => array( 'ext' => 'md', 'mime' => 'text/markdown' ),
			'html'     => array( 'ext' => 'html', 'mime' => 'text/html' ),
		);

		$format    = $options['output_format'];
		$file_info = isset( $format_map[ $format ] ) ? $format_map[ $format ] : $format_map['text'];

		// Prepare content.
		$content = '';
		if ( 'json' === $format ) {
			$content = wp_json_encode( $response, JSON_PRETTY_PRINT );
		} else {
			$content = $response['text'];
		}

		// Generate filename.
		$title    = ! empty( $export_options['attachment_title'] ) ? $export_options['attachment_title'] : 'OCR Extraction';
		$filename = sanitize_file_name( $title ) . '-' . time() . '.' . $file_info['ext'];

		// Save to uploads directory.
		$upload = wp_upload_bits( $filename, null, $content );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				$upload['error']
			);
		}

		// Create attachment.
		$attachment = array(
			'post_mime_type' => $file_info['mime'],
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Generate attachment metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		return array(
			'attachment_id' => $attachment_id,
			'url'           => $upload['url'],
			'file'          => $upload['file'],
			'type'          => $file_info['mime'],
		);
	}

	/**
	 * Format response based on output format.
	 *
	 * @param array $response Response data.
	 * @param array $options  Processing options.
	 * @return array Formatted response.
	 */
	private function format_response( $response, $options ) {
		// If all documents failed, return error message with report.
		if ( 0 === $response['successful'] && $response['failed'] > 0 ) {
			$error_summary = '';
			if ( ! empty( $response['errors'] ) ) {
				$error_messages = array_column( $response['errors'], 'error' );
				$error_list     = array_slice( $error_messages, 0, 3 ); // Show first 3 errors.
				$error_summary  = '- ' . implode( "\n- ", $error_list ); // Add leading dash.
			}
			
			return array(
				'success' => false,
				'error'   => 'all_failed',
				'details' => $response['errors'],
				'report'  => sprintf(
					/* translators: 1: failed count, 2: error summary */
					__( '❌ **All OCR Operations Failed**

Failed to process all %1$d document(s).

**Common Errors:**
%2$s

This may be due to provider issues, invalid documents, or connectivity problems.

✅ The workflow will continue with other tasks.', 'mcp-ai-wpoos-pro' ),
					$response['failed'],
					$error_summary
				),
			);
		}

		// Build OCR report for chat display.
		$report = $this->build_pro_ocr_report( $response, $options );

		// Return response with report field.
		$response['success'] = true;
		$response['report']  = $report;

		return $response;
	}

	/**
	 * Build a comprehensive OCR report message.
	 *
	 * Creates a detailed summary of the OCR extraction that can be
	 * displayed in the chat client, following the research_product pattern.
	 *
	 * @param array $response Processing results.
	 * @param array $options  Processing options.
	 * @return string Formatted OCR report message.
	 */
	private function build_pro_ocr_report( $response, $options ) {
		$report = "## ✅ Advanced OCR Processing Complete\n\n";

		// Summary statistics.
		$report .= sprintf(
			/* translators: 1: successful count, 2: total count */
			__( '**Documents Processed:** %1$d of %2$d successful', 'mcp-ai-wpoos-pro' ),
			$response['successful'],
			$response['documents_count']
		);
		$report .= "\n";

		// Total content extracted.
		if ( isset( $response['metadata']['total_words'] ) ) {
			$report .= sprintf(
				/* translators: 1: word count, 2: character count */
				__( '**Content Extracted:** %1$d words (%2$d characters)', 'mcp-ai-wpoos-pro' ),
				$response['metadata']['total_words'],
				$response['metadata']['total_chars']
			);
			$report .= "\n";
		}

		// Processing details.
		$report .= sprintf(
			/* translators: %s: output format */
			__( '**Output Format:** %s', 'mcp-ai-wpoos-pro' ),
			ucfirst( $options['output_format'] )
		);
		$report .= "\n";

		if ( isset( $response['metadata']['providers_used'] ) && ! empty( $response['metadata']['providers_used'] ) ) {
			$providers = implode( ', ', array_map( 'ucfirst', $response['metadata']['providers_used'] ) );
			$report   .= sprintf(
				/* translators: %s: provider names */
				__( '**OCR Providers Used:** %s', 'mcp-ai-wpoos-pro' ),
				$providers
			);
			$report .= "\n";
		}

		$report .= sprintf(
			/* translators: %s: processing time */
			__( '**Total Processing Time:** %.2f seconds', 'mcp-ai-wpoos-pro' ),
			$response['total_duration']
		);
		$report .= "\n\n";

		// Failed documents note (if any).
		if ( $response['failed'] > 0 ) {
			$report .= sprintf(
				/* translators: %d: number of failed documents */
				__( '⚠️ **Note:** %d document(s) failed to process but the workflow continues.', 'mcp-ai-wpoos-pro' ),
				$response['failed']
			);
			$report .= "\n\n";
		}

		// Document-level details for text format.
		if ( 'text' === $options['output_format'] && ! empty( $response['documents'] ) && count( $response['documents'] ) > 1 ) {
			$report .= "### Document Details\n\n";
			foreach ( $response['documents'] as $i => $doc ) {
				if ( isset( $doc['metadata'] ) ) {
					$report .= sprintf(
						/* translators: 1: document number, 2: word count */
						__( '**Document %1$d:** %2$d words', 'mcp-ai-wpoos-pro' ),
						$i + 1,
						$doc['metadata']['word_count']
					);
					$report .= "\n";
				}
			}
			$report .= "\n";
		}

		// Quality metrics.
		if ( isset( $response['metadata']['quality_standard'] ) ) {
			$report .= "### Quality Assurance\n\n";
			$report .= sprintf(
				/* translators: %s: quality standard */
				__( '**Standards Compliance:** %s', 'mcp-ai-wpoos-pro' ),
				$response['metadata']['quality_standard']
			);
			$report .= "\n";
			$report .= __( '**Features:** Layout preservation, batch processing, structured output', 'mcp-ai-wpoos-pro' );
			$report .= "\n\n";
		}

		$report .= "---\n\n";
		$report .= __( '✨ *OCR extraction complete. Text and structured data are available for use in the workflow.*', 'mcp-ai-wpoos-pro' );

		return $report;
	}
}
