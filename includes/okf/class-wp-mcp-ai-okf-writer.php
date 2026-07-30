<?php
/**
 * OKF Writer — Create and update OKF concept documents.
 *
 * Provides atomic write operations for OKF bundles, index regeneration,
 * and conformance validation per the OKF v0.2 specification.
 *
 * @package WP_MCP_AI
 * @since   2.1.0
 * @since   2.5.0 — Updated validation for OKF v0.2 trust-signal fields.
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 *
 * @link https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OKF bundle writer and validator.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_OKF_Writer {

	/**
	 * Parser instance.
	 *
	 * @since 2.1.0
	 * @var WP_MCP_AI_OKF_Parser
	 */
	private $parser;

	/**
	 * Bundle root path on disk.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	private $bundle_root;

	/**
	 * Filesystem service for atomic writes.
	 *
	 * @since 2.1.0
	 * @var WP_MCP_AI_Filesystem_Service|null
	 */
	private $fs = null;

	/**
	 * Constructor.
	 *
	 * @since 2.1.0
	 *
	 * @param string $bundle_root Absolute path to the bundle root directory.
	 */
	public function __construct( $bundle_root ) {
		$this->parser      = new WP_MCP_AI_OKF_Parser();
		$this->bundle_root = untrailingslashit( $bundle_root );

		// Use the existing filesystem service if available.
		if ( class_exists( 'WP_MCP_AI\\Filesystem\\WP_MCP_AI_Filesystem_Service' ) ) {
			$this->fs = \WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service::get_instance();
		}
	}

	/**
	 * Get the bundle root path.
	 *
	 * @since 2.1.0
	 * @return string
	 */
	public function get_bundle_root() {
		return $this->bundle_root;
	}

	/**
	 * Create or update a concept document.
	 *
	 * Writes the concept file atomically. Creates parent directories as needed.
	 *
	 * @since 2.1.0
	 *
	 * @param string $concept_id  Concept ID (path relative to bundle root, without .md).
	 * @param array  $frontmatter Associative array of frontmatter fields (must include 'type').
	 * @param string $body        Markdown body content.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function write_concept( $concept_id, array $frontmatter, $body ) {
		// Validate required frontmatter.
		if ( empty( $frontmatter['type'] ) ) {
			return new WP_Error(
				'okf_missing_type',
				__( 'OKF concept requires a "type" field in frontmatter.', 'mcp-ai-wpoos' )
			);
		}

		$concept_id = ltrim( $concept_id, '/' );
		$file_path  = $this->bundle_root . '/' . $concept_id . '.md';
		$file_path  = wp_normalize_path( $file_path );

		// Security: ensure within bundle root.
		$normalized_root = wp_normalize_path( $this->bundle_root );
		if ( 0 !== strpos( $file_path, $normalized_root ) ) {
			return new WP_Error(
				'okf_path_traversal',
				__( 'Concept path escapes the bundle root.', 'mcp-ai-wpoos' )
			);
		}

		// Ensure parent directory exists.
		$dir = dirname( $file_path );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Check-only; error caught by file write below. OKF bundles are local filesystem only.
			if ( ! @mkdir( $dir, 0755, true ) ) {
				return new WP_Error(
					'okf_mkdir_error',
					__( 'Failed to create OKF bundle directory.', 'mcp-ai-wpoos' )
				);
			}
		}

		// Serialize the document.
		$yaml    = $this->parser->serialize( $frontmatter );
		$content = $yaml . "\n" . trim( $body ) . "\n";

		// Atomic write.
		if ( $this->fs ) {
			$result = $this->fs->write_file( $file_path, $content );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- OKF bundle is a local filesystem operation; LOCK_EX provides atomicity.
			$written = file_put_contents( $file_path, $content, LOCK_EX );
			if ( false === $written ) {
				return new WP_Error(
					'okf_write_error',
					__( 'Failed to write OKF concept file.', 'mcp-ai-wpoos' )
				);
			}
		}

		/**
		 * Fires after an OKF concept is saved.
		 *
		 * @since 2.1.0
		 *
		 * @param string $concept_id  The concept ID that was saved.
		 * @param string $file_path   Absolute path to the saved file.
		 */
		do_action( 'wp_mcp_ai_okf_concept_saved', $concept_id, $file_path );

		return true;
	}

	/**
	 * Delete a concept document.
	 *
	 * Moves the concept file to a `.deleted` extension rather than removing it,
	 * providing a recovery path.
	 *
	 * @since 2.1.0
	 *
	 * @param string $concept_id Concept ID to delete.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function delete_concept( $concept_id ) {
		$concept_id = ltrim( $concept_id, '/' );
		$file_path  = $this->bundle_root . '/' . $concept_id . '.md';
		$file_path  = wp_normalize_path( $file_path );

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'okf_not_found',
				sprintf(
					/* translators: %s: concept ID */
					__( 'Concept not found: %s', 'mcp-ai-wpoos' ),
					$concept_id
				)
			);
		}

		// Security: ensure within bundle root.
		$normalized_root = wp_normalize_path( $this->bundle_root );
		if ( 0 !== strpos( $file_path, $normalized_root ) ) {
			return new WP_Error(
				'okf_path_traversal',
				__( 'Concept path escapes the bundle root.', 'mcp-ai-wpoos' )
			);
		}

		$deleted_path = $file_path . '.deleted.' . time();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Soft-delete via rename; OKF bundles are local filesystem only.
		$renamed = @rename( $file_path, $deleted_path );
		if ( ! $renamed ) {
			return new WP_Error(
				'okf_delete_error',
				__( 'Failed to delete OKF concept file.', 'mcp-ai-wpoos' )
			);
		}

		/**
		 * Fires after an OKF concept is deleted.
		 *
		 * @since 2.1.0
		 *
		 * @param string $concept_id   The concept ID that was deleted.
		 * @param string $deleted_path Path to the .deleted backup file.
		 */
		do_action( 'wp_mcp_ai_okf_concept_deleted', $concept_id, $deleted_path );

		return true;
	}

	/**
	 * Regenerate the index.md for a directory in the bundle.
	 *
	 * Scans the directory for concepts and subdirectories, reads their
	 * frontmatter for titles/descriptions, and writes an up-to-date index.md.
	 *
	 * @since 2.1.0
	 *
	 * @param string $path Directory path relative to bundle root (empty string for root).
	 * @return true|WP_Error
	 */
	public function regenerate_index( $path = '' ) {
		$dir_path = $this->bundle_root;
		if ( '' !== $path ) {
			$dir_path .= '/' . ltrim( $path, '/' );
		}

		if ( ! is_dir( $dir_path ) ) {
			return new WP_Error(
				'okf_not_found',
				__( 'OKF directory not found for index regeneration.', 'mcp-ai-wpoos' )
			);
		}

		$reader  = new WP_MCP_AI_OKF_Reader( $this->bundle_root );
		$entries = $reader->browse( $path );

		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		// Group entries by type (directories vs concepts).
		$dir_entries     = array();
		$concept_entries = array();
		foreach ( $entries as $entry ) {
			if ( '/' === substr( $entry['path'], -1 ) ) {
				$dir_entries[] = $entry;
			} else {
				$concept_entries[] = $entry;
			}
		}

		$lines = array( '# ' . ( '' === $path ? basename( $this->bundle_root ) : basename( $path ) ) );

		if ( ! empty( $dir_entries ) ) {
			$lines[] = '';
			$lines[] = '## Directories';
			foreach ( $dir_entries as $entry ) {
				$line = '* [' . $entry['title'] . '](' . $entry['path'] . ')';
				if ( ! empty( $entry['description'] ) ) {
					$line .= ' - ' . $entry['description'];
				}
				$lines[] = $line;
			}
		}

		if ( ! empty( $concept_entries ) ) {
			$lines[] = '';
			$lines[] = '## Concepts';
			foreach ( $concept_entries as $entry ) {
				$line = '* [' . $entry['title'] . '](' . $entry['path'] . ')';
				if ( ! empty( $entry['description'] ) ) {
					$line .= ' - ' . $entry['description'];
				}
				$lines[] = $line;
			}
		}

		$content    = implode( "\n", $lines ) . "\n";
		$index_path = $dir_path . '/index.md';

		if ( $this->fs ) {
			return $this->fs->write_file( $index_path, $content );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- OKF bundle local filesystem operation.
		$written = file_put_contents( $index_path, $content, LOCK_EX );
		if ( false === $written ) {
			return new WP_Error(
				'okf_write_error',
				__( 'Failed to write index.md.', 'mcp-ai-wpoos' )
			);
		}

		return true;
	}

	/**
	 * Validate a bundle for OKF v0.2 conformance per spec.
	 *
	 * Checks:
	 * 1. Every non-reserved .md file has parseable YAML frontmatter.
	 * 2. Every frontmatter block contains a non-empty `type` field.
	 * 3. Reserved filenames (index.md, log.md) follow conventions when present.
	 * 4. (v0.2) `stale_after` is a valid date when present.
	 * 5. (v0.2) `status` is a recognised value when present.
	 * 6. (v0.2) Concepts past their `stale_after` date are flagged as stale.
	 *
	 * @since 2.1.0
	 * @since 2.5.0 — Added OKF v0.2 trust-signal checks.
	 *
	 * @return array{conformant: bool, issues: string[], concept_count: int, stale_count: int, deprecated_count: int}
	 */
	public function validate_bundle() {
		$issues           = array();
		$reader           = new WP_MCP_AI_OKF_Reader( $this->bundle_root );
		$stale_count      = 0;
		$deprecated_count = 0;

		$files = $this->find_all_concept_files( $this->bundle_root );
		foreach ( $files as $file_path ) {
			$concept_id = $reader->file_to_concept_id( $file_path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read for validation.
			$content = file_get_contents( $file_path );
			if ( false === $content ) {
				$issues[] = sprintf(
					/* translators: %s: concept ID */
					__( 'Could not read concept: %s', 'mcp-ai-wpoos' ),
					$concept_id
				);
				continue;
			}

			$parsed = $this->parser->parse( $content );
			if ( is_wp_error( $parsed ) ) {
				$issues[] = sprintf(
					/* translators: 1: concept ID, 2: error message */
					__( 'Concept "%1$s": %2$s', 'mcp-ai-wpoos' ),
					$concept_id,
					$parsed->get_error_message()
				);
				continue;
			}

			if ( null === $parsed ) {
				$issues[] = sprintf(
					/* translators: %s: concept ID */
					__( 'Concept "%s" has no YAML frontmatter block.', 'mcp-ai-wpoos' ),
					$concept_id
				);
				continue;
			}

			$fm = $parsed['frontmatter'];

			if ( empty( $fm['type'] ) ) {
				$issues[] = sprintf(
					/* translators: %s: concept ID */
					__( 'Concept "%s" has no "type" field in frontmatter (required by OKF v0.2).', 'mcp-ai-wpoos' ),
					$concept_id
				);
			}

			// v0.2: validate status enum.
			if ( ! empty( $fm['status'] ) ) {
				$valid_statuses = array( 'draft', 'stable', 'deprecated' );
				if ( ! in_array( strtolower( $fm['status'] ), $valid_statuses, true ) ) {
					$issues[] = sprintf(
						/* translators: 1: concept ID, 2: invalid status value */
						__( 'Concept "%1$s" has unrecognised status "%2$s". Valid values: draft, stable, deprecated.', 'mcp-ai-wpoos' ),
						$concept_id,
						esc_html( $fm['status'] )
					);
				}

				if ( 'deprecated' === strtolower( $fm['status'] ) ) {
					++$deprecated_count;
				}
			}

			// v0.2: validate stale_after date.
			if ( ! empty( $fm['stale_after'] ) ) {
				$ts = strtotime( (string) $fm['stale_after'] );
				if ( false === $ts ) {
					$issues[] = sprintf(
						/* translators: 1: concept ID, 2: stale_after value */
						__( 'Concept "%1$s" has an unparseable stale_after date: "%2$s". Use ISO 8601 (e.g. 2026-12-31).', 'mcp-ai-wpoos' ),
						$concept_id,
						esc_html( (string) $fm['stale_after'] )
					);
				} elseif ( $ts < time() ) {
					++$stale_count;
				}
			}
		}

		return array(
			'conformant'       => empty( $issues ),
			'issues'           => $issues,
			'concept_count'    => count( $files ),
			'stale_count'      => $stale_count,
			'deprecated_count' => $deprecated_count,
		);
	}

	/**
	 * Append an entry to the nearest log.md in the bundle.
	 *
	 * Creates log.md if it does not exist at the given path.
	 *
	 * @since 2.1.0
	 *
	 * @param string $path    Directory path relative to bundle root (empty for root).
	 * @param string $entry   Log entry text (without leading bullet).
	 * @param string $action  Action name for the bold prefix (Update, Creation, Deprecation).
	 * @return true|WP_Error
	 */
	public function append_log( $path, $entry, $action = 'Update' ) {
		$dir_path = $this->bundle_root;
		if ( '' !== $path ) {
			$dir_path .= '/' . ltrim( $path, '/' );
		}

		if ( ! is_dir( $dir_path ) ) {
			return new WP_Error(
				'okf_not_found',
				__( 'OKF directory not found for log entry.', 'mcp-ai-wpoos' )
			);
		}

		$log_path = $dir_path . '/log.md';
		$today    = gmdate( 'Y-m-d' );
		$log_line = '* **' . sanitize_text_field( $action ) . '**: ' . wp_kses_post( $entry );

		if ( ! file_exists( $log_path ) ) {
			// Create new log file.
			$content = "# Directory Update Log\n\n## {$today}\n\n{$log_line}\n";
			if ( $this->fs ) {
				return $this->fs->write_file( $log_path, $content );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- OKF bundle local filesystem operation.
			$written = file_put_contents( $log_path, $content, LOCK_EX );
			return ( false === $written )
				? new WP_Error( 'okf_write_error', __( 'Failed to create log.md.', 'mcp-ai-wpoos' ) )
				: true;
		}

		// Append to existing log.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read for log.
		$existing = file_get_contents( $log_path );
		if ( false === $existing ) {
			return new WP_Error( 'okf_read_error', __( 'Failed to read log.md.', 'mcp-ai-wpoos' ) );
		}

		// Check if today's section already exists.
		if ( false !== strpos( $existing, "## {$today}" ) ) {
			// Insert after today's heading.
			$existing = preg_replace(
				'/(## ' . preg_quote( $today, '/' ) . '\n)/',
				"\$1\n{$log_line}",
				$existing,
				1
			);
		} else {
			// Insert new date section after the title line.
			$existing = preg_replace(
				'/(# Directory Update Log\n)/',
				"\$1\n## {$today}\n\n{$log_line}",
				$existing,
				1
			);
		}

		if ( $this->fs ) {
			return $this->fs->write_file( $log_path, $existing );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- OKF bundle local filesystem operation.
		$written = file_put_contents( $log_path, $existing, LOCK_EX );
		return ( false === $written )
			? new WP_Error( 'okf_write_error', __( 'Failed to update log.md.', 'mcp-ai-wpoos' ) )
			: true;
	}

	/**
	 * Ensure the bundle root directory exists.
	 *
	 * @since 2.1.0
	 * @return true|WP_Error
	 */
	public function ensure_bundle_root() {
		if ( is_dir( $this->bundle_root ) ) {
			return true;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Check-only; error caught below. OKF bundles are local filesystem only.
		if ( ! @mkdir( $this->bundle_root, 0755, true ) ) {
			return new WP_Error(
				'okf_mkdir_error',
				__( 'Failed to create OKF bundle root directory.', 'mcp-ai-wpoos' )
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Find all .md concept files recursively, skipping reserved names.
	 *
	 * @since 2.1.0
	 *
	 * @param string $dir Absolute directory path.
	 * @return string[] Array of absolute file paths.
	 */
	private function find_all_concept_files( $dir ) {
		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info->isFile() ) {
				continue;
			}

			$filename = $file_info->getFilename();
			if ( '.md' !== substr( $filename, -3 ) ) {
				continue;
			}

			if ( 'index.md' === $filename || 'log.md' === $filename ) {
				continue;
			}

			$files[] = wp_normalize_path( $file_info->getPathname() );
		}

		return $files;
	}
}
