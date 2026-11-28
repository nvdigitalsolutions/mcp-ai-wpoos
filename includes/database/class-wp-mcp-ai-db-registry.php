<?php
/**
 * Shared option-backed storage for the connection and scope registries.
 *
 * Proposal 167. Both registries are small, operator-edited lists keyed by a stable
 * slug, so they share one storage shape: a single option holding an array of
 * records, written whole. A custom table would buy concurrency control this data
 * does not need and would make export/import harder.
 *
 * ## The test seam
 *
 * `$records_override` lets the portable adapter tests exercise CRUD without
 * WordPress. It is never set by production code; when it is null the registry
 * reads and writes the option exactly as any other plugin class would.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base registry.
 */
abstract class WP_MCP_AI_Db_Registry {

	/**
	 * Option that holds the records.
	 *
	 * @var string
	 */
	protected string $option_name = '';

	/**
	 * Test-only record override.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $records_override = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>>|null $records_override Test-only seed; null in production.
	 */
	public function __construct( ?array $records_override = null ) {
		$this->records_override = $records_override;
	}

	/**
	 * All records, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$records = $this->read();
		$clean   = array();

		foreach ( $records as $id => $record ) {
			if ( ! is_string( $id ) || ! is_array( $record ) ) {
				continue;
			}

			$clean[ strtolower( $id ) ] = $record;
		}

		return $clean;
	}

	/**
	 * Whether any record exists.
	 */
	public function has_any(): bool {
		return array() !== $this->all();
	}

	/**
	 * Record ids.
	 *
	 * @return list<string>
	 */
	public function ids(): array {
		return array_keys( $this->all() );
	}

	/**
	 * Delete a record.
	 *
	 * @param string $id Record id.
	 */
	public function delete( string $id ): bool {
		$id      = strtolower( trim( $id ) );
		$records = $this->all();

		if ( ! isset( $records[ $id ] ) ) {
			return false;
		}

		unset( $records[ $id ] );

		return $this->write( $records );
	}

	/**
	 * Read the record map.
	 *
	 * @return array<string, mixed>
	 */
	protected function read(): array {
		if ( null !== $this->records_override ) {
			return $this->records_override;
		}

		$stored = get_option( $this->option_name, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Write the record map.
	 *
	 * @param array<string, mixed> $records Records.
	 */
	protected function write( array $records ): bool {
		if ( null !== $this->records_override ) {
			$this->records_override = $records;

			return true;
		}

		return (bool) update_option( $this->option_name, $records, false );
	}

	/**
	 * Persist a single record.
	 *
	 * A record that is already stored byte-for-byte is a successful no-op, not a
	 * failure: `update_option()` returns false when the value did not change, and
	 * the scope registry saves an unchanged form on every editor submission (the
	 * version must not move, but the operator's save must still succeed).
	 *
	 * @param string               $id     Record id.
	 * @param array<string, mixed> $record Record.
	 */
	protected function put( string $id, array $record ): bool {
		$records = $this->all();

		if ( array_key_exists( $id, $records ) && $records[ $id ] === $record ) {
			return true;
		}

		$records[ $id ] = $record;

		return $this->write( $records );
	}

	/**
	 * The stored record, or null.
	 *
	 * @param string $id Record id.
	 * @return array<string, mixed>|null
	 */
	protected function find( string $id ): ?array {
		$records = $this->all();
		$id      = strtolower( trim( $id ) );

		return $records[ $id ] ?? null;
	}
}
