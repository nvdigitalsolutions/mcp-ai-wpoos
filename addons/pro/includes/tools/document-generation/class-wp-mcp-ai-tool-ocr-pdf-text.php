<?php
/**
 * OCR PDF Text Extraction Tool
 *
 * Extract text from scanned/image-only PDFs using OCR technology.
 * Automatically detects if a PDF needs OCR and applies appropriate method.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the chat response trait from base plugin.
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

// Load OCR service.
require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';

/**
 * Extract text from scanned PDFs using OCR.
 *
 * Handles scanned/image-only PDF documents that don't contain
 * machine-readable text. Uses multiple OCR providers with fallback.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Tool_OCR_PDF_Text implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'ocr_pdf_text';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'OCR PDF Text Extraction', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Extract text from scanned or image-only PDF documents using OCR (Optical Character Recognition). Supports multiple OCR providers including OpenAI Vision, Google Gemini, Ollama, and Tesseract. Automatically detects if PDF needs OCR and applies image preprocessing for better accuracy.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Maximum number of pages to process. Default: 10 (OCR is resource-intensive)', 'mcp-ai-wpoos-pro' ),
				),
				'provider'      => array(
					'type'        => 'string',
					'enum'        => array( 'auto', 'openai', 'gemini', 'ollama', 'tesseract' ),
					'description' => __( 'OCR provider to use. "auto" selects best available. Default: auto', 'mcp-ai-wpoos-pro' ),
				),
				'preprocess'    => array(
					'type'        => 'boolean',
					'description' => __( 'Apply image preprocessing (grayscale, contrast, sharpening) for better OCR. Default: true', 'mcp-ai-wpoos-pro' ),
				),
				'language'      => array(
					'type'        => 'string',
					'description' => __( 'Language code for OCR (e.g., "eng" for English, "spa" for Spanish). Default: eng', 'mcp-ai-wpoos-pro' ),
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
			'requires-vision-model', // May use vision models.
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
		$temp_file = null;

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
		$filetype = wp_check_filetype( $file_path );
		$mime_type = ! empty( $filetype['type'] ) ? $filetype['type'] : '';
		
		if ( 'application/pdf' !== $mime_type ) {
			if ( $temp_file ) {
				@unlink( $temp_file );
			}
			return array(
				'error' => __( 'File is not a valid PDF document.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Prepare OCR options.
		$ocr_options = array(
			'max_pages'  => ! empty( $arguments['max_pages'] ) ? min( absint( $arguments['max_pages'] ), 50 ) : 10, // Cap at 50 pages.
			'provider'   => ! empty( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto',
			'preprocess' => isset( $arguments['preprocess'] ) ? (bool) $arguments['preprocess'] : true,
			'language'   => ! empty( $arguments['language'] ) ? sanitize_text_field( $arguments['language'] ) : 'eng',
			'dpi'        => 300, // High DPI for better quality.
		);

		try {
			$ocr_service = new WP_MCP_AI_OCR_Service();

			// Check if PDF needs OCR.
			$is_scanned = $ocr_service->is_scanned_pdf( $file_path );

			// Extract text using OCR.
			$start_time = microtime( true );
			$text       = $ocr_service->extract_text_from_pdf( $file_path, $ocr_options );
			$duration   = microtime( true ) - $start_time;

			// Clean up temp file if we downloaded one.
			if ( $temp_file ) {
				@unlink( $temp_file );
			}

			if ( is_wp_error( $text ) ) {
				return array(
					'error' => sprintf(
						/* translators: %s: error message */
						__( 'OCR extraction failed: %s', 'mcp-ai-wpoos-pro' ),
						$text->get_error_message()
					),
				);
			}

			$word_count = str_word_count( $text );
			$char_count = strlen( $text );

			// Log successful OCR.
			WP_MCP_AI_Logger::log_event(
				'ocr_extraction_success',
				'Successfully extracted text using OCR',
				array(
					'provider'   => $ocr_options['provider'],
					'pages'      => $ocr_options['max_pages'],
					'word_count' => $word_count,
					'duration'   => round( $duration, 2 ),
					'is_scanned' => $is_scanned,
				)
			);

			return $this->format_chat_response(
				array(
					'text'       => $text,
					'word_count' => $word_count,
					'char_count' => $char_count,
					'provider'   => $ocr_options['provider'],
					'is_scanned' => $is_scanned,
					'duration'   => round( $duration, 2 ),
					'pages'      => $ocr_options['max_pages'],
				),
				sprintf(
					/* translators: 1: word count, 2: OCR provider */
					__( 'Successfully extracted %1$d words using OCR (%2$s).', 'mcp-ai-wpoos-pro' ),
					$word_count,
					$ocr_options['provider']
				)
			);

		} catch ( Exception $e ) {
			// Clean up temp file if we downloaded one.
			if ( $temp_file ) {
				@unlink( $temp_file );
			}

			WP_MCP_AI_Logger::log_error(
				'ocr_extraction_exception',
				'OCR extraction threw exception',
				array(
					'message' => $e->getMessage(),
					'trace'   => $e->getTraceAsString(),
				)
			);

			return array(
				'error' => sprintf(
					/* translators: %s: error message */
					__( 'OCR extraction failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}
}
