<?php
/**
 * File Preprocessing Helper Service
 *
 * Provides utilities for preparing files before upload to OpenAI vector stores.
 * Includes validation, conversion recommendations, and quality checks based on
 * industry best practices for RAG implementations.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File Preprocessing Helper Service
 */
class WP_MCP_AI_File_Preprocessing_Helper {

	/**
	 * Validate file for vector store upload.
	 *
	 * Checks format, encoding, size, and provides actionable recommendations.
	 *
	 * @param string $file_path Absolute path to file.
	 * @param string $purpose   Upload purpose (default: 'assistants').
	 * @return array Validation result with 'valid', 'warnings', 'recommendations'.
	 */
	public static function validate_file_for_vector_store( $file_path, $purpose = 'assistants' ) {
		$result = array(
			'valid'           => true,
			'warnings'        => array(),
			'recommendations' => array(),
			'file_info'       => array(),
		);

		if ( ! file_exists( $file_path ) ) {
			$result['valid']      = false;
			$result['warnings'][] = __( 'File does not exist.', 'mcp-ai-wpoos' );
			return $result;
		}

		$file_type = wp_check_filetype( $file_path );
		$file_ext  = $file_type['ext'];
		$file_size = filesize( $file_path );

		$result['file_info'] = array(
			'path'      => $file_path,
			'extension' => $file_ext,
			'mime_type' => $file_type['type'],
			'size'      => $file_size,
		);

		// Check format suitability.
		$format_check = self::check_format_suitability( $file_ext, $purpose );
		if ( ! $format_check['suitable'] ) {
			$result['valid']           = false;
			$result['warnings']        = array_merge( $result['warnings'], $format_check['warnings'] );
			$result['recommendations'] = array_merge( $result['recommendations'], $format_check['recommendations'] );
		}

		// Check encoding for text files.
		if ( in_array( $file_ext, array( 'txt', 'md', 'json', 'jsonl', 'html' ), true ) ) {
			$encoding_check = self::check_file_encoding( $file_path );
			if ( ! $encoding_check['is_utf8'] ) {
				$result['warnings'][]        = __( 'File encoding is not UTF-8.', 'mcp-ai-wpoos' );
				$result['recommendations'][] = __( 'Convert file to UTF-8 encoding for reliable text extraction.', 'mcp-ai-wpoos' );
			}
		}

		// Check file size.
		$size_limit = 536870912; // 512 MB for assistants.
		if ( $file_size > $size_limit ) {
			$result['valid']      = false;
			$result['warnings'][] = sprintf(
				/* translators: 1: file size, 2: limit */
				__( 'File size (%1$s) exceeds limit (%2$s).', 'mcp-ai-wpoos' ),
				size_format( $file_size ),
				size_format( $size_limit )
			);
		}

		// Add general recommendations if valid.
		if ( $result['valid'] ) {
			$result['recommendations'][] = __( 'Ensure file is preprocessed: remove headers/footers, clean formatting, verify structure.', 'mcp-ai-wpoos' );
		}

		return $result;
	}

	/**
	 * Check format suitability for vector stores.
	 *
	 * @param string $file_ext File extension.
	 * @param string $purpose  Upload purpose (not currently used, reserved for future).
	 * @return array Result with 'suitable', 'warnings', 'recommendations'.
	 */
	private static function check_format_suitability( $file_ext, $purpose ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$reliable_formats   = array( 'pdf', 'txt', 'md', 'json', 'docx', 'html' );
		$unreliable_formats = array( 'csv', 'xlsx', 'xls', 'pptx', 'ppt' );

		$result = array(
			'suitable'        => true,
			'warnings'        => array(),
			'recommendations' => array(),
		);

		if ( in_array( $file_ext, $unreliable_formats, true ) ) {
			$result['suitable']   = false;
			$result['warnings'][] = sprintf(
				/* translators: %s: file extension */
				__( 'Format "%s" is unreliable for vector stores. OpenAI may fail to parse it correctly.', 'mcp-ai-wpoos' ),
				strtoupper( $file_ext )
			);
			$result['recommendations'][] = sprintf(
				/* translators: %s: file extension */
				__( 'Convert %s files to PDF or plain text format. For spreadsheets, export as structured text with clear sections.', 'mcp-ai-wpoos' ),
				strtoupper( $file_ext )
			);
		} elseif ( ! in_array( $file_ext, $reliable_formats, true ) ) {
			$result['suitable']   = false;
			$result['warnings'][] = sprintf(
				/* translators: %s: file extension */
				__( 'Format "%s" is not supported for vector stores.', 'mcp-ai-wpoos' ),
				strtoupper( $file_ext )
			);
			$result['recommendations'][] = sprintf(
				/* translators: %s: formats list */
				__( 'Use one of these formats: %s', 'mcp-ai-wpoos' ),
				implode( ', ', array_map( 'strtoupper', $reliable_formats ) )
			);
		}

		return $result;
	}

	/**
	 * Check if file is UTF-8 encoded.
	 *
	 * @param string $file_path File path.
	 * @return array Result with 'is_utf8' and 'encoding'.
	 */
	public static function check_file_encoding( $file_path ) {
		$sample_size = min( 8192, filesize( $file_path ) );
		$handle      = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return array(
				'is_utf8'  => false,
				'encoding' => 'unknown',
				'error'    => 'Could not open file',
			);
		}

