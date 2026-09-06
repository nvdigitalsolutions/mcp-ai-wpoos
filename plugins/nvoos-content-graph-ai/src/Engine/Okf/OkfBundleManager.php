<?php
/**
 * OKF bundle manager (Wave E6, sub-cluster 4).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_OKF_Bundle_Manager`
 * (`includes/okf/class-wp-mcp-ai-okf-bundle-manager.php`):
 * byte-identical single source of truth for OKF bundle locations and
 * lifecycle — the `wp_mcp_ai_okf_knowledge_root` filter with the
 * `mcp-ai-wpoos/knowledge` default subdirectory (data survives mode
 * transitions), the realpath-containment bundle resolution with the
 * `okf_invalid_bundle` / `okf_bundle_not_found` error codes, the
 * protected/standard bundle sets (`skill-knowledge` protected;
 * `site-knowledge`/`external-bundles` reserved), listing with the
 * full descriptor shape, create/rename/archive/delete, ZIP
 * export/import with the ZipSlip + symlink + entry-count + size
 * defenses (`MAX_ZIP_ENTRIES` 5000, `MAX_ZIP_TOTAL_BYTES` 25 MB),
 * the `okf_version: "0.2"` root-index stamping, raw-concept saves
 * with reserved-filename protection, log maintenance, and the
 * security-guard files.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - The Filesystem Service resolves per install mode via
 *    `defined( 'WP_MCP_AI_PATH' ) && class_exists( … )` — monolith
 *    installs use the base `WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service`
 *    singleton exactly as the base does; standalone installs take the
 *    base's own native-PHP fallback paths (the monorepo classmap would
 *    otherwise resolve the base class in standalone test runs).
 *  - `WP_Error` is fully qualified.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Okf
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Okf;

/**
 * Manages OKF bundle directories under the runtime knowledge root.
 *
 * @since 1.1.0
 */
class OkfBundleManager {

	/**
	 * Knowledge root subdirectory inside the uploads directory.
	 *
	 * @var string
	 */
	const KNOWLEDGE_SUBDIR = 'mcp-ai-wpoos/knowledge';

	/**
	 * Archive (trash) directory name inside the knowledge root.
	 *
	 * @var string
	 */
	const TRASH_DIR = '.trash';

	/**
	 * Conservative slug pattern for newly created bundle names.
	 *
	 * @var string
	 */
	const BUNDLE_NAME_REGEX = '/^[a-z0-9][a-z0-9_-]{0,99}$/';

	/**
	 * Maximum number of entries accepted in an imported ZIP archive.
	 *
	 * @var int
	 */
	const MAX_ZIP_ENTRIES = 5000;

	/**
	 * Maximum unpacked size (bytes) accepted for an imported ZIP archive.
	 *
	 * 25 MB — OKF bundles are markdown + small reference files.
	 *
	 * @var int
	 */
	const MAX_ZIP_TOTAL_BYTES = 26214400;

	/**
	 * Bundle names that are auto-generated and must not be modified directly.
	 *
	 * @return string[] Protected bundle names.
	 */
	public function get_protected_bundles() {
		return array( 'skill-knowledge' );
	}

	/**
	 * Bundle names with a documented, reserved role in the runtime layout.
	 *
	 * @return string[] Standard bundle names.
	 */
	public function get_standard_bundles() {
		return array( 'skill-knowledge', 'site-knowledge', 'external-bundles' );
	}

	/**
	 * Get the absolute knowledge root path, creating it (with security
	 * guards) on first use.
	 *
	 * @return string|\WP_Error Absolute normalized path, or WP_Error when the
	 *                          uploads directory is unavailable or unwritable.
	 */
	public function get_knowledge_root() {
		$upload_dir = \wp_upload_dir();

		if ( empty( $upload_dir['basedir'] ) ) {
			return new \WP_Error(
				'okf_no_uploads',
				__( 'The WordPress uploads directory is unavailable; OKF knowledge cannot be stored.', 'nvoos-content-graph-ai' )
			);
		}

		$root = \wp_normalize_path( $upload_dir['basedir'] . '/' . self::KNOWLEDGE_SUBDIR );

		/**
		 * Filter the OKF knowledge root directory path.
		 *
		 * @since 1.1.0
		 *
		 * @param string $root Absolute path to the knowledge root directory.
		 */
		$root = \apply_filters( 'wp_mcp_ai_okf_knowledge_root', $root );

		if ( ! \is_dir( $root ) && ! \wp_mkdir_p( $root ) ) {
			return new \WP_Error(
				'okf_mkdir_error',
				__( 'Failed to create the OKF knowledge root directory.', 'nvoos-content-graph-ai' )
			);
		}

		$this->write_security_guards( $root );

		return $root;
	}

