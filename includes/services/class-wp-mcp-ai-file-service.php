<?php
/**
 * File Service for WP oOS
 *
 * Orchestrates file handling operations including upload, download, and validation.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_File_Service' ) ) {
	/**
	 * Service layer for file handling orchestration.
	 *
	 * This service handles file upload, download, validation, and processing
	 * for AI assistant operations.
	 */
	class WP_MCP_AI_File_Service {
		/**
		 * Maximum file size in bytes (default: 10MB).
		 *
		 * @var int
		 */
		private $max_file_size;

		/**
		 * Allowed MIME types for uploads.
		 *
		 * @var array
		 */
		private $allowed_mimes;

		/**
		 * Constructor.
		 *
		 * @param int   $max_file_size  Optional. Maximum file size in bytes.
		 * @param array $allowed_mimes  Optional. Allowed MIME types.
		 */
		public function __construct( $max_file_size = null, $allowed_mimes = null ) {
			$this->max_file_size = $max_file_size ?? ( 10 * 1024 * 1024 ); // 10MB default.
			$this->allowed_mimes = $allowed_mimes ?? $this->get_default_allowed_mimes();
		}

		/**
		 * Upload a file for AI processing.
		 *
		 * @param array $file    File array from $_FILES.
		 * @param array $context Upload context (user_id, assistant_id, etc.).
		 * @return array|WP_Error Upload result with attachment_id or error.
		 */
		public function upload_file( $file, $context = array() ) {
			// Validate file array structure.
			if ( ! is_array( $file ) || ! isset( $file['tmp_name'] ) ) {
				return new WP_Error( 'invalid_file', __( 'Invalid file upload.', 'wp-mcp-ai' ) );
			}

			// Validate file size.
			$size_check = $this->validate_file_size( $file );
			if ( is_wp_error( $size_check ) ) {
				return $size_check;
			}

			// Validate MIME type.
			$mime_check = $this->validate_mime_type( $file );
			if ( is_wp_error( $mime_check ) ) {
				return $mime_check;
			}

			// Check user permissions.
			$permission_check = $this->validate_upload_permissions( $context );
			if ( is_wp_error( $permission_check ) ) {
				return $permission_check;
			}

			// Process the upload.
			$upload_result = $this->process_upload( $file, $context );
			if ( is_wp_error( $upload_result ) ) {
				return $upload_result;
			}

			// Create attachment record.
			$attachment_id = $this->create_attachment( $upload_result, $file, $context );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			return array(
				'attachment_id' => $attachment_id,
				'url'           => wp_get_attachment_url( $attachment_id ),
				'filename'      => basename( $upload_result['file'] ),
				'filesize'      => filesize( $upload_result['file'] ),
				'mime_type'     => $file['type'],
			);
		}

		/**
		 * Download a file from a URL for AI processing.
		 *
		 * @param string $url     File URL.
		 * @param array  $context Download context.
		 * @return array|WP_Error Download result or error.
		 */
		public function download_file( $url, $context = array() ) {
			// Validate URL.
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return new WP_Error( 'invalid_url', __( 'Invalid file URL.', 'wp-mcp-ai' ) );
			}

			// Check permissions.
			$permission_check = $this->validate_download_permissions( $context );
			if ( is_wp_error( $permission_check ) ) {
				return $permission_check;
			}

			// Download file.
			$download_result = $this->process_download( $url, $context );
			if ( is_wp_error( $download_result ) ) {
				return $download_result;
			}

			return $download_result;
		}

		/**
		 * Get file information.
		 *
		 * @param int $attachment_id Attachment ID.
		 * @return array|WP_Error File information or error.
		 */
		public function get_file_info( $attachment_id ) {
			$attachment = get_post( $attachment_id );
			
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return new WP_Error( 'attachment_not_found', __( 'Attachment not found.', 'wp-mcp-ai' ) );
			}

			$file_path = get_attached_file( $attachment_id );
			
			return array(
				'attachment_id' => $attachment_id,
				'url'           => wp_get_attachment_url( $attachment_id ),
				'filename'      => basename( $file_path ),
				'filesize'      => file_exists( $file_path ) ? filesize( $file_path ) : 0,
				'mime_type'     => get_post_mime_type( $attachment_id ),
				'title'         => $attachment->post_title,
			);
		}

		/**
		 * Delete a file.
		 *
		 * @param int   $attachment_id Attachment ID.
		 * @param array $context       Deletion context.
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		public function delete_file( $attachment_id, $context = array() ) {
			// Check permissions.
			$permission_check = $this->validate_delete_permissions( $attachment_id, $context );
			if ( is_wp_error( $permission_check ) ) {
				return $permission_check;
			}

			// Delete attachment.
			$result = wp_delete_attachment( $attachment_id, true );
			
			if ( ! $result ) {
				return new WP_Error( 'delete_failed', __( 'Failed to delete file.', 'wp-mcp-ai' ) );
			}

			/**
			 * Action fired after a file is deleted.
			 *
			 * @param int   $attachment_id Attachment ID.
			 * @param array $context       Deletion context.
			 */
			do_action( 'wp_mcp_ai_file_deleted', $attachment_id, $context );

			return true;
		}

		/**
		 * Validate file size.
		 *
		 * @param array $file File array.
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		private function validate_file_size( $file ) {
			if ( $file['size'] > $this->max_file_size ) {
				return new WP_Error(
					'file_too_large',
					sprintf(
						/* translators: %s: maximum file size in MB */
						__( 'File size exceeds maximum allowed size of %s MB.', 'wp-mcp-ai' ),
						size_format( $this->max_file_size )
					)
				);
			}

			return true;
		}

		/**
		 * Validate MIME type.
		 *
		 * @param array $file File array.
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		private function validate_mime_type( $file ) {
			$finfo     = finfo_open( FILEINFO_MIME_TYPE );
			$mime_type = finfo_file( $finfo, $file['tmp_name'] );
			finfo_close( $finfo );

			if ( ! in_array( $mime_type, $this->allowed_mimes, true ) ) {
				return new WP_Error(
					'invalid_mime_type',
					sprintf(
						/* translators: %s: MIME type */
						__( 'File type "%s" is not allowed.', 'wp-mcp-ai' ),
						$mime_type
					)
				);
			}

			return true;
		}

		/**
		 * Validate upload permissions.
		 *
		 * @param array $context Upload context.
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		private function validate_upload_permissions( $context ) {
			$user_id = $context['user_id'] ?? get_current_user_id();

			if ( ! user_can( $user_id, 'upload_files' ) ) {
				return new WP_Error( 'insufficient_permissions', __( 'You do not have permission to upload files.', 'wp-mcp-ai' ) );
			}

			return true;
		}

		/**
		 * Validate download permissions.
		 *
		 * @param array $context Download context.
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		private function validate_download_permissions( $context ) {
			$user_id = $context['user_id'] ?? get_current_user_id();

			if ( ! user_can( $user_id, 'edit_posts' ) ) {
				return new WP_Error( 'insufficient_permissions', __( 'You do not have permission to download files.', 'wp-mcp-ai' ) );
			}

			return true;
		}

		/**
		 * Validate delete permissions.
		 *
		 * @param int   $attachment_id Attachment ID.
		 * @param array $context       Deletion context.
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		private function validate_delete_permissions( $attachment_id, $context ) {
			$user_id = $context['user_id'] ?? get_current_user_id();

			if ( ! user_can( $user_id, 'delete_posts' ) ) {
				return new WP_Error( 'insufficient_permissions', __( 'You do not have permission to delete files.', 'wp-mcp-ai' ) );
			}

			return true;
		}

		/**
		 * Process file upload.
		 *
		 * @param array $file    File array.
		 * @param array $context Upload context.
		 * @return array|WP_Error Upload result or error.
		 */
		private function process_upload( $file, $context ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			$upload = wp_handle_upload(
				$file,
				array(
					'test_form' => false,
					'mimes'     => $this->get_allowed_mimes_for_upload(),
				)
			);

			if ( isset( $upload['error'] ) ) {
				return new WP_Error( 'upload_error', $upload['error'] );
			}

			return $upload;
		}

		/**
		 * Process file download.
		 *
		 * @param string $url     File URL.
		 * @param array  $context Download context.
		 * @return array|WP_Error Download result or error.
		 */
		private function process_download( $url, $context ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			$temp_file = download_url( $url );

			if ( is_wp_error( $temp_file ) ) {
				return $temp_file;
			}

			// Get file info.
			$file_info = array(
				'tmp_name' => $temp_file,
				'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
				'type'     => mime_content_type( $temp_file ),
				'size'     => filesize( $temp_file ),
			);

			return $file_info;
		}

		/**
		 * Create attachment record.
		 *
		 * @param array $upload  Upload result.
		 * @param array $file    Original file array.
		 * @param array $context Upload context.
		 * @return int|WP_Error Attachment ID or error.
		 */
		private function create_attachment( $upload, $file, $context ) {
			$attachment = array(
				'post_mime_type' => $upload['type'],
				'post_title'     => sanitize_file_name( $file['name'] ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			wp_update_attachment_metadata( $attachment_id, $attachment_data );

			/**
			 * Action fired after a file is uploaded.
			 *
			 * @param int   $attachment_id Attachment ID.
			 * @param array $upload        Upload result.
			 * @param array $context       Upload context.
			 */
			do_action( 'wp_mcp_ai_file_uploaded', $attachment_id, $upload, $context );

			return $attachment_id;
		}

		/**
		 * Get default allowed MIME types.
		 *
		 * @return array Allowed MIME types.
		 */
		private function get_default_allowed_mimes() {
			return array(
				'image/jpeg',
				'image/png',
				'image/gif',
				'image/webp',
				'application/pdf',
				'text/plain',
				'text/csv',
				'application/json',
			);
		}

		/**
		 * Get allowed MIME types formatted for wp_handle_upload.
		 *
		 * @return array Allowed MIME types with extensions.
		 */
		private function get_allowed_mimes_for_upload() {
			return array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
				'pdf'          => 'application/pdf',
				'txt'          => 'text/plain',
				'csv'          => 'text/csv',
				'json'         => 'application/json',
			);
		}

		/**
		 * Set maximum file size.
		 *
		 * @param int $size Maximum file size in bytes.
		 * @return void
		 */
		public function set_max_file_size( $size ) {
			$this->max_file_size = absint( $size );
		}

		/**
		 * Set allowed MIME types.
		 *
		 * @param array $mimes Allowed MIME types.
		 * @return void
		 */
		public function set_allowed_mimes( $mimes ) {
			$this->allowed_mimes = $mimes;
		}

		/**
		 * Get maximum file size.
		 *
		 * @return int Maximum file size in bytes.
		 */
		public function get_max_file_size() {
			return $this->max_file_size;
		}

		/**
		 * Get allowed MIME types.
		 *
		 * @return array Allowed MIME types.
		 */
		public function get_allowed_mimes() {
			return $this->allowed_mimes;
		}
	}
}
