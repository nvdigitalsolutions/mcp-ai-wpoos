<?php
/**
 * Trait for adding rendered document HTML to document generation tool responses.
 *
 * This trait provides a standardized way to display document attachments (PDF, Word, Excel)
 * with download buttons, file information, and optional preview capabilities.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Tool_Document_Response
 *
 * Provides helper methods to add rendered document display HTML to tool responses.
 * This ensures that document generation tools return not just URLs but actual
 * displayable content with download buttons and file information.
 *
 * Usage:
 * ```php
 * class My_Document_Tool implements WP_MCP_AI_Tool_Interface {
 *     use WP_MCP_AI_Tool_Document_Response;
 *
 *     public function execute( $arguments, $context ) {
 *         // Generate document and get attachment_id, url, etc.
 *         $result = array(
 *             'attachment_id' => $attachment_id,
 *             'url' => $url,
 *             'mime_type' => 'application/pdf',
 *             'text' => 'Document generated successfully',
 *         );
 *
 *         // Add rendered document HTML to response
 *         return $this->add_document_html_to_response( $result );
 *     }
 * }
 * ```
 */
trait WP_MCP_AI_Tool_Document_Response {

	/**
	 * Add rendered document HTML to a tool response.
	 *
	 * This method takes a document generation result and adds rendered HTML
	 * to the 'message' field with download button, file info, and optional preview.
	 *
	 * @param array $result Tool result containing attachment_id or url and optionally text/message.
	 * @return array Modified result with document HTML added to message field.
	 */
	protected function add_document_html_to_response( array $result ) {
		// Check if we have either attachment_id or direct URL.
		$document_url = '';
		$mime_type    = isset( $result['mime_type'] ) ? $result['mime_type'] : '';

		if ( ! empty( $result['attachment_id'] ) ) {
			$attachment_id = absint( $result['attachment_id'] );

			// Get attachment URL and MIME type.
			$document_url = wp_get_attachment_url( $attachment_id );
			if ( empty( $mime_type ) ) {
				$mime_type = get_post_mime_type( $attachment_id );
			}
		} elseif ( ! empty( $result['url'] ) ) {
			// Use direct URL.
			$document_url = esc_url( $result['url'] );
		}

		if ( empty( $document_url ) ) {
			return $result;
		}

		// Get document metadata.
		$title     = isset( $result['title'] ) ? $result['title'] : '';
		$file_name = isset( $result['file_name'] ) ? $result['file_name'] : basename( $document_url );
		$file_size = isset( $result['bytes'] ) ? $result['bytes'] : 0;

		if ( empty( $title ) && ! empty( $result['attachment_id'] ) ) {
			$title = get_the_title( $result['attachment_id'] );
		}

		// Get file size from attachment if not provided.
		if ( empty( $file_size ) && ! empty( $result['attachment_id'] ) ) {
			$file_path = get_attached_file( $result['attachment_id'] );
			if ( $file_path && file_exists( $file_path ) ) {
				$file_size = filesize( $file_path );
			}
		}

		// Generate the document HTML.
		$document_html = $this->generate_document_html( $document_url, $mime_type, $title, $file_name, $file_size, $result );

		// Get existing text message.
		$text_message = isset( $result['text'] ) ? $result['text'] : ( isset( $result['message'] ) ? $result['message'] : '' );

		// Combine text message with rendered document display.
		$result['message'] = ! empty( $text_message ) ? $text_message . "\n\n" . $document_html : $document_html;

		return $result;
	}