	/**
	 * Write .htaccess + index.php guards into a directory when missing.
	 *
	 * Mirrors the Paper Store manager's guard pattern: OKF bundle files are
	 * read via PHP filesystem APIs and must never be served over HTTP.
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	private function write_security_guards( $dir ) {
		$htaccess = $dir . '/.htaccess';
		if ( ! \file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Managed security file creation; Paper Store uses the same pattern.
			\file_put_contents( $htaccess, "Deny from all\n" );
		}

		$index_php = $dir . '/index.php';
		if ( ! \file_exists( $index_php ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Managed security file creation; Paper Store uses the same pattern.
			\file_put_contents( $index_php, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Resolve a bundle name to an absolute directory path.
	 *
	 * Containment is enforced with realpath, which also rejects symlinked
	 * bundles pointing outside the knowledge root. Names for *new* bundles
	 * are restricted to a conservative slug; already-existing bundles with
	 * legacy names stay readable for backward compatibility.
	 *
	 * @param string $bundle Bundle name.
	 * @param bool   $create When true, a missing bundle directory is not an
	 *                       error — the caller may create it.
	 * @return string|\WP_Error Absolute normalized path, or WP_Error with codes
	 *                          'okf_invalid_bundle' or 'okf_bundle_not_found'.
	 */
	public function resolve_bundle_root( $bundle, $create = false ) {
		$bundle = (string) $bundle;

		if ( '' === $bundle ) {
			return new \WP_Error(
				'okf_invalid_bundle',
				__( 'Invalid bundle name. Use lowercase letters, numbers, hyphens, and underscores only.', 'nvoos-content-graph-ai' )
			);
		}

		$root = $this->get_knowledge_root();
		if ( \is_wp_error( $root ) ) {
			return $root;
		}

		$path = \wp_normalize_path( $root . '/' . $bundle );

		// realpath containment: the bundle (when it exists) or its parent
		// (when it does not) must stay inside the knowledge root. This also
		// rejects symlink escapes.
		$real_root  = \realpath( $root );
		$real_check = \is_dir( $path ) ? \realpath( $path ) : \realpath( \dirname( $path ) );

		if ( false === $real_root || false === $real_check ) {
			return new \WP_Error(
				'okf_invalid_bundle',
				__( 'Invalid bundle name. Use lowercase letters, numbers, hyphens, and underscores only.', 'nvoos-content-graph-ai' )
			);
		}

		if ( $real_check !== $real_root && 0 !== \strpos( $real_check, $real_root . DIRECTORY_SEPARATOR ) ) {
			return new \WP_Error(
				'okf_invalid_bundle',
				__( 'Invalid bundle name. Use lowercase letters, numbers, hyphens, and underscores only.', 'nvoos-content-graph-ai' )
			);
		}

		// Existing bundles keep working under their legacy names.
		if ( \is_dir( $path ) ) {
			return $path;
		}

		// New bundle names become directory names: validate strictly.
		if ( 1 !== \preg_match( self::BUNDLE_NAME_REGEX, $bundle ) ) {
			return new \WP_Error(
				'okf_invalid_bundle',
				__( 'Invalid bundle name. Use lowercase letters, numbers, hyphens, and underscores only.', 'nvoos-content-graph-ai' )
			);
		}

		if ( ! $create ) {
			return new \WP_Error(
				'okf_bundle_not_found',
				\sprintf(
					/* translators: %s: bundle name */
					__( 'OKF bundle not found: %s', 'nvoos-content-graph-ai' ),
					$bundle
				)
			);
		}

		return $path;
	}

	/**
	 * Check whether a bundle is auto-generated and protected from writes.
	 *
	 * @param string $bundle Bundle name.
	 * @return bool True when the bundle is protected.
	 */
	public function is_protected_bundle( $bundle ) {
		return \in_array( (string) $bundle, $this->get_protected_bundles(), true );
	}

	/**
	 * Check whether a bundle name has a reserved role in the runtime layout.
	 *
	 * @param string $bundle Bundle name.
	 * @return bool True when the bundle is standard/reserved.
	 */
	public function is_standard_bundle( $bundle ) {
		return \in_array( (string) $bundle, $this->get_standard_bundles(), true );
	}

	/**
	 * Assert that a bundle may be modified (written, renamed, archived).
	 *
	 * @param string $bundle Bundle name.
	 * @return true|\WP_Error True when writable, WP_Error 'okf_protected_bundle' otherwise.
	 */
	public function assert_bundle_writable( $bundle ) {
		if ( $this->is_protected_bundle( $bundle ) ) {
			return new \WP_Error(
				'okf_protected_bundle',
				__( 'The skill-knowledge bundle is auto-generated and cannot be modified directly. Curate site-specific knowledge in site-knowledge instead.', 'nvoos-content-graph-ai' )
			);
		}

		return true;
	}

