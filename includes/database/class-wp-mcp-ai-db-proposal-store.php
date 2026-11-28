<?php
/**
 * Write proposals — the two-phase write path.
 *
 * Proposal 167 section 167.6.9 and phase P6. A write is never one call:
 * `db_propose_write` records what would happen (statement template, structured
 * request, scope version, estimated affected rows, expiry) and returns an id;
 * `db_apply_write` refuses unless a human approved it, re-counts inside the write
 * transaction, and refuses if the count drifted.
 *
 * ## Why the request is encrypted
 *
 * The structured request can contain new values (a price, a status, a customer
 * note). Storing them in an option in the clear would make the proposal store a
 * worse data-exposure surface than the database it protects, so the payload is
 * sealed with the same master key as a connection credential, and only the hash
 * of the statement and the estimate are stored in the clear.
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

/**
 * Proposal storage.
 */
final class WP_MCP_AI_Db_Proposal_Store {

	/**
	 * Option name.
	 */
	public const OPTION = 'wp_mcp_ai_database_write_proposals';

	/**
	 * Default lifetime, in seconds.
	 */
	public const DEFAULT_TTL = 900;

	/**
	 * Maximum retained proposals.
	 */
	private const MAX_RECORDS = 100;

	/**
	 * Credential cipher, reused for payload sealing.
	 *
	 * @var WP_MCP_AI_Db_Encrypted_Credential_Store
	 */
	private WP_MCP_AI_Db_Encrypted_Credential_Store $cipher;

	/**
	 * Test-only record override.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $records_override = null;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Db_Encrypted_Credential_Store|null $cipher Cipher.
	 * @param array<string, array<string, mixed>>|null     $records_override Test-only seed.
	 */
	public function __construct(
		?WP_MCP_AI_Db_Encrypted_Credential_Store $cipher = null,
		?array $records_override = null
	) {
		$this->cipher           = $cipher ?? new WP_MCP_AI_Db_Encrypted_Credential_Store();
		$this->records_override = $records_override;
	}

	/**
	 * Create a proposal.
	 *
	 * @param array<string, mixed> $proposal Proposal fields.
	 * @return array<string, mixed> Stored proposal, without the sealed payload.
	 * @throws DatabaseRefusal When the payload cannot be sealed.
	 */
	public function create( array $proposal ): array {
		$id     = 'wpr_' . bin2hex( random_bytes( 8 ) );
		$ttl    = isset( $proposal['ttl'] ) ? max( 60, (int) $proposal['ttl'] ) : self::DEFAULT_TTL;
		$record = array(
			'id'                 => $id,
			'created_at'         => time(),
			'expires_at'         => time() + $ttl,
			'status'             => 'proposed',
			'connection'         => (string) ( $proposal['connection'] ?? '' ),
			'scope'              => (string) ( $proposal['scope'] ?? '' ),
			'scope_version'      => (int) ( $proposal['scope_version'] ?? 0 ),
			'principal'          => (string) ( $proposal['principal'] ?? '' ),
			'operation'          => (string) ( $proposal['operation'] ?? '' ),
			'relation'           => (string) ( $proposal['relation'] ?? '' ),
			'rationale'          => (string) ( $proposal['rationale'] ?? '' ),
			'statement_hash'     => (string) ( $proposal['statement_hash'] ?? '' ),
			'statement_template' => (string) ( $proposal['statement_template'] ?? '' ),
			'estimated_rows'     => (int) ( $proposal['estimated_rows'] ?? 0 ),
			'payload'            => $this->seal_payload( (array) ( $proposal['request'] ?? array() ) ),
			'approved_by'        => 0,
			'approved_at'        => 0,
			'applied_at'         => 0,
		);

		$records        = $this->read();
		$records[ $id ] = $record;

		if ( count( $records ) > self::MAX_RECORDS ) {
			uasort(
				$records,
				static fn ( array $a, array $b ): int => (int) ( $a['created_at'] ?? 0 ) <=> (int) ( $b['created_at'] ?? 0 )
			);
			$records = array_slice( $records, -self::MAX_RECORDS, null, true );
		}

		$this->write( $records );

		return $this->public_view( $record );
	}

