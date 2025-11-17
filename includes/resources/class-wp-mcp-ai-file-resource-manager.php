<?php
/**
 * Base File Resource Manager
 *
 * Generic file lifecycle management system for handling uploads, caching,
 * and cleanup across different file types (video, audio, images, documents).
 *
 * Part of Phase 2.1: File Management Enhancement (#1288)
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for file resource managers.
 *
 * Provides common functionality for:
 * - File upload tracking
 * - File caching (avoid re-uploading)
 * - Lifecycle management
 * - Cleanup scheduling
 * - Failure recovery
 *
 * @since 1.0.0
 */
abstract class WP_MCP_AI_File_Resource_Manager {

	/**
	 * File type identifier (e.g., 'video', 'audio', 'image', 'document')
	 *
	 * @var string
	 */
	protected $file_type;

	/**
	 * Option name for storing file metadata
	 *
	 * @var string
	 */
	protected $option_name;

	/**
	 * Default cache duration in seconds (24 hours)
	 *
	 * @var int
	 */
	protected $cache_duration = DAY_IN_SECONDS;

	/**
	 * Maximum file age before cleanup (7 days)
	 *
	 * @var int
	 */
	protected $max_file_age = 7 * DAY_IN_SECONDS;

	/**
	 * Constructor
	 *
	 * @param string $file_type File type identifier.
	 */
	public function __construct( $file_type ) {
		$this->file_type   = sanitize_key( $file_type );
		$this->option_name = 'wp_mcp_ai_' . $this->file_type . '_files';
	}

	/**
	 * Initialize the resource manager
	 *
	 * Sets up hooks and schedules cleanup cron job.
	 *
	 * @return void
	 */
	public function init() {
		// Schedule cleanup cron job if not already scheduled.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_' . $this->file_type . '_files' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_cleanup_' . $this->file_type . '_files' );
		}

