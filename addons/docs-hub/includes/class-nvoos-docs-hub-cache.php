<?php
/**
 * NV oOS Docs Hub — Cache
 *
 * Manages filesystem and transient caching for the documentation
 * manifest, per-page payloads, and search index.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache manager for the Docs Hub addon.
 *
 * @since 1.0.0
 */
class NV_oOS_Docs_Hub_Cache {

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'nvoos_dh_';

	/**
	 * Transient TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	const TRANSIENT_TTL = 3600;

	/**
	 * Cache directory relative to uploads basedir.
	 *
	 * @var string
	 */
	const CACHE_DIR = 'nvoos-docs-hub';

	/**
	 * Cached upload directory path.
	 *
	 * @var string|null
	 */
	private $upload_dir = null;

	/**
	 * Get the manifest from cache.
	 *
	 * @since 1.0.0
	 *
	 * @return array|false
	 */
	public function get_manifest() {
		$transient_key = self::TRANSIENT_PREFIX . 'manifest';
		$cached        = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$data = $this->read_json( 'manifest.json' );
		if ( false !== $data ) {
			set_transient( $transient_key, $data, self::TRANSIENT_TTL );
		}
		return $data;
	}

	/**
	 * Store the manifest in cache.
	 *
	 * @since 1.0.0
	 *
	 * @param array $manifest Manifest data.
	 * @return bool
	 */
	public function set_manifest( $manifest ) {
		$result = $this->write_json( 'manifest.json', $manifest );
		if ( $result ) {
			set_transient( self::TRANSIENT_PREFIX . 'manifest', $manifest, self::TRANSIENT_TTL );
		}
		return $result;
	}

	/**
	 * Get a page payload from cache.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Page slug.
	 * @return array|false
	 */
	public function get_page( $slug ) {
		$transient_key = self::TRANSIENT_PREFIX . 'p_' . md5( $slug );
		$cached        = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$filename = 'pages/' . $this->slug_to_filename( $slug ) . '.json';
		$data     = $this->read_json( $filename );
		if ( false !== $data ) {
			set_transient( $transient_key, $data, self::TRANSIENT_TTL );
		}
		return $data;
	}

	/**
	 * Store a page payload in cache.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug    Page slug.
	 * @param array  $payload Page payload.
	 * @return bool
	 */
	public function set_page( $slug, $payload ) {
		$filename = 'pages/' . $this->slug_to_filename( $slug ) . '.json';
		$result   = $this->write_json( $filename, $payload );
		if ( $result ) {
			$transient_key = self::TRANSIENT_PREFIX . 'p_' . md5( $slug );
			set_transient( $transient_key, $payload, self::TRANSIENT_TTL );
		}
		return $result;
	}

	/**
	 * Get the search index from cache.
	 *
	 * @since 1.0.0
	 *
	 * @return array|false
	 */
	public function get_search_index() {
		$transient_key = self::TRANSIENT_PREFIX . 'search';
		$cached        = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$data = $this->read_json( 'search-index.json' );
		if ( false !== $data ) {
			set_transient( $transient_key, $data, self::TRANSIENT_TTL );
		}
		return $data;
	}

	/**
	 * Store the search index in cache.
	 *
	 * @since 1.0.0
	 *
	 * @param array $index Search index data.
	 * @return bool
	 */
	public function set_search_index( $index ) {
		$result = $this->write_json( 'search-index.json', $index );
		if ( $result ) {
			set_transient( self::TRANSIENT_PREFIX . 'search', $index, self::TRANSIENT_TTL );
		}
		return $result;
	}

