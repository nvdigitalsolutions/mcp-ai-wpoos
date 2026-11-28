<?php
/**
 * Remote Sites provider — database records authored as a connection.
 *
 * Proposal 167 decisions D11 and D12. A Remote Sites record with
 * `connection_type = database` is a database endpoint an operator already
 * configured, plus per-assistant enablement through
 * `_wp_mcp_ai_pro_remote_connections`. This provider projects it into the
 * toolkit's descriptor vocabulary so an assistant can use one id space.
 *
 * ## The two gates, in order
 *
 *  1. **Remote Sites enablement** — an assistant with no enablement meta sees
 *     every enabled record; an assistant with a list sees only that list. This
 *     mirrors the existing convention used by the remote-WP tools, so the same
 *     checkbox means the same thing.
 *  2. **The scope** — the executor still requires a scope bound to the principal
 *     whose `connection` equals `rs:<id>`. Enablement is not a grant; it is only
 *     permission to *see* the endpoint in a list.
 *
 * ## Credentials
 *
 * The record's `db_password` is decrypted by the Remote Sites manager and never
 * copied into the descriptor. The descriptor carries the handle `remote:<id>`,
 * which the toolkit resolves through {@see WP_MCP_AI_Db_Encrypted_Credential_Store}
 * and this provider's filter. That keeps one plaintext window, at connect time.
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

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\ProbeResult;

/**
 * Database records from Remote Sites.
 */
final class WP_MCP_AI_Remote_Site_Connection_Provider implements WP_MCP_AI_Connection_Provider_Interface {

	/**
	 * Assistant enablement meta key.
	 */
	public const ENABLED_META = '_wp_mcp_ai_pro_remote_connections';

