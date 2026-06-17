<?php
/**
 * NV oOS Docs Hub — Scanner
 *
 * Discovers Markdown documentation files from configured sources
 * (base plugin, addons, repo root, context directory).
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans the filesystem for documentation files.
 *
 * @since 1.0.0
 */
class NV_oOS_Docs_Hub_Scanner {

	/**
	 * Maximum allowed file size in bytes (2 MB).
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 2097152;

	/**
	 * Allowed file extensions.
	 *
	 * @var string[]
	 */
	const ALLOWED_EXTENSIONS = array( 'md', 'txt' );

	/**
	 * Default exclusion globs applied on every rebuild (filterable).
	 *
	 * Covers third-party / build / VCS noise so vendor docs don't pollute
	 * the index. Tested against both the relative_path and the basename
	 * of every candidate file in apply_exclusions(); also used by
	 * is_dir_pruned() to skip whole subtrees during recursion.
	 *
	 * @var string[]
	 */
	const DEFAULT_EXCLUDED_GLOBS = array(
		// Dependency directories.
		'vendor/*',
		'**/vendor/*',
		'node_modules/*',
		'**/node_modules/*',
		'bower_components/*',
		'**/bower_components/*',
		// VCS / CI / build outputs.
		'.git/*',
		'**/.git/*',
		'.github/*',
		'**/.github/*',
		'dist/*',
		'**/dist/*',
		'build/*',
		'**/build/*',
		'coverage/*',
		'**/coverage/*',
		'tests/fixtures/*',
		'**/tests/fixtures/*',
		// Third-party noise filenames.
		'LICENSE.md',
		'LICENSE.txt',
		'CODE_OF_CONDUCT.md',
		'THIRD_PARTY_NOTICES.md',
	);

	/**
	 * Directory names that are unconditionally pruned during recursive
	 * directory walks (matched on basename).
	 *
	 * Filtered through `nvoos_docs_hub_pruned_dir_names` so site owners
	 * can extend or shrink this list at runtime.
	 *
	 * @var string[]
	 */
	const PRUNED_DIR_NAMES = array(
		'vendor',
		'node_modules',
		'bower_components',
		'.git',
		'.github',
		'.svn',
		'dist',
		'build',
		'coverage',
	);

	/**
	 * Scan configured sources and return an array of file entries.
	 *
	 * Each entry: [ 'path', 'source', 'plugin_name', 'relative_path' ]
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function scan() {
		$settings        = NV_oOS_Docs_Hub_Plugin::get_settings();
		$enabled_sources = isset( $settings['sources'] ) ? (array) $settings['sources'] : array( 'base', 'addons', 'root' );

		/**
		 * Filter the list of enabled documentation sources.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $enabled_sources List of source keys: base, addons, root, context.
		 */
		$enabled_sources = apply_filters( 'nvoos_docs_hub_sources', $enabled_sources );

		$entries = array();

		if ( in_array( 'base', $enabled_sources, true ) ) {
			$entries = array_merge( $entries, $this->scan_base() );
		}

		if ( in_array( 'addons', $enabled_sources, true ) ) {
			$entries = array_merge( $entries, $this->scan_addons() );
		}

		if ( in_array( 'root', $enabled_sources, true ) ) {
			$entries = array_merge( $entries, $this->scan_root() );
		}

		if ( in_array( 'context', $enabled_sources, true ) ) {
			$entries = array_merge( $entries, $this->scan_context( $settings ) );
		}

		if ( in_array( 'remote', $enabled_sources, true ) ) {
			$entries = array_merge( $entries, $this->scan_remote_repos( $settings ) );
		}

		// Always apply the default exclusion list, with optional user
		// extensions on top. The filter receives the merged defaults so
		// site owners can both extend and override (return [] to opt-out).
		$default_excluded = self::DEFAULT_EXCLUDED_GLOBS;

		/**
		 * Filter the list of glob patterns to exclude from indexing.
		 *
		 * Defaults exclude vendor/, node_modules/, build outputs and
		 * common third-party noise filenames. Return an empty array to
		 * opt out entirely (not recommended).
		 *
		 * @since 1.0.0
		 * @since 1.2.0 Now seeded with self::DEFAULT_EXCLUDED_GLOBS.
		 *
		 * @param string[] $excluded Glob patterns.
		 */
		$excluded = apply_filters( 'nvoos_docs_hub_excluded_globs', $default_excluded );

		/**
		 * Filter the list of glob patterns to force-include even when an
		 * exclusion would otherwise drop them.
		 *
		 * Use this when you legitimately want to publish a vendored doc
		 * (e.g. `vendor/some-team/internal-runbooks/*.md`).
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $force_included Glob patterns.
		 */
		$force_included = apply_filters( 'nvoos_docs_hub_force_include_globs', array() );