	/**
	 * Clear all cached data.
	 *
	 * Deletes all JSON files in the cache directory and clears transients.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function clear() {
		$dir = $this->get_upload_dir();
		if ( ! $dir ) {
			return;
		}

		// Delete manifest and search index.
		$root_jsons = glob( $dir . '/*.json' );
		if ( ! empty( $root_jsons ) ) {
			foreach ( $root_jsons as $file ) {
				wp_delete_file( $file );
			}
		}

		// Delete page cache files.
		$pages_dir   = $dir . '/pages';
		$page_jsons  = is_dir( $pages_dir ) ? glob( $pages_dir . '/*.json' ) : array();
		if ( ! empty( $page_jsons ) ) {
			foreach ( $page_jsons as $file ) {
				wp_delete_file( $file );
			}
		}

		// Clear transients.
		delete_transient( self::TRANSIENT_PREFIX . 'manifest' );
		delete_transient( self::TRANSIENT_PREFIX . 'search' );

		// Note: page transients use md5 keys so we cannot enumerate them easily.
		// They expire naturally via TTL.
	}

	/**
	 * Get the build timestamp from the manifest.
	 *
	 * @since 1.0.0
	 *
	 * @return int Unix timestamp, or 0 if not built.
	 */
	public function get_last_built() {
		$manifest = $this->get_manifest();
		if ( ! is_array( $manifest ) || empty( $manifest['built_at'] ) ) {
			return 0;
		}
		return (int) $manifest['built_at'];
	}

	/**
	 * Check whether the cache is stale relative to the given file paths.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $file_paths List of absolute file paths to check.
	 * @return bool True if cache is stale or has never been built.
	 */
	public function is_stale( $file_paths ) {
		$last_built = $this->get_last_built();
		if ( 0 === $last_built ) {
			return true;
		}

		foreach ( $file_paths as $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$mtime = filemtime( $path );
			if ( false !== $mtime && $mtime > $last_built ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert a slug to a filesystem-safe filename.
	 *
	 * Slashes in slugs become `--` to remain within a flat directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	private function slug_to_filename( $slug ) {
		return preg_replace( '#/#', '--', $slug );
	}

	/**
	 * Get the absolute path to the cache upload directory, creating it if needed.
	 *
	 * @since 1.0.0
	 *
	 * @return string|false False on failure.
	 */
	private function get_upload_dir() {
		if ( null !== $this->upload_dir ) {
			return $this->upload_dir;
		}

		$upload_info = wp_upload_dir();
		$base        = $upload_info['basedir'];
		$dir         = $base . DIRECTORY_SEPARATOR . self::CACHE_DIR;

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Also create pages subdirectory.
		wp_mkdir_p( $dir . DIRECTORY_SEPARATOR . 'pages' );

		// Write security guards on first use.
		$htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$index_guard = $dir . DIRECTORY_SEPARATOR . 'index.php';
		if ( ! file_exists( $index_guard ) ) {
			file_put_contents( $index_guard, "<?php\n// Silence is golden.\n" );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$this->upload_dir = $dir;
		return $dir;
	}

	/**
	 * Read and decode a JSON file from the cache directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative_path Path relative to cache directory.
	 * @return array|false
	 */
	private function read_json( $relative_path ) {
		$dir = $this->get_upload_dir();
		if ( ! $dir ) {
			return false;
		}

		$file = $dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );

		if ( ! file_exists( $file ) ) {
			return false;
		}

		$contents = file_get_contents( $file );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return false;
		}

		$decoded = json_decode( $contents, true );
		if ( ! is_array( $decoded ) ) {
			return false;
		}

		return $decoded;
	}

	/**
	 * Encode data as JSON and write to a cache file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative_path Path relative to cache directory.
	 * @param array  $data          Data to encode.
	 * @return bool
	 */
	private function write_json( $relative_path, $data ) {
		$dir = $this->get_upload_dir();
		if ( ! $dir ) {
			return false;
		}

		$file      = $dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
		$file_dir  = dirname( $file );

		if ( ! wp_mkdir_p( $file_dir ) ) {
			return false;
		}

		$encoded = wp_json_encode( $data );
		if ( false === $encoded ) {
			return false;
		}

		$result = file_put_contents( $file, $encoded );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== $result;
	}
}
