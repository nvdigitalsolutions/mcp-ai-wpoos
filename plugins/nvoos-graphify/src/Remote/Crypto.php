<?php
declare(strict_types=1);

namespace NvoosGraphify\Remote;

/**
 * Thin AES-256-GCM encryption wrapper for storing sensitive remote source
 * credentials (tokens, passwords) in the database.
 *
 * @since 1.0.0
 */
final class Crypto {

	/**
	 * Cipher algorithm used for encryption.
	 *
	 * @var string
	 */
	private const CIPHER = 'aes-256-gcm';

	/**
	 * Whether strong encryption is available (OpenSSL with AES-256-GCM).
	 *
	 * When false, credentials are stored with a weaker fallback —
	 * an admin notice should be displayed prompting the site owner
	 * to enable the OpenSSL PHP extension.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function isAvailable(): bool {
		return \function_exists( 'openssl_encrypt' )
			&& \in_array( self::CIPHER, \openssl_get_cipher_methods(), true );
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * Uses AES-256-GCM via openssl_encrypt if available, otherwise falls back
	 * to base64 encoding (for environments without OpenSSL).
	 *
	 * @param string $plaintext The value to encrypt.
	 * @return string Base64-encoded ciphertext (or base64 of plaintext as fallback).
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! self::isAvailable() ) {
			// Fallback: simple base64 (not secure, but functional).
			// An admin notice is displayed via Plugin::renderAdminNotices()
			// when this fallback path is active.
			return 'b64:' . \base64_encode( $plaintext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$key   = self::getKey();
		$ivLen = \openssl_cipher_iv_length( self::CIPHER );
		$iv    = \openssl_random_pseudo_bytes( $ivLen );
		$tag   = '';

		$encrypted = \openssl_encrypt( $plaintext, self::CIPHER, $key, \OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		if ( false === $encrypted ) {
			return 'b64:' . \base64_encode( $plaintext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		// Pack: iv_len(1) + iv + tag(16) + ciphertext.
		$packed = \pack( 'C', $ivLen ) . $iv . $tag . $encrypted;
		return 'gcm:' . \base64_encode( $packed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a ciphertext string produced by encrypt().
	 *
	 * @param string $ciphertext Encrypted value from encrypt().
	 * @return string Plaintext on success, original value if unencrypted.
	 */
	public static function decrypt( string $ciphertext ): string {
		if ( '' === $ciphertext ) {
			return '';
		}

		// Base64 fallback.
		if ( 0 === \strpos( $ciphertext, 'b64:' ) ) {
			return \base64_decode( \substr( $ciphertext, 4 ), true ) ?: $ciphertext; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}

		if ( 0 !== \strpos( $ciphertext, 'gcm:' ) ) {
			// Unknown format — return as-is (legacy plaintext stored before encryption was added).
			return $ciphertext;
		}

		if ( ! \function_exists( 'openssl_decrypt' ) ) {
			return $ciphertext;
		}

		$packed = \base64_decode( \substr( $ciphertext, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $packed || \strlen( $packed ) < 18 ) {
			return $ciphertext;
		}

		$ivLen     = \ord( $packed[0] );
		$iv        = \substr( $packed, 1, $ivLen );
		$tag       = \substr( $packed, 1 + $ivLen, 16 );
		$encrypted = \substr( $packed, 1 + $ivLen + 16 );
		$key       = self::getKey();

		$plaintext = \openssl_decrypt( $encrypted, self::CIPHER, $key, \OPENSSL_RAW_DATA, $iv, $tag );
		return false !== $plaintext ? $plaintext : $ciphertext;
	}

	/**
	 * Determine whether a config key is sensitive and should be encrypted.
	 *
	 * @param string $key Config field key.
	 * @return bool True if the key contains a sensitive pattern.
	 */
	public static function isSensitiveKey( string $key ): bool {
		$key      = \strtolower( $key );
		$patterns = array( 'token', 'password', 'secret', 'api_key', 'apikey', 'passwd', 'credential' );
		foreach ( $patterns as $pattern ) {
			if ( false !== \strpos( $key, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Derive a 32-byte encryption key from WordPress salts.
	 *
	 * @return string 32-byte binary key.
	 */
	private static function getKey(): string {
		$authKey       = \defined( 'AUTH_KEY' ) ? \AUTH_KEY : 'nvoos-graphify-auth-key-fallback';
		$secureAuthKey = \defined( 'SECURE_AUTH_KEY' ) ? \SECURE_AUTH_KEY : 'nvoos-graphify-secure-key-fallback';

		// Derive via HKDF-like construction using SHA-256.
		$salt = 'nvoos-graphify-v1|' . \get_option( 'siteurl', '' );
		$ikm  = $authKey . '|' . $secureAuthKey;
		return \hash_hmac( 'sha256', $salt, $ikm, true ); // 32 bytes.
	}
}