		if ( ! empty( $excluded ) ) {
			$entries = $this->apply_exclusions( $entries, $excluded, $force_included );
		}

		return $entries;
	}

	/**
	 * Scan the NV oOS base plugin docs directory.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function scan_base() {
		$candidates = array(
			dirname( NVOOS_DOCS_HUB_PATH ) . '/mcp-ai-wpoos/docs',
		);

		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			array_unshift( $candidates, WP_PLUGIN_DIR . '/mcp-ai-wpoos/docs' );
		}

		// Also check sibling directory if mcp-ai-wpoos.php exists one level up.
		$sibling_root = dirname( dirname( NVOOS_DOCS_HUB_PATH ) );
		if ( file_exists( $sibling_root . '/mcp-ai-wpoos.php' ) ) {
			$candidates[] = $sibling_root . '/docs';
		}

		$allowed_roots = array_unique( $candidates );
		$entries       = array();

		foreach ( $candidates as $docs_dir ) {
			if ( ! is_dir( $docs_dir ) ) {
				continue;
			}

			$real_docs = realpath( $docs_dir );
			if ( false === $real_docs ) {
				continue;
			}

			$files = $this->glob_recursive( $real_docs, '*.md' );
			foreach ( $files as $file ) {
				if ( ! $this->is_path_safe( $file, array( $real_docs ) ) ) {
					continue;
				}
				if ( ! $this->is_allowed_file( $file ) ) {
					continue;
				}
				$relative  = ltrim( str_replace( $real_docs, '', $file ), DIRECTORY_SEPARATOR );
				$entries[] = array(
					'path'          => $file,
					'source'        => 'base',
					'plugin_name'   => 'NV oOS Base',
					'relative_path' => str_replace( DIRECTORY_SEPARATOR, '/', 'docs/' . $relative ),
				);
			}
			// Only use the first valid docs dir found.
			if ( ! empty( $entries ) ) {
				break;
			}
		}

		return $entries;
	}

	/**
	 * Scan addon directories for documentation files.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function scan_addons() {
		$addons_dir  = dirname( NVOOS_DOCS_HUB_PATH );
		$real_addons = realpath( $addons_dir );
		if ( false === $real_addons ) {
			return array();
		}

		$entries    = array();
		$addon_dirs = glob( $real_addons . '/*', GLOB_ONLYDIR );

		if ( empty( $addon_dirs ) ) {
			return array();
		}

		$settings        = NV_oOS_Docs_Hub_Plugin::get_settings();
		$include_readmes = ! isset( $settings['include_addon_readmes'] ) || ! empty( $settings['include_addon_readmes'] );

		foreach ( $addon_dirs as $addon_dir ) {
			$addon_slug = basename( $addon_dir );

			// Skip the docs-hub addon itself.
			if ( 'docs-hub' === $addon_slug ) {
				continue;
			}

			$real_addon = realpath( $addon_dir );
			if ( false === $real_addon ) {
				continue;
			}

			$plugin_name = $this->derive_addon_name( $real_addon, $addon_slug );

			// Scan docs subdirectory. glob_recursive() now skips vendor/,
			// node_modules/, etc. so a `docs/vendor/...` regression in any
			// addon will not pollute the index.
			$docs_dir = $real_addon . DIRECTORY_SEPARATOR . 'docs';
			if ( is_dir( $docs_dir ) ) {
				$files = $this->glob_recursive( $docs_dir, '*.md' );
				foreach ( $files as $file ) {
					if ( ! $this->is_path_safe( $file, array( $real_addon ) ) ) {
						continue;
					}
					if ( ! $this->is_allowed_file( $file ) ) {
						continue;
					}
					$relative  = ltrim( str_replace( $real_addon, '', $file ), DIRECTORY_SEPARATOR );
					$entries[] = array(
						'path'          => $file,
						'source'        => 'addons',
						'plugin_name'   => $plugin_name,
						'relative_path' => str_replace( DIRECTORY_SEPARATOR, '/', $relative ),
					);
				}
			}

			if ( ! $include_readmes ) {
				continue;
			}

			// Also include top-level README.md / CHANGELOG.md so each addon
			// can ship a one-page intro without a docs/ tree.
			foreach ( array( 'README.md', 'CHANGELOG.md' ) as $top_file ) {
				$candidate = $real_addon . DIRECTORY_SEPARATOR . $top_file;
				if ( file_exists( $candidate ) && $this->is_allowed_file( $candidate ) ) {
					$entries[] = array(
						'path'          => $candidate,
						'source'        => 'addons',
						'plugin_name'   => $plugin_name,
						'relative_path' => $top_file,
					);
				}
			}
		}

		return $entries;
	}

	/**
	 * Scan the repo root for well-known documentation files.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function scan_root() {
		// `.context/*.md` discovery still requires WP_DEBUG or context_enabled
		// and is handled by scan_context(). The well-known root files
		// (README, CHANGELOG, CONTRIBUTING, SECURITY) are non-sensitive and
		// are exactly what end users expect to see, so we always index them
		// when the `root` source is enabled.

		// Two levels up from plugin dir.
		$repo_root = dirname( dirname( NVOOS_DOCS_HUB_PATH ) );
		$real_root = realpath( $repo_root );
		if ( false === $real_root ) {
			return array();
		}

		$known_files = array( 'README.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md' );
		$entries     = array();

		foreach ( $known_files as $filename ) {
			$file_path = $real_root . DIRECTORY_SEPARATOR . $filename;
			if ( ! file_exists( $file_path ) ) {
				continue;
			}
			$real_file = realpath( $file_path );
			if ( false === $real_file ) {
				continue;
			}
			if ( ! $this->is_path_safe( $real_file, array( $real_root ) ) ) {
				continue;
			}
			if ( ! $this->is_allowed_file( $real_file ) ) {
				continue;
			}
			$entries[] = array(
				'path'          => $real_file,
				'source'        => 'root',
				'plugin_name'   => 'Repository',
				'relative_path' => $filename,
			);
		}

		return $entries;
	}

	/**
	 * Scan the .context directory for documentation.
	 *
	 * Only available when context_enabled is true AND current user has manage_options.
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	private function scan_context( $settings ) {
		if ( empty( $settings['context_enabled'] ) ) {
			return array();
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}

		$repo_root    = dirname( dirname( NVOOS_DOCS_HUB_PATH ) );
		$context_dir  = $repo_root . DIRECTORY_SEPARATOR . '.context';
		$real_context = realpath( $context_dir );
		if ( false === $real_context || ! is_dir( $real_context ) ) {
			return array();
		}

		$files   = glob( $real_context . DIRECTORY_SEPARATOR . '*.md' );
		$entries = array();

		if ( empty( $files ) ) {
			return array();
		}

		foreach ( $files as $file ) {
			$real_file = realpath( $file );
			if ( false === $real_file ) {
				continue;
			}
			if ( ! $this->is_path_safe( $real_file, array( $real_context ) ) ) {
				continue;
			}
			if ( ! $this->is_allowed_file( $real_file ) ) {
				continue;
			}
			$entries[] = array(
				'path'          => $real_file,
				'source'        => 'context',
				'plugin_name'   => 'Context',
				'relative_path' => '.context/' . basename( $real_file ),
			);
		}

		return $entries;
	}

	/**
	 * Check whether a resolved file path is within allowed root directories.
	 *
	 * Prevents path traversal attacks.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $real_path     Resolved absolute file path.
	 * @param string[] $allowed_roots List of allowed root directories.
	 * @return bool
	 */
	private function is_path_safe( $real_path, $allowed_roots ) {
		foreach ( $allowed_roots as $root ) {
			$real_root = realpath( $root );
			if ( false === $real_root ) {
				continue;
			}
			// Ensure the file is strictly within the allowed root.
			if ( 0 === strpos( $real_path, $real_root . DIRECTORY_SEPARATOR )
				|| $real_path === $real_root ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether a file passes extension and size checks.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Absolute file path.
	 * @return bool
	 */
	private function is_allowed_file( $file ) {
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
			return false;
		}
		$size = filesize( $file );
		if ( false === $size || $size > self::MAX_FILE_SIZE ) {
			return false;
		}
		return true;
	}

	/**
	 * Recursively glob for files matching a pattern in a directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir     Base directory.
	 * @param string $pattern Glob pattern (e.g. '*.md').
	 * @return string[]
	 */
	private function glob_recursive( $dir, $pattern ) {
		$results = array();
		$files   = glob( rtrim( $dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $pattern );
		if ( ! empty( $files ) ) {
			$results = array_merge( $results, $files );
		}
		$subdirs = glob( rtrim( $dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
		if ( ! empty( $subdirs ) ) {
			foreach ( $subdirs as $subdir ) {
				if ( $this->is_dir_pruned( $subdir ) ) {
					continue;
				}
				$results = array_merge( $results, $this->glob_recursive( $subdir, $pattern ) );
			}
		}
		return $results;
	}

	/**
	 * Whether a directory should be pruned during recursive walks.
	 *
	 * Pruning happens at directory-entry time so we never descend into
	 * `vendor/` or `node_modules/`, which can contain tens of thousands of
	 * irrelevant files in a single addon. This is what makes the scan
	 * affordable on big repos.
	 *
	 * @since 1.2.0
	 *
	 * @param string $dir Absolute directory path.
	 * @return bool
	 */
	private function is_dir_pruned( $dir ) {
		$basename = basename( $dir );

		/**
		 * Filter the list of directory basenames pruned during recursion.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $names List of directory basenames.
		 */
		$names = apply_filters( 'nvoos_docs_hub_pruned_dir_names', self::PRUNED_DIR_NAMES );

		return in_array( $basename, (array) $names, true );
	}

	/**
	 * Apply excluded glob patterns, removing matching entries.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Adds force-include override list.
	 *
	 * @param array    $entries        List of file entries.
	 * @param string[] $excluded       Glob patterns to exclude.
	 * @param string[] $force_included Glob patterns that override exclusions.
	 * @return array
	 */
	private function apply_exclusions( $entries, $excluded, $force_included = array() ) {
		return array_filter(
			$entries,
			function ( $entry ) use ( $excluded, $force_included ) {
				if ( $this->matches_any_glob( $entry, $force_included ) ) {
					return true;
				}
				return ! $this->matches_any_glob( $entry, $excluded );
			}
		);
	}

	/**
	 * Test an entry's relative_path / basename against a list of glob patterns.
	 *
	 * Supports the `**\/dir\/*` recursive syntax in addition to fnmatch's
	 * native `?` / `*` / `[…]` matching.
	 *
	 * @since 1.2.0
	 *
	 * @param array    $entry    File entry array.
	 * @param string[] $patterns Glob patterns.
	 * @return bool
	 */
	private function matches_any_glob( $entry, $patterns ) {
		$relative = isset( $entry['relative_path'] ) ? (string) $entry['relative_path'] : '';
		$basename = isset( $entry['path'] ) ? basename( (string) $entry['path'] ) : '';

		foreach ( (array) $patterns as $pattern ) {
			if ( '' === $pattern ) {
				continue;
			}

			// Native fnmatch first.
			if ( '' !== $relative && fnmatch( $pattern, $relative ) ) {
				return true;
			}
			if ( '' !== $basename && fnmatch( $pattern, $basename ) ) {
				return true;
			}

			// `**/dir/*` should match `dir/foo`, `a/dir/foo`, `a/b/dir/foo`.
			if ( 0 === strpos( $pattern, '**/' ) ) {
				$inner = substr( $pattern, 3 );
				if ( '' !== $relative ) {
					if ( fnmatch( $inner, $relative ) ) {
						return true;
					}
					// Walk every path-segment offset so `a/b/c.md` is checked
					// against `b/c.md` and `c.md` too.
					$tail = $relative;
					while ( false !== ( $pos = strpos( $tail, '/' ) ) ) {
						$tail = substr( $tail, $pos + 1 );
						if ( '' !== $tail && fnmatch( $inner, $tail ) ) {
							return true;
						}
					}
				}
			}
		}

		return false;
	}

	/**
	 * Scan remote repositories configured in the addon settings.
	 *
	 * Delegates to NV_oOS_Docs_Hub_Remote_Repo for each configured repo.
	 *
	 * @since 1.1.0
	 *
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	private function scan_remote_repos( $settings ) {
		$remote_repos = isset( $settings['remote_repos'] ) ? (array) $settings['remote_repos'] : array();
		if ( empty( $remote_repos ) ) {
			return array();
		}

		$fetcher = new NV_oOS_Docs_Hub_Remote_Repo();
		$entries = array();

		foreach ( $remote_repos as $repo_config ) {
			if ( empty( $repo_config['owner'] ) || empty( $repo_config['repo'] ) ) {
				continue;
			}

			$result = $fetcher->fetch_entries( $repo_config );

			if ( is_wp_error( $result ) ) {
				// Log error but continue with other repos.
				do_action( 'nvoos_docs_hub_remote_fetch_error', $result, $repo_config );
				continue;
			}

			$entries = array_merge( $entries, $result );
		}

		return $entries;
	}

	/**
	 * Derive a human-readable addon name from the plugin PHP file header.
	 *
	 * @since 1.0.0
	 *
	 * @param string $addon_dir  Absolute path to addon directory.
	 * @param string $addon_slug Directory slug as fallback.
	 * @return string
	 */
	private function derive_addon_name( $addon_dir, $addon_slug ) {
		$php_files = glob( $addon_dir . DIRECTORY_SEPARATOR . '*.php' );
		if ( ! empty( $php_files ) ) {
			foreach ( $php_files as $php_file ) {
				$contents = file_get_contents( $php_file );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( false !== $contents && preg_match( '/Plugin Name:\s*(.+)/i', $contents, $m ) ) {
					return trim( $m[1] );
				}
			}
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $addon_slug ) );
	}
}
