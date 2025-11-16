<?php
/**
 * Video File Manager Service
 *
 * Manages lifecycle of video files uploaded to Gemini File API.
 * Handles tracking, caching, and cleanup of uploaded video files.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Video File Manager class
 *
 * Responsible for:
 * - Tracking uploaded video files
 * - Caching file uploads to avoid re-uploading
 * - Cleaning up old/expired files
 * - Managing file metadata and lifecycle
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Video_File_Manager {

	/**
	 * Option name for storing file registry
	 *
	 * @var string
	 */
	const REGISTRY_OPTION = 'wp_mcp_ai_video_file_registry';

	/**
	 * Transient prefix for file caching
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'wp_mcp_ai_video_';

	/**
	 * Default cache duration in seconds (24 hours)
	 *
	 * @var int
	 */
	const DEFAULT_CACHE_DURATION = 86400;

	/**
	 * Default file expiry in seconds (48 hours)
	 *
	 * @var int
	 */
	const DEFAULT_FILE_EXPIRY = 172800;

	/**
	 * Gemini File Service instance
	 *
	 * @var WP_MCP_AI_Gemini_File_Service
	 */
	private $file_service;

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Gemini_File_Service $file_service Gemini File Service instance.
	 */
	public function __construct( $file_service = null ) {
		if ( null === $file_service ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-file-service.php';
			$file_service = new WP_MCP_AI_Gemini_File_Service();
		}
		$this->file_service = $file_service;
	}

	/**
	 * Get cached file information by video hash
	 *
	 * @param string $video_hash Hash of the video content.
	 * @return array|false Cached file data or false if not found/expired.
	 */
	public function get_cached_file( $video_hash ) {
		$cache_key = self::CACHE_PREFIX . $video_hash;
		$cached    = get_transient( $cache_key );

		if ( false === $cached ) {
			return false;
		}

		// Verify file still exists in registry.
		$registry = $this->get_registry();
		if ( ! isset( $registry[ $video_hash ] ) ) {
			delete_transient( $cache_key );
			return false;
		}

		// Check if file has expired.
		$file_data = $registry[ $video_hash ];
		if ( $this->is_file_expired( $file_data ) ) {
			delete_transient( $cache_key );
			return false;
		}

		return $file_data;
	}

	/**
	 * Register an uploaded file
	 *
	 * @param string $video_hash  Hash of the video content.
	 * @param array  $upload_data Upload result from Gemini File Service.
	 * @param array  $metadata    Additional metadata to store.
	 * @return bool True on success, false on failure.
	 */
	public function register_file( $video_hash, $upload_data, $metadata = array() ) {
		$registry = $this->get_registry();

		$file_data = array(
			'file_name'    => $upload_data['file_name'],
			'file_uri'     => $upload_data['file_uri'],
			'video_hash'   => $video_hash,
			'mime_type'    => isset( $upload_data['mime_type'] ) ? $upload_data['mime_type'] : '',
			'uploaded_at'  => time(),
			'last_used_at' => time(),
			'expiry_time'  => time() + self::DEFAULT_FILE_EXPIRY,
			'metadata'     => $metadata,
		);

		$registry[ $video_hash ] = $file_data;
		$updated                 = update_option( self::REGISTRY_OPTION, $registry, false );

		if ( $updated ) {
			// Set cache.
			$cache_key = self::CACHE_PREFIX . $video_hash;
			set_transient( $cache_key, $file_data, self::DEFAULT_CACHE_DURATION );

			WP_MCP_AI_Logger::log_event(
				'video_file_registered',
				'Video file registered in cache.',
				array(
					'video_hash' => $video_hash,
					'file_name'  => $upload_data['file_name'],
				)
			);
		}

		return $updated;
	}

	/**
	 * Update last used timestamp for a file
	 *
	 * @param string $video_hash Hash of the video content.
	 * @return bool True on success, false on failure.
	 */
	public function touch_file( $video_hash ) {
		$registry = $this->get_registry();

		if ( ! isset( $registry[ $video_hash ] ) ) {
			return false;
		}

		$registry[ $video_hash ]['last_used_at'] = time();
		
		// Extend expiry when file is reused.
		$registry[ $video_hash ]['expiry_time'] = time() + self::DEFAULT_FILE_EXPIRY;

		$updated = update_option( self::REGISTRY_OPTION, $registry, false );

		if ( $updated ) {
			// Extend cache as well.
			$cache_key = self::CACHE_PREFIX . $video_hash;
			set_transient( $cache_key, $registry[ $video_hash ], self::DEFAULT_CACHE_DURATION );
		}

		return $updated;
	}

	/**
	 * Unregister a file from tracking
	 *
	 * @param string $video_hash Hash of the video content.
	 * @return bool True on success, false on failure.
	 */
	public function unregister_file( $video_hash ) {
		$registry = $this->get_registry();

		if ( ! isset( $registry[ $video_hash ] ) ) {
			return false;
		}

		unset( $registry[ $video_hash ] );
		$updated = update_option( self::REGISTRY_OPTION, $registry, false );

		if ( $updated ) {
			// Delete cache.
			$cache_key = self::CACHE_PREFIX . $video_hash;
			delete_transient( $cache_key );
		}

		return $updated;
	}

	/**
	 * Generate hash for video content
	 *
	 * @param string $file_path Path to video file.
	 * @return string|WP_Error Video hash or error.
	 */
	public function generate_video_hash( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Video file not found.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		// Use MD5 for performance (SHA256 would be more secure but slower).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$hash = md5_file( $file_path );

		if ( false === $hash ) {
			return new WP_Error(
				'wp_mcp_ai_hash_failed',
				__( 'Failed to generate video hash.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		return $hash;
	}

	/**
	 * Clean up expired files
	 *
	 * Removes expired files from Gemini API and local registry.
	 *
	 * @return array Cleanup results with counts.
	 */
	public function cleanup_expired_files() {
		$registry = $this->get_registry();
		$results  = array(
			'total_checked' => count( $registry ),
			'deleted'       => 0,
			'failed'        => 0,
			'errors'        => array(),
		);

		foreach ( $registry as $video_hash => $file_data ) {
			if ( ! $this->is_file_expired( $file_data ) ) {
				continue;
			}

			// Attempt to delete from Gemini API.
			$delete_result = $this->file_service->delete_file( $file_data['file_name'] );

			if ( is_wp_error( $delete_result ) ) {
				$results['failed']++;
				$results['errors'][] = array(
					'video_hash' => $video_hash,
					'error'      => $delete_result->get_error_message(),
				);

				WP_MCP_AI_Logger::log_error(
					'Failed to delete expired video file from Gemini.',
					array(
						'video_hash' => $video_hash,
						'file_name'  => $file_data['file_name'],
						'error'      => $delete_result->get_error_message(),
					)
				);
			} else {
				$results['deleted']++;

				WP_MCP_AI_Logger::log_event(
					'video_file_cleaned',
					'Expired video file deleted from Gemini.',
					array(
						'video_hash' => $video_hash,
						'file_name'  => $file_data['file_name'],
					)
				);
			}

			// Remove from registry regardless of API deletion success.
			$this->unregister_file( $video_hash );
		}

		WP_MCP_AI_Logger::log_event(
			'video_cleanup_complete',
			'Video file cleanup completed.',
			$results
		);

		return $results;
	}

	/**
	 * Get all registered files
	 *
	 * @return array File registry.
	 */
	public function get_all_files() {
		return $this->get_registry();
	}

	/**
	 * Get file statistics
	 *
	 * @return array Statistics about registered files.
	 */
	public function get_statistics() {
		$registry = $this->get_registry();
		$now      = time();

		$stats = array(
			'total_files'   => count( $registry ),
			'expired_files' => 0,
			'active_files'  => 0,
			'total_size'    => 0,
			'oldest_file'   => null,
			'newest_file'   => null,
		);

		foreach ( $registry as $file_data ) {
			if ( $this->is_file_expired( $file_data ) ) {
				$stats['expired_files']++;
			} else {
				$stats['active_files']++;
			}

			// Track oldest and newest.
			$uploaded_at = $file_data['uploaded_at'];
			if ( null === $stats['oldest_file'] || $uploaded_at < $stats['oldest_file'] ) {
				$stats['oldest_file'] = $uploaded_at;
			}
			if ( null === $stats['newest_file'] || $uploaded_at > $stats['newest_file'] ) {
				$stats['newest_file'] = $uploaded_at;
			}
		}

		return $stats;
	}

	/**
	 * Get the file registry
	 *
	 * @return array File registry.
	 */
	private function get_registry() {
		$registry = get_option( self::REGISTRY_OPTION, array() );

		if ( ! is_array( $registry ) ) {
			$registry = array();
		}

		return $registry;
	}

	/**
	 * Check if a file has expired
	 *
	 * @param array $file_data File data from registry.
	 * @return bool True if expired, false otherwise.
	 */
	private function is_file_expired( $file_data ) {
		if ( ! isset( $file_data['expiry_time'] ) ) {
			return true;
		}

		return time() > $file_data['expiry_time'];
	}

	/**
	 * Clear all registered files (used for testing or cleanup)
	 *
	 * WARNING: This will remove all tracking but NOT delete files from Gemini API.
	 *
	 * @return bool True on success.
	 */
	public function clear_registry() {
		delete_option( self::REGISTRY_OPTION );

		// Clear all transients.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%'
			)
		);

		return true;
	}
}