	/**
	 * All proposals, without sealed payloads, keyed by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$records = $this->read();
		$out     = array();

		foreach ( $records as $id => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			unset( $record['payload'] );
			$out[ (string) ( $record['id'] ?? $id ) ] = $record;
		}

		return $out;
	}

	/**
	 * Replace one stored proposal.
	 *
	 * Used by the admin revocation path, which flips an approval back to
	 * `proposed` without losing the rest of the record.
	 *
	 * @param string               $id     Proposal id.
	 * @param array<string, mixed> $record Full record.
	 */
	public function replace( string $id, array $record ): bool {
		$records = $this->read();

		if ( ! isset( $records[ $id ] ) ) {
			return false;
		}

		$records[ $id ] = $record;

		return $this->write( $records );
	}

	/**
	 * Fetch a proposal.
	 *
	 * @param string $id Proposal id.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		$records = $this->read();

		return $records[ $id ] ?? null;
	}

	/**
	 * The structured request, unsealed.
	 *
	 * @param string $id Proposal id.
	 * @return array<string, mixed>
	 * @throws DatabaseRefusal When the proposal has expired or its payload cannot be read.
	 */
	public function request( string $id ): array {
		$record = $this->get( $id );

		if ( null === $record ) {
			throw DatabaseRefusal::of( RefusalCode::APPROVAL_REQUIRED, 'Proposal not found: ' . $id );
		}

		if ( (int) ( $record['expires_at'] ?? 0 ) < time() ) {
			throw DatabaseRefusal::of( RefusalCode::APPROVAL_REQUIRED, 'Proposal ' . $id . ' has expired.' );
		}

		$payload = $this->cipher->open_string( (string) ( $record['payload'] ?? '' ) );
		$decoded = json_decode( $payload, true );

		if ( ! is_array( $decoded ) ) {
			throw DatabaseRefusal::of( RefusalCode::APPROVAL_REQUIRED, 'Proposal payload is unreadable.' );
		}

		return $decoded;
	}

	/**
	 * Record a human approval.
	 *
	 * @param string $id      Proposal id.
	 * @param int    $user_id Approving user.
	 * @return bool
	 */
	public function approve( string $id, int $user_id ): bool {
		$records = $this->read();

		if ( ! isset( $records[ $id ] ) ) {
			return false;
		}

		if ( (int) ( $records[ $id ]['expires_at'] ?? 0 ) < time() ) {
			return false;
		}

		$records[ $id ]['status']      = 'approved';
		$records[ $id ]['approved_by'] = $user_id;
		$records[ $id ]['approved_at'] = time();

		return $this->write( $records );
	}

	/**
	 * Mark a proposal applied.
	 *
	 * @param string $id Proposal id.
	 * @return bool
	 */
	public function consume( string $id ): bool {
		$records = $this->read();

		if ( ! isset( $records[ $id ] ) ) {
			return false;
		}

		$records[ $id ]['status']     = 'applied';
		$records[ $id ]['applied_at'] = time();

		return $this->write( $records );
	}

	/**
	 * Drop expired proposals.
	 */
	public function prune(): void {
		$records = $this->read();
		$now     = time();

		foreach ( $records as $id => $record ) {
			if ( (int) ( $record['expires_at'] ?? 0 ) < $now ) {
				unset( $records[ $id ] );
			}
		}

		$this->write( $records );
	}

	/**
	 * Public projection of a proposal.
	 *
	 * @param array<string, mixed> $record Stored record.
	 * @return array<string, mixed>
	 */
	private function public_view( array $record ): array {
		unset( $record['payload'] );

		return $record;
	}

	/**
	 * Seal the structured request.
	 *
	 * @param array<string, mixed> $request Structured request.
	 * @throws DatabaseRefusal When sealing fails.
	 */
	private function seal_payload( array $request ): string {
		return $this->cipher->seal_string( (string) wp_json_encode( $request ) );
	}

	/**
	 * Read the proposal map.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function read(): array {
		if ( null !== $this->records_override ) {
			return $this->records_override;
		}

		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Write the proposal map.
	 *
	 * @param array<string, array<string, mixed>> $records Records.
	 */
	private function write( array $records ): bool {
		if ( null !== $this->records_override ) {
			$this->records_override = $records;

			return true;
		}

		return (bool) update_option( self::OPTION, $records, false );
	}
}
