<?php
/**
 * Base File Resource Manager.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for managing file resources with caching and tracking.
 */
class WP_MCP_AI_File_Resource_Manager {

	/**
	 * File type identifier.
	 *
	 * @var string
	 */
	protected $file_type = 'generic';

	/**
	 * Tracked files.
	 *
	 * @var array
	 */
	protected $tracked_files = array();

	/**
	 * Cache duration in seconds.
	 *
	 * @var int
	 */
	protected $cache_duration = 86400; // 24 hours.

	/**
	 * Maximum file age in seconds.
	 *
	 * @var int
	 */
	protected $max_file_age = 604800; // 7 days.

	/**
	 * Get file type.
	 *
	 * @return string File type.
	 */
	public function get_file_type() {
		return $this->file_type;
	}

	/**
	 * Track a file.
	 *
	 * @param string $file_id  File ID.
	 * @param array  $metadata File metadata.
	 * @return bool True on success.
	 */
	public function track_file( $file_id, $metadata ) {
		$metadata['file_type']  = $this->file_type;
		$metadata['tracked_at'] = time();

		$this->tracked_files[ $file_id ] = $metadata;

		return true;
	}

	/**
	 * Get tracked file.
	 *
	 * @param string $file_id File ID.
	 * @return array|null File metadata or null.
	 */
	public function get_tracked_file( $file_id ) {
		if ( isset( $this->tracked_files[ $file_id ] ) ) {
			return $this->tracked_files[ $file_id ];
		}

		return null;
	}

	/**
	 * Untrack a file.
	 *
	 * @param string $file_id File ID.
	 * @return bool True on success.
	 */
	public function untrack_file( $file_id ) {
		if ( isset( $this->tracked_files[ $file_id ] ) ) {
			unset( $this->tracked_files[ $file_id ] );
			return true;
		}

		return false;
	}

	/**
	 * Check if file is cached.
	 *
	 * @param string $file_id File ID.
	 * @return bool True if cached.
	 */
	public function is_file_cached( $file_id ) {
		if ( ! isset( $this->tracked_files[ $file_id ] ) ) {
			return false;
		}

		$file         = $this->tracked_files[ $file_id ];
		$tracked_at   = isset( $file['tracked_at'] ) ? $file['tracked_at'] : 0;
		$current_time = time();

		// Check if file is within cache duration.
		if ( ( $current_time - $tracked_at ) <= $this->cache_duration ) {
			return true;
		}

		return false;
	}

	/**
	 * Get cached file.
	 *
	 * @param string $file_id File ID.
	 * @return array|null File metadata or null.
	 */
	public function get_cached_file( $file_id ) {
		if ( $this->is_file_cached( $file_id ) ) {
			return $this->tracked_files[ $file_id ];
		}

		return null;
	}

	/**
	 * Get statistics.
	 *
	 * @return array Statistics.
	 */
	public function get_statistics() {
		$total_files   = count( $this->tracked_files );
		$cached_files  = 0;
		$expired_files = 0;

		foreach ( $this->tracked_files as $file_id => $file ) {
			if ( $this->is_file_cached( $file_id ) ) {
				++$cached_files;
			} else {
				++$expired_files;
			}
		}

		$cache_hit_rate = $total_files > 0 ? ( $cached_files / $total_files ) * 100 : 0;

		return array(
			'file_type'      => $this->file_type,
			'total_files'    => $total_files,
			'cached_files'   => $cached_files,
			'expired_files'  => $expired_files,
			'cache_hit_rate' => $cache_hit_rate,
		);
	}

	/**
	 * Set cache duration.
	 *
	 * @param int $duration Duration in seconds.
	 * @return void
	 */
	public function set_cache_duration( $duration ) {
		$this->cache_duration = absint( $duration );
	}

	/**
	 * Set maximum file age.
	 *
	 * @param int $age Age in seconds.
	 * @return void
	 */
	public function set_max_file_age( $age ) {
		$this->max_file_age = absint( $age );
	}

	/**
	 * Get all tracked files.
	 *
	 * @return array Tracked files.
	 */
	public function get_tracked_files() {
		return $this->tracked_files;
	}

	/**
	 * Clear all tracked files.
	 *
	 * @return bool True on success.
	 */
	public function clear_all() {
		$this->tracked_files = array();
		return true;
	}
}