	/**
	 * List every bundle under the knowledge root with its statistics.
	 *
	 * Dot-directories (including .trash) and non-directories are skipped, as
	 * are symlinked directories pointing outside the knowledge root.
	 *
	 * @return array|\WP_Error List of bundle descriptors, or WP_Error.
	 */
	public function list_bundles() {
		$root = $this->get_knowledge_root();
		if ( \is_wp_error( $root ) ) {
			return $root;
		}

		if ( ! \is_dir( $root ) ) {
			return array();
		}

		$items = \scandir( $root );
		if ( false === $items ) {
			return new \WP_Error(
				'okf_read_error',
				__( 'Failed to scan the OKF knowledge root directory.', 'nvoos-content-graph-ai' )
			);
		}

		$real_root = \realpath( $root );
		$bundles   = array();

		foreach ( $items as $item ) {
			if ( '.' === $item[0] ) {
				continue; // Skip dotfiles, including .trash.
			}

			$path = \wp_normalize_path( $root . '/' . $item );
			if ( ! \is_dir( $path ) ) {
				continue;
			}

			$real = \realpath( $path );
			if ( false === $real || false === $real_root ) {
				continue;
			}
			if ( $real !== $real_root && 0 !== \strpos( $real, $real_root . DIRECTORY_SEPARATOR ) ) {
				continue; // Symlink escape; never surface it as a bundle.
			}

			$bundles[] = $this->describe_bundle( $item, $path );
		}

		\usort(
			$bundles,
			static function ( $a, $b ) {
				return \strcmp( $a['name'], $b['name'] );
			}
		);

		return $bundles;
	}

	/**
	 * Build a descriptor array for one bundle.
	 *
	 * @param string $name Bundle name.
	 * @param string $path Absolute bundle path.
	 * @return array Descriptor with name, path, protected flag, concept/issue/
	 *               stale/deprecated counts, types, trust tiers, and mtime.
	 */
	private function describe_bundle( $name, $path ) {
		$writer = new OkfWriter( $path );
		$reader = new OkfReader( $path );

		$validation = $writer->validate_bundle();
		$concepts   = $reader->search( array() );

		$trust_tiers = array(
			'unverified'        => 0,
			'machine-confirmed' => 0,
			'human-reviewed'    => 0,
		);

		foreach ( $concepts as $concept ) {
			$tier = isset( $concept['trust_tier'] ) ? $concept['trust_tier'] : 'unverified';
			if ( isset( $trust_tiers[ $tier ] ) ) {
				++$trust_tiers[ $tier ];
			}
		}

		$modified = \filemtime( $path );

		return array(
			'name'              => $name,
			'path'              => $path,
			'protected'         => $this->is_protected_bundle( $name ),
			'concept_count'     => $validation['concept_count'],
			'stale_count'       => $validation['stale_count'],
			'deprecated_count'  => $validation['deprecated_count'],
			'broken_link_count' => \count( $validation['broken_links'] ),
			'conformant'        => $validation['conformant'],
			'issue_count'       => \count( $validation['issues'] ),
			'types'             => $reader->get_types(),
			'trust_tiers'       => $trust_tiers,
			'modified'          => false === $modified ? 0 : $modified,
		);
	}

	/**
	 * Get the statistics for a single bundle.
	 *
	 * @param string $bundle Bundle name.
	 * @return array|\WP_Error Bundle descriptor, or WP_Error.
	 */
	public function bundle_stats( $bundle ) {
		$path = $this->resolve_bundle_root( $bundle );
		if ( \is_wp_error( $path ) ) {
			return $path;
		}

		return $this->describe_bundle( $bundle, $path );
	}

