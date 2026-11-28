<?php
/**
 * Credential store backed by the plugin's master key.
 *
 * Proposal 167 decisions D5 and A2. There is no second cipher: a connection's
 * credential is a blob produced by {@see WP_MCP_AI_Encryption} and stored in the
 * connection record. That keeps key rotation, the "externally managed key"
 * path and the Site Health key checks working for database credentials exactly
 * as they already do for provider API keys.
 *
 * ## Handle formats
 *
 *  - `v2:…` (or any value `WP_MCP_AI_Encryption::is_encrypted()` accepts):
 *    decrypted in place, then parsed as JSON `{"username":"…","password":"…"}`.
 *  - `vault:<item>`: resolved by an integration through the
 *    {@see 'wp_mcp_ai_database_resolve_credential'} filter. The Vault add-on,
 *    or any secrets manager, can answer without this class knowing about it.
 *
 * Anything else is refused. A plaintext password in the record is a
 * configuration error, not a fallback: accepting it would silently downgrade
 * every other connection's handling.
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
 * Resolves encrypted credential blobs and Vault handles.
 */
final class WP_MCP_AI_Db_Encrypted_Credential_Store implements WP_MCP_AI_Db_Credential_Interface {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $handle Stored credential handle.
	 * @throws DatabaseRefusal When the handle cannot be resolved or decrypted.
	 */
	public function resolve( string $handle ): array {
		$handle = trim( $handle );

		if ( '' === $handle ) {
			throw DatabaseRefusal::of( RefusalCode::AUTH_FAILED, 'Connection has no credential handle.' );
		}

		if ( str_contains( $handle, ':' ) && ! ( class_exists( 'WP_MCP_AI_Encryption' ) && WP_MCP_AI_Encryption::is_encrypted( $handle ) ) ) {
			return $this->resolve_external( $handle );
		}

		if ( ! class_exists( 'WP_MCP_AI_Encryption' ) || ! WP_MCP_AI_Encryption::is_encrypted( $handle ) ) {
			throw DatabaseRefusal::of(
				RefusalCode::AUTH_FAILED,
				'Connection credential is not an encrypted blob; re-save the record.'
			);
		}

		$plaintext = WP_MCP_AI_Encryption::decrypt( $handle );

		if ( ! is_string( $plaintext ) || '' === $plaintext ) {
			throw DatabaseRefusal::of( RefusalCode::AUTH_FAILED, 'Credential blob could not be decrypted.' );
		}

		return $this->parse( $plaintext );
	}

	/**
	 * Encrypt an arbitrary payload for storage.
	 *
	 * Used by the write-proposal store, which must not keep structured write
	 * requests in the clear. Same key, same rotation behaviour, one cipher.
	 *
	 * @param string $value Plaintext.
	 * @return string Encrypted handle.
	 * @throws DatabaseRefusal When encryption fails or is unavailable.
	 */
	public function seal_string( string $value ): string {
		if ( ! class_exists( 'WP_MCP_AI_Encryption' ) ) {
			throw DatabaseRefusal::of(
				RefusalCode::CONNECTION_UNAVAILABLE,
				'WP_MCP_AI_Encryption is not loaded; cannot seal a payload.'
			);
		}

		$sealed = WP_MCP_AI_Encryption::encrypt( $value );

		if ( ! is_string( $sealed ) || '' === $sealed ) {
			throw DatabaseRefusal::of( RefusalCode::CONNECTION_UNAVAILABLE, 'Payload encryption failed.' );
		}

		return $sealed;
	}

	/**
	 * Decrypt a payload produced by {@see self::seal_string()}.
	 *
	 * @param string $handle Encrypted handle.
	 * @return string Plaintext.
	 * @throws DatabaseRefusal When the handle cannot be decrypted.
	 */
	public function open_string( string $handle ): string {
		if ( ! class_exists( 'WP_MCP_AI_Encryption' ) || ! WP_MCP_AI_Encryption::is_encrypted( $handle ) ) {
			throw DatabaseRefusal::of( RefusalCode::CONNECTION_UNAVAILABLE, 'Payload is not an encrypted blob.' );
		}

		$plaintext = WP_MCP_AI_Encryption::decrypt( $handle );

		if ( ! is_string( $plaintext ) ) {
			throw DatabaseRefusal::of( RefusalCode::CONNECTION_UNAVAILABLE, 'Payload could not be decrypted.' );
		}

		return $plaintext;
	}

