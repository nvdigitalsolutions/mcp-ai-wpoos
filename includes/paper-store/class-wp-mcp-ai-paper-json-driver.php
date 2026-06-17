<?php
/**
 * Paper JSON Driver — Read/write JSON record files via Symfony Filesystem.
 *
 * Encodes/decodes records using wp_json_encode() / json_decode().
 * All disk I/O delegated to WP_MCP_AI_Filesystem_Service (atomic dumpFile).
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Paper_Json_Driver
 *
 * Reads and writes `.json` record files. Enforces a minimum schema
 * (id, type, title required). All I/O goes through the existing
 * Filesystem Service for atomicity.
 */
class WP_MCP_AI_Paper_Json_Driver implements WP_MCP_AI_Paper_Driver_Interface {

	/**
	 * Required top-level keys in every JSON record.
	 *
	 * @var string[]
	 */
	private $required_fields = array( 'id', 'type', 'title' );

	/**
	 * Filesystem service instance.
	 *
	 * @var WP_MCP_AI_Filesystem_Service
	 */
	private $filesystem;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( class_exists( 'WP_MCP_AI\\Filesystem\\WP_MCP_AI_Filesystem_Service' ) ) {
			$this->filesystem = WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service::get_instance();
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_extension() {
		return '.json';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $file_path Absolute path to the .json record file.
	 * @return array|WP_Error  Normalized record array, or WP_Error on failure.
	 */
	public function read( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'paper_file_not_found',
				sprintf(
					/* translators: %s: file path */
					__( 'Paper Store record file not found: %s', 'mcp-ai-wpoos' ),
					basename( $file_path )
				)
			);
		}

		if ( ! is_readable( $file_path ) ) {
			return new WP_Error(
				'paper_file_unreadable',
				sprintf(
					/* translators: %s: file path */
					__( 'Paper Store record file is not readable: %s', 'mcp-ai-wpoos' ),
					basename( $file_path )
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin-managed flat file; WP_Filesystem not available in REST/cron/tool contexts.
		$raw = file_get_contents( $file_path );

		if ( false === $raw ) {
			return new WP_Error(
				'paper_read_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Failed to read Paper Store record: %s', 'mcp-ai-wpoos' ),
					basename( $file_path )
				)
			);
		}

		$record = json_decode( $raw, true );

		if ( null === $record || ! is_array( $record ) ) {
			return new WP_Error(
				'paper_invalid_json',
				sprintf(
					/* translators: %s: file path */
					__( 'Invalid JSON in Paper Store record: %s', 'mcp-ai-wpoos' ),
					basename( $file_path )
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
			return new WP_Error(
				'paper_missing_fields',
				sprintf(
					/* translators: 1: file path, 2: comma-separated field names */
					__( 'Record "%1$s" is missing required fields: %2$s', 'mcp-ai-wpoos' ),
					basename( $file_path ),
					implode( ', ', $missing )
				)
			);
		}

		// Ensure UTF-8 encoding.
		array_walk_recursive(
			$record,
			function ( &$value ) {
				if ( is_string( $value ) && ! mb_check_encoding( $value, 'UTF-8' ) ) {
					$value = mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
				}
			}
		);

		return $record;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $file_path Absolute path to the .json record file.
	 * @param array  $record    Normalized record array.
	 * @return bool|WP_Error    True on success, WP_Error on failure.
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
			return new WP_Error(
				'paper_missing_fields',
				sprintf(
					/* translators: %s: comma-separated field names */
					__( 'Record is missing required fields: %s', 'mcp-ai-wpoos' ),
					implode( ', ', $missing )
				)
			);
		}

		// Sanitize ID and type for filename safety.
		$record['id']   = sanitize_key( $record['id'] );
		$record['type'] = sanitize_key( $record['type'] );

		// Timestamp management.
		$now = gmdate( 'c' );
		if ( ! isset( $record['created_at'] ) || empty( $record['created_at'] ) ) {
			$record['created_at'] = $now;
		}
		$record['updated_at'] = $now;

		// Encode with pretty-print for human readability.
		$json = wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			return new WP_Error(
				'paper_json_encode_failed',
				__( 'Failed to encode record as JSON.', 'mcp-ai-wpoos' )
			);
		}

		if ( null === $this->filesystem ) {
			// Fallback to native PHP when Filesystem Service is unavailable.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Managed flat-file store; WP_Filesystem not available in all contexts.
			$result = file_put_contents( $file_path, $json, LOCK_EX );
			if ( false === $result ) {
				return new WP_Error(
					'paper_write_failed',
					sprintf(
						/* translators: %s: file path */
						__( 'Failed to write Paper Store record: %s', 'mcp-ai-wpoos' ),
						basename( $file_path )
					)
				);
			}
			return true;
		}

		return $this->filesystem->write_file( $file_path, $json );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $file_path Absolute path to the .json record file.
	 * @return bool|WP_Error    True on success, WP_Error on failure.
	 */
	public function delete( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'paper_file_not_found',
				sprintf(
					/* translators: %s: file path */
					__( 'Paper Store record file not found: %s', 'mcp-ai-wpoos' ),
					basename( $file_path )
				)
			);
		}

		if ( null === $this->filesystem ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Managed flat-file store deletion.
			if ( ! unlink( $file_path ) ) {
				return new WP_Error(
					'paper_delete_failed',
					sprintf(
						/* translators: %s: file path */
						__( 'Failed to delete Paper Store record: %s', 'mcp-ai-wpoos' ),
						basename( $file_path )
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