	/**
	 * Generate clean, optimized document HTML display.
	 *
	 * Creates a document display with icon, file info, and download button.
	 * For PDFs, optionally includes an embedded preview.
	 *
	 * @param string $document_url Document file URL.
	 * @param string $mime_type    Document MIME type.
	 * @param string $title        Title/description.
	 * @param string $file_name    File name.
	 * @param int    $file_size    File size in bytes.
	 * @param array  $result       Full result array (for extracting metadata).
	 * @return string HTML document display.
	 */
	protected function generate_document_html( $document_url, $mime_type, $title = '', $file_name = '', $file_size = 0, array $result = array() ) {
		if ( empty( $document_url ) ) {
			return '';
		}

		// Determine document type and icon.
		$doc_type = $this->get_document_type( $mime_type );
		$icon     = $this->get_document_icon( $doc_type );

		// Format file size.
		$formatted_size = $file_size > 0 ? size_format( $file_size, 2 ) : '';

		// Build document display container.
		$html = '<div class="wp-mcp-ai-generated-document" style="border: 1px solid #ddd; border-radius: 4px; padding: 15px; margin: 10px 0; background: #f9f9f9;">';

		// Document header with icon and title.
		$html .= '<div class="wp-mcp-ai-document-header" style="display: flex; align-items: center; margin-bottom: 10px;">';
		$html .= '<span class="wp-mcp-ai-document-icon" style="font-size: 32px; margin-right: 15px;">' . $icon . '</span>';
		$html .= '<div class="wp-mcp-ai-document-info" style="flex: 1;">';

		if ( ! empty( $title ) ) {
			$html .= '<div class="wp-mcp-ai-document-title" style="font-weight: bold; font-size: 14px; margin-bottom: 4px;">' . esc_html( $title ) . '</div>';
		}

		$html .= '<div class="wp-mcp-ai-document-meta" style="font-size: 12px; color: #666;">';
		$html .= '<span class="wp-mcp-ai-document-filename">' . esc_html( $file_name ) . '</span>';

		if ( ! empty( $formatted_size ) ) {
			$html .= ' <span class="wp-mcp-ai-document-separator">•</span> ';
			$html .= '<span class="wp-mcp-ai-document-size">' . esc_html( $formatted_size ) . '</span>';
		}

		$html .= ' <span class="wp-mcp-ai-document-separator">•</span> ';
		$html .= '<span class="wp-mcp-ai-document-type">' . esc_html( strtoupper( $doc_type ) ) . '</span>';
		$html .= '</div>';

		$html .= '</div>';
		$html .= '</div>';

		// Download button.
		$html .= '<a href="' . esc_url( $document_url ) . '" download class="wp-mcp-ai-document-download" style="display: inline-block; padding: 8px 16px; background: #0073aa; color: #fff; text-decoration: none; border-radius: 3px; font-size: 14px; font-weight: 500;">';
		$html .= '📥 ' . __( 'Download', 'mcp-ai-wpoos' );
		$html .= '</a>';

		// Add PDF preview for PDFs if enabled.
		if ( 'pdf' === $doc_type && $this->should_show_pdf_preview( $result ) ) {
			$html .= $this->generate_pdf_preview_html( $document_url );
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Get document type from MIME type.
	 *
	 * @param string $mime_type MIME type.
	 * @return string Document type (pdf, word, excel, etc.).
	 */
	protected function get_document_type( $mime_type ) {
		$mime_type = strtolower( $mime_type );

		$type_map = array(
			'application/pdf'                         => 'pdf',
			'application/msword'                      => 'word',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word',
			'application/vnd.ms-excel'                => 'excel',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'excel',
			'application/vnd.oasis.opendocument.text' => 'odt',
			'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
			'text/csv'                                => 'csv',
		);

		return isset( $type_map[ $mime_type ] ) ? $type_map[ $mime_type ] : 'document';
	}

	/**
	 * Get emoji icon for document type.
	 *
	 * @param string $doc_type Document type.
	 * @return string Emoji icon.
	 */
	protected function get_document_icon( $doc_type ) {
		$icons = array(
			'pdf'      => '📄',
			'word'     => '📝',
			'excel'    => '📊',
			'csv'      => '📊',
			'odt'      => '📝',
			'ods'      => '📊',
			'document' => '📋',
		);

		return isset( $icons[ $doc_type ] ) ? $icons[ $doc_type ] : '📋';
	}

	/**
	 * Check if PDF preview should be shown.
	 *
	 * @param array $result Result array.
	 * @return bool True if preview should be shown.
	 */
	protected function should_show_pdf_preview( array $result ) {
		// Check if preview is explicitly enabled/disabled in result.
		if ( isset( $result['show_preview'] ) ) {
			return (bool) $result['show_preview'];
		}

		// Default: show preview for PDFs under 5MB.
		$file_size = isset( $result['bytes'] ) ? $result['bytes'] : 0;
		return $file_size > 0 && $file_size < 5242880; // 5MB
	}

	/**
	 * Generate PDF preview HTML using iframe.
	 *
	 * @param string $pdf_url PDF file URL.
	 * @return string HTML iframe for PDF preview.
	 */
	protected function generate_pdf_preview_html( $pdf_url ) {
		$html  = '<div class="wp-mcp-ai-pdf-preview" style="margin-top: 15px; border-top: 1px solid #ddd; padding-top: 15px;">';
		$html .= '<div style="font-size: 12px; color: #666; margin-bottom: 8px;">' . __( 'Preview:', 'mcp-ai-wpoos' ) . '</div>';

		// Use sandbox attribute for security.
		$html .= '<iframe src="' . esc_url( $pdf_url ) . '" ';
		$html .= 'style="width: 100%; height: 500px; border: 1px solid #ccc; border-radius: 3px;" ';
		$html .= 'sandbox="allow-same-origin allow-scripts" ';
		$html .= 'title="' . esc_attr( __( 'PDF Preview', 'mcp-ai-wpoos' ) ) . '">';
		$html .= '<p>' . __( 'Your browser does not support PDF preview.', 'mcp-ai-wpoos' ) . ' ';
		$html .= '<a href="' . esc_url( $pdf_url ) . '" download>' . __( 'Download PDF', 'mcp-ai-wpoos' ) . '</a></p>';
		$html .= '</iframe>';
		$html .= '</div>';

		return $html;
	}
}