	/**
	 * Encrypt a plaintext credential for storage.
	 *
	 * @param string $username Database user.
	 * @param string $password Database password.
	 * @return string Stored handle.
	 * @throws DatabaseRefusal When the payload cannot be encrypted.
	 */
	public function seal( string $username, string $password ): string {
		if ( ! class_exists( 'WP_MCP_AI_Encryption' ) ) {
			throw DatabaseRefusal::of(
				RefusalCode::CONNECTION_UNAVAILABLE,
				'WP_MCP_AI_Encryption is not loaded; cannot store a database credential.'
			);
		}

		$payload = (string) wp_json_encode(
			array(
				'username' => $username,
				'password' => $password,
			)
		);

		$sealed = WP_MCP_AI_Encryption::encrypt( $payload );

		if ( ! is_string( $sealed ) || '' === $sealed ) {
			throw DatabaseRefusal::of( RefusalCode::CONNECTION_UNAVAILABLE, 'Credential encryption failed.' );
		}

		return $sealed;
	}

	/**
	 * Resolve an external handle (`vault:…`, `remote:…`) through an integration.
	 *
	 * Any scheme is accepted rather than a fixed list, so a host can add a secret
	 * store without editing this class. The filter's default is null, which
	 * refuses: an unresolvable handle never degrades to an unauthenticated
	 * connection.
	 *
	 * @param string $handle Full handle, including its scheme.
	 * @return array{username: string, password: string}
	 * @throws DatabaseRefusal When no integration resolves the handle.
	 */
	private function resolve_external( string $handle ): array {
		$handle = trim( $handle );

		if ( str_ends_with( $handle, ':' ) ) {
			throw DatabaseRefusal::of( RefusalCode::AUTH_FAILED, 'Credential handle has no item reference.' );
		}

		/**
		 * Resolve a database credential from an external secret store.
		 *
		 * Return an array with `username` and `password` keys, or null to decline.
		 * The default is null, which refuses the connection: a handle never
		 * silently degrades to an unauthenticated connection.
		 *
		 * @since 3.1.0
		 *
		 * @param array{username: string, password: string}|null $resolved Resolved credential.
		 * @param string                                          $handle   Full handle.
		 */
		$resolved = apply_filters( 'wp_mcp_ai_database_resolve_credential', null, $handle );

		if ( ! is_array( $resolved ) || ! isset( $resolved['username'], $resolved['password'] ) ) {
			throw DatabaseRefusal::of(
				RefusalCode::AUTH_FAILED,
				'Credential handle could not be resolved by any integration.'
			);
		}

		return array(
			'username' => (string) $resolved['username'],
			'password' => (string) $resolved['password'],
		);
	}

	/**
	 * Parse a decrypted JSON credential.
	 *
	 * @param string $plaintext JSON payload.
	 * @return array{username: string, password: string}
	 * @throws DatabaseRefusal When the payload is not a usable credential.
	 */
	private function parse( string $plaintext ): array {
		$decoded = json_decode( $plaintext, true );

		if ( ! is_array( $decoded ) ) {
			throw DatabaseRefusal::of( RefusalCode::AUTH_FAILED, 'Credential payload is not JSON.' );
		}

		$username = $decoded['username'] ?? ( $decoded['user'] ?? null );
		$password = $decoded['password'] ?? ( $decoded['pass'] ?? null );

		if ( ! is_string( $username ) || '' === trim( $username ) || ! is_string( $password ) ) {
			throw DatabaseRefusal::of( RefusalCode::AUTH_FAILED, 'Credential payload is missing username or password.' );
		}

		return array(
			'username' => trim( $username ),
			'password' => $password,
		);
	}
}
