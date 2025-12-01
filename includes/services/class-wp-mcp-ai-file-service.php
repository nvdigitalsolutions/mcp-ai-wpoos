<?php
/**
 * File Service
 *
 * Handles file upload, download, and attachment operations.
 * Extracted from WP_MCP_AI_REST as part of service layer refactoring.
 *
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File Service class
 *
 * Responsible for:
 * - File upload processing
 * - File download handling
 * - Attachment validation
 * - Memory document preparation
 *
 * @since 1.0.0
 */
class WP_MCP_AI_File_Service {

	/**
	 * Maximum file size in bytes (default 10MB)
	 *
	 * @var int
	 */
	private $max_file_size;

	/**
	 * Allowed MIME types
	 *
	 * @var array
	 */
	private $allowed_mime_types;

	/**
	 * Constructor
	 *
	 * @param int   $max_file_size      Maximum file size in bytes.
	 * @param array $allowed_mime_types Allowed MIME types.
	 */
	public function __construct( $max_file_size = 10485760, $allowed_mime_types = array() ) {
		$this->max_file_size = $max_file_size;

		// Default allowed MIME types.
		$defaults = array(
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
			'application/pdf',
			'text/plain',
			'text/csv',
			'application/json',
			'application/msword',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		);

		$this->allowed_mime_types = ! empty( $allowed_mime_types ) ? $allowed_mime_types : $defaults;
	}

	/**
	 * Process file upload
	 *
	 * @param array $file     File data from $_FILES.
	 * @param array $context  Upload context (assistant_id, user_id, etc.).
	 * @return array|WP_Error Upload result or error.
	 */
	public function process_file_upload( $file, $context = array() ) {
		// Validate file.
		$validation = $this->validate_file_upload( $file );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Check file size.
		if ( $file['size'] > $this->max_file_size ) {
			return new WP_Error(
				'wp_mcp_ai_file_too_large',
				sprintf(
					/* translators: %s: maximum file size */
					__( 'File size exceeds maximum allowed size of %s.', 'wp-mcp-ai' ),
					size_format( $this->max_file_size )
				),
				array( 'status' => 400 )
			);
		}

		// Verify MIME type.
		$file_type = wp_check_filetype( $file['name'] );
		if ( ! in_array( $file_type['type'], $this->allowed_mime_types, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_file_type',
				sprintf(
					/* translators: %s: file type */
					__( 'File type "%s" is not allowed.', 'wp-mcp-ai' ),
					$file_type['type']
				),
				array( 'status' => 400 )
			);
		}

		// Use WordPress file upload handler.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$upload_overrides = array(
			'test_form' => false,
			'mimes'     => array_fill_keys( $this->allowed_mime_types, true ),
		);

		$uploaded_file = wp_handle_upload( $file, $upload_overrides );

		if ( isset( $uploaded_file['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_error',
				$uploaded_file['error'],
				array( 'status' => 500 )
			);
		}

		// Create attachment post.
		$attachment_id = $this->create_attachment( $uploaded_file, $context );

		if ( is_wp_error( $attachment_id ) ) {
			// Clean up uploaded file.
			wp_delete_file( $uploaded_file['file'] );
			return $attachment_id;
		}

		// Log upload.
		WP_MCP_AI_Logger::log_event(
			'file_uploaded',
			'File uploaded successfully',
			array(
				'attachment_id' => $attachment_id,
				'filename'      => $file['name'],
				'size'          => $file['size'],
				'user_id'       => get_current_user_id(),
			)
		);

		return array(
			'attachment_id' => $attachment_id,
			'url'           => $uploaded_file['url'],
			'file'          => $uploaded_file['file'],
			'type'          => $uploaded_file['type'],
		);
	}

