<?php
/**
 * Paper JSON driver (Wave E6, sub-cluster 3).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Paper_Json_Driver`
 * (`includes/paper-store/class-wp-mcp-ai-paper-json-driver.php`):
 * byte-identical `.json` record I/O — the `id`/`type`/`title`
 * required-field enforcement on read AND write (error code
 * `paper_missing_fields` with the comma-joined field list), the
 * missing/unreadable/invalid-JSON/read-failed error codes, the
 * recursive UTF-8 encoding walk, `sanitize_key()` on `id`/`type` at
 * write, the `created_at`/`updated_at` timestamp contract (ISO 8601
 * via `gmdate( 'c' )`, `created_at` preserved on re-write), the
 * pretty-printed `wp_json_encode()` flags, the atomic-write delegation
 * to the Filesystem Service, and the native
 * `file_put_contents( …, LOCK_EX )` / `unlink()` fallback.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - The Filesystem Service resolves per install mode via
 *    `defined( 'WP_MCP_AI_PATH' ) && class_exists( … )` — monolith
 *    installs use the base `WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service`
 *    singleton exactly as the base does; standalone installs take the
 *    base's own native-PHP fallback path (the monorepo classmap would
 *    otherwise resolve the base class in standalone test runs,
 *    masking the true standalone behavior).
 *  - `WP_Error` is fully qualified.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\PaperStore
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\PaperStore;

/**
 * Reads and writes `.json` record files. Enforces a minimum schema
 * (id, type, title required). All I/O goes through the existing
 * Filesystem Service for atomicity when available.
 *
 * @since 1.1.0
 */
class PaperJsonDriver implements PaperDriverInterface {

	/**
	 * Required top-level keys in every JSON record.
	 *
	 * @var string[]
	 */
	private $required_fields = array( 'id', 'type', 'title' );

