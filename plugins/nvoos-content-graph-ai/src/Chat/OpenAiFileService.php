<?php
/**
 * OpenAI File Service for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/services/class-wp-mcp-ai-openai-file-service.php` with the
 * upload/delete HTTP calls from
 * `includes/class-wp-mcp-ai-openai-client.php` folded in (the base
 * service delegates those calls to the base OpenAI client, which does
 * not exist in standalone installs — ecosystem port plan D-NOBASE).
 *
 * Cache keys, transients, tracking options, error codes, and the
 * multipart upload payload are byte-identical.
 *
 * Decoupling (documented, additive):
 * - API-key reads use the CG-AI `CredentialResolver` ('openai').
 * - Logging forwards to the base `WP_MCP_AI_Logger` in monolith installs
 *   only (CG-AI audit logger lands in Wave D4); this class is used by
 *   the bridge in standalone installs regardless.
 * - Transport errors replicate the base HTTP utility's default branch
 *   (original error under `data.error`); the full connection-
 *   refused/timeout enrichment joins when the HTTP utility is ported.
 *
 * @package NvoosContentGraphAi\Chat
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Chat;

use NvoosContentGraphAi\Adapter\CredentialResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI File Service class.
 *
 * @since 1.1.0
 */
class OpenAiFileService {

	const FILES_ENDPOINT = 'https://api.openai.com/v1/files';

	/**
	 * Get cached file information for any file type.
	 *
	 * @param string   $file_url      File URL (optional if attachment_id provided).
	 * @param int|null $attachment_id WordPress attachment ID (optional if file_url provided).
	 * @param string   $purpose       OpenAI file purpose (e.g., 'vision', 'assistants').
	 * @return array|null Cached file info or null if not found/expired.
	 */
	public function get_cached_file( $file_url = '', $attachment_id = null, $purpose = '' ) {
		$cache_key = $this->generate_cache_key( $file_url, $attachment_id, $purpose );

		if ( ! $cache_key ) {
			return null;
		}

		$cached_data = get_transient( $cache_key );

		if ( false === $cached_data ) {
			return null;
		}

		static::log_event(
			'openai_file_cache_hit',
			'Using cached OpenAI file instead of re-uploading.',
			array(
				'cache_key' => $cache_key,
				'file_id'   => $cached_data['file_id'],
				'purpose'   => $purpose,
			)
		);

		return $cached_data;
	}

	/**
	 * Track an uploaded file in cache.
	 *
	 * @param string   $file_id       OpenAI file ID.
	 * @param string   $purpose       OpenAI file purpose.
	 * @param string   $filename      File name.
	 * @param string   $file_url      File URL (optional if attachment_id provided).
	 * @param int|null $attachment_id WordPress attachment ID (optional if file_url provided).
	 * @return bool True on success, false on failure.
	 */
	public function track_uploaded_file( $file_id, $purpose, $filename, $file_url = '', $attachment_id = null ) {
		$cache_key = $this->generate_cache_key( $file_url, $attachment_id, $purpose );

		if ( ! $cache_key ) {
			return false;
		}

		$upload_time = time();
		$cache_data  = array(
			'file_id'       => $file_id,
			'purpose'       => $purpose,
			'filename'      => $filename,
			'uploaded_at'   => $upload_time,
			'file_url'      => $file_url,
			'attachment_id' => $attachment_id,
		);

		// Cache for 24 hours.
		$expiration = 24 * HOUR_IN_SECONDS;

		$result = set_transient( $cache_key, $cache_data, $expiration );

		if ( $result ) {
			// Also add to list of tracked files for cleanup.
			$this->add_to_tracked_files_list( $cache_key, $file_id, $upload_time );

			static::log_event(
				'openai_file_tracked',
				'File tracked in cache for reuse.',
				array(
					'cache_key' => $cache_key,
					'file_id'   => $file_id,
					'purpose'   => $purpose,
					'expires'   => gmdate( 'Y-m-d H:i:s', time() + $expiration ),
				)
			);
		}

		return $result;
	}

