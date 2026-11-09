<?php
/**
 * Signed, short-lived preview tokens — Proposal 135 Phase 5.
 *
 * The problem the token solves: the forged frontend runs on the PUBLIC origin
 * while WordPress lives on `cms.` — they share no cookie. An editor who clicks
 * "Preview" in `/manage` must be able to read a DRAFT from the CMS without a
 * WordPress session on the public host.
 *
 * Design (deliberately no shared secret on the frontend):
 *
 * 1. The bridge mints a token: `base64url("<post_id>.<exp>.<hmac>")`.
 * 2. The `/manage` UI hands the editor `<public-origin>/api/preview?token=…`.
 * 3. The forged app stores the token in a HOST-ONLY cookie and redirects to
 *    the page — it never verifies the token, it only transports it.
 * 4. The draft-aware route sends it back as `?preview_token=…`; the BRIDGE
 *    verifies the HMAC and serves exactly ONE non-published post.
 *
 * So the verification key never leaves WordPress, the frontend cannot mint
 * tokens, and a token is worth exactly one document for at most
 * Schema::PREVIEW_TTL seconds.
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

use NvoosAstroBridge\Schema;

/**
 * Mint and verify preview tokens.
 */
final class PreviewToken {

	/**
	 * Domain-separation label mixed into the HMAC.
	 *
	 * `wp_salt( 'auth' )` is also the key material for auth cookies; prefixing
	 * the MAC input means a preview token can never be replayed as, or
	 * confused with, an authentication artefact.
	 */
	private const LABEL = 'nvoos-bridge-preview-v1';

	/**
	 * Mint a token for one post.
	 *
	 * @param int      $post_id Post ID the token unlocks.
	 * @param int|null $ttl     Lifetime in seconds (null = Schema::PREVIEW_TTL).
	 * @return array{token: string, expiresAt: string, expires: int}
	 */
	public static function issue( int $post_id, ?int $ttl = null ): array {
		$lifetime = null === $ttl ? Schema::PREVIEW_TTL : max( 1, $ttl );
		$expires  = time() + $lifetime;
		$payload  = $post_id . '.' . $expires;
		$token    = self::payload( $payload ) . '.' . self::mac( $payload );

		return array(
			'token'     => $token,
			'expiresAt' => gmdate( 'c', $expires ),
			'expires'   => $expires,
		);
	}

	/**
	 * Verify a token.
	 *
	 * @param mixed $token Raw `preview_token` request value.
	 * @return int The unlocked post ID, or 0 when the token is absent,
	 *             malformed, tampered with, or expired.
	 */
	public static function verify( $token ): int {
		if ( ! is_string( $token ) || '' === $token ) {
			return 0;
		}

		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return 0;
		}

		list( $encoded_payload, $mac ) = array( $parts[0], $parts[1] );

		$payload = self::decode( $encoded_payload );
		if ( null === $payload ) {
			return 0;
		}

		// Constant-time comparison — a token is an authorization decision.
		if ( ! hash_equals( self::mac( $payload ), $mac ) ) {
			return 0;
		}

		$claim = explode( '.', $payload );
		if ( 2 !== count( $claim ) ) {
			return 0;
		}

		$post_id = (int) $claim[0];
		$expires = (int) $claim[1];

		if ( $post_id < 1 || $expires < time() ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * The HMAC over a payload.
	 *
	 * @param string $payload `<post_id>.<exp>`.
	 * @return string Hex digest.
	 */
	private static function mac( string $payload ): string {
		return hash_hmac( 'sha256', self::LABEL . '.' . $payload, (string) wp_salt( 'auth' ) );
	}

	/**
	 * URL-safe base64 of a payload (no padding).
	 *
	 * @param string $payload Raw payload.
	 * @return string
	 */
	private static function payload( string $payload ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Wire encoding, not obfuscation.
		return rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
	}

	/**
	 * Strict URL-safe base64 decode. Returns null on any anomaly.
	 *
	 * @param string $encoded Encoded payload.
	 * @return string|null
	 */
	private static function decode( string $encoded ): ?string {
		if ( '' === $encoded || preg_match( '/[^A-Za-z0-9_-]/', $encoded ) ) {
			return null;
		}

		$padded = strtr( $encoded, '-_', '+/' );
		$pad    = strlen( $padded ) % 4;
		if ( 0 !== $pad ) {
			$padded .= str_repeat( '=', 4 - $pad );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Wire decoding, not obfuscation.
		$decoded = base64_decode( $padded, true );

		return is_string( $decoded ) ? $decoded : null;
	}

	/** Private — static utility only. */
	private function __construct() {}
}
