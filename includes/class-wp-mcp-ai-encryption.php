<?php
/**
 * At-Rest Encryption Helper for WP oOS
 *
 * Provides plugin-level encryption for sensitive data stored in the database.
 * Uses WordPress authentication salts as encryption keys.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Encryption' ) ) {
	/**
	 * Encryption helper class for at-rest data protection.
	 */
	class WP_MCP_AI_Encryption {

		/**
		 * Encryption method.
		 */
		const METHOD = 'aes-256-cbc';

		/**
		 * Check if encryption is available.
		 *
		 * @return bool
		 */
		public static function is_available() {
			return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
		}

		/**
		 * Get encryption key from WordPress salts.
		 *
		 * @return string
		 */
		private static function get_key() {
			// Use WordPress authentication salts as key material.
			$key_material = AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY . NONCE_KEY;

			// Derive a key using hash.
			return hash( 'sha256', $key_material, true );
		}

		/**
		 * Encrypt data.
		 *
		 * @param string $data Data to encrypt.
		 * @return string|WP_Error Encrypted data or error.
		 */
		public static function encrypt( $data ) {
			if ( ! self::is_available() ) {
				return new WP_Error(
					'wp_mcp_ai_encryption_unavailable',
					__( 'OpenSSL encryption is not available on this server.', 'wp-mcp-ai' )
				);
			}

			if ( empty( $data ) ) {
				return '';
			}

			$key = self::get_key();
			$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::METHOD ) );

			$encrypted = openssl_encrypt( $data, self::METHOD, $key, 0, $iv );

			if ( false === $encrypted ) {
				return new WP_Error(
					'wp_mcp_ai_encryption_failed',
					__( 'Failed to encrypt data.', 'wp-mcp-ai' )
				);
			}

			// Combine IV and encrypted data, then base64 encode.
			$result = base64_encode( $iv . $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

			return $result;
		}

		/**
		 * Decrypt data.
		 *
		 * @param string $encrypted_data Encrypted data.
		 * @return string|WP_Error Decrypted data or error.
		 */
		public static function decrypt( $encrypted_data ) {
			if ( ! self::is_available() ) {
				return new WP_Error(
					'wp_mcp_ai_encryption_unavailable',
					__( 'OpenSSL encryption is not available on this server.', 'wp-mcp-ai' )
				);
			}

			if ( empty( $encrypted_data ) ) {
				return '';
			}

			$key = self::get_key();

			// Decode base64.
			$decoded = base64_decode( $encrypted_data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

			if ( false === $decoded ) {
				return new WP_Error(
					'wp_mcp_ai_decryption_failed',
					__( 'Invalid encrypted data format.', 'wp-mcp-ai' )
				);
			}

			// Extract IV and encrypted data.
			$iv_length = openssl_cipher_iv_length( self::METHOD );
			$iv        = substr( $decoded, 0, $iv_length );
			$encrypted = substr( $decoded, $iv_length );

			$decrypted = openssl_decrypt( $encrypted, self::METHOD, $key, 0, $iv );

			if ( false === $decrypted ) {
				return new WP_Error(
					'wp_mcp_ai_decryption_failed',
					__( 'Failed to decrypt data.', 'wp-mcp-ai' )
				);
			}

			return $decrypted;
		}

		/**
		 * Encrypt API key for storage.
		 *
		 * @param string $api_key API key to encrypt.
		 * @return string|WP_Error Encrypted API key or error.
		 */
		public static function encrypt_api_key( $api_key ) {
			if ( empty( $api_key ) ) {
				return '';
			}

			return self::encrypt( $api_key );
		}

		/**
		 * Decrypt API key from storage.
		 *
		 * @param string $encrypted_key Encrypted API key.
		 * @return string|WP_Error Decrypted API key or error.
		 */
		public static function decrypt_api_key( $encrypted_key ) {
			if ( empty( $encrypted_key ) ) {
				return '';
			}

			return self::decrypt( $encrypted_key );
		}

		/**
		 * Encrypt OAuth token for storage.
		 *
		 * @param string $token OAuth token to encrypt.
		 * @return string|WP_Error Encrypted token or error.
		 */
		public static function encrypt_token( $token ) {
			if ( empty( $token ) ) {
				return '';
			}

			return self::encrypt( $token );
		}

		/**
		 * Decrypt OAuth token from storage.
		 *
		 * @param string $encrypted_token Encrypted token.
		 * @return string|WP_Error Decrypted token or error.
		 */
		public static function decrypt_token( $encrypted_token ) {
			if ( empty( $encrypted_token ) ) {
				return '';
			}

			return self::decrypt( $encrypted_token );
		}

		/**
		 * Migrate plaintext data to encrypted format.
		 *
		 * @param string $option_name Option name containing plaintext data.
		 * @param string $key         Array key within option (optional).
		 * @return bool True on success, false on failure.
		 */
		public static function migrate_to_encrypted( $option_name, $key = null ) {
			if ( ! self::is_available() ) {
				return false;
			}

			$option_value = get_option( $option_name );

			if ( false === $option_value ) {
				return false;
			}

			// Handle nested array.
			if ( $key ) {
				if ( ! isset( $option_value[ $key ] ) ) {
					return false;
				}

				$plaintext = $option_value[ $key ];

				// Skip if already encrypted (check for base64 pattern).
				if ( self::is_encrypted( $plaintext ) ) {
					return true;
				}

				$encrypted = self::encrypt( $plaintext );

				if ( is_wp_error( $encrypted ) ) {
					return false;
				}

				$option_value[ $key ] = $encrypted;
			} else {
				// Handle simple value.
				$plaintext = $option_value;

				// Skip if already encrypted.
				if ( self::is_encrypted( $plaintext ) ) {
					return true;
				}

				$encrypted = self::encrypt( $plaintext );

				if ( is_wp_error( $encrypted ) ) {
					return false;
				}

				$option_value = $encrypted;
			}

			return update_option( $option_name, $option_value );
		}

		/**
		 * Check if data appears to be encrypted.
		 *
		 * @param string $data Data to check.
		 * @return bool
		 */
		public static function is_encrypted( $data ) {
			if ( empty( $data ) || ! is_string( $data ) ) {
				return false;
			}

			// Check if it's base64 encoded.
			if ( base64_encode( base64_decode( $data, true ) ) !== $data ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				return false;
			}

			// Try to decrypt to verify.
			$decoded = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

			if ( false === $decoded ) {
				return false;
			}

			$iv_length = openssl_cipher_iv_length( self::METHOD );

			// Check if length is at least IV length.
			return strlen( $decoded ) > $iv_length;
		}

		/**
		 * Securely hash sensitive data for comparison.
		 *
		 * Use for storing hashed versions of secrets that only need comparison.
		 *
		 * @param string $data Data to hash.
		 * @return string Hashed data.
		 */
		public static function hash( $data ) {
			return hash_hmac( 'sha256', $data, self::get_key() );
		}

		/**
		 * Verify hashed data.
		 *
		 * @param string $data   Original data.
		 * @param string $hash   Hash to verify against.
		 * @return bool True if hash matches.
		 */
		public static function verify_hash( $data, $hash ) {
			$computed_hash = self::hash( $data );
			return hash_equals( $computed_hash, $hash );
		}
	}
}