	/**
	 * Create a new, empty OKF bundle.
	 *
	 * Stamps the bundle-root index.md with `okf_version: "0.2"` (OKF v0.2
	 * §12) and initializes log.md (OKF v0.2 §9). Fires the documented
	 * `wp_mcp_ai_okf_bundle_initialized` event via ensure_bundle_root().
	 *
	 * @param string $name Bundle name.
	 * @return array|\WP_Error Descriptor array ('bundle', 'path'), or WP_Error.
	 */
	public function create_bundle( $name ) {
		if ( $this->is_protected_bundle( $name ) ) {
			return new \WP_Error(
				'okf_protected_bundle',
				__( 'The skill-knowledge bundle is auto-generated and cannot be created manually.', 'nvoos-content-graph-ai' )
			);
		}

		$path = $this->resolve_bundle_root( $name, true );
		if ( \is_wp_error( $path ) ) {
			return $path;
		}

		if ( \is_dir( $path ) ) {
			return new \WP_Error(
				'okf_bundle_exists',
				\sprintf(
					/* translators: %s: bundle name */
					__( 'OKF bundle already exists: %s', 'nvoos-content-graph-ai' ),
					$name
				)
			);
		}

		$writer  = new OkfWriter( $path );
		$ensured = $writer->ensure_bundle_root();
		if ( \is_wp_error( $ensured ) ) {
			return $ensured;
		}

		$index_path    = $path . '/index.md';
		$index_content = "---\nokf_version: \"" . OkfWriter::OKF_VERSION . "\"\n---\n\n# {$name}\n";
		$written       = $this->put_contents( $index_path, $index_content );
		if ( \is_wp_error( $written ) ) {
			return $written;
		}

		$log_result = $writer->append_log( '', 'Created bundle.', 'Initialization' );
		if ( \is_wp_error( $log_result ) ) {
			return $log_result;
		}

		return array(
			'bundle' => $name,
			'path'   => $path,
		);
	}

	/**
	 * Rename a user-created bundle.
	 *
	 * Standard bundles (skill-knowledge, site-knowledge, external-bundles)
	 * cannot be renamed — their names are part of the documented layout.
	 *
	 * @param string $from Current bundle name.
	 * @param string $to   New bundle name.
	 * @return array|\WP_Error Descriptor array ('bundle', 'path'), or WP_Error.
	 */
	public function rename_bundle( $from, $to ) {
		if ( $this->is_standard_bundle( $from ) ) {
			return new \WP_Error(
				'okf_protected_bundle',
				__( 'This bundle has a reserved role in the OKF layout and cannot be renamed.', 'nvoos-content-graph-ai' )
			);
		}

		$from_path = $this->resolve_bundle_root( $from );
		if ( \is_wp_error( $from_path ) ) {
			return $from_path;
		}

		$to_path = $this->resolve_bundle_root( $to, true );
		if ( \is_wp_error( $to_path ) ) {
			return $to_path;
		}

		if ( \is_dir( $to_path ) ) {
			return new \WP_Error(
				'okf_bundle_exists',
				\sprintf(
					/* translators: %s: bundle name */
					__( 'OKF bundle already exists: %s', 'nvoos-content-graph-ai' ),
					$to
				)
			);
		}

		if ( ! $this->rename_path( $from_path, $to_path ) ) {
			return new \WP_Error(
				'okf_rename_error',
				__( 'Failed to rename the OKF bundle.', 'nvoos-content-graph-ai' )
			);
		}

		return array(
			'bundle' => $to,
			'path'   => $to_path,
		);
	}

	/**
	 * Archive a bundle into the knowledge root's .trash directory.
	 *
	 * Recoverable: the bundle directory is moved, not deleted. Protected
	 * bundles (skill-knowledge) cannot be archived.
	 *
	 * @param string $bundle Bundle name.
	 * @return array|\WP_Error Descriptor with the trash path, or WP_Error.
	 */
	public function archive_bundle( $bundle ) {
		$writable = $this->assert_bundle_writable( $bundle );
		if ( \is_wp_error( $writable ) ) {
			return $writable;
		}

		$path = $this->resolve_bundle_root( $bundle );
		if ( \is_wp_error( $path ) ) {
			return $path;
		}

		$root = $this->get_knowledge_root();
		if ( \is_wp_error( $root ) ) {
			return $root;
		}

		$trash = \wp_normalize_path( $root . '/' . self::TRASH_DIR );
		if ( ! \is_dir( $trash ) && ! \wp_mkdir_p( $trash ) ) {
			return new \WP_Error(
				'okf_mkdir_error',
				__( 'Failed to create the OKF trash directory.', 'nvoos-content-graph-ai' )
			);
		}
		$this->write_security_guards( $trash );

		$trash_path = \wp_normalize_path( $trash . '/' . $bundle . '-' . \gmdate( 'YmdHis' ) );

		if ( ! $this->rename_path( $path, $trash_path ) ) {
			return new \WP_Error(
				'okf_archive_error',
				__( 'Failed to archive the OKF bundle.', 'nvoos-content-graph-ai' )
			);
		}

		return array(
			'bundle'     => $bundle,
			'trash_path' => $trash_path,
		);
	}

