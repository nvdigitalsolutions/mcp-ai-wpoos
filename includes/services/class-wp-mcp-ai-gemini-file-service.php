<?php
/**
 * Gemini File Service
 *
 * Handles file upload, status checking, deletion, and caching for Gemini File API.
 * Supports all file types: videos (MP4, MOV, WebM), images (PNG, JPG, GIF, WebP),
 * documents (PDF, TXT, HTML, CSV, XML, RTF), and audio files.
 *
 * Features:
 * - File upload with multipart/related encoding
 * - Automatic file caching to avoid re-uploads
 * - Processing status polling
 * - Cleanup of old files via cron
 * - Support for WordPress attachments and remote URLs
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini File Service class
 *
 * Responsible for:
 * - Uploading files to Gemini File API (videos, images, PDFs, audio, etc.)
 * - Caching uploaded files to avoid duplicate uploads
 * - Checking processing status
 * - Polling for completion
 * - Deleting files after use
 * - Scheduled cleanup of old files
 *
 * Supported File Types:
 * - Videos: MP4, MOV, MPEG, AVI, WebM, 3GPP, WMV, FLV, MKV
 * - Images: PNG, JPEG, GIF, WebP, BMP, ICO
 * - Documents: PDF, TXT, HTML, CSS, JavaScript, CSV, XML, RTF
 * - Audio: WAV, MP3, AIFF, AAC, OGG, FLAC
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Gemini_File_Service {

	const API_UPLOAD_ENDPOINT = 'https://generativelanguage.googleapis.com/upload/v1beta/files';
	const API_FILES_ENDPOINT  = 'https://generativelanguage.googleapis.com/v1beta/files';

	/**
	 * Maximum polling attempts for file processing
	 *
	 * @var int
	 */
	private $max_polling_attempts = 60;

	/**
	 * Delay between polling attempts in seconds
	 *
	 * @var int
	 */
	private $polling_delay = 5;

	/**
	 * Constructor
	 *
	 * @param int $max_polling_attempts Maximum number of polling attempts.
	 * @param int $polling_delay        Delay between polls in seconds.
	 */
	public function __construct( $max_polling_attempts = 60, $polling_delay = 5 ) {
		$this->max_polling_attempts = absint( $max_polling_attempts );
		$this->polling_delay        = absint( $polling_delay );
	}

	/**
	 * Upload a file to Gemini File API
	 *
	 * @param string $file_path    Local file path to upload.
	 * @param string $mime_type    MIME type of the file.
	 * @param string $display_name Display name for the file.
	 * @return array|WP_Error Upload result with file name and URI, or error.
	 */
	public function upload_file( $file_path, $mime_type, $display_name = '' ) {
		// Validate inputs.
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'File not found on server.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		if ( empty( $mime_type ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_mime_type',
				__( 'MIME type is required for file upload.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Get API key.
		$api_key = $this->get_api_key();
		if ( is_wp_error( $api_key ) ) {
			return $api_key;
		}

		// Prepare display name.
		if ( empty( $display_name ) ) {
			$display_name = basename( $file_path );
		}

		// Read file content (local file, not remote URL).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$file_content = file_get_contents( $file_path );
		if ( false === $file_content ) {
			return new WP_Error(
				'wp_mcp_ai_file_read_error',
				__( 'Failed to read file content.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Build multipart request.
		$boundary = wp_generate_password( 24, false );
		$body     = $this->build_multipart_body( $file_content, $mime_type, $display_name, $boundary );

		// Build URL with API key.
		$url = self::API_UPLOAD_ENDPOINT;

		$request_args = array(
			'headers' => array(
				'Content-Type'   => 'multipart/related; boundary=' . $boundary,
				'x-goog-api-key' => $api_key,
			),
			'body'    => $body,
			'timeout' => 300, // 5 minutes for large files.
		);

		WP_MCP_AI_Logger::log_event(
			'gemini_file_upload',
			'Uploading file to Gemini File API.',
			array(
				'display_name' => $display_name,
				'mime_type'    => $mime_type,
				'file_size'    => strlen( $file_content ),
			)
		);

		// Make request.
		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				'Gemini file upload failed.',
				array( 'error' => $response->get_error_message() )
			);

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The Gemini File API upload request failed to complete.', 'wp-mcp-ai' ),
				__( 'Gemini File API', 'wp-mcp-ai' )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to decode Gemini file upload response.',
				array( 'body' => $body )
			);

			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'The Gemini File API returned malformed JSON.', 'wp-mcp-ai' )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: __( 'Unexpected response from Gemini File API.', 'wp-mcp-ai' );

			WP_MCP_AI_Logger::log_error(
				'Gemini File API returned an error response.',
				array(
					'code' => $code,
					'body' => $decoded,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_api_error',
				$error_message,
				array(
					'status' => $code,
					'body'   => $decoded,
				)
			);
		}

		// Extract file information from response.
		if ( ! isset( $decoded['file'] ) || ! isset( $decoded['file']['name'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Gemini File API response missing file information.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		$file_name  = $decoded['file']['name'];
		$file_uri   = isset( $decoded['file']['uri'] ) ? $decoded['file']['uri'] : '';
		$file_state = isset( $decoded['file']['state'] ) ? $decoded['file']['state'] : 'UNKNOWN';

		WP_MCP_AI_Logger::log_event(
			'gemini_file_uploaded',
			'File uploaded to Gemini File API successfully.',
			array(
				'file_name'  => $file_name,
				'file_state' => $file_state,
			)
		);

		return array(
			'file_name'  => $file_name,
			'file_uri'   => $file_uri,
			'state'      => $file_state,
			'mime_type'  => $mime_type,
			'uploaded'   => true,
			'created_at' => time(),
		);
	}

	/**
	 * Get file status from Gemini File API
	 *
	 * @param string $file_name File name (e.g., "files/abc123").
	 * @return array|WP_Error File status information or error.
	 */
	public function get_file_status( $file_name ) {
		// Validate input.
		if ( empty( $file_name ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_file_name',
				__( 'File name is required.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Get API key.
		$api_key = $this->get_api_key();
		if ( is_wp_error( $api_key ) ) {
			return $api_key;
		}

		// Build URL.
		$url = trailingslashit( self::API_FILES_ENDPOINT ) . rawurlencode( $file_name );

		$request_args = array(
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'timeout' => 30,
		);

		// Make request.
		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				'Gemini file status check failed.',
				array( 'error' => $response->get_error_message() )
			);

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The Gemini File API status request failed to complete.', 'wp-mcp-ai' ),
				__( 'Gemini File API', 'wp-mcp-ai' )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to decode Gemini file status response.',
				array( 'body' => $body )
			);

			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'The Gemini File API returned malformed JSON.', 'wp-mcp-ai' )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: __( 'Unexpected response from Gemini File API.', 'wp-mcp-ai' );

			WP_MCP_AI_Logger::log_error(
				'Gemini File API returned an error response for status check.',
				array(
					'code' => $code,
					'body' => $decoded,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_api_error',
				$error_message,
				array(
					'status' => $code,
					'body'   => $decoded,
				)
			);
		}

		return array(
			'name'  => isset( $decoded['name'] ) ? $decoded['name'] : '',
			'uri'   => isset( $decoded['uri'] ) ? $decoded['uri'] : '',
			'state' => isset( $decoded['state'] ) ? $decoded['state'] : 'UNKNOWN',
		);
	}

	/**
	 * Wait for file processing to complete
	 *
	 * Polls the file status until it's ACTIVE or an error occurs.
	 *
	 * @param string $file_name File name to poll.
	 * @param int    $timeout   Maximum time to wait in seconds. Default 300 (5 minutes).
	 * @return array|WP_Error File status when active, or error.
	 */
	public function wait_for_processing( $file_name, $timeout = 300 ) {
		$timeout      = absint( $timeout );
		$max_attempts = min( $this->max_polling_attempts, ceil( $timeout / $this->polling_delay ) );
		$attempt      = 0;
		$start_time   = time();

		WP_MCP_AI_Logger::log_event(
			'gemini_file_polling_start',
			'Starting to poll for file processing completion.',
			array(
				'file_name'    => $file_name,
				'timeout'      => $timeout,
				'max_attempts' => $max_attempts,
			)
		);

		while ( $attempt < $max_attempts ) {
			++$attempt;

			// Check if we've exceeded timeout.
			if ( ( time() - $start_time ) > $timeout ) {
				WP_MCP_AI_Logger::log_error(
					'Gemini file processing timeout.',
					array(
						'file_name' => $file_name,
						'attempts'  => $attempt,
						'elapsed'   => time() - $start_time,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_processing_timeout',
					__( 'File processing timed out. The file may still be processing.', 'wp-mcp-ai' ),
					array( 'status' => 408 )
				);
			}

			// Get file status.
			$status = $this->get_file_status( $file_name );

			if ( is_wp_error( $status ) ) {
				return $status;
			}

			$state = isset( $status['state'] ) ? $status['state'] : 'UNKNOWN';

			// Check if processing is complete.
			if ( 'ACTIVE' === $state ) {
				WP_MCP_AI_Logger::log_event(
					'gemini_file_processing_complete',
					'File processing completed successfully.',
					array(
						'file_name' => $file_name,
						'attempts'  => $attempt,
						'elapsed'   => time() - $start_time,
					)
				);

				return $status;
			}

			// Check for failed state.
			if ( 'FAILED' === $state ) {
				WP_MCP_AI_Logger::log_error(
					'Gemini file processing failed.',
					array( 'file_name' => $file_name )
				);

				return new WP_Error(
					'wp_mcp_ai_processing_failed',
					__( 'File processing failed on Gemini servers.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}

			// Still processing, wait before next attempt.
			if ( $attempt < $max_attempts ) {
				sleep( $this->polling_delay );
			}
		}

		// Max attempts reached.
		WP_MCP_AI_Logger::log_error(
			'Gemini file processing max attempts reached.',
			array(
				'file_name' => $file_name,
				'attempts'  => $attempt,
			)
		);

		return new WP_Error(
			'wp_mcp_ai_max_attempts_reached',
			__( 'Maximum polling attempts reached while waiting for file processing.', 'wp-mcp-ai' ),
			array( 'status' => 408 )
		);
	}

	/**
	 * Delete a file from Gemini File API
	 *
	 * @param string $file_name File name to delete.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function delete_file( $file_name ) {
		// Validate input.
		if ( empty( $file_name ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_file_name',
				__( 'File name is required.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Get API key.
		$api_key = $this->get_api_key();
		if ( is_wp_error( $api_key ) ) {
			return $api_key;
		}

		// Build URL.
		$url = trailingslashit( self::API_FILES_ENDPOINT ) . rawurlencode( $file_name );

		$request_args = array(
			'method'  => 'DELETE',
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'timeout' => 30,
		);

		WP_MCP_AI_Logger::log_event(
			'gemini_file_delete',
			'Deleting file from Gemini File API.',
			array( 'file_name' => $file_name )
		);

		// Make request.
		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				'Gemini file deletion failed.',
				array( 'error' => $response->get_error_message() )
			);

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The Gemini File API deletion request failed to complete.', 'wp-mcp-ai' ),
				__( 'Gemini File API', 'wp-mcp-ai' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		// 204 No Content is the success response for DELETE.
		if ( 204 === $code || 200 === $code ) {
			WP_MCP_AI_Logger::log_event(
				'gemini_file_deleted',
				'File deleted from Gemini File API successfully.',
				array( 'file_name' => $file_name )
			);

			return true;
		}

		// Handle errors.
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE === json_last_error() && isset( $decoded['error']['message'] ) ) {
			$error_message = $decoded['error']['message'];
		} else {
			$error_message = __( 'Unexpected response from Gemini File API.', 'wp-mcp-ai' );
		}

		WP_MCP_AI_Logger::log_error(
			'Gemini File API returned an error response for deletion.',
			array(
				'code' => $code,
				'body' => $decoded,
			)
		);

		return new WP_Error(
			'wp_mcp_ai_api_error',
			$error_message,
			array(
				'status' => $code,
				'body'   => $decoded,
			)
		);
	}

	/**
	 * Build multipart body for file upload
	 *
	 * @param string $file_content File content.
	 * @param string $mime_type    MIME type.
	 * @param string $display_name Display name.
	 * @param string $boundary     Multipart boundary.
	 * @return string Multipart body.
	 */
	private function build_multipart_body( $file_content, $mime_type, $display_name, $boundary ) {
		$eol = "\r\n";

		// Metadata part.
		$metadata = array(
			'file' => array(
				'displayName' => $display_name,
			),
		);

		$body  = '--' . $boundary . $eol;
		$body .= 'Content-Type: application/json; charset=UTF-8' . $eol;
		$body .= $eol;
		$body .= wp_json_encode( $metadata ) . $eol;

		// File data part.
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Type: ' . $mime_type . $eol;
		$body .= $eol;
		$body .= $file_content . $eol;

		// End boundary.
		$body .= '--' . $boundary . '--' . $eol;

		return $body;
	}

	/**
	 * Get Gemini API key
	 *
	 * @return string|WP_Error API key or error.
	 */
	private function get_api_key() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$api_key  = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_gemini_api_key',
				__( 'No Gemini API key has been configured.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_gemini_api_key' => __( 'Add a Gemini API key in the WP oOS settings.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		return $api_key;
	}

	/**
	 * Get cached file information for any file type
	 *
	 * Checks if a file has been uploaded recently and returns the cached file info.
	 * Uses file URL or attachment ID hash as the cache key.
	 * Works for all file types: videos, images, PDFs, audio, documents.
	 *
	 * @param string   $file_url      File URL (optional if attachment_id provided).
	 * @param int|null $attachment_id WordPress attachment ID (optional if file_url provided).
	 * @return array|null Cached file info or null if not found/expired.
	 */
	public function get_cached_file( $file_url = '', $attachment_id = null ) {
		$cache_key = $this->generate_cache_key( $file_url, $attachment_id );

		if ( ! $cache_key ) {
			return null;
		}

		$cached_data = get_transient( $cache_key );

		if ( false === $cached_data ) {
			return null;
		}

		// Verify the file still exists on Gemini's servers.
		$status = $this->get_file_status( $cached_data['file_name'] );

		if ( is_wp_error( $status ) || 'ACTIVE' !== ( isset( $status['state'] ) ? $status['state'] : '' ) ) {
			// File no longer available, delete the cache.
			delete_transient( $cache_key );
			return null;
		}

		WP_MCP_AI_Logger::log_event(
			'gemini_file_cache_hit',
			'Using cached Gemini file instead of re-uploading.',
			array(
				'cache_key' => $cache_key,
				'file_name' => $cached_data['file_name'],
				'mime_type' => isset( $cached_data['mime_type'] ) ? $cached_data['mime_type'] : '',
			)
		);

		return $cached_data;
	}

	/**
	 * Track an uploaded file in cache
	 *
	 * Stores file information in a transient for reuse.
	 * Cache expires after 24 hours (86400 seconds).
	 * Works for all file types: videos, images, PDFs, audio, documents.
	 *
	 * @param string   $file_name     Gemini file name.
	 * @param string   $file_uri      Gemini file URI.
	 * @param string   $mime_type     File MIME type.
	 * @param string   $file_url      File URL (optional if attachment_id provided).
	 * @param int|null $attachment_id WordPress attachment ID (optional if file_url provided).
	 * @return bool True on success, false on failure.
	 */
	public function track_uploaded_file( $file_name, $file_uri, $mime_type, $video_url = '', $attachment_id = null ) {
		$cache_key = $this->generate_cache_key( $video_url, $attachment_id );

		if ( ! $cache_key ) {
			return false;
		}

		$upload_time = time();
		$cache_data  = array(
			'file_name'     => $file_name,
			'file_uri'      => $file_uri,
			'mime_type'     => $mime_type,
			'uploaded_at'   => $upload_time,
			'video_url'     => $video_url,
			'attachment_id' => $attachment_id,
		);

		// Cache for 24 hours (Gemini files are auto-deleted after 48 hours).
		$expiration = 24 * HOUR_IN_SECONDS;

		$result = set_transient( $cache_key, $cache_data, $expiration );

		if ( $result ) {
			// Also add to list of tracked files for cleanup.
			// Store upload time in tracking list to persist beyond transient expiration.
			$this->add_to_tracked_files_list( $cache_key, $file_name, $upload_time );

			WP_MCP_AI_Logger::log_event(
				'gemini_file_tracked',
				'File tracked in cache for reuse.',
				array(
					'cache_key' => $cache_key,
					'file_name' => $file_name,
					'expires'   => gmdate( 'Y-m-d H:i:s', time() + $expiration ),
				)
			);
		}

		return $result;
	}

	/**
	 * Get list of all tracked files
	 *
	 * Returns all files currently tracked, including those whose transients have expired.
	 * This ensures cleanup can still delete old files after cache expiration.
	 *
	 * @return array Array of tracked file information.
	 */
	public function list_tracked_files() {
		$tracked_files_list = get_option( 'wp_mcp_ai_gemini_tracked_files', array() );
		$tracked_files      = array();

		foreach ( $tracked_files_list as $cache_key => $file_data ) {
			// Try to get cached data first.
			$cached_data = get_transient( $cache_key );

			if ( false !== $cached_data ) {
				// Transient still exists, use it.
				$cached_data['cache_key'] = $cache_key;
				$tracked_files[]          = $cached_data;
			} else {
				// Transient expired, but we need the tracking data for cleanup.
				// Use the file_name and uploaded_at from the tracking list.
				if ( is_array( $file_data ) && isset( $file_data['file_name'], $file_data['uploaded_at'] ) ) {
					$tracked_files[] = array(
						'cache_key'   => $cache_key,
						'file_name'   => $file_data['file_name'],
						'uploaded_at' => $file_data['uploaded_at'],
					);
				}
			}
		}

		return $tracked_files;
	}

	/**
	 * Cleanup old files from Gemini and local cache
	 *
	 * Removes files older than specified age from Gemini File API
	 * and clears associated transients.
	 *
	 * @param int $max_age_seconds Maximum age in seconds. Default 24 hours.
	 * @return array Cleanup results with counts of deleted files.
	 */
	public function cleanup_old_files( $max_age_seconds = 86400 ) {
		$tracked_files = $this->list_tracked_files();
		$current_time  = time();
		$deleted_count = 0;
		$failed_count  = 0;
		$errors        = array();

		foreach ( $tracked_files as $file_info ) {
			$age = $current_time - ( isset( $file_info['uploaded_at'] ) ? $file_info['uploaded_at'] : 0 );

			if ( $age > $max_age_seconds ) {
				// Delete from Gemini.
				$result = $this->delete_file( $file_info['file_name'] );

				if ( is_wp_error( $result ) ) {
					// Check if it's a 404 (file not found) - treat as successful cleanup.
					$error_data = $result->get_error_data();
					$status     = isset( $error_data['status'] ) ? $error_data['status'] : 0;

					if ( 404 === $status ) {
						// File already gone on Gemini, clean up local tracking.
						++$deleted_count;

						if ( isset( $file_info['cache_key'] ) ) {
							delete_transient( $file_info['cache_key'] );
							$this->remove_from_tracked_files_list( $file_info['cache_key'] );
						}

						WP_MCP_AI_Logger::log_event(
							'gemini_file_cleanup',
							'Gemini file already deleted (404), cleaned up local tracking.',
							array(
								'file_name' => $file_info['file_name'],
								'age'       => $age,
							)
						);
					} else {
						++$failed_count;
						$errors[] = array(
							'file_name' => $file_info['file_name'],
							'error'     => $result->get_error_message(),
						);

						WP_MCP_AI_Logger::log_error(
							'Failed to delete old Gemini file during cleanup.',
							array(
								'file_name' => $file_info['file_name'],
								'age'       => $age,
								'error'     => $result->get_error_message(),
							)
						);
					}
				} else {
					++$deleted_count;

					// Delete from cache.
					if ( isset( $file_info['cache_key'] ) ) {
						delete_transient( $file_info['cache_key'] );
						$this->remove_from_tracked_files_list( $file_info['cache_key'] );
					}

					WP_MCP_AI_Logger::log_event(
						'gemini_file_cleanup',
						'Old Gemini file deleted during cleanup.',
						array(
							'file_name' => $file_info['file_name'],
							'age'       => $age,
						)
					);
				}
			}
		}

		$result = array(
			'deleted_count' => $deleted_count,
			'failed_count'  => $failed_count,
			'total_checked' => count( $tracked_files ),
		);

		if ( ! empty( $errors ) ) {
			$result['errors'] = $errors;
		}

		WP_MCP_AI_Logger::log_event(
			'gemini_file_cleanup_complete',
			'Gemini file cleanup completed.',
			$result
		);

		return $result;
	}

	/**
	 * Generate a cache key for a video
	 *
	 * Generates a unique cache key based on video URL or attachment ID.
	 * For attachments, includes the file modification time to ensure cache
	 * invalidation when the file is updated.
	 *
	 * Works for all video types: MP4, MOV, WebM, AVI, MKV, etc.
	 *
	 * @param string   $video_url     Video URL.
	 * @param int|null $attachment_id WordPress attachment ID.
	 * @return string|null Cache key or null if invalid input.
	 */
	private function generate_cache_key( $video_url = '', $attachment_id = null ) {
		if ( $attachment_id ) {
			$attachment_id = absint( $attachment_id );

			// Include file modification time to invalidate cache if file changes.
			$file_path = get_attached_file( $attachment_id );
			$mod_time  = '';

			if ( $file_path && file_exists( $file_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filemtime
				$mod_time = filemtime( $file_path );
			}

			return 'wp_mcp_ai_gemini_file_' . $attachment_id . '_' . $mod_time;
		}

		if ( ! empty( $video_url ) ) {
			return 'wp_mcp_ai_gemini_file_' . md5( $video_url );
		}

		return null;
	}

	/**
	 * Add file to tracked files list
	 *
	 * @param string $cache_key    Cache key.
	 * @param string $file_name    Gemini file name.
	 * @param int    $uploaded_at  Upload timestamp.
	 */
	private function add_to_tracked_files_list( $cache_key, $file_name, $uploaded_at ) {
		$tracked_files               = get_option( 'wp_mcp_ai_gemini_tracked_files', array() );
		$tracked_files[ $cache_key ] = array(
			'file_name'   => $file_name,
			'uploaded_at' => $uploaded_at,
		);
		update_option( 'wp_mcp_ai_gemini_tracked_files', $tracked_files, false );
	}

	/**
	 * Remove file from tracked files list
	 *
	 * @param string $cache_key Cache key.
	 */
	private function remove_from_tracked_files_list( $cache_key ) {
		$tracked_files = get_option( 'wp_mcp_ai_gemini_tracked_files', array() );

		if ( isset( $tracked_files[ $cache_key ] ) ) {
			unset( $tracked_files[ $cache_key ] );
			update_option( 'wp_mcp_ai_gemini_tracked_files', $tracked_files, false );
		}
	}
}
