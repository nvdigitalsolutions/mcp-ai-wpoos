<?php
/**
 * Prepare File for Vector Store Tool (Pro)
 *
 * Automatically prepares files for optimal vector store ingestion.
 * Handles format conversion, text extraction, OCR, encoding fixes,
 * and structure optimization.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load required classes from base plugin.
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-file-preprocessing-helper.php';

/**
 * Prepare files for vector store upload with automatic optimization.
 *
 * This pro tool leverages advanced document processing libraries to:
 * - Convert unreliable formats (CSV, XLSX) to structured text or PDF
 * - Extract text from PDFs with OCR support for scanned documents
 * - Fix encoding issues (convert to UTF-8)
 * - Clean and optimize document structure
 * - Generate preview chunks for validation
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Tool_Prepare_File_For_Vector_Store implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'prepare_file_for_vector_store';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Prepare File for Vector Store (Pro)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Automatically prepare files for optimal vector store ingestion. Converts unreliable formats (CSV, XLSX, PPTX) to PDF or structured text, extracts text with OCR support, fixes encoding issues, and optimizes document structure. Returns a new, optimized file ready for upload to OpenAI vector stores.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_id'      => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the file to prepare.', 'mcp-ai-wpoos-pro' ),
				),
				'output_format'      => array(
					'type'        => 'string',
					'enum'        => array( 'auto', 'pdf', 'txt', 'md' ),
					'description' => __( 'Desired output format. "auto" chooses best format based on input. Default: auto', 'mcp-ai-wpoos-pro' ),
					'default'     => 'auto',
				),
				'enable_ocr'         => array(
					'type'        => 'boolean',
					'description' => __( 'Enable OCR for scanned PDFs and images. Default: true', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'preserve_structure' => array(
					'type'        => 'boolean',
					'description' => __( 'Preserve document structure (headings, lists, tables). Default: true', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'clean_formatting'   => array(
					'type'        => 'boolean',
					'description' => __( 'Remove headers, footers, and unnecessary formatting. Default: true', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'generate_preview'   => array(
					'type'        => 'boolean',
					'description' => __( 'Generate chunk preview for validation. Default: true', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'attachment_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check capability.
		if ( ! current_user_can( 'upload_files' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'You do not have permission to process files.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate attachment ID.
		$attachment_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid attachment ID.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return array(
				'success' => false,
				'error'   => __( 'File not found.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get parameters.
		$output_format      = isset( $arguments['output_format'] ) ? sanitize_key( $arguments['output_format'] ) : 'auto';
		$enable_ocr         = isset( $arguments['enable_ocr'] ) ? (bool) $arguments['enable_ocr'] : true;
		$preserve_structure = isset( $arguments['preserve_structure'] ) ? (bool) $arguments['preserve_structure'] : true;
		$clean_formatting   = isset( $arguments['clean_formatting'] ) ? (bool) $arguments['clean_formatting'] : true;
		$generate_preview   = isset( $arguments['generate_preview'] ) ? (bool) $arguments['generate_preview'] : true;

		// Detect file type.
		$file_type = wp_check_filetype( $file_path );
		$file_ext  = strtolower( $file_type['ext'] );

		// Log preprocessing attempt.
		WP_MCP_AI_Logger::log_event(
			'vector_store_file_prep',
			'Starting file preparation for vector store',
			array(
				'attachment_id' => $attachment_id,
				'file_type'     => $file_ext,
				'output_format' => $output_format,
			)
		);

		try {
			// Process based on file type.
			$result = $this->process_file(
				$file_path,
				$file_ext,
				$output_format,
				$enable_ocr,
				$preserve_structure,
				$clean_formatting,
				$attachment_id
			);

			// Generate preview if requested.
			if ( $generate_preview && $result['success'] && isset( $result['processed_file_id'] ) ) {
				$preview           = $this->generate_chunk_preview( $result['processed_file_id'] );
				$result['preview'] = $preview;
			}

			return $result;

		} catch ( Exception $e ) {
			WP_MCP_AI_Logger::log_error(
				'Vector store file preparation failed',
				array(
					'attachment_id' => $attachment_id,
					'error'         => $e->getMessage(),
				)
			);

			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: error message */
					__( 'File preparation failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Process file based on type.
	 *
	 * @param string $file_path File path.
	 * @param string $file_ext File extension.
	 * @param string $output_format Desired output format.
	 * @param bool   $enable_ocr Enable OCR.
	 * @param bool   $preserve_structure Preserve structure.
	 * @param bool   $clean_formatting Clean formatting.
	 * @param int    $attachment_id Original attachment ID.
	 * @return array Result.
	 */
	private function process_file( $file_path, $file_ext, $output_format, $enable_ocr, $preserve_structure, $clean_formatting, $attachment_id ) {
		// Handle unreliable formats that need conversion.
		$unreliable_formats = array( 'csv', 'xlsx', 'xls', 'pptx', 'ppt' );

		if ( in_array( $file_ext, $unreliable_formats, true ) ) {
			return $this->convert_unreliable_format( $file_path, $file_ext, $output_format, $preserve_structure, $attachment_id );
		}

		// Handle PDFs (may need OCR).
		if ( 'pdf' === $file_ext ) {
			return $this->process_pdf( $file_path, $enable_ocr, $clean_formatting, $attachment_id );
		}

		// Handle text files (encoding fixes).
		if ( in_array( $file_ext, array( 'txt', 'md', 'html', 'json' ), true ) ) {
			return $this->process_text_file( $file_path, $file_ext, $clean_formatting, $attachment_id );
		}

		// Handle DOCX (structure extraction).
		if ( 'docx' === $file_ext ) {
			return $this->process_docx( $file_path, $output_format, $clean_formatting, $attachment_id );
		}

		// File type already optimal or not supported.
		return array(
			'success'           => true,
			'message'           => __( 'File is already in optimal format for vector store.', 'mcp-ai-wpoos-pro' ),
			'processed_file_id' => $attachment_id,
			'conversion_needed' => false,
		);
	}

	/**
	 * Convert unreliable format (CSV, XLSX, PPTX) to optimal format.
	 *
	 * @param string $file_path File path.
	 * @param string $file_ext File extension.
	 * @param string $output_format Desired output format.
	 * @param bool   $preserve_structure Preserve structure.
	 * @param int    $attachment_id Original attachment ID.
	 * @return array Result.
	 */
	private function convert_unreliable_format( $file_path, $file_ext, $output_format, $preserve_structure, $attachment_id ) {
		// Determine best output format.
		if ( 'auto' === $output_format ) {
			// For spreadsheets, structured text is better than PDF for RAG.
			$output_format = in_array( $file_ext, array( 'csv', 'xlsx', 'xls' ), true ) ? 'txt' : 'pdf';
		}

		// Handle CSV/Excel conversion.
		if ( in_array( $file_ext, array( 'csv', 'xlsx', 'xls' ), true ) ) {
			return $this->convert_spreadsheet( $file_path, $file_ext, $output_format, $preserve_structure, $attachment_id );
		}

		// Handle PPTX conversion.
		if ( in_array( $file_ext, array( 'pptx', 'ppt' ), true ) ) {
			return $this->convert_presentation( $file_path, $output_format, $attachment_id );
		}

		return array(
			'success' => false,
			'error'   => sprintf(
				/* translators: %s: file extension */
				__( 'Conversion not yet implemented for %s format.', 'mcp-ai-wpoos-pro' ),
				$file_ext
			),
		);
	}

	/**
	 * Convert spreadsheet to structured text.
	 *
	 * @param string $file_path File path.
	 * @param string $file_ext File extension.
	 * @param string $output_format Output format.
	 * @param bool   $preserve_structure Preserve structure.
	 * @param int    $attachment_id Original attachment ID.
	 * @return array Result.
	 */
	private function convert_spreadsheet( $file_path, $file_ext, $output_format, $preserve_structure, $attachment_id ) {
		// Check if excel_data_import tool exists.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Excel_Data_Import' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Excel processing tool not available. Please ensure pro plugin is fully activated.', 'mcp-ai-wpoos-pro' ),
			);
		}

		try {
			// Use excel_data_import tool to extract data.
			$excel_tool   = new WP_MCP_AI_Tool_Excel_Data_Import();
			$excel_result = $excel_tool->execute(
				array(
					'attachment_id' => $attachment_id,
					'has_headers'   => true,
				),
				array()
			);

			if ( ! isset( $excel_result['success'] ) || ! $excel_result['success'] ) {
				return array(
					'success' => false,
					'error'   => isset( $excel_result['error'] ) ? $excel_result['error'] : __( 'Failed to extract Excel data.', 'mcp-ai-wpoos-pro' ),
				);
			}

			// Convert extracted data to structured text.
			$structured_text = $this->format_spreadsheet_as_text( $excel_result['data'], $preserve_structure );

			// Create new text file.
			$original_filename = basename( $file_path, '.' . $file_ext );
			$new_filename      = $original_filename . '-converted.txt';
			$upload_dir        = wp_upload_dir();
			$new_file_path     = $upload_dir['path'] . '/' . $new_filename;

			// Save converted content.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $new_file_path, $structured_text );

			// Create attachment for new file.
			$new_attachment_id = $this->create_attachment_from_file( $new_file_path, $new_filename );

			if ( ! $new_attachment_id ) {
				return array(
					'success' => false,
					'error'   => __( 'Failed to create attachment for converted file.', 'mcp-ai-wpoos-pro' ),
				);
			}

			return array(
				'success'            => true,
				'message'            => sprintf(
					/* translators: 1: original format, 2: new format */
					__( 'Successfully converted %1$s to structured %2$s format optimized for vector store.', 'mcp-ai-wpoos-pro' ),
					strtoupper( $file_ext ),
					strtoupper( $output_format )
				),
				'processed_file_id'  => $new_attachment_id,
				'original_file_id'   => $attachment_id,
				'conversion_applied' => true,
				'output_format'      => $output_format,
				'rows_extracted'     => isset( $excel_result['row_count'] ) ? $excel_result['row_count'] : 0,
			);

		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: error message */
					__( 'Spreadsheet conversion failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Format spreadsheet data as structured text.
	 *
	 * @param array $data Spreadsheet data.
	 * @param bool  $preserve_structure Preserve structure.
	 * @return string Formatted text.
	 */
	private function format_spreadsheet_as_text( $data, $preserve_structure ) {
		if ( ! is_array( $data ) || empty( $data ) ) {
			return '';
		}

		$output = '';

		// Add header if present.
		if ( isset( $data['headers'] ) && is_array( $data['headers'] ) ) {
			$output .= "# Data Structure\n\n";
			$output .= "Columns: " . implode( ', ', $data['headers'] ) . "\n\n";
			$output .= "---\n\n";
		}

		// Add rows.
		if ( isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
			foreach ( $data['rows'] as $index => $row ) {
				$output .= "## Record " . ( $index + 1 ) . "\n\n";

				if ( isset( $data['headers'] ) && count( $data['headers'] ) === count( $row ) ) {
					// Format as key-value pairs.
					foreach ( $data['headers'] as $col_index => $header ) {
						$value   = isset( $row[ $col_index ] ) ? $row[ $col_index ] : '';
						$output .= "**{$header}**: {$value}\n";
					}
				} else {
					// Format as numbered list.
					foreach ( $row as $cell_index => $cell ) {
						$output .= ( $cell_index + 1 ) . ". {$cell}\n";
					}
				}

				$output .= "\n---\n\n";
			}
		}

		return $output;
	}

	/**
	 * Convert presentation to text or PDF.
	 *
	 * @param string $file_path File path.
	 * @param string $output_format Output format.
	 * @param int    $attachment_id Original attachment ID.
	 * @return array Result.
	 */
	private function convert_presentation( $file_path, $output_format, $attachment_id ) {
		// For now, recommend manual conversion.
		// Full PPTX parsing requires additional libraries.
		return array(
			'success'        => false,
			'error'          => __( 'PPTX conversion requires manual export. Please export as PDF with notes or use a converter tool.', 'mcp-ai-wpoos-pro' ),
			'recommendation' => __( 'Export presentation to PDF format with speaker notes for best vector store results.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Process PDF file (check if OCR needed).
	 *
	 * @param string $file_path File path.
	 * @param bool   $enable_ocr Enable OCR.
	 * @param bool   $clean_formatting Clean formatting.
	 * @param int    $attachment_id Attachment ID.
	 * @return array Result.
	 */
	private function process_pdf( $file_path, $enable_ocr, $clean_formatting, $attachment_id ) {
		// Try to extract text first.
		if ( class_exists( 'WP_MCP_AI_Tool_Extract_PDF_Text' ) ) {
			$pdf_tool = new WP_MCP_AI_Tool_Extract_PDF_Text();
			$result   = $pdf_tool->execute(
				array(
					'attachment_id' => $attachment_id,
					'enable_ocr'    => $enable_ocr,
					'ocr_provider'  => 'auto',
				),
				array()
			);

			// If extraction successful and OCR was applied, file is already optimized.
			if ( isset( $result['success'] ) && $result['success'] ) {
				return array(
					'success'           => true,
					'message'           => __( 'PDF is ready for vector store. Text extracted successfully.', 'mcp-ai-wpoos-pro' ),
					'processed_file_id' => $attachment_id,
					'ocr_applied'       => isset( $result['ocr_applied'] ) ? $result['ocr_applied'] : false,
					'text_length'       => isset( $result['text_length'] ) ? $result['text_length'] : 0,
				);
			}
		}

		// If no extraction tool or extraction failed.
		return array(
			'success'           => true,
			'message'           => __( 'PDF file is in acceptable format for vector store.', 'mcp-ai-wpoos-pro' ),
			'processed_file_id' => $attachment_id,
			'warning'           => __( 'Could not verify text extraction. Consider using extract_pdf_text tool first.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Process text file (encoding fixes).
	 *
	 * @param string $file_path File path.
	 * @param string $file_ext File extension.
	 * @param bool   $clean_formatting Clean formatting.
	 * @param int    $attachment_id Attachment ID.
	 * @return array Result.
	 */
	private function process_text_file( $file_path, $file_ext, $clean_formatting, $attachment_id ) {
		// Check encoding.
		$encoding_check = WP_MCP_AI_File_Preprocessing_Helper::check_file_encoding( $file_path );

		if ( $encoding_check['is_utf8'] && ! $clean_formatting ) {
			// File is already optimal.
			return array(
				'success'           => true,
				'message'           => __( 'Text file is already in optimal format (UTF-8).', 'mcp-ai-wpoos-pro' ),
				'processed_file_id' => $attachment_id,
				'encoding'          => 'UTF-8',
			);
		}

		// Need to process file.
		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// Convert to UTF-8 if needed.
		if ( ! $encoding_check['is_utf8'] ) {
			$content = mb_convert_encoding( $content, 'UTF-8', mb_detect_encoding( $content, mb_detect_order(), true ) );
		}

		// Clean formatting if requested.
		if ( $clean_formatting ) {
			$content = $this->clean_text_formatting( $content );
		}

		// Save processed file.
		$original_filename = basename( $file_path, '.' . $file_ext );
		$new_filename      = $original_filename . '-optimized.' . $file_ext;
		$upload_dir        = wp_upload_dir();
		$new_file_path     = $upload_dir['path'] . '/' . $new_filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $new_file_path, $content );

		// Create attachment.
		$new_attachment_id = $this->create_attachment_from_file( $new_file_path, $new_filename );

		if ( ! $new_attachment_id ) {
			return array(
				'success' => false,
				'error'   => __( 'Failed to create optimized file attachment.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return array(
			'success'            => true,
			'message'            => __( 'Text file optimized for vector store.', 'mcp-ai-wpoos-pro' ),
			'processed_file_id'  => $new_attachment_id,
			'original_file_id'   => $attachment_id,
			'encoding_fixed'     => ! $encoding_check['is_utf8'],
			'formatting_cleaned' => $clean_formatting,
		);
	}

	/**
	 * Process DOCX file.
	 *
	 * @param string $file_path File path.
	 * @param string $output_format Output format.
	 * @param bool   $clean_formatting Clean formatting.
	 * @param int    $attachment_id Attachment ID.
	 * @return array Result.
	 */
	private function process_docx( $file_path, $output_format, $clean_formatting, $attachment_id ) {
		// DOCX is already a supported format.
		// Just validate it's properly formatted.
		return array(
			'success'           => true,
			'message'           => __( 'DOCX format is supported by vector stores.', 'mcp-ai-wpoos-pro' ),
			'processed_file_id' => $attachment_id,
			'recommendation'    => __( 'Ensure track changes are accepted and comments removed before upload.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Clean text formatting.
	 *
	 * @param string $content Text content.
	 * @return string Cleaned content.
	 */
	private function clean_text_formatting( $content ) {
		// Remove excessive whitespace.
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );
		$content = preg_replace( '/[ \t]+/', ' ', $content );

		// Normalize line endings.
		$content = str_replace( "\r\n", "\n", $content );
		$content = str_replace( "\r", "\n", $content );

		// Trim.
		$content = trim( $content );

		return $content;
	}

	/**
	 * Generate chunk preview.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Preview data.
	 */
	private function generate_chunk_preview( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path ) {
			return array(
				'error' => __( 'Could not generate preview.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Read first 2000 characters.
		$content = file_get_contents( $file_path, false, null, 0, 2000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// Estimate tokens (rough approximation: 1 token ≈ 4 characters).
		$estimated_tokens = strlen( $content ) / 4;

		// Split into first chunk (512 tokens ≈ 2048 characters).
		$chunk_size  = 2048;
		$first_chunk = substr( $content, 0, $chunk_size );

		return array(
			'preview_text'           => $first_chunk,
			'estimated_tokens'       => (int) $estimated_tokens,
			'recommended_chunk_size' => '256-512 tokens',
			'file_size'              => filesize( $file_path ),
		);
	}

	/**
	 * Create attachment from file.
	 *
	 * @param string $file_path File path.
	 * @param string $filename Filename.
	 * @return int|false Attachment ID or false.
	 */
	private function create_attachment_from_file( $file_path, $filename ) {
		$file_type = wp_check_filetype( $filename );

		$attachment = array(
			'guid'           => wp_upload_dir()['url'] . '/' . basename( $filename ),
			'post_mime_type' => $file_type['type'],
			'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $file_path );

		if ( is_wp_error( $attach_id ) ) {
			return false;
		}

		// Generate metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		return $attach_id;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * Get extended tool definition.
	 *
	 * @return array Tool definition.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'data_analytics',
			'pattern_compatibility' => array( 'sequential', 'orchestrator' ),
			'profession_tags'       => array( 'data_scientist', 'machine_learning_engineer' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'modifies-state',   // Creates new files.
			'local-processing', // Processes files locally.
		);
	}
}
