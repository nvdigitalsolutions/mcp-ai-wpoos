<?php
/**
 * Connection records.
 *
 * Proposal 167 sections 167.6.2 and 167.6.5. A record is the operator's
 * configuration of an endpoint; a {@see \Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor}
 * is its validated, secret-free projection. This class owns the transition
 * between the two, which is the only place a plaintext password is touched.
 *
 * ## Credential sealing
 *
 * `save()` accepts either an already-sealed handle (`credential`) or a
 * `username`/`password` pair. A pair is encrypted here and never stored; the
 * returned descriptor carries the handle. A record that contains a plaintext
 * password under `credential` is refused, because accepting it would mean the
 * store sometimes holds secrets in the clear.
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
use Nvoos\Database\Domain\Contract\Database\ConnectionRegistryInterface;

/**
 * CRUD for connection records.
 */
final class WP_MCP_AI_Db_Connection_Registry extends WP_MCP_AI_Db_Registry implements ConnectionRegistryInterface {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	protected string $option_name = 'wp_mcp_ai_database_connections';

	/**
	 * Credential store, for sealing a username/password pair.
	 *
	 * @var WP_MCP_AI_Db_Encrypted_Credential_Store
	 */
	private WP_MCP_AI_Db_Encrypted_Credential_Store $credentials;

	/**
	 * Scope registry, used for principal visibility.
	 *
	 * @var WP_MCP_AI_Db_Scope_Registry|null
	 */
	private ?WP_MCP_AI_Db_Scope_Registry $scopes;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Db_Encrypted_Credential_Store|null $credentials Credential store.
	 * @param WP_MCP_AI_Db_Scope_Registry|null             $scopes      Scope registry.
	 * @param array<string, array<string, mixed>>|null     $records_override Test-only seed.
	 */
	public function __construct(
		?WP_MCP_AI_Db_Encrypted_Credential_Store $credentials = null,
		?WP_MCP_AI_Db_Scope_Registry $scopes = null,
		?array $records_override = null
	) {
		parent::__construct( $records_override );

		$this->credentials = $credentials ?? new WP_MCP_AI_Db_Encrypted_Credential_Store();
		$this->scopes      = $scopes;
	}

	/**
	 * Attach the scope registry after construction (circular dependency).
	 *
	 * @param WP_MCP_AI_Db_Scope_Registry $scopes Scope registry.
	 */
	public function set_scope_registry( WP_MCP_AI_Db_Scope_Registry $scopes ): void {
		$this->scopes = $scopes;
	}

	/**
	 * Create or replace a connection record.
	 *
	 * @param array<string, mixed> $record Raw record.
	 * @return ConnectionDescriptor Saved descriptor.
	 * @throws DatabaseRefusal When the record is invalid or targets the site database.
	 */
	public function save( array $record ): ConnectionDescriptor {
		$record['id'] = strtolower( trim( (string) ( $record['id'] ?? '' ) ) );

		if ( isset( $record['username'] ) || isset( $record['password'] ) ) {
			$record['credential'] = $this->credentials->seal(
				(string) ( $record['username'] ?? '' ),
				(string) ( $record['password'] ?? '' )
			);

			unset( $record['username'], $record['password'] );
		}

		if ( isset( $record['credential'] ) && is_string( $record['credential'] ) && '' !== $record['credential'] ) {
			$this->assert_sealed_credential( $record['credential'] );
		}

		$existing = $this->find( $record['id'] );

		if ( is_array( $existing ) && ! isset( $record['probe'] ) && isset( $existing['probe'] ) ) {
			$record['probe'] = $existing['probe'];
		}

		// A save that carries no credential keeps the stored one. The admin form
		// deliberately never renders the sealed blob, so requiring it back would
		// make every edit of a label ask for the password again.
		if ( is_array( $existing ) && ! isset( $record['credential'] ) && isset( $existing['credential'] ) ) {
			$record['credential'] = $existing['credential'];
		}

		// Fill an engine default before validation: the descriptor refuses port 0
		// on a server engine, and a UI that leaves the field blank means "default".
		if ( empty( $record['port'] ) && ConnectionDescriptor::SQLITE !== ( $record['engine'] ?? '' ) ) {
			$record['port'] = $this->default_port( (string) ( $record['engine'] ?? '' ) );
		}

		if ( ConnectionDescriptor::SQLITE !== ( $record['engine'] ?? '' ) && empty( $record['credential'] ) ) {
			throw DatabaseRefusal::of( RefusalCode::AUTH_FAILED, 'A connection needs a credential.' );
		}

		$descriptor = ConnectionDescriptor::fromArray( $record );

		WP_MCP_AI_Db_Site_Database::assert_not_site_database( $descriptor );

		if ( ! $this->put( $descriptor->id, $record ) ) {
			throw DatabaseRefusal::of( RefusalCode::CONNECTION_UNAVAILABLE, 'Connection record could not be saved.' );
		}

		return ConnectionDescriptor::fromArray( $record );
	}

