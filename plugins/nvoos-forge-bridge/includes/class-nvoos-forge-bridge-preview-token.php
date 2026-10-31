<?php
/**
 * Preview tokens — single-use, expiring bearer proofs that authorize one
 * preview build (Proposal 096 Phase 2).
 *
 * Tokens are minted through a capability-gated REST route and stored ONLY
 * as hashes (wp_hash of the raw token) inside a transient; the raw value is
 * returned exactly once. Verification is constant-time and consumes the
 * token.
 *
 * @package NvoosForgeBridge
 */

namespace NvoosForgeBridge;

/**
 * Preview token mint/verify store.
 */
final class Preview_Token {

	/**
	 * Token lifetime (seconds).
	 */
	public const TTL_SECONDS = 900;

	/**
	 * Transient key for the hash → expiry map.
	 */
	private const TRANSIENT_STORE = 'nvoos_forge_bridge_preview_tokens';

	/**
	 * Mint a token. Returns the RAW token once; the store keeps the hash.
	 *
	 * @return string Raw token (64 hex chars).
	 */
	public static function mint(): string {
		$raw             = bin2hex( random_bytes( 32 ) );
		$hash            = self::hash( $raw );
		$tokens          = self::store();
		$tokens[ $hash ] = time() + self::TTL_SECONDS;
		self::prune( $tokens );
		set_transient( self::TRANSIENT_STORE, $tokens, self::TTL_SECONDS );
		return $raw;
	}

	/**
	 * Verify and consume a token. Fail closed.
	 *
	 * @param string $raw Raw token.
	 * @return bool
	 */
	public static function verify_and_consume( string $raw ): bool {
		if ( '' === $raw ) {
			return false;
		}
		$tokens = self::store();
		self::prune( $tokens );

		$matched = false;
		foreach ( array_keys( $tokens ) as $stored_hash ) {
			if ( hash_equals( $stored_hash, self::hash( $raw ) ) ) {
				unset( $tokens[ $stored_hash ] );
				$matched = true;
				break;
			}
		}
		set_transient( self::TRANSIENT_STORE, $tokens, self::TTL_SECONDS );
		return $matched;
	}

	/**
	 * Read the store (empty array when unset).
	 *
	 * @return array<string,int> hash => expiry timestamp.
	 */
	private static function store(): array {
		$stored = get_transient( self::TRANSIENT_STORE );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Drop expired entries in place.
	 *
	 * @param array $tokens Hash => expiry map (by reference).
	 * @return void
	 */
	private static function prune( array &$tokens ): void {
		$now = time();
		foreach ( $tokens as $hash => $expiry ) {
			if ( $expiry < $now ) {
				unset( $tokens[ $hash ] );
			}
		}
	}

	/**
	 * Hash a raw token with the site's salts.
	 *
	 * @param string $raw Raw token.
	 * @return string
	 */
	private static function hash( string $raw ): string {
		return wp_hash( $raw );
	}
}
