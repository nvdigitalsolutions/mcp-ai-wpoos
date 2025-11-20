<?php
/**
 * Gemini File Service
 *
 * Handles file upload, status checking, and deletion for Gemini File API.
 * Used primarily for video analysis via Gemini's vision models.
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
 * - Uploading files to Gemini File API
 * - Checking processing status
 * - Polling for completion
 * - Deleting files after use
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
}