	/**
	 * Get list of all tracked files.
	 *
	 * @return array Array of tracked file information.
	 */
	public function list_tracked_files() {
		$tracked_files_list = get_option( 'wp_mcp_ai_openai_tracked_files', array() );
		$tracked_files      = array();

		foreach ( $tracked_files_list as $cache_key => $file_data ) {
			// Try to get cached data first.
			$cached_data = get_transient( $cache_key );

			if ( false !== $cached_data ) {
				// Transient still exists, use it.
				$cached_data['cache_key'] = $cache_key;
				$tracked_files[]          = $cached_data;
			} elseif ( is_array( $file_data ) && isset( $file_data['file_id'], $file_data['uploaded_at'] ) ) {
				// Transient expired, but we need the tracking data for cleanup.
				$tracked_files[] = array(
					'cache_key'   => $cache_key,
					'file_id'     => $file_data['file_id'],
					'uploaded_at' => $file_data['uploaded_at'],
				);
			}
		}

		return $tracked_files;
	}

	/**
	 * Cleanup old files from OpenAI and local cache.
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
				// Delete from OpenAI.
				$result = $this->delete_file( $file_info['file_id'] );

				if ( is_wp_error( $result ) ) {
					// Check if it's a 404 (file not found) - treat as successful cleanup.
					$error_data = $result->get_error_data();
					$status     = isset( $error_data['status'] ) ? $error_data['status'] : 0;

					if ( 404 === $status ) {
						// File already gone on OpenAI, clean up local tracking.
						++$deleted_count;

						if ( isset( $file_info['cache_key'] ) ) {
							delete_transient( $file_info['cache_key'] );
							$this->remove_from_tracked_files_list( $file_info['cache_key'] );
						}

						static::log_event(
							'openai_file_cleanup',
							'OpenAI file already deleted (404), cleaned up local tracking.',
							array(
								'file_id' => $file_info['file_id'],
								'age'     => $age,
							)
						);
					} else {
						++$failed_count;
						$errors[] = array(
							'file_id' => $file_info['file_id'],
							'error'   => $result->get_error_message(),
						);

						static::log_error(
							'Failed to delete old OpenAI file during cleanup.',
							array(
								'file_id' => $file_info['file_id'],
								'age'     => $age,
								'error'   => $result->get_error_message(),
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

					static::log_event(
						'openai_file_cleanup',
						'Old OpenAI file deleted during cleanup.',
						array(
							'file_id' => $file_info['file_id'],
							'age'     => $age,
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

		static::log_event(
			'openai_file_cleanup_complete',
			'OpenAI file cleanup completed.',
			$result
		);

		return $result;
	}

	/**
	 * Upload a file to the OpenAI File API (multipart).
	 *
	 * Folded in from the base plugin's
	 * `WP_MCP_AI_OpenAI_Client::upload_file()` — same endpoint, payload
	 * shape, and error codes.
	 *
	 * @param string $file_path Local file path.
	 * @param array  $args      Upload args (purpose, filename, mime_type, timeout).
	 * @return array|\WP_Error Upload result or error.
	 */
	public function upload_file( $file_path, array $args = array() ) {
		$api_key = $this->get_api_key();

		if ( is_wp_error( $api_key ) ) {
			return $api_key;
		}

		$file_path = (string) $file_path;

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new \WP_Error(
				'wp_mcp_ai_file_upload_missing_file',
				__( 'The file to upload could not be located.', 'nvoos-content-graph-ai' ),
				array( 'status' => 404 )
			);
		}

		$purpose   = isset( $args['purpose'] ) ? sanitize_key( $args['purpose'] ) : '';
		$filename  = isset( $args['filename'] ) ? sanitize_file_name( $args['filename'] ) : '';
		$mime_type = isset( $args['mime_type'] ) ? sanitize_mime_type( $args['mime_type'] ) : '';

