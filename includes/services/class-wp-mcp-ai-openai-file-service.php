<?php
/**
 * OpenAI File Service
 *
 * Handles file upload, deletion, and caching for OpenAI File API.
 * Supports all file types: images (PNG, JPG, GIF, WebP), documents (PDF, TXT, etc.),
 * and other formats supported by OpenAI's vision and assistant APIs.
 *
 * Features:
 * - File upload with purpose-based categorization
 * - Automatic file caching to avoid re-uploads
 * - File deletion
 * - Cleanup of old files via cron
 * - Support for WordPress attachments and local files
 *
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI File Service class
 *
 * Responsible for:
 * - Uploading files to OpenAI File API (images, documents, etc.)
 * - Caching uploaded files to avoid duplicate uploads
 * - Deleting files after use
 * - Scheduled cleanup of old files
 *
 * Supported File Types:
 * - Images: PNG, JPEG, GIF, WebP (for vision API)
 * - Documents: PDF, TXT, DOCX, etc. (for assistants API)
 * - Code: Python, JavaScript, JSON, etc. (for code interpreter)
 * - Data: CSV, JSON, JSONL (for fine-tuning and analysis)
 *
 * @since 1.0.0
 */
class WP_MCP_AI_OpenAI_File_Service {

	/**
	 * Get cached file information for any file type
	 *
	 * Checks if a file has been uploaded recently and returns the cached file info.
	 * Uses file URL or attachment ID hash as the cache key.
	 * Works for all file types: images, PDFs, documents, etc.
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

		// For OpenAI, we could verify the file still exists, but that requires an API call.
		// For now, we trust the cache expiration (24 hours).
		// Files on OpenAI persist until explicitly deleted.

		WP_MCP_AI_Logger::log_event(
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
	 * Track an uploaded file in cache
	 *
	 * Stores file information in a transient for reuse.
	 * Cache expires after 24 hours (86400 seconds).
	 * Works for all file types: images, PDFs, documents, etc.
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
			// Store upload time in tracking list to persist beyond transient expiration.
			$this->add_to_tracked_files_list( $cache_key, $file_id, $upload_time );

			WP_MCP_AI_Logger::log_event(
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
	 * Get list of all tracked files
	 *
	 * Returns all files currently tracked, including those whose transients have expired.
	 * This ensures cleanup can still delete old files after cache expiration.
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
			} else {
				// Transient expired, but we need the tracking data for cleanup.
				// Use the file_id and uploaded_at from the tracking list.
				if ( is_array( $file_data ) && isset( $file_data['file_id'], $file_data['uploaded_at'] ) ) {
					$tracked_files[] = array(
						'cache_key'   => $cache_key,
						'file_id'     => $file_data['file_id'],
						'uploaded_at' => $file_data['uploaded_at'],
					);
				}
			}
		}

		return $tracked_files;
	}

	/**
	 * Cleanup old files from OpenAI and local cache
	 *
	 * Removes files older than specified age from OpenAI File API
	 * and clears associated transients.
	 *
	 * @param int $max_age_seconds Maximum age in seconds. Default 24 hours.
	 * @return array Cleanup results with counts of deleted files.
	 */
	public function cleanup_old_files( $max_age_seconds = 86400 ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';

		$tracked_files = $this->list_tracked_files();
		$current_time  = time();
		$deleted_count = 0;
		$failed_count  = 0;
		$errors        = array();

		$client = new WP_MCP_AI_OpenAI_Client();

		foreach ( $tracked_files as $file_info ) {
			$age = $current_time - ( isset( $file_info['uploaded_at'] ) ? $file_info['uploaded_at'] : 0 );

			if ( $age > $max_age_seconds ) {
				// Delete from OpenAI.
				$result = $client->delete_file( $file_info['file_id'] );

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

						WP_MCP_AI_Logger::log_event(
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

						WP_MCP_AI_Logger::log_error(
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

					WP_MCP_AI_Logger::log_event(
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

		WP_MCP_AI_Logger::log_event(
			'openai_file_cleanup_complete',
			'OpenAI file cleanup completed.',
			$result
		);

		return $result;
	}

	/**
	 * Generate a cache key for a file
	 *
	 * Generates a unique cache key based on file URL or attachment ID.
	 * For attachments, includes the file modification time to ensure cache
	 * invalidation when the file is updated.
	 *
	 * Works for all file types: images, PDFs, documents, etc.
	 *
	 * @param string   $file_url      File URL.
	 * @param int|null $attachment_id WordPress attachment ID.
	 * @param string   $purpose       OpenAI file purpose (included in key for separation).
	 * @return string|null Cache key or null if invalid input.
	 */
	private function generate_cache_key( $file_url = '', $attachment_id = null, $purpose = '' ) {
		$purpose_suffix = ! empty( $purpose ) ? '_' . sanitize_key( $purpose ) : '';

		if ( $attachment_id ) {
			$attachment_id = absint( $attachment_id );

			// Include file modification time to invalidate cache if file changes.
			$file_path = get_attached_file( $attachment_id );
			$mod_time  = '';

			if ( $file_path && file_exists( $file_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filemtime.
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
	 * Add file to tracked files list
	 *
	 * @param string $cache_key    Cache key.
	 * @param string $file_id      OpenAI file ID.
	 * @param int    $uploaded_at  Upload timestamp.
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
	 * Remove file from tracked files list
	 *
	 * @param string $cache_key Cache key.
	 */
	private function remove_from_tracked_files_list( $cache_key ) {
		$tracked_files = get_option( 'wp_mcp_ai_openai_tracked_files', array() );

		if ( isset( $tracked_files[ $cache_key ] ) ) {
			unset( $tracked_files[ $cache_key ] );
			update_option( 'wp_mcp_ai_openai_tracked_files', $tracked_files, false );
		}
	}
}