	/**
	 * Permanently delete a bundle directory tree.
	 *
	 * Protected bundles (skill-knowledge) cannot be deleted.
	 *
	 * @param string $bundle Bundle name.
	 * @return array|\WP_Error Descriptor array, or WP_Error.
	 */
	public function delete_bundle( $bundle ) {
		$writable = $this->assert_bundle_writable( $bundle );
		if ( \is_wp_error( $writable ) ) {
			return $writable;
		}

		$path = $this->resolve_bundle_root( $bundle );
		if ( \is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! $this->remove_tree( $path ) ) {
			return new \WP_Error(
				'okf_delete_error',
				__( 'Failed to delete the OKF bundle.', 'nvoos-content-graph-ai' )
			);
		}

		return array(
			'bundle'  => $bundle,
			'deleted' => true,
		);
	}

	/**
	 * Export a bundle as a ZIP archive (OKF v0.2 §3 distribution format).
	 *
	 * Soft-deleted concept backups (<file>.deleted.<timestamp>) are excluded.
	 *
	 * @param string $bundle Bundle name.
	 * @return array|\WP_Error Descriptor array ('path', 'url', 'entries'), or WP_Error.
	 */
	public function export_bundle_zip( $bundle ) {
		if ( ! \class_exists( 'ZipArchive' ) ) {
			return new \WP_Error(
				'okf_zip_missing',
				__( 'The PHP ZipArchive extension is required to export OKF bundles.', 'nvoos-content-graph-ai' )
			);
		}

		$path = $this->resolve_bundle_root( $bundle );
		if ( \is_wp_error( $path ) ) {
			return $path;
		}

		$upload_dir = \wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return new \WP_Error(
				'okf_no_uploads',
				__( 'The WordPress uploads directory is unavailable; cannot export the OKF bundle.', 'nvoos-content-graph-ai' )
			);
		}

		$export_dir = \wp_normalize_path( $upload_dir['basedir'] . '/mcp-ai-wpoos/okf-exports' );
		if ( ! \is_dir( $export_dir ) && ! \wp_mkdir_p( $export_dir ) ) {
			return new \WP_Error(
				'okf_mkdir_error',
				__( 'Failed to create the OKF export directory.', 'nvoos-content-graph-ai' )
			);
		}

		$zip_path = \wp_normalize_path( $export_dir . '/' . $bundle . '-' . \gmdate( 'YmdHis' ) . '.zip' );

		$zip    = new \ZipArchive();
		$opened = $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		if ( true !== $opened ) {
			return new \WP_Error(
				'okf_zip_write_error',
				__( 'Could not create the OKF bundle ZIP archive.', 'nvoos-content-graph-ai' )
			);
		}

		$prefix = \rtrim( $path, '/\\' );
		$count  = 0;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( \UnexpectedValueException $e ) {
			$zip->close();

			return new \WP_Error(
				'okf_read_error',
				__( 'Failed to scan the OKF bundle for export.', 'nvoos-content-graph-ai' )
			);
		}

		foreach ( $iterator as $file_info ) {
			$real_path = $file_info->getPathname();
			$relative  = \str_replace( '\\', '/', \substr( $real_path, \strlen( $prefix ) + 1 ) );

			// Skip soft-deleted concept backups.
			if ( false !== \strpos( $relative, '.deleted.' ) ) {
				continue;
			}

			if ( $file_info->isDir() ) {
				$zip->addEmptyDir( $relative );
				continue;
			}

			if ( $zip->addFile( $real_path, $relative ) ) {
				++$count;
			}
		}

		$zip->close();

