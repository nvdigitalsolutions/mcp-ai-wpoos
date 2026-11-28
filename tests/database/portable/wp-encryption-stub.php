<?php
/**
 * Test double for the WordPress master-key cipher.
 *
 * Proposal 167. The write-proposal store seals its structured request with
 * `WP_MCP_AI_Encryption`, which does not exist outside WordPress. The portable
 * and Postgres suites need the seal/open round trip to exercise the proposal
 * lifecycle, so this stub provides the same three-method contract without
 * claiming to be cryptography.
 *
 * Guarded by `class_exists`: under the WordPress suite the real class wins.
 *
 * @package WP_MCP_AI\Tests\Database
 * @since   3.1.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

if ( class_exists( 'WP_MCP_AI_Encryption' ) ) {
	return;
}

/**
 * Reversible, non-cryptographic stand-in.
 */
class WP_MCP_AI_Encryption {

	/**
	 * Marker that `is_encrypted()` recognises.
	 */
	private const PREFIX = 'test-enc:';

	/**
	 * Encrypt (reversibly encode).
	 *
	 * @param string $data Plaintext.
	 * @return string
	 */
	public static function encrypt( $data ) {
		return self::PREFIX . base64_encode( (string) $data );
	}

	/**
	 * Decrypt (decode).
	 *
	 * @param string $value Ciphertext.
	 * @return string|false
	 */
	public static function decrypt( $value ) {
		if ( ! self::is_encrypted( $value ) ) {
			return false;
		}

		return base64_decode( substr( (string) $value, strlen( self::PREFIX ) ), true );
	}

	/**
	 * Whether a value carries the marker.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		return is_string( $value ) && str_starts_with( $value, self::PREFIX );
	}

	/**
	 * Master key stand-in.
	 *
	 * @return string
	 */
	public static function get_master_key() {
		return 'nvoos-database-test-master-key';
	}
}
