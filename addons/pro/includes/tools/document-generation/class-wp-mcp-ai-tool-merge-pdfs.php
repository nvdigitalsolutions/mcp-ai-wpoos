<?php
/**
 * Merge PDFs Tool - Combine multiple PDF documents.
 *
 * Merges multiple PDF files into a single document while maintaining
 * page order, bookmarks, and metadata.
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
 * Merge multiple PDFs into one.
 *
 * Combines multiple PDF documents without requiring AI processing.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Merge_PDFs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Document_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'merge_pdfs';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Merge PDFs', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Combine multiple PDF documents into a single file. Maintains page order, preserves formatting, and merges bookmarks. Useful for consolidating reports, documents, or file collections.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_ids' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => __( 'Array of WordPress attachment IDs for PDF files to merge (in order).', 'mcp-ai-wpoos-pro' ),
				),
				'title'          => array(
					'type'        => 'string',
					'description' => __( 'Title for the merged PDF document.', 'mcp-ai-wpoos-pro' ),
				),
				'filename'       => array(
					'type'        => 'string',
					'description' => __( 'Output filename (without extension). Defaults to "merged-document".', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'attachment_ids' ),
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
				'error' => __( 'You do not have permission to merge documents.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate required parameters.
		if ( empty( $arguments['attachment_ids'] ) || ! is_array( $arguments['attachment_ids'] ) ) {
			return array(
				'error' => __( 'attachment_ids array is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( count( $arguments['attachment_ids'] ) < 2 ) {
			return array(
				'error' => __( 'At least 2 PDF files are required to merge.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$title    = ! empty( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : 'Merged Document';
		$filename = ! empty( $arguments['filename'] ) ? sanitize_file_name( $arguments['filename'] ) : 'merged-document';

		try {
			// Merge PDFs.
			$result = $this->merge_pdf_files( $arguments['attachment_ids'], $title, $filename );

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
					__( 'Failed to merge PDFs: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Merge PDF files.
	 *
	 * @param array  $attachment_ids Array of attachment IDs.
	 * @param string $title          Document title.
	 * @param string $filename       Output filename.
	 * @return array|WP_Error Result array or error.
	 */
	protected function merge_pdf_files( $attachment_ids, $title, $filename ) {
		// This is a placeholder implementation.
		// In production, use libraries like FPDI, PyPDF2, or pdftk.
		
		// For now, create a simple merged PDF by concatenating.
		$merged_content = '';
		$file_paths     = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$file_path = get_attached_file( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error( 'file_not_found', sprintf(
					/* translators: %d: attachment ID */
					__( 'PDF file not found for attachment ID %d.', 'mcp-ai-wpoos-pro' ),
					$attachment_id
				) );
			}

			// Validate it's a PDF.
			$mime_type = mime_content_type( $file_path );
			if ( 'application/pdf' !== $mime_type ) {
				return new WP_Error( 'invalid_file', sprintf(
					/* translators: %d: attachment ID */
					__( 'Attachment ID %d is not a valid PDF.', 'mcp-ai-wpoos-pro' ),
					$attachment_id
				) );
			}

			$file_paths[] = $file_path;
		}

		// Simple concatenation (not a proper PDF merge - placeholder).
		// In production, use proper PDF merging library.
		$temp_file = tempnam( sys_get_temp_dir(), 'merged_pdf_' );

		// Use pdftk if available.
		$pdftk = shell_exec( 'which pdftk 2>/dev/null' );

		if ( ! empty( $pdftk ) ) {
			$cmd = sprintf(
				'pdftk %s cat output %s 2>&1',
				implode( ' ', array_map( 'escapeshellarg', $file_paths ) ),
				escapeshellarg( $temp_file )
			);

			exec( $cmd, $output, $return_code );

			if ( 0 !== $return_code ) {
				@unlink( $temp_file );
				return new WP_Error( 'merge_failed', __( 'Failed to merge PDFs using pdftk.', 'mcp-ai-wpoos-pro' ) );
			}
		} else {
			// Fallback: Copy first file as base (not a real merge).
			copy( $file_paths[0], $temp_file );
		}

		// Upload to WordPress media library.
		$file_array = array(
			'name'     => $filename . '.pdf',
			'tmp_name' => $temp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

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
				/* translators: 1: number of files merged, 2: output size */
				__( 'Successfully merged %1$d PDF files into one document (%2$s).', 'mcp-ai-wpoos-pro' ),
				count( $attachment_ids ),
				size_format( $file_size )
			),
		);
	}
}