	/**
	 * Filesystem service instance (monolith-only).
	 *
	 * @var \WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service|null
	 */
	private $filesystem;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Per-mode seam: the base Filesystem Service only exists in
		// monolith installs. The `defined( 'WP_MCP_AI_PATH' )` discriminator
		// (never bare class_exists) keeps the standalone path on the
		// base's native-PHP fallback even though the monorepo classmap can
		// resolve the base class in test runs.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI\\Filesystem\\WP_MCP_AI_Filesystem_Service' ) ) {
			$this->filesystem = \WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service::get_instance();
		}
	}

	/**
	 * Get the file extension this driver handles.
	 *
	 * @return string '.json'
	 */
	public function get_extension() {
		return '.json';
	}

	/**
	 * Read a single record from a file path.
	 *
	 * @param string $file_path Absolute path to the .json record file.
	 * @return array|\WP_Error Normalized record array, or WP_Error on failure.
	 */
	public function read( $file_path ) {
		if ( ! \file_exists( $file_path ) ) {
			return new \WP_Error(
				'paper_file_not_found',
				\sprintf(
					/* translators: %s: file path */
					__( 'Paper Store record file not found: %s', 'nvoos-content-graph-ai' ),
					\basename( $file_path )
				)
			);
		}

		if ( ! \is_readable( $file_path ) ) {
			return new \WP_Error(
				'paper_file_unreadable',
				\sprintf(
					/* translators: %s: file path */
					__( 'Paper Store record file is not readable: %s', 'nvoos-content-graph-ai' ),
					\basename( $file_path )
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin-managed flat file; WP_Filesystem not available in REST/cron/tool contexts.
		$raw = \file_get_contents( $file_path );

		if ( false === $raw ) {
			return new \WP_Error(
				'paper_read_failed',
				\sprintf(
					/* translators: %s: file path */
					__( 'Failed to read Paper Store record: %s', 'nvoos-content-graph-ai' ),
					\basename( $file_path )
				)
			);
		}

		$record = \json_decode( $raw, true );

		if ( null === $record || ! \is_array( $record ) ) {
			return new \WP_Error(
				'paper_invalid_json',
				\sprintf(
					/* translators: %s: file path */
					__( 'Invalid JSON in Paper Store record: %s', 'nvoos-content-graph-ai' ),
					\basename( $file_path )
				)
			);
		}

		// Validate required fields.
		$missing = array();
		foreach ( $this->required_fields as $field ) {
			if ( ! isset( $record[ $field ] ) || '' === $record[ $field ] ) {
				$missing[] = $field;
			}
		}

		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'paper_missing_fields',
				\sprintf(
					/* translators: 1: file path, 2: comma-separated field names */
					__( 'Record "%1$s" is missing required fields: %2$s', 'nvoos-content-graph-ai' ),
					\basename( $file_path ),
					\implode( ', ', $missing )
				)
			);
		}

		// Ensure UTF-8 encoding.
		\array_walk_recursive(
			$record,
			function ( &$value ) {
				if ( \is_string( $value ) && ! \mb_check_encoding( $value, 'UTF-8' ) ) {
					$value = \mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
				}
			}
		);

		return $record;
	}

	/**
	 * Write a record to a file atomically.
	 *
	 * @param string $file_path Absolute path to the .json record file.
	 * @param array  $record    Normalized record array.
	 * @return bool|\WP_Error  True on success, WP_Error on failure.
	 */
	public function write( $file_path, array $record ) {
		// Validate required fields before writing.
		$missing = array();
		foreach ( $this->required_fields as $field ) {
			if ( ! isset( $record[ $field ] ) || '' === $record[ $field ] ) {
				$missing[] = $field;
			}
		}

		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'paper_missing_fields',
				\sprintf(
					/* translators: %s: comma-separated field names */
					__( 'Record is missing required fields: %s', 'nvoos-content-graph-ai' ),
					\implode( ', ', $missing )
				)
			);
		}

		// Sanitize ID and type for filename safety.
		$record['id']   = \sanitize_key( $record['id'] );
		$record['type'] = \sanitize_key( $record['type'] );

		// Timestamp management.
		$now = \gmdate( 'c' );
		if ( ! isset( $record['created_at'] ) || empty( $record['created_at'] ) ) {
			$record['created_at'] = $now;
		}
		$record['updated_at'] = $now;

		// Encode with pretty-print for human readability.
		$json = \wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			return new \WP_Error(
				'paper_json_encode_failed',
				__( 'Failed to encode record as JSON.', 'nvoos-content-graph-ai' )
			);
		}

		if ( null === $this->filesystem ) {
			// Fallback to native PHP when the Filesystem Service is
			// unavailable (standalone installs — the base's own fallback).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Managed flat-file store; WP_Filesystem not available in all contexts.
			$result = \file_put_contents( $file_path, $json, LOCK_EX );
			if ( false === $result ) {
				return new \WP_Error(
					'paper_write_failed',
					\sprintf(
						/* translators: %s: file path */
						__( 'Failed to write Paper Store record: %s', 'nvoos-content-graph-ai' ),
						\basename( $file_path )
					)
				);
			}
			return true;
		}

		return $this->filesystem->write_file( $file_path, $json );
	}

	/**
	 * Delete a record file.
	 *
	 * @param string $file_path Absolute path to the .json record file.
	 * @return bool|\WP_Error  True on success, WP_Error on failure.
	 */
	public function delete( $file_path ) {
		if ( ! \file_exists( $file_path ) ) {
			return new \WP_Error(
				'paper_file_not_found',
				\sprintf(
					/* translators: %s: file path */
					__( 'Paper Store record file not found: %s', 'nvoos-content-graph-ai' ),
					\basename( $file_path )
				)
			);
		}

		if ( null === $this->filesystem ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Managed flat-file store deletion.
			if ( ! \unlink( $file_path ) ) {
				return new \WP_Error(
					'paper_delete_failed',
					\sprintf(
						/* translators: %s: file path */
						__( 'Failed to delete Paper Store record: %s', 'nvoos-content-graph-ai' ),
						\basename( $file_path )
					)
				);
			}
			return true;
		}

		return $this->filesystem->remove( $file_path );
	}

	/**
	 * Get the list of required field names.
	 *
	 * @return string[]
	 */
	public function get_required_fields() {
		return $this->required_fields;
	}
}
