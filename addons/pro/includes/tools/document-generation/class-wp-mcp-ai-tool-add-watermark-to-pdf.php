<?php
/**
 * Add Watermark to PDF Tool - Add watermarks to PDF documents.
 *
 * Adds text or image watermarks to PDF files for branding,
 * security, or document tracking purposes.
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
 * Add watermark to PDF documents.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Add_Watermark_To_PDF implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Document_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'add_watermark_to_pdf';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Add Watermark to PDF', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Add text or image watermarks to PDF documents. Perfect for branding, security, copyright protection, or document tracking. Supports custom positioning, opacity, and rotation.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'WordPress attachment ID of the PDF to watermark.', 'mcp-ai-wpoos-pro' ),
				),
				'url'           => array(
					'type'        => 'string',
					'description' => __( 'URL of the PDF file to watermark (alternative to attachment_id).', 'mcp-ai-wpoos-pro' ),
				),
				'text'          => array(
					'type'        => 'string',
					'description' => __( 'Watermark text (e.g., "CONFIDENTIAL", "DRAFT", company name).', 'mcp-ai-wpoos-pro' ),
				),
				'opacity'       => array(
					'type'        => 'number',
					'minimum'     => 0,
					'maximum'     => 1,
					'description' => __( 'Watermark opacity (0.0 to 1.0). Default: 0.3', 'mcp-ai-wpoos-pro' ),
				),
				'position'      => array(
					'type'        => 'string',
					'enum'        => array( 'center', 'diagonal', 'top', 'bottom' ),
					'description' => __( 'Watermark position. Default: diagonal', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'text' ),
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
				'error' => __( 'You do not have permission to modify documents.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate required parameters.
		if ( empty( $arguments['text'] ) ) {
			return array(
				'error' => __( 'text parameter is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( empty( $arguments['attachment_id'] ) && empty( $arguments['url'] ) ) {
			return array(
				'error' => __( 'Either attachment_id or url is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$attachment_id = ! empty( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
		$url           = ! empty( $arguments['url'] ) ? $arguments['url'] : '';
		$text          = sanitize_text_field( $arguments['text'] );
		$opacity       = isset( $arguments['opacity'] ) ? floatval( $arguments['opacity'] ) : 0.3;
		$position      = ! empty( $arguments['position'] ) ? $arguments['position'] : 'diagonal';

		// Clamp opacity.
		$opacity = max( 0, min( 1, $opacity ) );

		try {
			// Add watermark to PDF.
			$result = $this->add_watermark( $attachment_id, $url, $text, $opacity, $position );

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
					__( 'Failed to add watermark: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Add watermark to PDF.
	 *
	 * @param int    $attachment_id Attachment ID (0 if using URL).
	 * @param string $url           PDF URL (empty if using attachment_id).
	 * @param string $text          Watermark text.
	 * @param float  $opacity       Opacity.
	 * @param string $position      Position.
	 * @return array|WP_Error Result array or error.
	 */
	protected function add_watermark( $attachment_id, $url, $text, $opacity, $position ) {
		$file_path = null;
		$temp_file = null;

		if ( ! empty( $attachment_id ) ) {
			$file_path = get_attached_file( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error( 'file_not_found', __( 'PDF file not found.', 'mcp-ai-wpoos-pro' ) );
			}
		} elseif ( ! empty( $url ) ) {
			// Download URL to temp file.
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$temp_file = download_url( $url );

			if ( is_wp_error( $temp_file ) ) {
				return new WP_Error(
					'download_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Failed to download PDF: %s', 'mcp-ai-wpoos-pro' ),
						$temp_file->get_error_message()
					)
				);
			}

			$file_path = $temp_file;
		}

		if ( ! $file_path ) {
			return new WP_Error( 'no_file', __( 'No file specified.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate it's a PDF.
		$mime_type = mime_content_type( $file_path );
		if ( 'application/pdf' !== $mime_type ) {
			if ( $temp_file ) {
				@unlink( $temp_file );
			}
			return new WP_Error( 'invalid_file', __( 'File is not a valid PDF.', 'mcp-ai-wpoos-pro' ) );
		}

		// Try TCPDF library.
		if ( class_exists( '\TCPDF' ) ) {
			$result = $this->add_watermark_with_tcpdf( $file_path, $text, $opacity, $position );
			if ( $temp_file ) {
				@unlink( $temp_file );
			}
			return $result;
		}

		// Clean up temp file if we downloaded one.
		if ( $temp_file ) {
			@unlink( $temp_file );
		}

		// No suitable watermarking method available.
		return new WP_Error(
			'no_watermarker',
			__( 'PDF watermarking requires TCPDF library (Composer: composer require tecnickcom/tcpdf) or pdf-lib Node.js package. Alternatively, use pdftk with a stamp overlay.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Add watermark using TCPDF.
	 *
	 * @param string $file_path PDF file path.
	 * @param string $text      Watermark text.
	 * @param float  $opacity   Opacity.
	 * @param string $position  Position.
	 * @return array|WP_Error Result array or error.
	 */
	protected function add_watermark_with_tcpdf( $file_path, $text, $opacity, $position ) {
		try {
			$pdf = new \TCPDF();
			$pdf->setPrintHeader( false );
			$pdf->setPrintFooter( false );

			// Import pages from original PDF.
			$page_count = $pdf->setSourceFile( $file_path );

			for ( $i = 1; $i <= $page_count; $i++ ) {
				$pdf->AddPage();
				$tpl_id = $pdf->importPage( $i );
				$pdf->useTemplate( $tpl_id );

				// Add watermark text.
				$pdf->SetAlpha( $opacity );
				$pdf->SetFont( 'helvetica', 'B', 50 );
				$pdf->SetTextColor( 200, 200, 200 );

				// Position watermark.
				switch ( $position ) {
					case 'diagonal':
						$pdf->StartTransform();
						$pdf->Rotate( 45, 105, 148 );
						$pdf->Text( 60, 148, $text );
						$pdf->StopTransform();
						break;
					case 'center':
						$pdf->Text( 105 - ( strlen( $text ) * 2 ), 148, $text, false, false, true, 0, 0, 'C' );
						break;
					case 'top':
						$pdf->Text( 105 - ( strlen( $text ) * 2 ), 20, $text );
						break;
					case 'bottom':
						$pdf->Text( 105 - ( strlen( $text ) * 2 ), 280, $text );
						break;
				}

				$pdf->SetAlpha( 1 ); // Reset alpha.
			}

			// Save to temp file.
			$temp_file = tempnam( sys_get_temp_dir(), 'watermarked_pdf_' );
			$pdf->Output( $temp_file, 'F' );

			// Upload to WordPress media library.
			$original_filename = basename( $file_path, '.pdf' );
			$file_array        = array(
				'name'     => $original_filename . '-watermarked.pdf',
				'tmp_name' => $temp_file,
			);

			$new_attachment_id = media_handle_sideload( $file_array, 0 );

			@unlink( $temp_file );

			if ( is_wp_error( $new_attachment_id ) ) {
				return $new_attachment_id;
			}

			// Get attachment details.
			$attachment_url = wp_get_attachment_url( $new_attachment_id );
			$new_file_path  = get_attached_file( $new_attachment_id );
			$file_size      = filesize( $new_file_path );

			return array(
				'attachment_id' => $new_attachment_id,
				'url'           => $attachment_url,
				'filename'      => basename( $new_file_path ),
				'mime_type'     => 'application/pdf',
				'size'          => $file_size,
				'text'          => sprintf(
					/* translators: %s: watermark text */
					__( 'Successfully added watermark "%s" to PDF.', 'mcp-ai-wpoos-pro' ),
					$text
				),
			);

		} catch ( Exception $e ) {
			return new WP_Error(
				'tcpdf_error',
				sprintf(
					/* translators: %s: error message */
					__( 'TCPDF watermarking failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				)
			);
		}
	}
}