		$sample = fread( $handle, $sample_size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $sample ) {
			return array(
				'is_utf8'  => false,
				'encoding' => 'unknown',
				'error'    => 'Could not read file',
			);
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$is_utf8 = @mb_check_encoding( $sample, 'UTF-8' );

		return array(
			'is_utf8'  => $is_utf8,
			'encoding' => $is_utf8 ? 'UTF-8' : 'non-UTF-8',
		);
	}

	/**
	 * Get preprocessing recommendations for a specific file type.
	 *
	 * @param string $file_ext File extension.
	 * @return array Recommendations specific to the file type.
	 */
	public static function get_preprocessing_recommendations( $file_ext ) {
		$recommendations = array();

		switch ( strtolower( $file_ext ) ) {
			case 'pdf':
				$recommendations[] = __( 'Ensure PDF contains text layer (not scanned images). Use OCR if needed.', 'mcp-ai-wpoos' );
				$recommendations[] = __( 'Remove embedded images not essential for understanding content.', 'mcp-ai-wpoos' );
				$recommendations[] = __( 'Linearize PDF structure to remove complex formatting.', 'mcp-ai-wpoos' );
				break;

			case 'docx':
			case 'doc':
				$recommendations[] = __( 'Accept all track changes before uploading.', 'mcp-ai-wpoos' );
				$recommendations[] = __( 'Remove comments and hidden content.', 'mcp-ai-wpoos' );
				$recommendations[] = __( 'Use clear heading styles (H1, H2, etc.) for better structure.', 'mcp-ai-wpoos' );
				break;

			case 'txt':
			case 'md':
				$recommendations[] = __( 'Ensure UTF-8 encoding.', 'mcp-ai-wpoos' );
				$recommendations[] = __( 'Use clear section markers and headings.', 'mcp-ai-wpoos' );
				$recommendations[] = __( 'Remove excessive whitespace and formatting characters.', 'mcp-ai-wpoos' );
				break;

			case 'html':
				$recommendations[] = __( 'Remove navigation elements, headers, and footers.', 'mcp-ai-wpoos' );
				$recommendations[] = __( 'Clean up inline styles and scripts.', 'mcp-ai-wpoos' );
				$recommendations[] = __( 'Keep semantic HTML structure (headings, paragraphs, lists).', 'mcp-ai-wpoos' );
				break;

			case 'json':
				$recommendations[] = __( 'Ensure valid JSON format.', 'mcp-ai-wpoos' );
				$recommendations[] = __( 'Add context fields for better semantic understanding.', 'mcp-ai-wpoos' );
				$recommendations[] = __( 'Consider pretty-printing for readability.', 'mcp-ai-wpoos' );
				break;

			case 'csv':
			case 'xlsx':
			case 'xls':
				$recommendations[] = __( 'IMPORTANT: Convert to PDF or structured text format first.', 'mcp-ai-wpoos' );
				$recommendations[] = __( 'These formats are unreliable in vector stores.', 'mcp-ai-wpoos' );
				$recommendations[] = __( 'For spreadsheets: Export each sheet as a separate file with context headers.', 'mcp-ai-wpoos' );
				break;

			default:
				$recommendations[] = __( 'Verify file format is supported by OpenAI vector stores.', 'mcp-ai-wpoos' );
				$recommendations[] = __( 'Consider converting to PDF or TXT for best compatibility.', 'mcp-ai-wpoos' );
		}

		return $recommendations;
	}

	/**
	 * Get chunking recommendations based on file type and content.
	 *
	 * @param string $file_ext File extension.
	 * @param int    $file_size File size in bytes.
	 * @return array Chunking recommendations.
	 */
	public static function get_chunking_recommendations( $file_ext, $file_size ) {
		$recommendations = array();

		// General chunking advice.
		$recommendations[] = __( 'Optimal chunk size: 256-512 tokens per section.', 'mcp-ai-wpoos' );
		$recommendations[] = __( 'Use 10-20% overlap between chunks (50-100 tokens).', 'mcp-ai-wpoos' );

		// Size-based recommendations.
		if ( $file_size > 10485760 ) { // > 10MB.
			$recommendations[] = __( 'Large file detected. Consider pre-chunking into smaller logical sections.', 'mcp-ai-wpoos' );
			$recommendations[] = __( 'Upload each major section as a separate file for better retrieval granularity.', 'mcp-ai-wpoos' );
		}

		// Type-based recommendations.
		switch ( strtolower( $file_ext ) ) {
			case 'pdf':
			case 'docx':
				$recommendations[] = __( 'Split at chapter or section boundaries for best results.', 'mcp-ai-wpoos' );
				break;

			case 'md':
			case 'txt':
				$recommendations[] = __( 'Use heading markers (# in Markdown) to define chunk boundaries.', 'mcp-ai-wpoos' );
				break;

			case 'html':
				$recommendations[] = __( 'Split at <section> or major heading tags (<h1>, <h2>).', 'mcp-ai-wpoos' );
				break;
		}

		return $recommendations;
	}
}
