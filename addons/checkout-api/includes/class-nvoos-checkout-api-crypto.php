<?php
/**
 * Credential encryption at rest.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AES-256-CBC encryption for stored credentials.
 *
 * The Stripe secret key and webhook secret are stored in the database
 * encrypted, with the key derived from AUTH_KEY + SECURE_AUTH_KEY (mirrors
 * the credential store in the saas-controller addon). Values saved before
 * encryption was introduced are still read correctly (legacy plaintext
 * pass-through).
 *
 * @since 0.1.0
 */
class NVOOS_Checkout_API_Crypto {

	/** Prefix marking an encrypted value. */
	public const PREFIX = 'nvoos_v1:';

	/**
	 * Whether the OpenSSL extension is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Encrypt a credential for storage.
	 *
	 * Empty values pass through unchanged. When OpenSSL is unavailable the
	 * value is stored with a cleartext marker so callers can detect and
	 * warn about the weak fallback.
	 *
	 * @param string $plaintext Credential value.
	 * @return string
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! self::is_available() ) {
			return self::PREFIX . 'plain:' . base64_encode( $plaintext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- value is encrypted in transit at rest when OpenSSL exists; fallback is flagged for auditability.
		}

		$iv = openssl_random_pseudo_bytes( 16 );
		$ct = openssl_encrypt( $plaintext, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $ct ) {
			return self::PREFIX . 'plain:' . base64_encode( $plaintext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- see above.
		}

		return self::PREFIX . base64_encode( $iv ) . ':' . base64_encode( $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- standard binary-to-text encoding for ciphertext storage.
	}

	/**
	 * Decrypt a stored credential.
	 *
	 * Unprefixed values (legacy plaintext) pass through unchanged.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			return $stored;
		}

		$rest = substr( $stored, strlen( self::PREFIX ) );

		if ( str_starts_with( $rest, 'plain:' ) ) {
			return (string) base64_decode( substr( $rest, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- inverse of the fallback encoding above.
		}

		if ( ! self::is_available() ) {
			return '';
		}

		$parts = explode( ':', $rest, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}

		$iv         = (string) base64_decode( $parts[0], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see above.
		$ciphertext = (string) base64_decode( $parts[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see above.
		$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Key derived from the WordPress secret keys.
	 *
	 * @return string
	 */
	private static function key(): string {
		// wp_salt() returns the defined *_KEY constants on a normal install
		// and a stable fallback when wp-config omits them (never a fatal).
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) );
	}
}