	/**
	 * Engines a Remote Sites database record may declare.
	 *
	 * @var list<string>
	 */
	private const ENGINES = array( 'pgsql', 'mysql', 'mariadb' );

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'remote_site';
	}

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return 'rs';
	}

	/**
	 * Whether the Remote Sites manager is available.
	 */
	public function is_available(): bool {
		return class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' );
	}

	/**
	 * Register the credential resolver for `remote:` handles.
	 *
	 * Called once from the Pro toolkit initialiser. Kept separate from the
	 * constructor so a provider that is only listed does not mutate global state.
	 */
	public function register_credential_filter(): void {
		add_filter( 'wp_mcp_ai_database_resolve_credential', array( $this, 'resolve_credential' ), 10, 2 );
	}

	/**
	 * Resolve a `remote:<id>` credential from the Remote Sites record.
	 *
	 * @param array{username: string, password: string}|null $resolved Incoming value.
	 * @param string                                         $handle   Handle.
	 * @return array{username: string, password: string}|null
	 */
	public function resolve_credential( $resolved, $handle ) {
		if ( null !== $resolved || ! is_string( $handle ) || ! str_starts_with( $handle, 'remote:' ) ) {
			return $resolved;
		}

		if ( ! $this->is_available() ) {
			return null;
		}

		$record = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( substr( $handle, 7 ) );

		if ( ! is_array( $record ) ) {
			return null;
		}

		$username = (string) ( $record['db_username'] ?? $record['username'] ?? '' );
		$password = (string) ( $record['db_password'] ?? $record['password'] ?? '' );

		if ( '' !== $password && method_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager', 'decrypt_value' ) ) {
			$password = (string) WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $password );
		}

		if ( '' === $username || '' === $password ) {
			return null;
		}

		return array(
			'username' => $username,
			'password' => $password,
		);
	}

	/**
	 * Connections this principal may use, redacted.
	 *
	 * @param PrincipalRef $principal Principal.
	 * @return list<ConnectionDescriptor>
	 */
	public function list_connections( PrincipalRef $principal ): array {
		if ( ! $this->is_available() || PrincipalRef::ASSISTANT !== $principal->kind ) {
			return array();
		}

		$enabled_for_assistant = get_post_meta( (int) $principal->id, self::ENABLED_META, true );
		$enabled_for_assistant = is_array( $enabled_for_assistant ) ? array_map( 'strval', $enabled_for_assistant ) : array();

		$found = array();

		foreach ( (array) WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $id => $record ) {
			if ( ! is_array( $record ) || ! $this->is_database_record( $record ) ) {
				continue;
			}

			if ( empty( $record['enabled'] ) ) {
				continue;
			}

			if ( array() !== $enabled_for_assistant && ! in_array( (string) $id, $enabled_for_assistant, true ) ) {
				continue;
			}

			$descriptor = $this->descriptor( (string) $id, $record );

			if ( null !== $descriptor ) {
				$found[] = $descriptor;
			}
		}

		usort( $found, static fn ( ConnectionDescriptor $a, ConnectionDescriptor $b ): int => strcmp( $a->id, $b->id ) );

		return $found;
	}

	/**
	 * Whether this provider owns an id.
	 *
	 * @param string $id Namespaced or bare id.
	 */
	public function supports( string $id ): bool {
		$id = strtolower( trim( $id ) );

		if ( str_starts_with( $id, 'rs:' ) ) {
			return true;
		}

		// A bare id never selects a Remote Sites record: the two stores could share
		// a slug, and silently preferring one would make the same tool argument
		// mean different endpoints on different sites.
		return false;
	}

	/**
	 * Resolve an id to a descriptor.
	 *
	 * @param string       $id        Namespaced or bare id.
	 * @param PrincipalRef $principal Principal.
	 * @throws DatabaseRefusal When the id is unknown or not visible.
	 */
	public function resolve( string $id, PrincipalRef $principal ): ConnectionDescriptor {
		if ( ! $this->is_available() ) {
			throw DatabaseRefusal::of( RefusalCode::NOT_A_DATABASE, 'Remote Sites is not available on this site.' );
		}

		$bare = str_starts_with( strtolower( $id ), 'rs:' ) ? substr( $id, 3 ) : $id;

		foreach ( $this->list_connections( $principal ) as $connection ) {
			if ( 'rs:' . strtolower( trim( $bare ) ) === $connection->id ) {
				return $connection;
			}
		}

		$record = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $bare );

		if ( is_array( $record ) && ! $this->is_database_record( $record ) ) {
			throw DatabaseRefusal::of( RefusalCode::NOT_A_DATABASE, 'Remote Sites record ' . $bare . ' is not a database.' );
		}

		throw DatabaseRefusal::of( RefusalCode::UNKNOWN_CONNECTION, 'Remote Sites database not found or not enabled for this assistant: ' . $bare );
	}

	/**
	 * Whether a record is a database connection.
	 *
	 * @param array<string, mixed> $record Record.
	 */
	private function is_database_record( array $record ): bool {
		return isset( $record['connection_type'] ) && 'database' === (string) $record['connection_type'];
	}

	/**
	 * Build a descriptor from a record, or null when the record is incomplete.
	 *
	 * @param string               $id     Record id.
	 * @param array<string, mixed> $record Record.
	 */
	private function descriptor( string $id, array $record ): ?ConnectionDescriptor {
		$engine = strtolower( (string) ( $record['db_engine'] ?? $record['engine'] ?? '' ) );

		if ( ! in_array( $engine, self::ENGINES, true ) ) {
			return null;
		}

		$host = (string) ( $record['db_host'] ?? $record['host'] ?? '' );
		$name = (string) ( $record['db_name'] ?? $record['database'] ?? '' );

		if ( '' === $host || '' === $name ) {
			return null;
		}

		$probe = null;

		if ( isset( $record['probe'] ) && is_array( $record['probe'] ) ) {
			$probe = new ProbeResult(
				reachable: ! empty( $record['probe']['reachable'] ),
				serverVersion: (string) ( $record['probe']['server_version'] ?? '' ),
				role: (string) ( $record['probe']['role'] ?? '' ),
				transactionReadOnly: ! empty( $record['probe']['transaction_read_only'] ),
				canWrite: ! empty( $record['probe']['can_write'] ),
				canCreateSchema: ! empty( $record['probe']['can_create_schema'] ),
				bypassesRls: ! empty( $record['probe']['bypasses_rls'] ),
				checkedAt: (int) ( $record['probe']['checked_at'] ?? 0 ),
				error: isset( $record['probe']['error'] ) ? (string) $record['probe']['error'] : null,
			);
		}

		try {
			return new ConnectionDescriptor(
				id: 'rs:' . strtolower( $id ),
				label: (string) ( $record['name'] ?? $record['label'] ?? $id ),
				engine: $engine,
				host: $host,
				port: (int) ( $record['db_port'] ?? $record['port'] ?? 0 ),
				database: $name,
				sslmode: strtolower( (string) ( $record['db_sslmode'] ?? $record['sslmode'] ?? 'require' ) ),
				credential: 'remote:' . $id,
				role: (string) ( $record['db_role'] ?? $record['role'] ?? '' ),
				allowSiteDb: ! empty( $record['allow_site_db'] ),
				probe: $probe,
				kind: 'remote_site'
			);
		} catch ( DatabaseRefusal $refusal ) {
			// An incomplete record is skipped rather than listed as broken, matching
			// the Remote Sites list's own tolerance for half-migrated records.
			return null;
		}
	}
}
