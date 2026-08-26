<?php
/**
 * NV oOS Graphify — Encryption Helper
 *
 * Thin AES-256-GCM encryption wrapper for storing sensitive remote source
 * credentials (tokens, passwords) in the database.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin encryption helper using AES-256-GCM when OpenSSL is available.
 *
 * @since 0.6.0
 */
class NV_oOS_Graphify_Crypto {

	/**
	 * Cipher algorithm used for encryption.
	 *
	 * @var string
	 */
	const CIPHER = 'aes-256-gcm';

	/**
	 * Encrypt a plaintext string.
	 *
	 * Uses AES-256-GCM via openssl_encrypt if available, otherwise falls back
	 * to base64 encoding (for environments without OpenSSL).
	 *
	 * @since 0.6.0
	 *
	 * @param string $plaintext The value to encrypt.
	 * @return string Base64-encoded ciphertext (or base64 of plaintext as fallback).
	 */
	public static function encrypt( $plaintext ) {
		if ( empty( $plaintext ) ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( self::CIPHER, openssl_get_cipher_methods(), true ) ) {
			// Fallback: simple base64 (not secure, but functional).
			return 'b64:' . base64_encode( $plaintext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$key    = self::get_key();
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = openssl_random_pseudo_bytes( $iv_len );
		$tag    = '';

		$encrypted = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		if ( false === $encrypted ) {
			// Fallback on failure.
			return 'b64:' . base64_encode( $plaintext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		// Pack: iv_len(1) + iv + tag(16) + ciphertext.
		$packed = pack( 'C', $iv_len ) . $iv . $tag . $encrypted;
		return 'gcm:' . base64_encode( $packed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a ciphertext string produced by encrypt().
	 *
	 * @since 0.6.0
	 *
	 * @param string $ciphertext Encrypted value from encrypt().
	 * @return string|false Plaintext on success, false on failure.
	 */
	public static function decrypt( $ciphertext ) {
		if ( empty( $ciphertext ) ) {
			return '';
		}

		// Base64 fallback.
		if ( 0 === strpos( $ciphertext, 'b64:' ) ) {
			return base64_decode( substr( $ciphertext, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}

		if ( 0 !== strpos( $ciphertext, 'gcm:' ) ) {
			// Unknown format — return as-is (legacy plaintext stored before encryption was added).
			return $ciphertext;
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		$packed = base64_decode( substr( $ciphertext, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $packed || strlen( $packed ) < 18 ) {
			return false;
		}

		$iv_len    = ord( $packed[0] );
		$iv        = substr( $packed, 1, $iv_len );
		$tag       = substr( $packed, 1 + $iv_len, 16 );
		$encrypted = substr( $packed, 1 + $iv_len + 16 );
		$key       = self::get_key();

		$plaintext = openssl_decrypt( $encrypted, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
		return $plaintext;
	}

	/**
	 * Determine whether a config key is sensitive and should be encrypted.
	 *
	 * @since 0.6.0
	 *
	 * @param string $key Config field key.
	 * @return bool True if the key contains a sensitive pattern.
	 */
	public static function is_sensitive_key( $key ) {
		$key      = strtolower( (string) $key );
		$patterns = array( 'token', 'password', 'secret', 'api_key', 'apikey', 'passwd', 'credential' );
		foreach ( $patterns as $pattern ) {
			if ( strpos( $key, $pattern ) !== false ) {
				return true;
			}
		}
		// Provider-prefixed API keys (openai_key, gemini_key, …) end in `_key`.
		if ( '_key' === substr( $key, -4 ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Derive a 32-byte encryption key from WordPress salts.
	 *
	 * @since 0.6.0
	 *
	 * @return string 32-byte binary key.
	 */
	private static function get_key() {
		$auth_key        = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'nvoos-graphify-auth-key-fallback';
		$secure_auth_key = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'nvoos-graphify-secure-key-fallback';

		// Derive via HKDF-like construction using SHA-256.
		$salt    = 'nvoos-graphify-v1|' . get_option( 'siteurl', '' );
		$ikm     = $auth_key . '|' . $secure_auth_key;
		$derived = hash_hmac( 'sha256', $salt, $ikm, true );
		return $derived; // 32 bytes.
	}
}