	/**
	 * Validate file upload
	 *
	 * @param array $file File data from $_FILES.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_file_upload( $file ) {
		if ( empty( $file['name'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_file',
				__( 'No file was uploaded.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error(
				'wp_mcp_ai_upload_error',
				$this->get_upload_error_message( $file['error'] ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Get upload error message
	 *
	 * @param int $error_code PHP upload error code.
	 * @return string Error message.
	 */
	private function get_upload_error_message( $error_code ) {
		$messages = array(
			UPLOAD_ERR_INI_SIZE   => __( 'File exceeds upload_max_filesize directive in php.ini.', 'wp-mcp-ai' ),
			UPLOAD_ERR_FORM_SIZE  => __( 'File exceeds MAX_FILE_SIZE directive.', 'wp-mcp-ai' ),
			UPLOAD_ERR_PARTIAL    => __( 'File was only partially uploaded.', 'wp-mcp-ai' ),
			UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'wp-mcp-ai' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'Missing temporary folder.', 'wp-mcp-ai' ),
			UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk.', 'wp-mcp-ai' ),
			UPLOAD_ERR_EXTENSION  => __( 'File upload stopped by extension.', 'wp-mcp-ai' ),
		);

		return $messages[ $error_code ] ?? __( 'Unknown upload error.', 'wp-mcp-ai' );
	}

	/**
	 * Create attachment post
	 *
	 * @param array $uploaded_file Uploaded file data from wp_handle_upload.
	 * @param array $context       Upload context.
	 * @return int|WP_Error Attachment ID or error.
	 */
	private function create_attachment( $uploaded_file, $context ) {
		$attachment_data = array(
			'post_mime_type' => $uploaded_file['type'],
			'post_title'     => sanitize_file_name( basename( $uploaded_file['file'] ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment_data, $uploaded_file['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Generate metadata.
		$attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $uploaded_file['file'] );
		wp_update_attachment_metadata( $attachment_id, $attachment_metadata );

		// Store context.
		if ( isset( $context['assistant_id'] ) ) {
			update_post_meta( $attachment_id, '_mcp_ai_assistant_id', absint( $context['assistant_id'] ) );
		}

		return $attachment_id;
	}

	/**
	 * Handle file download
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $context       Download context.
	 * @return array|WP_Error File data or error.
	 */
	public function handle_file_download( $attachment_id, $context = array() ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_attachment',
				__( 'Invalid attachment ID.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		// Check permissions.
		$assistant_id = get_post_meta( $attachment_id, '_mcp_ai_assistant_id', true );
		if ( $assistant_id && ! current_user_can( 'read_post', $assistant_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_permission_denied',
				__( 'You do not have permission to download this file.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		$file_path = get_attached_file( $attachment_id );

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'File not found on server.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		return array(
			'file_path' => $file_path,
			'filename'  => basename( $file_path ),
			'mime_type' => get_post_mime_type( $attachment_id ),
			'size'      => filesize( $file_path ),
		);
	}

	/**
	 * Prepare memory documents from file IDs
	 *
	 * @param array $file_ids Array of attachment IDs.
	 * @return array|WP_Error Array of memory documents or error.
	 */
	public function prepare_memory_documents( $file_ids ) {
		if ( empty( $file_ids ) || ! is_array( $file_ids ) ) {
			return array();
		}

		$documents = array();

		foreach ( $file_ids as $file_id ) {
			$attachment = get_post( $file_id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				continue; // Skip invalid attachments.
			}

			$file_path = get_attached_file( $file_id );

			if ( ! file_exists( $file_path ) ) {
				continue; // Skip missing files.
			}

			// Read file content.
			$content = file_get_contents( $file_path );

			if ( false === $content ) {
				continue; // Skip if can't read.
			}

			$documents[] = array(
				'id'      => $file_id,
				'name'    => basename( $file_path ),
				'type'    => get_post_mime_type( $file_id ),
				'content' => $content,
				'size'    => strlen( $content ),
			);
		}

		return $documents;
	}

	/**
	 * Validate attachments array
	 *
	 * @param array $attachments Attachments to validate.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_attachments( $attachments ) {
		if ( ! is_array( $attachments ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_attachments',
				__( 'Attachments must be an array.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		foreach ( $attachments as $attachment ) {
			if ( ! isset( $attachment['type'] ) || ! isset( $attachment['data'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_attachment_format',
					__( 'Each attachment must have "type" and "data" fields.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}
}