	/**
	 * Fetch a descriptor by id.
	 *
	 * @param string $id Connection id.
	 */
	public function get( string $id ): ?ConnectionDescriptor {
		$record = $this->find( $id );

		if ( null === $record ) {
			return null;
		}

		try {
			return ConnectionDescriptor::fromArray( $record );
		} catch ( DatabaseRefusal $refusal ) {
			return null;
		}
	}

	/**
	 * Fetch a descriptor or refuse.
	 *
	 * @param string $id Connection id.
	 * @throws DatabaseRefusal When the connection does not exist or is invalid.
	 */
	public function get_or_refuse( string $id ): ConnectionDescriptor {
		$descriptor = $this->get( $id );

		if ( null === $descriptor ) {
			throw DatabaseRefusal::of( RefusalCode::UNKNOWN_CONNECTION, 'Connection not found: ' . $id );
		}

		return $descriptor;
	}

	/**
	 * Store a probe result.
	 *
	 * @param string      $id      Connection id.
	 * @param ProbeResult $probe   Result.
	 */
	public function record_probe( string $id, ProbeResult $probe ): bool {
		$record = $this->find( $id );

		if ( null === $record ) {
			return false;
		}

		$record['probe'] = array(
			'reachable'             => $probe->reachable,
			'server_version'        => $probe->serverVersion,
			'role'                  => $probe->role,
			'transaction_read_only' => $probe->transactionReadOnly,
			'can_write'             => $probe->canWrite,
			'can_create_schema'     => $probe->canCreateSchema,
			'bypasses_rls'          => $probe->bypassesRls,
			'checked_at'            => $probe->checkedAt,
			'error'                 => $probe->error,
		);

		return $this->put( strtolower( trim( $id ) ), $record );
	}

	/**
	 * A record for the admin form, with the credential removed.
	 *
	 * The form never needs the sealed blob, and rendering it would put a copy of
	 * the ciphertext in the page source. Saving with a blank password keeps the
	 * stored value, so no round trip is required.
	 *
	 * @param string $id Connection id.
	 * @return array<string, mixed>|null
	 */
	public function find_public( string $id ): ?array {
		$record = $this->find( $id );

		if ( null === $record ) {
			return null;
		}

		$record['has_credential'] = ! empty( $record['credential'] );
		unset( $record['credential'] );

		return $record;
	}

	/**
	 * Redacted summaries for the console and `db_list_connections`.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function summaries(): array {
		$summaries = array();

		foreach ( $this->all() as $id => $record ) {
			try {
				$summaries[] = ConnectionDescriptor::fromArray( $record )->toSummary();
			} catch ( DatabaseRefusal $refusal ) {
				$summaries[] = array(
					'id'        => (string) $id,
					'label'     => (string) ( $record['label'] ?? $id ),
					'engine'    => (string) ( $record['engine'] ?? '' ),
					'kind'      => 'database',
					'queryable' => false,
					'probe'     => null,
					'error'     => $refusal->getRefusalCode(),
				);
			}
		}

		return $summaries;
	}

	/**
	 * Connections a principal may use, through any scope.
	 *
	 * @param PrincipalRef $principal Principal.
	 * @return list<ConnectionDescriptor>
	 */
	public function visible_to( PrincipalRef $principal ): array {
		if ( null === $this->scopes ) {
			return array();
		}

		$ids   = array();
		$found = array();

		foreach ( $this->scopes->for_principal( $principal ) as $scope ) {
			$ids[ $scope->connection ] = true;
		}

		foreach ( array_keys( $ids ) as $id ) {
			$descriptor = $this->get( (string) $id );

			if ( null !== $descriptor ) {
				$found[] = $descriptor;
			}
		}

		usort( $found, static fn ( ConnectionDescriptor $a, ConnectionDescriptor $b ): int => strcmp( $a->id, $b->id ) );

		return $found;
	}

	/**
	 * Refuse a credential that is not a sealed handle.
	 *
	 * @param string $credential Stored value.
	 * @throws DatabaseRefusal When the credential is neither sealed nor a Vault handle.
	 */
	private function assert_sealed_credential( string $credential ): void {
		if ( str_starts_with( $credential, 'vault:' ) ) {
			return;
		}

		if ( class_exists( 'WP_MCP_AI_Encryption' ) && WP_MCP_AI_Encryption::is_encrypted( $credential ) ) {
			return;
		}

		throw DatabaseRefusal::of(
			RefusalCode::AUTH_FAILED,
			'Credential is not sealed; pass username/password to be encrypted, or an encrypted handle.'
		);
	}

	/**
	 * Default port for an engine.
	 *
	 * @param string $engine Engine key.
	 */
	private function default_port( string $engine ): int {
		return match ( $engine ) {
			ConnectionDescriptor::PGSQL   => 5432,
			ConnectionDescriptor::MYSQL,
			ConnectionDescriptor::MARIADB => 3306,
			default                       => 0,
		};
	}
}
