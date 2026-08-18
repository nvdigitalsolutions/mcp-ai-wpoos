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
	 * Sub-directory used as the staging namespace during a chunked rebuild.
	 *
	 * Pages are written here first, then atomically swapped into the live
	 * cache once every phase succeeds. This eliminates the historical
	 * "blank docs while rebuilding" window.
	 *
	 * @var string
	 */
	const STAGING_DIR = '_staging';

	/**
	 * Cached upload directory path.
	 *
	 * @var string|null
	 */
	private $upload_dir = null;

	/**
	 * Whether read/write operations target the staging namespace instead of
	 * the live cache. Toggle via use_staging().
	 *
	 * @var bool
	 */
	private $staging = false;

	/**
	 * Switch this instance to read/write the staging namespace.
	 *
	 * Subsequent get_/set_ calls go to <upload>/<CACHE_DIR>/_staging/...
	 * instead of <upload>/<CACHE_DIR>/...
	 *
	 * @since 1.2.0
	 *
	 * @param bool $on Enable (true) or disable (false).
	 * @return self
	 */
	public function use_staging( $on = true ) {
		$this->staging = (bool) $on;
		return $this;
	}

	/**
	 * Get the manifest from cache.
	 *
	 * @since 1.0.0
	 *
	 * @return array|false
	 */
	public function get_manifest() {
		// In staging mode, never hit the live transient — always read
		// directly from the staging filesystem so the async rebuild
		// pipeline operates on its own copy.
		if ( ! $this->staging ) {
			$transient_key = self::TRANSIENT_PREFIX . 'manifest';
			$cached        = get_transient( $transient_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$data = $this->read_json( 'manifest.json' );
		if ( false !== $data && ! $this->staging ) {
			set_transient( self::TRANSIENT_PREFIX . 'manifest', $data, self::TRANSIENT_TTL );
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
		// Only set the live transient when writing to the live cache.
		// Staging writes must not pollute the live transient, otherwise
		// get_manifest() in live mode would see partial staged data.
		if ( $result && ! $this->staging ) {
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
		// In staging mode, never hit the live transient.
		if ( ! $this->staging ) {
			$transient_key = self::TRANSIENT_PREFIX . 'search';
			$cached        = get_transient( $transient_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$data = $this->read_json( 'search-index.json' );
		if ( false !== $data && ! $this->staging ) {
			set_transient( self::TRANSIENT_PREFIX . 'search', $data, self::TRANSIENT_TTL );
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
		if ( $result && ! $this->staging ) {
			set_transient( self::TRANSIENT_PREFIX . 'search', $index, self::TRANSIENT_TTL );
		}
		return $result;
	}

	/**
	 * Clear all cached data.
	 *
	 * Deletes all JSON files in the cache directory and clears transients.
	 * When `$preserve_remote` is true, the per-file remote content cache is
	 * kept so the next rebuild does not re-fetch every Markdown file from
	 * GitHub — useful after a plugin update, which only changes local
	 * docs and leaves the remote refs untouched.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $preserve_remote Keep cached remote file content.
	 * @return void
	 */
	public function clear( $preserve_remote = false ) {
		$dir = $this->get_live_dir();
		if ( ! $dir ) {
			return;
		}

		// Delete manifest, search index, and any other top-level JSON blobs.
		$root_jsons = glob( $dir . '/*.json' );
		if ( ! empty( $root_jsons ) ) {
			foreach ( $root_jsons as $file ) {
				wp_delete_file( $file );
			}
		}

		// Delete page cache files.
		$pages_dir  = $dir . '/pages';
		$page_jsons = is_dir( $pages_dir ) ? glob( $pages_dir . '/*.json' ) : array();
		if ( ! empty( $page_jsons ) ) {
			foreach ( $page_jsons as $file ) {
				wp_delete_file( $file );
			}
		}

		// Clear the staging namespace so any half-finished rebuild starts fresh.
		$this->clear_staging();

		if ( ! $preserve_remote ) {
			// Delete remote content cache files (individual .md files fetched from
			// GitHub). The next rebuild will re-fetch them.
			$remote_dir = $dir . '/remote';
			$remote_mds = is_dir( $remote_dir ) ? glob( $remote_dir . '/*.md' ) : array();
			if ( ! empty( $remote_mds ) ) {
				foreach ( $remote_mds as $file ) {
					wp_delete_file( $file );
				}
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
	 * Honours the staging toggle: when staging is on, returns the
	 * `_staging` sub-directory.
	 *
	 * @since 1.0.0
	 *
	 * @return string|false False on failure.
	 */
	private function get_upload_dir() {
		$base_dir = $this->get_live_dir();
		if ( false === $base_dir ) {
			return false;
		}

		if ( ! $this->staging ) {
			return $base_dir;
		}

		$staging = $base_dir . DIRECTORY_SEPARATOR . self::STAGING_DIR;
		if ( ! wp_mkdir_p( $staging ) ) {
			return false;
		}
		wp_mkdir_p( $staging . DIRECTORY_SEPARATOR . 'pages' );

		return $staging;
	}

	/**
	 * Get (and lazily create) the live cache directory.
	 *
	 * Always returns the non-staging directory regardless of the staging
	 * toggle — used by clear_staging() and promote_staging().
	 *
	 * @since 1.2.0
	 *
	 * @return string|false False on failure.
	 */
	private function get_live_dir() {
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

		// IIS / Windows Server equivalent.
		$web_config = $dir . DIRECTORY_SEPARATOR . 'web.config';
		if ( ! file_exists( $web_config ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$web_config,
				"<?xml version=\"1.0\" encoding=\"utf-8\"?>\n" .
				"<configuration>\n" .
				"  <system.webServer>\n" .
				"    <security>\n" .
				"      <requestFiltering>\n" .
				"        <hiddenSegments>\n" .
				"          <add segment=\"nvoos-docs-hub\" />\n" .
				"        </hiddenSegments>\n" .
				"      </requestFiltering>\n" .
				"    </security>\n" .
				"  </system.webServer>\n" .
				"</configuration>\n"
			);
		}

		$index_guard = $dir . DIRECTORY_SEPARATOR . 'index.php';
		if ( ! file_exists( $index_guard ) ) {
			file_put_contents( $index_guard, "<?php\n// Silence is golden.\n" );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$this->upload_dir = $dir;
		return $dir;
	}

	/**
	 * Delete every artefact in the staging namespace.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function clear_staging() {
		$live = $this->get_live_dir();
		if ( ! $live ) {
			return;
		}
		$staging = $live . DIRECTORY_SEPARATOR . self::STAGING_DIR;
		if ( ! is_dir( $staging ) ) {
			return;
		}
		$this->rm_rf( $staging );
	}

	/**
	 * Promote the staging namespace to the live cache atomically.
	 *
	 * Writes manifest + search-index + page payloads from `_staging/`
	 * into the live cache, then clears staging. Existing live files are
	 * overwritten; orphaned page files (slugs that no longer exist) are
	 * removed so the live cache cleanly reflects the new manifest.
	 *
	 * Transients for the manifest and search index are refreshed to keep
	 * fast-path reads aligned with the on-disk truth.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True on success, false when staging is empty or unwritable.
	 */
	public function promote_staging() {
		$live = $this->get_live_dir();
		if ( ! $live ) {
			return false;
		}
		$staging = $live . DIRECTORY_SEPARATOR . self::STAGING_DIR;
		if ( ! is_dir( $staging ) ) {
			return false;
		}

		// 1. Read staged manifest + search index.
		$manifest     = $this->read_path( $staging . DIRECTORY_SEPARATOR . 'manifest.json' );
		$search_index = $this->read_path( $staging . DIRECTORY_SEPARATOR . 'search-index.json' );

		if ( ! is_array( $manifest ) ) {
			return false;
		}

		// 2. Determine valid page filenames from the staged slug map.
		$valid_filenames = array();
		if ( isset( $manifest['slug_map'] ) && is_array( $manifest['slug_map'] ) ) {
			foreach ( array_keys( $manifest['slug_map'] ) as $slug ) {
				$valid_filenames[ $this->slug_to_filename( $slug ) . '.json' ] = true;
			}
		}

		// 3. Move staged page files into live.
		$staged_pages = is_dir( $staging . '/pages' ) ? glob( $staging . '/pages/*.json' ) : array();
		$live_pages   = $live . DIRECTORY_SEPARATOR . 'pages';
		wp_mkdir_p( $live_pages );

		if ( ! empty( $staged_pages ) ) {
			foreach ( $staged_pages as $src ) {
				$basename = basename( $src );
				$dst      = $live_pages . DIRECTORY_SEPARATOR . $basename;
				// PHP rename() is atomic on the same filesystem.
				if ( ! @rename( $src, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$contents = file_get_contents( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					if ( false !== $contents ) {
						file_put_contents( $dst, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
						wp_delete_file( $src );
					}
				}
			}
		}

		// 4. Remove orphaned live page files (slugs that disappeared).
		$existing = glob( $live_pages . '/*.json' );
		if ( ! empty( $existing ) ) {
			foreach ( $existing as $file ) {
				if ( empty( $valid_filenames[ basename( $file ) ] ) ) {
					wp_delete_file( $file );
				}
			}
		}

		// 5. Write manifest + search-index into live (and refresh transients).
		$this->write_json( 'manifest.json', $manifest );
		set_transient( self::TRANSIENT_PREFIX . 'manifest', $manifest, self::TRANSIENT_TTL );

		if ( is_array( $search_index ) ) {
			$this->write_json( 'search-index.json', $search_index );
			set_transient( self::TRANSIENT_PREFIX . 'search', $search_index, self::TRANSIENT_TTL );
		}

		// 6. Tear down staging.
		$this->rm_rf( $staging );

		return true;
	}

	/**
	 * Read and decode a JSON file by absolute path.
	 *
	 * @since 1.2.0
	 *
	 * @param string $path Absolute file path.
	 * @return array|false
	 */
	private function read_path( $path ) {
		if ( ! file_exists( $path ) ) {
			return false;
		}
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return false;
		}
		$decoded = json_decode( $contents, true );
		return is_array( $decoded ) ? $decoded : false;
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @since 1.2.0
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	private function rm_rf( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$entries = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $entries as $entry ) {
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				$this->rm_rf( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		// rmdir is intentionally suppressed — non-empty corner cases shouldn't fatal.
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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

		$file     = $dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
		$file_dir = dirname( $file );

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