		// Register cleanup action.
		add_action( 'wp_mcp_ai_cleanup_' . $this->file_type . '_files', array( $this, 'cleanup_old_files' ) );
	}

	/**
	 * Track an uploaded file
	 *
	 * Stores file metadata for caching and lifecycle management.
	 *
	 * @param string $file_identifier Unique file identifier (URL hash, attachment ID, etc.).
	 * @param array  $metadata        File metadata (remote_id, url, upload_time, size, etc.).
	 * @return bool True on success, false on failure.
	 */
	public function track_file( $file_identifier, $metadata ) {
		$files = $this->get_tracked_files();

		$metadata['tracked_at'] = time();
		$metadata['file_type']  = $this->file_type;

		$files[ $file_identifier ] = $metadata;

		return update_option( $this->option_name, $files, false );
	}

	/**
	 * Get tracked file by identifier
	 *
	 * @param string $file_identifier Unique file identifier.
	 * @return array|null File metadata or null if not found.
	 */
	public function get_tracked_file( $file_identifier ) {
		$files = $this->get_tracked_files();
		return isset( $files[ $file_identifier ] ) ? $files[ $file_identifier ] : null;
	}

	/**
	 * Get all tracked files
	 *
	 * @return array Array of tracked files.
	 */
	public function get_tracked_files() {
		return get_option( $this->option_name, array() );
	}

	/**
	 * Remove file from tracking
	 *
	 * @param string $file_identifier Unique file identifier.
	 * @return bool True on success, false on failure.
	 */
	public function untrack_file( $file_identifier ) {
		$files = $this->get_tracked_files();

		if ( ! isset( $files[ $file_identifier ] ) ) {
			return false;
		}

		unset( $files[ $file_identifier ] );

		return update_option( $this->option_name, $files, false );
	}

	/**
	 * Check if file is cached and still valid
	 *
	 * @param string $file_identifier Unique file identifier.
	 * @return bool True if cached and valid, false otherwise.
	 */
	public function is_file_cached( $file_identifier ) {
		$file = $this->get_tracked_file( $file_identifier );

		if ( ! $file ) {
			return false;
		}

		// Check if cache is still valid.
		$tracked_at = isset( $file['tracked_at'] ) ? $file['tracked_at'] : 0;
		$age        = time() - $tracked_at;

		return $age < $this->cache_duration;
	}

	/**
	 * Get cached file metadata if available
	 *
	 * @param string $file_identifier Unique file identifier.
	 * @return array|null Cached file metadata or null if not cached/expired.
	 */
	public function get_cached_file( $file_identifier ) {
		if ( ! $this->is_file_cached( $file_identifier ) ) {
			return null;
		}

		return $this->get_tracked_file( $file_identifier );
	}

	/**
	 * Cleanup old files
	 *
	 * Removes files that exceed the maximum age.
	 * Subclasses should override this to implement specific cleanup logic.
	 *
	 * @return int Number of files cleaned up.
	 */
	public function cleanup_old_files() {
		$files         = $this->get_tracked_files();
		$cleaned_count = 0;
		$current_time  = time();

		foreach ( $files as $file_identifier => $metadata ) {
			$tracked_at = isset( $metadata['tracked_at'] ) ? $metadata['tracked_at'] : 0;
			$age        = $current_time - $tracked_at;

			// Check if file exceeds maximum age.
			if ( $age > $this->max_file_age ) {
				// Attempt to delete remote file if applicable.
				$deleted = $this->delete_remote_file( $metadata );

				// Remove from tracking regardless of deletion success.
				$this->untrack_file( $file_identifier );

				if ( $deleted || is_wp_error( $deleted ) ) {
					++$cleaned_count;
				}

				// Log cleanup.
				WP_MCP_AI_Logger::log_event(
					$this->file_type . '_file_cleanup',
					sprintf( 'Cleaned up old %s file', $this->file_type ),
					array(
						'file_identifier' => $file_identifier,
						'age_days'        => round( $age / DAY_IN_SECONDS, 2 ),
						'deleted'         => ! is_wp_error( $deleted ),
					)
				);
			}
		}

		if ( $cleaned_count > 0 ) {
			WP_MCP_AI_Logger::log_event(
				$this->file_type . '_cleanup_complete',
				sprintf( 'Cleaned up %d old %s files', $cleaned_count, $this->file_type ),
				array( 'count' => $cleaned_count )
			);
		}

		return $cleaned_count;
	}

	/**
	 * Delete remote file
	 *
	 * Subclasses must implement this to delete files from remote services.
	 *
	 * @param array $metadata File metadata.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	abstract protected function delete_remote_file( $metadata );

	/**
	 * Generate unique file identifier
	 *
	 * Creates a consistent hash for file identification.
	 *
	 * @param string $source File source (URL, attachment ID, etc.).
	 * @return string Unique identifier.
	 */
	protected function generate_file_identifier( $source ) {
		return md5( $this->file_type . ':' . $source );
	}

	/**
	 * Set cache duration
	 *
	 * @param int $seconds Cache duration in seconds.
	 * @return void
	 */
	public function set_cache_duration( $seconds ) {
		$this->cache_duration = absint( $seconds );
	}

	/**
	 * Set maximum file age
	 *
	 * @param int $seconds Maximum file age in seconds.
	 * @return void
	 */
	public function set_max_file_age( $seconds ) {
		$this->max_file_age = absint( $seconds );
	}

	/**
	 * Get file type
	 *
	 * @return string File type identifier.
	 */
	public function get_file_type() {
		return $this->file_type;
	}

	/**
	 * Get statistics for tracked files
	 *
	 * @return array Statistics including total files, cache hits, expired files.
	 */
	public function get_statistics() {
		$files         = $this->get_tracked_files();
		$total_files   = count( $files );
		$cached_files  = 0;
		$expired_files = 0;
		$current_time  = time();

		foreach ( $files as $metadata ) {
			$tracked_at = isset( $metadata['tracked_at'] ) ? $metadata['tracked_at'] : 0;
			$age        = $current_time - $tracked_at;

			if ( $age < $this->cache_duration ) {
				++$cached_files;
			}

			if ( $age > $this->max_file_age ) {
				++$expired_files;
			}
		}

		return array(
			'file_type'      => $this->file_type,
			'total_files'    => $total_files,
			'cached_files'   => $cached_files,
			'expired_files'  => $expired_files,
			'cache_hit_rate' => $total_files > 0 ? round( ( $cached_files / $total_files ) * 100, 2 ) : 0,
		);
	}

	/**
	 * Clear all tracked files
	 *
	 * Useful for testing and maintenance.
	 *
	 * @return bool True on success.
	 */
	public function clear_all() {
		return delete_option( $this->option_name );
	}
}
