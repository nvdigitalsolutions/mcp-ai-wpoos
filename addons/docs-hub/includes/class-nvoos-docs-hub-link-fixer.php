<?php
/**
 * NV oOS Docs Hub — Link Fixer
 *
 * Safely rewrites Markdown link targets in source .md files when the user
 * accepts a broken-link suggestion. Uses atomic tempfile + rename to prevent
 * partial writes. Only operates on local files (not remote repo sources).
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles safe, atomic editing of Markdown documentation files.
 *
 * @since 1.3.0
 */
class NV_oOS_Docs_Hub_Link_Fixer {

	/**
	 * Maximum allowed file size for rewriting (2 MB).
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 2097152;

	/**
	 * Fix a single broken link in a source Markdown file.
	 *
	 * Finds the Markdown link whose target matches $old_target and replaces
	 * only the target portion with $new_target.  The link text and surrounding
	 * content are preserved exactly.
	 *
	 * The replacement regex is anchored with a word-boundary guard so that
	 * `setup.md` matches only `setup.md` and not `docker-setup.md`.
	 *
	 * @since 1.3.0
	 *
	 * @param string $source_path     Absolute path to the .md file to edit.
	 * @param string $old_target      The broken link target (as it appears in the .md file).
	 * @param string $new_target      The corrected link target to write.
	 * @param string $source_relative Relative path of the source file (for validation).
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function fix_link( $source_path, $old_target, $new_target, $source_relative ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- reserved for future validation
		// Guard: source file must exist and be readable.
		if ( ! file_exists( $source_path ) || ! is_readable( $source_path ) ) {
			return new WP_Error(
				'nvoos_dh_fix_not_found',
				sprintf(
					/* translators: %s: file path */
					__( 'Source file not found or not readable: %s', 'nvoos-docs-hub' ),
					$source_path
				)
			);
		}

		// Guard: source file must be writable.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- intentional direct FS check
		if ( ! is_writable( $source_path ) ) {
			return new WP_Error(
				'nvoos_dh_fix_not_writable',
				sprintf(
					/* translators: %s: file path */
					__( 'Source file is not writable: %s', 'nvoos-docs-hub' ),
					$source_path
				)
			);
		}

		// Guard: file size check.
		$size = filesize( $source_path );
		if ( false === $size || $size > self::MAX_FILE_SIZE ) {
			return new WP_Error(
				'nvoos_dh_fix_too_large',
				__( 'Source file exceeds the maximum allowed size.', 'nvoos-docs-hub' )
			);
		}

		// Guard: path traversal check — neither old nor new target may contain
		// directory traversal sequences.
		if ( $this->contains_path_traversal( $old_target ) || $this->contains_path_traversal( $new_target ) ) {
			return new WP_Error(
				'nvoos_dh_fix_path_traversal',
				__( 'Link target contains invalid path traversal sequences.', 'nvoos-docs-hub' )
			);
		}

		// Read the current file content.
		$content = file_get_contents( $source_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return new WP_Error(
				'nvoos_dh_fix_read_error',
				__( 'Failed to read source file content.', 'nvoos-docs-hub' )
			);
		}

		// Build a regex that matches the specific Markdown link with the old
		// target. We need to match: [any text](old_target)
		//
		// The pattern captures the full link as group 0 and the link text as
		// group 1 so we can reconstruct with the new target.
		$escaped_target = preg_quote( $old_target, '/' );
		$pattern        = '/\[([^\]]*)\]\(\s*' . $escaped_target . '\s*\)/';

		$count   = 0;
		$updated = preg_replace( $pattern, '[$1](' . $new_target . ')', $content, -1, $count );

		if ( null === $updated ) {
			return new WP_Error(
				'nvoos_dh_fix_regex_error',
				sprintf(
					/* translators: %s: error description */
					__( 'Regex error while processing file: %s', 'nvoos-docs-hub' ),
					preg_last_error_msg()
				)
			);
		}

		if ( 0 === $count ) {
			return new WP_Error(
				'nvoos_dh_fix_not_matched',
				sprintf(
					/* translators: %s: the old link target that was not found */
					__( 'The link target "%s" was not found in the source file (it may have already been fixed).', 'nvoos-docs-hub' ),
					$old_target
				)
			);
		}

		// Write to a tempfile, then atomically rename over the source.
		// This prevents partial writes if the process is killed mid-write.
		$dir      = dirname( $source_path );
		$tempfile = $dir . DIRECTORY_SEPARATOR . '.nvoos-dh-tmp-' . wp_generate_uuid4() . '.md';

		$written = file_put_contents( $tempfile, $updated, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			return new WP_Error(
				'nvoos_dh_fix_write_error',
				__( 'Failed to write temporary file.', 'nvoos-docs-hub' )
			);
		}

		// Verify the tempfile content matches what we intended to write.
		$verify = file_get_contents( $tempfile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $verify !== $updated ) {
			wp_delete_file( $tempfile );
			return new WP_Error(
				'nvoos_dh_fix_verify_error',
				__( 'Temporary file verification failed — content mismatch.', 'nvoos-docs-hub' )
			);
		}

		// Atomic rename — on the same filesystem this is a single operation.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- intentional atomic rename on same filesystem
		if ( ! @rename( $tempfile, $source_path ) ) {
			// Fallback: copy + delete.
			if ( ! @copy( $tempfile, $source_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				wp_delete_file( $tempfile );
				return new WP_Error(
					'nvoos_dh_fix_rename_error',
					__( 'Failed to replace source file with corrected content.', 'nvoos-docs-hub' )
				);
			}
			wp_delete_file( $tempfile );
		}

		return true;
	}

	/**
	 * Apply multiple fixes in a single operation.
	 *
	 * Each fix entry should be:
	 *   { source: 'relative/path.md', old_target: 'old.md', new_target: 'new.md' }
	 *
	 * @since 1.3.0
	 *
	 * @param array  $fixes  Array of fix entries.
	 * @param array  $slug_map Slug map from the indexer (for resolving relative paths to absolute).
	 * @param string $mode   'dry_run' to validate without writing, 'apply' to commit.
	 * @return array { fixed: int, skipped: int, errors: array, results: array }
	 */
	public function apply_fixes( $fixes, $slug_map, $mode = 'dry_run' ) {
		$results = array(
			'fixed'   => 0,
			'skipped' => 0,
			'errors'  => array(),
			'results' => array(),
		);

		$is_dry_run = ( 'dry_run' === $mode );

		foreach ( $fixes as $fix ) {
			$source_rel = isset( $fix['source'] ) ? (string) $fix['source'] : '';
			$old_target = isset( $fix['old_target'] ) ? (string) $fix['old_target'] : '';
			$new_target = isset( $fix['new_target'] ) ? (string) $fix['new_target'] : '';

			if ( '' === $source_rel || '' === $old_target || '' === $new_target ) {
				++$results['skipped'];
				$results['results'][] = array(
					'source' => $source_rel,
					'status' => 'skipped',
					'reason' => 'Missing required fields: source, old_target, new_target.',
				);
				continue;
			}

			// Resolve source relative path to absolute path via slug_map.
			// Find the slug whose relative_path matches.
			$source_abs = $this->resolve_absolute_path( $source_rel, $slug_map );
			if ( null === $source_abs ) {
				++$results['skipped'];
				$results['results'][] = array(
					'source' => $source_rel,
					'status' => 'skipped',
					'reason' => 'Could not resolve source relative path to an absolute file path.',
				);
				continue;
			}

			// Check that this is not a remote-sourced entry (remote repos are
			// read-only — we cannot edit GitHub files from here).
			if ( $this->is_remote_source( $source_rel, $slug_map ) ) {
				++$results['skipped'];
				$results['results'][] = array(
					'source' => $source_rel,
					'status' => 'skipped',
					'reason' => 'Cannot edit remote repository files. Fix the link in the source repository instead.',
				);
				continue;
			}

			if ( $is_dry_run ) {
				// Validate that the file exists and we could potentially edit it.
				if ( ! file_exists( $source_abs ) ) {
					++$results['skipped'];
					$results['results'][] = array(
						'source' => $source_rel,
						'status' => 'skipped',
						'reason' => 'Source file does not exist.',
					);
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- intentional direct FS check
				if ( ! is_writable( $source_abs ) ) {
					++$results['skipped'];
					$results['results'][] = array(
						'source' => $source_rel,
						'status' => 'skipped',
						'reason' => 'Source file is not writable.',
					);
					continue;
				}

				++$results['fixed'];
				$results['results'][] = array(
					'source' => $source_rel,
					'status' => 'would_fix',
					'old'    => $old_target,
					'new'    => $new_target,
				);
			} else {
				// Apply the fix.
				$result = $this->fix_link( $source_abs, $old_target, $new_target, $source_rel );

				if ( is_wp_error( $result ) ) {
					$results['errors'][]  = array(
						'source'  => $source_rel,
						'message' => $result->get_error_message(),
					);
					$results['results'][] = array(
						'source' => $source_rel,
						'status' => 'error',
						'reason' => $result->get_error_message(),
					);
				} else {
					++$results['fixed'];
					$results['results'][] = array(
						'source' => $source_rel,
						'status' => 'fixed',
						'old'    => $old_target,
						'new'    => $new_target,
					);
				}
			}
		}

		return $results;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Check if a path string contains directory traversal sequences.
	 *
	 * @since 1.3.0
	 *
	 * @param string $path The path to check.
	 * @return bool True if traversal sequences are found.
	 */
	private function contains_path_traversal( $path ) {
		return ( false !== strpos( $path, '..' ) );
	}

	/**
	 * Resolve a relative source path to an absolute file path via the slug map.
	 *
	 * @since 1.3.0
	 *
	 * @param string $relative_path The relative path (e.g. "docs/getting-started.md").
	 * @param array  $slug_map     The slug map from the indexer.
	 * @return string|null Absolute path, or null if not found.
	 */
	private function resolve_absolute_path( $relative_path, $slug_map ) {
		foreach ( $slug_map as $slug => $data ) {
			$rel = isset( $data['relative_path'] ) ? (string) $data['relative_path'] : '';
			if ( $rel === $relative_path && isset( $data['path'] ) ) {
				return (string) $data['path'];
			}
		}
		return null;
	}

	/**
	 * Check whether a given source is from a remote repository.
	 *
	 * Remote sources are read-only — we cannot write to GitHub from the
	 * WordPress plugin.
	 *
	 * @since 1.3.0
	 *
	 * @param string $relative_path The relative path of the source file.
	 * @param array  $slug_map     The slug map from the indexer.
	 * @return bool True if the source is remote.
	 */
	private function is_remote_source( $relative_path, $slug_map ) {
		foreach ( $slug_map as $slug => $data ) {
			$rel = isset( $data['relative_path'] ) ? (string) $data['relative_path'] : '';
			if ( $rel === $relative_path ) {
				return 'remote' === ( isset( $data['source'] ) ? (string) $data['source'] : '' );
			}
		}
		return false;
	}
}
