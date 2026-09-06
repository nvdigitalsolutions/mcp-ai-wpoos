<?php
/**
 * Safe archive handling for conversation imports (Wave E4, sub-cluster 6).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Conversation_Import_Archive`: byte-identical caps
 * (2000 ZIP entries, 512 MB uncompressed, 500 JSON files, depth 6),
 * the zip-slip entry rejection (absolute paths, drive letters, `..`,
 * backslashes), the entry-size pre-scan, the temp-dir extraction with
 * uploads-scoped deletion, the recursive JSON/JSONL discovery, and the
 * plain-file pass-through.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - `ZipArchive` and `WP_Error` are fully qualified.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\ConversationImport
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ConversationImport;

/**
 * Prepares import payload files and cleans them up afterwards.
 *
 * @since 2.1.0
 */
class Archive {

	const MAX_ZIP_ENTRIES     = 2000;
	const MAX_ZIP_TOTAL_BYTES = 536870912; // 512 MB uncompressed.
	const MAX_JSON_FILES      = 500;
	const MAX_DIRECTORY_DEPTH = 6;

	/**
	 * Temp directory created for ZIP extraction.
	 *
	 * @var string
	 */
	protected $temp_dir = '';

	/**
	 * Prepare candidate JSON payload file paths from a source path.
	 *
	 * @param string $source_path Absolute path to a `.zip`, `.json`, `.jsonl`,
	 *                            or a directory containing JSON exports.
	 * @return string[]|\WP_Error Absolute file paths, or a WP_Error.
	 */
	public function prepare( $source_path ) {
		$source_path = $this->validate_source( $source_path );
		if ( is_wp_error( $source_path ) ) {
			return $source_path;
		}

		if ( is_dir( $source_path ) ) {
			return $this->discover_json_files( $source_path );
		}

		$extension = strtolower( (string) pathinfo( $source_path, PATHINFO_EXTENSION ) );

		if ( 'zip' === $extension ) {
			return $this->extract_zip( $source_path );
		}

		if ( in_array( $extension, array( 'json', 'jsonl' ), true ) ) {
			return array( $source_path );
		}

		return new \WP_Error(
			'wp_mcp_ai_import_unsupported_file',
			__( 'Unsupported file type. Provide a .zip, .json, or .jsonl export file.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Remove the temporary extraction directory, if any.
	 *
	 * @return void
	 */
	public function cleanup() {
		if ( '' === $this->temp_dir || ! is_dir( $this->temp_dir ) ) {
			return;
		}

		$this->remove_tree( $this->temp_dir );
		$this->temp_dir = '';
	}

	/**
	 * Validate and normalise the source path.
	 *
	 * @param string $source_path Raw source path.
	 * @return string|\WP_Error
	 */
	protected function validate_source( $source_path ) {
		$source_path = trim( (string) $source_path );

		if ( '' === $source_path ) {
			return new \WP_Error(
				'wp_mcp_ai_import_empty_source',
				__( 'No import source path was provided.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( ! file_exists( $source_path ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_source_missing',
				__( 'The import source file does not exist.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( ! is_readable( $source_path ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_source_unreadable',
				__( 'The import source file is not readable.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return wp_normalize_path( $source_path );
	}

	/**
	 * Safely extract a ZIP archive into a fresh temp directory.
	 *
	 * @param string $zip_path Absolute path to the ZIP file.
	 * @return string[]|\WP_Error JSON file paths inside the archive, or a WP_Error.
	 */
	protected function extract_zip( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_zip_missing',
				__( 'The PHP ZipArchive extension is required to import ZIP exports.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$zip         = new \ZipArchive();
		$open_result = $zip->open( $zip_path );
		if ( true !== $open_result ) {
			return new \WP_Error(
				'wp_mcp_ai_import_zip_open_failed',
				/* translators: %d: ZipArchive error code. */
				sprintf( __( 'Could not open the ZIP archive (code %d).', 'nvoos-content-graph-ai-platform' ), $open_result )
			);
		}

		$entry_count = $zip->count();
		if ( $entry_count > self::MAX_ZIP_ENTRIES ) {
			$zip->close();

			return new \WP_Error(
				'wp_mcp_ai_import_zip_too_many_entries',
				__( 'The ZIP archive contains too many entries.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$total_size = 0;
		for ( $i = 0; $i < $entry_count; $i++ ) {
			$stats = $zip->statIndex( $i );
			if ( is_array( $stats ) && isset( $stats['size'] ) ) {
				$total_size += (int) $stats['size'];
			}

			$name = $zip->getNameIndex( $i );
			if ( false === $name || $this->is_unsafe_entry( $name ) ) {
				$zip->close();

				return new \WP_Error(
					'wp_mcp_ai_import_zip_unsafe_entry',
					__( 'The ZIP archive contains an unsafe file path and was rejected.', 'nvoos-content-graph-ai-platform' )
				);
			}
		}

		if ( $total_size > self::MAX_ZIP_TOTAL_BYTES ) {
			$zip->close();

			return new \WP_Error(
				'wp_mcp_ai_import_zip_too_large',
				__( 'The ZIP archive expands beyond the allowed size.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$temp_dir = $this->create_temp_dir();
		if ( is_wp_error( $temp_dir ) ) {
			$zip->close();

			return $temp_dir;
		}

		if ( ! $zip->extractTo( $temp_dir ) ) {
			$zip->close();
			$this->remove_tree( $temp_dir );

			return new \WP_Error(
				'wp_mcp_ai_import_zip_extract_failed',
				__( 'ZIP extraction failed.', 'nvoos-content-graph-ai-platform' )
			);
		}
		$zip->close();

		$files = $this->discover_json_files( $temp_dir );
		if ( empty( $files ) ) {
			$this->remove_tree( $temp_dir );

			return new \WP_Error(
				'wp_mcp_ai_import_zip_no_json',
				__( 'The ZIP archive contains no conversation JSON files.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $files;
	}

	/**
	 * Detect zip-slip style entries.
	 *
	 * Rejects absolute paths, drive letters, `..` traversal, and backslashes
	 * (which some extractors treat as separators on Windows).
	 *
	 * @param string $name Entry name as reported by the archive.
	 * @return bool True when the entry must be rejected.
	 */
	protected function is_unsafe_entry( $name ) {
		if ( '' === $name ) {
			return true;
		}

		if ( false !== strpos( $name, '..' ) ) {
			return true;
		}

		if ( false !== strpos( $name, '\\' ) ) {
			return true;
		}

		if ( preg_match( '#^[a-zA-Z]:#', $name ) ) {
			return true;
		}

		if ( 0 === strpos( $name, '/' ) || 0 === strpos( $name, '\\\\' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Recursively find JSON/JSONL files under a directory.
	 *
	 * @param string $directory Directory to scan.
	 * @param int    $depth     Current recursion depth.
	 * @return string[]
	 */
	protected function discover_json_files( $directory, $depth = 0 ) {
		$files = array();

		if ( $depth > self::MAX_DIRECTORY_DEPTH ) {
			return $files;
		}

		$entries = scandir( $directory );
		if ( false === $entries ) {
			return $files;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = wp_normalize_path( $directory . DIRECTORY_SEPARATOR . $entry );

			if ( is_dir( $path ) ) {
				$files = array_merge( $files, $this->discover_json_files( $path, $depth + 1 ) );
				continue;
			}

			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( in_array( $extension, array( 'json', 'jsonl' ), true ) && is_readable( $path ) ) {
				$files[] = $path;
			}

			if ( count( $files ) >= self::MAX_JSON_FILES ) {
				return $files;
			}
		}

		return $files;
	}

	/**
	 * Create a unique temp directory for extraction.
	 *
	 * @return string|\WP_Error
	 */
	protected function create_temp_dir() {
		$upload_dir = wp_upload_dir();
		$base       = isset( $upload_dir['basedir'] ) && is_string( $upload_dir['basedir'] )
			? $upload_dir['basedir']
			: sys_get_temp_dir();

		$candidate = wp_normalize_path(
			$base . DIRECTORY_SEPARATOR . 'wp-mcp-ai-imports-' . uniqid( '', true )
		);

		if ( ! wp_mkdir_p( $candidate ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_temp_dir_failed',
				__( 'Could not create a temporary directory for the import.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$this->temp_dir = $candidate;

		return $candidate;
	}

	/**
	 * Recursively remove a directory tree.
	 *
	 * @param string $directory Directory to remove.
	 * @return void
	 */
	protected function remove_tree( $directory ) {
		$directory  = wp_normalize_path( (string) $directory );
		$upload_dir = wp_upload_dir();
		$base       = isset( $upload_dir['basedir'] ) && is_string( $upload_dir['basedir'] )
			? wp_normalize_path( $upload_dir['basedir'] )
			: '';

		// Refuse to delete anything outside the uploads tree.
		if ( '' !== $base && 0 !== strpos( $directory, $base ) ) {
			return;
		}

		if ( ! is_dir( $directory ) ) {
			return;
		}

		$entries = scandir( $directory );
		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $directory . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				$this->remove_tree( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		@rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- WP has no rmdir() wrapper; temp-dir cleanup is best-effort and non-fatal.
	}
}