		return array(
			'path'    => $zip_path,
			'url'     => \trailingslashit( $upload_dir['baseurl'] ) . 'mcp-ai-wpoos/okf-exports/' . \basename( $zip_path ),
			'entries' => $count,
		);
	}

	/**
	 * Import a bundle from a ZIP archive (OKF v0.2 §3 distribution format).
	 *
	 * Applies ZipSlip defenses, symlink rejection, entry/size caps, and a
	 * minimum-content check before the bundle becomes visible to the tools.
	 *
	 * @param string $zip_path Absolute path to the ZIP archive.
	 * @param string $bundle   Target bundle name.
	 * @return array|\WP_Error Descriptor array ('bundle', 'path', 'concepts',
	 *                          'conformant', 'issues'), or WP_Error.
	 */
	public function import_bundle_zip( $zip_path, $bundle ) {
		if ( ! \class_exists( 'ZipArchive' ) ) {
			return new \WP_Error(
				'okf_zip_missing',
				__( 'The PHP ZipArchive extension is required to import OKF bundles.', 'nvoos-content-graph-ai' )
			);
		}

		$writable = $this->assert_bundle_writable( $bundle );
		if ( \is_wp_error( $writable ) ) {
			return $writable;
		}

		$target = $this->resolve_bundle_root( $bundle, true );
		if ( \is_wp_error( $target ) ) {
			return $target;
		}

		if ( \is_dir( $target ) ) {
			return new \WP_Error(
				'okf_bundle_exists',
				\sprintf(
					/* translators: %s: bundle name */
					__( 'OKF bundle already exists: %s', 'nvoos-content-graph-ai' ),
					$bundle
				)
			);
		}

		$zip    = new \ZipArchive();
		$opened = $zip->open( $zip_path );
		if ( true !== $opened ) {
			return new \WP_Error(
				'okf_zip_open_error',
				/* translators: %d: ZipArchive error code */
				\sprintf( __( 'Could not open the ZIP archive (code %d).', 'nvoos-content-graph-ai' ), $opened )
			);
		}

		// `num_files` is not exposed as a ZipArchive property on PHP 8.x
		// (the loop silently never runs), so count the archive instead.
		$entry_count = \count( $zip );
		if ( $entry_count > self::MAX_ZIP_ENTRIES ) {
			$zip->close();

			return new \WP_Error(
				'okf_zip_too_many_entries',
				__( 'The ZIP archive contains too many entries.', 'nvoos-content-graph-ai' )
			);
		}

		$total_size = 0;
		for ( $i = 0; $i < $entry_count; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false === $name || $this->is_unsafe_zip_entry( $name ) ) {
				$zip->close();

				return new \WP_Error(
					'okf_zip_unsafe_entry',
					__( 'The ZIP archive contains an unsafe file path and was rejected.', 'nvoos-content-graph-ai' )
				);
			}

			$stats = $zip->statIndex( $i );
			if ( \is_array( $stats ) ) {
				if ( isset( $stats['size'] ) ) {
					$total_size += (int) $stats['size'];
				}

				if ( isset( $stats['mode'] ) && 0xA000 === ( (int) $stats['mode'] & 0xF000 ) ) {
					$zip->close();

					return new \WP_Error(
						'okf_zip_symlink_entry',
						__( 'The ZIP archive contains a symbolic link and was rejected.', 'nvoos-content-graph-ai' )
					);
				}
			}
		}

		if ( $total_size > self::MAX_ZIP_TOTAL_BYTES ) {
			$zip->close();

			return new \WP_Error(
				'okf_zip_too_large',
				__( 'The ZIP archive expands beyond the allowed size.', 'nvoos-content-graph-ai' )
			);
		}

		if ( ! \wp_mkdir_p( $target ) ) {
			$zip->close();

			return new \WP_Error(
				'okf_mkdir_error',
				__( 'Failed to create the OKF bundle directory.', 'nvoos-content-graph-ai' )
			);
		}

		if ( ! $zip->extractTo( $target ) ) {
			$zip->close();
			$this->remove_tree( $target );

			return new \WP_Error(
				'okf_zip_extract_error',
				__( 'ZIP extraction failed.', 'nvoos-content-graph-ai' )
			);
		}
		$zip->close();

		// Belt-and-braces: remove any symlink that survived extraction.
		$this->remove_symlinks( $target );

		$writer   = new OkfWriter( $target );
		$reader   = new OkfReader( $target );
		$concepts = $reader->search( array() );

		// A bundle with zero concepts is not useful to the tools; reject it
		// and clean up rather than leaving a hollow directory behind.
		if ( empty( $concepts ) ) {
			$this->remove_tree( $target );

			return new \WP_Error(
				'okf_zip_no_concepts',
				__( 'The ZIP archive contains no OKF concept documents.', 'nvoos-content-graph-ai' )
			);
		}

		// OKF v0.2 §12: the bundle-root index may declare okf_version. Generate
		// a missing index, and stamp the version on an existing one that lacks
		// the frontmatter block (entries are preserved).
		$index_path = $target . '/index.md';
		if ( ! \file_exists( $index_path ) ) {
			$writer->regenerate_index( '' );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read of the imported index.
			$index_content = \file_get_contents( $index_path );
			if ( \is_string( $index_content ) && 0 !== \strpos( $index_content, '---' ) ) {
				$stamped = "---\nokf_version: \"" . OkfWriter::OKF_VERSION . "\"\n---\n\n" . $index_content;
				$this->put_contents( $index_path, $stamped );
			}
		}

		$validation = $writer->validate_bundle();

		/**
		 * Fires after an OKF bundle directory has been created by import.
		 *
		 * @since 1.1.0
		 *
		 * @param string $bundle_path   Absolute path to the new bundle directory.
		 * @param int    $concept_count Number of concepts imported.
		 */
		\do_action( 'wp_mcp_ai_okf_bundle_initialized', $target, \count( $concepts ) );

		return array(
			'bundle'     => $bundle,
			'path'       => $target,
			'concepts'   => \count( $concepts ),
			'conformant' => $validation['conformant'],
			'issues'     => $validation['issues'],
		);
	}

	/**
	 * Detect ZipSlip-style archive entries.
	 *
	 * Mirrors the conversation-import subsystem's guard: rejects absolute
	 * paths, drive letters, `..` traversal, and backslashes (which some
	 * extractors treat as separators on Windows).
	 *
	 * @param string $name Entry name as reported by the archive.
	 * @return bool True when the entry must be rejected.
	 */
	private function is_unsafe_zip_entry( $name ) {
		if ( '' === $name ) {
			return true;
		}

		if ( false !== \strpos( $name, '..' ) ) {
			return true;
		}

		if ( false !== \strpos( $name, '\\' ) ) {
			return true;
		}

		if ( \preg_match( '#^[a-zA-Z]:#', $name ) ) {
			return true;
		}

		if ( 0 === \strpos( $name, '/' ) || 0 === \strpos( $name, '\\\\' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Append an entry to a bundle's log.md (OKF v0.2 §9).
	 *
	 * @param string $bundle Bundle name.
	 * @param string $path   Directory path relative to bundle root ('' for root).
	 * @param string $entry  Log entry text (without the leading bullet).
	 * @param string $action Action name for the bold prefix (Update, Creation, Deletion…).
	 * @return true|\WP_Error
	 */
	public function append_log( $bundle, $path, $entry, $action = 'Update' ) {
		$bundle_root = $this->resolve_bundle_root( $bundle );
		if ( \is_wp_error( $bundle_root ) ) {
			return $bundle_root;
		}

		$writer = new OkfWriter( $bundle_root );

		return $writer->append_log( $path, $entry, $action );
	}

	/**
	 * Save a concept's raw file content (admin editor path).
	 *
	 * Validates the concept ID (no traversal, no reserved filenames), requires
	 * parseable frontmatter with a non-empty `type`, writes atomically, appends
	 * a log.md entry, and fires the concept-saved event. Auto-generated bundles
	 * are refused like every other write path.
	 *
	 * @param string $bundle     Bundle name.
	 * @param string $concept_id Concept ID (path without the .md suffix).
	 * @param string $content    Raw markdown content (frontmatter + body).
	 * @return true|\WP_Error
	 */
	public function save_concept_raw( $bundle, $concept_id, $content ) {
		$writable = $this->assert_bundle_writable( $bundle );
		if ( \is_wp_error( $writable ) ) {
			return $writable;
		}

		$bundle_root = $this->resolve_bundle_root( $bundle );
		if ( \is_wp_error( $bundle_root ) ) {
			return $bundle_root;
		}

		$concept_id = \ltrim( (string) $concept_id, '/' );

		if (
			'' === $concept_id
			|| false !== \strpos( $concept_id, '..' )
			|| false !== \strpos( $concept_id, '\\' )
			|| \preg_match( '#^[a-zA-Z]:#', $concept_id )
		) {
			return new \WP_Error(
				'okf_invalid_concept',
				__( 'Invalid concept ID.', 'nvoos-content-graph-ai' )
			);
		}

		// Reserved filenames must never be written directly (OKF v0.2 §3.1).
		if ( \in_array( \basename( $concept_id ), array( 'index', 'log' ), true ) ) {
			return new \WP_Error(
				'okf_reserved_filename',
				__( 'index.md and log.md are reserved and cannot be edited directly.', 'nvoos-content-graph-ai' )
			);
		}

		// The editor writes whole files, so the content must itself be a
		// conformant concept: parseable frontmatter with a non-empty type.
		$parser = new OkfParser();
		$parsed = $parser->parse( $content );
		if ( \is_wp_error( $parsed ) ) {
			return $parsed;
		}
		if ( ! \is_array( $parsed ) || empty( $parsed['frontmatter']['type'] ) ) {
			return new \WP_Error(
				'okf_missing_type',
				__( 'OKF concept requires a "type" field in frontmatter.', 'nvoos-content-graph-ai' )
			);
		}

		$file_path = \wp_normalize_path( $bundle_root . '/' . $concept_id . '.md' );
		$dir       = \dirname( $file_path );

		// Ensure the parent directory exists before the containment check.
		if ( ! \is_dir( $dir ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- OKF bundles are local filesystem only; error caught below.
			if ( ! @\mkdir( $dir, 0755, true ) ) {
				return new \WP_Error(
					'okf_mkdir_error',
					__( 'Failed to create OKF concept directory.', 'nvoos-content-graph-ai' )
				);
			}
		}

		// realpath containment on the parent (symlink-safe).
		$parent_real = \realpath( $dir );
		$root_real   = \realpath( $bundle_root );
		if (
			false === $parent_real
			|| false === $root_real
			|| ( $parent_real !== $root_real && 0 !== \strpos( $parent_real, $root_real . DIRECTORY_SEPARATOR ) )
		) {
			return new \WP_Error(
				'okf_invalid_concept',
				__( 'Concept path escapes the bundle root.', 'nvoos-content-graph-ai' )
			);
		}

		$written = $this->put_contents( $file_path, $content );
		if ( \is_wp_error( $written ) ) {
			return $written;
		}

		$log_dir = \dirname( $concept_id );
		if ( '.' === $log_dir ) {
			$log_dir = '';
		}
		$this->append_log(
			$bundle,
			$log_dir,
			\sprintf(
				/* translators: %s: concept ID */
				__( 'Concept "%s" saved.', 'nvoos-content-graph-ai' ),
				$concept_id
			),
			'Update'
		);

		/**
		 * Fires after an OKF concept is saved.
		 *
		 * @since 1.1.0
		 *
		 * @param string $concept_id The concept ID that was saved.
		 * @param string $file_path  Absolute path to the saved file.
		 */
		\do_action( 'wp_mcp_ai_okf_concept_saved', $concept_id, $file_path );

		return true;
	}

	/**
	 * Write file contents with an atomic write when possible.
	 *
	 * @param string $path    Absolute file path.
	 * @param string $content File content.
	 * @return true|\WP_Error
	 */
	private function put_contents( $path, $content ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI\\Filesystem\\WP_MCP_AI_Filesystem_Service' ) ) {
			$result = \WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service::get_instance()->write_file( $path, $content );
			if ( \is_wp_error( $result ) ) {
				return $result;
			}

			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- OKF bundles are local filesystem only; LOCK_EX provides atomicity.
		$written = \file_put_contents( $path, $content, LOCK_EX );
		if ( false === $written ) {
			return new \WP_Error(
				'okf_write_error',
				__( 'Failed to write the OKF file.', 'nvoos-content-graph-ai' )
			);
		}

		return true;
	}

	/**
	 * Move a path, preferring the filesystem service when available.
	 *
	 * @param string $from Absolute source path.
	 * @param string $to   Absolute target path.
	 * @return bool True on success.
	 */
	private function rename_path( $from, $to ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI\\Filesystem\\WP_MCP_AI_Filesystem_Service' ) ) {
			$result = \WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service::get_instance()->rename( $from, $to );
			if ( ! \is_wp_error( $result ) ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- OKF bundles are local filesystem only.
		return @\rename( $from, $to );
	}

	/**
	 * Recursively remove a directory tree.
	 *
	 * @param string $dir Absolute directory path.
	 * @return bool True when the directory no longer exists.
	 */
	private function remove_tree( $dir ) {
		if ( ! \is_dir( $dir ) ) {
			return true;
		}

		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI\\Filesystem\\WP_MCP_AI_Filesystem_Service' ) ) {
			$result = \WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service::get_instance()->remove( $dir );
			if ( ! \is_wp_error( $result ) && ! \is_dir( $dir ) ) {
				return true;
			}
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
		} catch ( \UnexpectedValueException $e ) {
			return false;
		}

		foreach ( $iterator as $file_info ) {
			if ( $file_info->isDir() ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Recursive cleanup of a local bundle directory.
				@\rmdir( $file_info->getPathname() );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Recursive cleanup of a local bundle directory.
				@\unlink( $file_info->getPathname() );
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Recursive cleanup of a local bundle directory.
		@\rmdir( $dir );

		return ! \is_dir( $dir );
	}

	/**
	 * Remove symlinked files/directories from an extracted bundle.
	 *
	 * ZipArchive can materialize symlink entries on some platforms; this
	 * sweep guarantees no symlink survives import.
	 *
	 * @param string $dir Absolute directory path to sweep.
	 * @return void
	 */
	private function remove_symlinks( $dir ) {
		if ( ! \is_dir( $dir ) ) {
			return;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
		} catch ( \UnexpectedValueException $e ) {
			return;
		}

		foreach ( $iterator as $file_info ) {
			if ( ! \is_link( $file_info->getPathname() ) ) {
				continue;
			}

			if ( $file_info->isDir() ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Symlink sweep after ZIP import.
				@\rmdir( $file_info->getPathname() );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Symlink sweep after ZIP import.
				@\unlink( $file_info->getPathname() );
			}
		}
	}
}