		if ( '' === $purpose ) {
			$purpose = 'assistants';
		}

		if ( '' === $filename ) {
			$filename = wp_basename( $file_path );
		}

		if ( '' === $mime_type ) {
			$mime_type = 'application/octet-stream';
		}

		$timeout = isset( $args['timeout'] ) && '' !== $args['timeout'] ? absint( $args['timeout'] ) : 120;
		$timeout = max( 5, $timeout );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file for upload; no remote URL involved.
		$file_contents = file_get_contents( $file_path );

		if ( false === $file_contents ) {
			return new \WP_Error(
				'wp_mcp_ai_file_upload_read_failed',
				__( 'The file to upload could not be read.', 'nvoos-content-graph-ai' )
			);
		}

		$boundary = 'wp-mcp-ai-' . wp_generate_password( 24, false, false );

		$body  = '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="purpose"' . "\r\n\r\n";
		$body .= $purpose . "\r\n";
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
		$body .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
		$body .= $file_contents . "\r\n";
		$body .= '--' . $boundary . '--';

		$response = wp_remote_post(
			self::FILES_ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			return static::prepare_transport_error(
				$response,
				'wp_mcp_ai_file_upload_http_error',
				__( 'The OpenAI file upload failed to complete.', 'nvoos-content-graph-ai' ),
				__( 'OpenAI', 'nvoos-content-graph-ai' )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		$json_err = json_last_error();

		if ( JSON_ERROR_NONE !== $json_err ) {
			return new \WP_Error( 'wp_mcp_ai_file_upload_invalid_response', __( 'OpenAI returned malformed JSON for the file upload.', 'nvoos-content-graph-ai' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI file upload failed.', 'nvoos-content-graph-ai' );

			return new \WP_Error(
				'wp_mcp_ai_file_upload_failed',
				$message,
				array(
					'status' => $code,
					'body'   => $decoded,
				)
			);
		}

		return $decoded;
	}

	/**
	 * Delete a file from the OpenAI File API.
	 *
	 * @param string $file_id OpenAI file ID.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function delete_file( $file_id ) {
		$api_key = $this->get_api_key();

		if ( is_wp_error( $api_key ) ) {
			return $api_key;
		}

		$response = wp_remote_request(
			trailingslashit( self::FILES_ENDPOINT ) . rawurlencode( (string) $file_id ),
			array(
				'method'  => 'DELETE',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return static::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The OpenAI file deletion failed to complete.', 'nvoos-content-graph-ai' ),
				__( 'OpenAI', 'nvoos-content-graph-ai' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $code || 204 === $code ) {
			return true;
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		$message = isset( $decoded['error']['message'] )
			? $decoded['error']['message']
			: __( 'The OpenAI file deletion failed.', 'nvoos-content-graph-ai' );

		return new \WP_Error(
			'wp_mcp_ai_api_error',
			$message,
			array(
				'status' => $code,
				'body'   => $decoded,
			)
		);
	}

	/**
	 * Retrieve file metadata from the OpenAI File API.
	 *
	 * @param string $file_id OpenAI file ID.
	 * @return array|\WP_Error File metadata or error.
	 */
	public function retrieve_file( $file_id ) {
		$api_key = $this->get_api_key();

		if ( is_wp_error( $api_key ) ) {
			return $api_key;
		}

		$file_id = sanitize_text_field( (string) $file_id );

		if ( '' === $file_id ) {
			return new \WP_Error( 'wp_mcp_ai_missing_file_id', __( 'A file identifier must be supplied.', 'nvoos-content-graph-ai' ) );
		}

		$response = wp_remote_get(
			trailingslashit( self::FILES_ENDPOINT ) . rawurlencode( $file_id ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return static::prepare_transport_error(
				$response,
				'wp_mcp_ai_retrieve_file_http_error',
				__( 'The OpenAI file metadata request failed.', 'nvoos-content-graph-ai' ),
				__( 'OpenAI', 'nvoos-content-graph-ai' )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error( 'wp_mcp_ai_retrieve_file_invalid_response', __( 'OpenAI returned malformed JSON for the file metadata.', 'nvoos-content-graph-ai' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI file metadata request failed.', 'nvoos-content-graph-ai' );

			return new \WP_Error(
				'wp_mcp_ai_retrieve_file_error',
				$message,
				array(
					'status'   => $code,
					'response' => $decoded,
				)
			);
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Download a file from the OpenAI File API.
	 *
	 * @param string $file_id OpenAI file ID.
	 * @return array|\WP_Error Download payload (`body`, `filename`,
	 *                         `content_type`) or error.
	 */
	public function download_file( $file_id ) {
		$api_key = $this->get_api_key();

		if ( is_wp_error( $api_key ) ) {
			return $api_key;
		}

		$file_id = sanitize_text_field( (string) $file_id );

		if ( '' === $file_id ) {
			return new \WP_Error( 'wp_mcp_ai_missing_file_id', __( 'A file identifier must be supplied.', 'nvoos-content-graph-ai' ) );
		}

		$response = wp_remote_get(
			trailingslashit( self::FILES_ENDPOINT ) . rawurlencode( $file_id ) . '/content',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return static::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The OpenAI file could not be downloaded.', 'nvoos-content-graph-ai' ),
				__( 'OpenAI', 'nvoos-content-graph-ai' )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$decoded = json_decode( $body, true );
			$message = __( 'OpenAI returned an unexpected response while downloading the file.', 'nvoos-content-graph-ai' );

			if ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) && '' !== $decoded['error']['message'] ) {
				$message = $decoded['error']['message'];
			}

			return new \WP_Error(
				'wp_mcp_ai_file_download_failed',
				$message,
				array(
					'status'  => $status_code,
					'file_id' => $file_id,
					'body'    => $decoded,
				)
			);
		}

		if ( '' === $body ) {
			return new \WP_Error( 'wp_mcp_ai_file_download_empty', __( 'The downloaded OpenAI file was empty.', 'nvoos-content-graph-ai' ) );
		}

		$headers = wp_remote_retrieve_headers( $response );
		if ( $headers instanceof \WP_HTTP_Headers ) {
			$headers = $headers->getAll();
		}

		$normalised_headers = array();
		if ( is_array( $headers ) ) {
			foreach ( $headers as $key => $value ) {
				$key                        = strtolower( (string) $key );
				$value                      = is_array( $value ) ? implode( ',', $value ) : (string) $value;
				$normalised_headers[ $key ] = $value;
			}
		}

		$content_type = isset( $normalised_headers['content-type'] ) ? $normalised_headers['content-type'] : 'application/octet-stream';
		$filename     = '';

		if ( isset( $normalised_headers['content-disposition'] ) ) {
			$filename = $this->parse_content_disposition_filename( $normalised_headers['content-disposition'] );
		}

		return array(
			'body'         => $body,
			'headers'      => $normalised_headers,
			'content_type' => $content_type,
			'filename'     => $filename,
			'status_code'  => $status_code,
			'file_id'      => $file_id,
		);
	}

	/**
	 * Parse a filename out of a Content-Disposition header value.
	 *
	 * @param string $header Content-Disposition header value.
	 * @return string Filename or empty string.
	 */
	private function parse_content_disposition_filename( $header ) {
		if ( preg_match( '/filename\s*=\s*"([^"]+)"/i', $header, $matches ) ) {
			return sanitize_file_name( $matches[1] );
		}

		if ( preg_match( "/filename\s*=\s*([^;]+)/i", $header, $matches ) ) {
			return sanitize_file_name( trim( $matches[1] ) );
		}

		return '';
	}

	/**
	 * Resolve the OpenAI API key via the CG-AI credential resolver.
	 *
	 * @return string|\WP_Error
	 */
	protected function get_api_key() {
		$resolved = CredentialResolver::getApiKey( 'openai' );
		$api_key  = null !== $resolved ? $resolved : '';

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'No OpenAI API key has been configured.', 'nvoos-content-graph-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		return $api_key;
	}

	/**
	 * Generate a cache key for a file.
	 *
	 * @param string   $file_url      File URL.
	 * @param int|null $attachment_id WordPress attachment ID.
	 * @param string   $purpose       OpenAI file purpose.
	 * @return string|null Cache key or null if invalid input.
	 */
	private function generate_cache_key( $file_url = '', $attachment_id = null, $purpose = '' ) {
		$purpose_suffix = ! empty( $purpose ) ? '_' . sanitize_key( $purpose ) : '';

		if ( $attachment_id ) {
			$attachment_id = absint( $attachment_id );

			$file_path = get_attached_file( $attachment_id );
			$mod_time  = '';

			if ( $file_path && file_exists( $file_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filemtime -- filemtime() used for cache-busting.
				$mod_time = filemtime( $file_path );
			}

			return 'wp_mcp_ai_openai_file_' . $attachment_id . '_' . $mod_time . $purpose_suffix;
		}

		if ( ! empty( $file_url ) ) {
			return 'wp_mcp_ai_openai_file_' . md5( $file_url ) . $purpose_suffix;
		}

		return null;
	}

	/**
	 * Add file to tracked files list.
	 *
	 * @param string $cache_key   Cache key.
	 * @param string $file_id     OpenAI file ID.
	 * @param int    $uploaded_at Upload timestamp.
	 * @return void
	 */
	private function add_to_tracked_files_list( $cache_key, $file_id, $uploaded_at ) {
		$tracked_files               = get_option( 'wp_mcp_ai_openai_tracked_files', array() );
		$tracked_files[ $cache_key ] = array(
			'file_id'     => $file_id,
			'uploaded_at' => $uploaded_at,
		);
		update_option( 'wp_mcp_ai_openai_tracked_files', $tracked_files, false );
	}

	/**
	 * Remove file from tracked files list.
	 *
	 * @param string $cache_key Cache key.
	 * @return void
	 */
	private function remove_from_tracked_files_list( $cache_key ) {
		$tracked_files = get_option( 'wp_mcp_ai_openai_tracked_files', array() );

		if ( isset( $tracked_files[ $cache_key ] ) ) {
			unset( $tracked_files[ $cache_key ] );
			update_option( 'wp_mcp_ai_openai_tracked_files', $tracked_files, false );
		}
	}

	/**
	 * Log an event through the base plugin's logger (monolith only).
	 *
	 * @param string $event   Event identifier.
	 * @param string $message Human-readable message.
	 * @param array  $data    Optional event data.
	 * @return void
	 */
	protected static function log_event( $event, $message, $data = array() ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $event, $message, $data );
		}
	}

	/**
	 * Log an error through the base plugin's logger (monolith only).
	 *
	 * @param string $message Human-readable message.
	 * @param array  $data    Optional error data.
	 * @return void
	 */
	protected static function log_error( $message, $data = array() ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_error( $message, $data );
		}
	}

	/**
	 * Prepare a transport error (default-branch replication of the base
	 * HTTP utility; full enrichment joins when it is ported).
	 *
	 * @param \WP_Error $transport_error Transport-level error.
	 * @param string    $default_code    Fallback error code.
	 * @param string    $default_message Fallback error message.
	 * @param string    $service_label   Human-readable service name.
	 * @return \WP_Error
	 */
	protected static function prepare_transport_error( $transport_error, $default_code, $default_message, $service_label = '' ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_HTTP' ) ) {
			return \WP_MCP_AI_HTTP::prepare_transport_error(
				$transport_error,
				$default_code,
				$default_message,
				$service_label
			);
		}

		if ( ! $transport_error instanceof \WP_Error ) {
			return new \WP_Error( $default_code, $default_message );
		}

		return new \WP_Error(
			$default_code,
			$default_message,
			array( 'error' => $transport_error )
		);
	}
}
