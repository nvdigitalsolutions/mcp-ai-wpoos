<?php
/**
 * Enhanced credential encryption for OAuth and API tokens.
 *
 * Provides improved encryption mechanisms for storing sensitive credentials
 * with support for key rotation and secure key derivation.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enhanced encryption for OAuth credentials and API tokens.
 */
class WP_MCP_AI_Credential_Encryption {

	/**
	 * Encryption method to use.
	 */
	const CIPHER_METHOD = 'aes-256-gcm';

	/**
	 * Optional encrypt override for testing scenarios.
	 *
	 * @var callable|null
	 */
	protected static $encrypt_override = null;

	/**
	 * Key derivation iterations (PBKDF2).
	 */
	const PBKDF2_ITERATIONS = 10000;

	/**
	 * Salt length for key derivation.
	 */
	const SALT_LENGTH = 32;

	/**
	 * Option key for encryption master key.
	 */
	const MASTER_KEY_OPTION = 'wp_mcp_ai_encryption_master_key';

	/**
	 * Option key for key rotation tracking.
	 */
	const KEY_ROTATION_OPTION = 'wp_mcp_ai_key_rotation';

	/**
	 * Check if encryption is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'openssl_encrypt' ) &&
			function_exists( 'openssl_decrypt' ) &&
			in_array( self::CIPHER_METHOD, openssl_get_cipher_methods(), true );
	}

	/**
	 * Get or create the master encryption key.
	 *
	 * @return string|false Master key or false on failure.
	 */
	protected static function get_master_key() {
		$master_key = get_option( self::MASTER_KEY_OPTION );

		if ( empty( $master_key ) ) {
			// Generate new master key.
			$master_key = self::generate_key();
			
			if ( false === $master_key ) {
				return false;
			}

			update_option( self::MASTER_KEY_OPTION, $master_key, false );

			// Initialize rotation tracking.
			self::track_key_creation();
		}

		return $master_key;
	}

	/**
	 * Generate a cryptographically secure key.
	 *
	 * @param int $length Key length in bytes. Default 32 (256 bits).
	 * @return string|false Generated key or false on failure.
	 */
	public static function generate_key( $length = 32 ) {
		if ( ! function_exists( 'random_bytes' ) ) {
			return false;
		}

		try {
			return base64_encode( random_bytes( $length ) );
		} catch ( Exception $e ) {
			WP_MCP_AI_Logger::log_event( 'encryption_error', 'Failed to generate encryption key', array( 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Derive an encryption key from the master key using PBKDF2.
	 *
	 * @param string $salt Salt for key derivation.
	 * @return string|false Derived key or false on failure.
	 */
	protected static function derive_key( $salt ) {
		$master_key = self::get_master_key();
		
		if ( false === $master_key ) {
			return false;
		}

		$derived_key = hash_pbkdf2(
			'sha256',
			$master_key,
			$salt,
			self::PBKDF2_ITERATIONS,
			32,
			true
		);

		return $derived_key;
	}

	/**
	 * Encrypt a credential.
	 *
	 * @param string $plaintext Plaintext credential to encrypt.
	 * @return string|false Encrypted credential (base64) or false on failure.
	 */
	public static function encrypt( $plaintext ) {
		if ( is_callable( self::$encrypt_override ) ) {
			$override_result = call_user_func( self::$encrypt_override, $plaintext );

			if ( null !== $override_result ) {
				return $override_result;
			}
		}

		if ( ! self::is_available() ) {
			// Fallback to base64 encoding if encryption not available.
			return base64_encode( $plaintext );
		}

		try {
			// Generate random salt for key derivation.
			$salt = random_bytes( self::SALT_LENGTH );

			// Derive encryption key.
			$key = self::derive_key( $salt );
			if ( false === $key ) {
				return false;
			}

			// Generate random IV.
			$iv_length = openssl_cipher_iv_length( self::CIPHER_METHOD );
			$iv = random_bytes( $iv_length );

			// Encrypt with GCM mode (provides authentication).
			$tag = '';
			$ciphertext = openssl_encrypt(
				$plaintext,
				self::CIPHER_METHOD,
				$key,
				OPENSSL_RAW_DATA,
				$iv,
				$tag,
				'',
				16 // GCM tag length.
			);

			if ( false === $ciphertext ) {
				return false;
			}

			// Package: version|salt|iv|tag|ciphertext.
			$package = array(
				'v'   => '2', // Version 2 = enhanced encryption.
				's'   => base64_encode( $salt ),
				'iv'  => base64_encode( $iv ),
				't'   => base64_encode( $tag ),
				'ct'  => base64_encode( $ciphertext ),
			);

			return base64_encode( wp_json_encode( $package ) );
		} catch ( Exception $e ) {
			WP_MCP_AI_Logger::log_event( 'encryption_error', 'Failed to encrypt credential', array( 'error' => $e->getMessage() ) );
			return false;
		}
	}



	/**
	 * Register an encrypt override callback for testing scenarios.
	 *
	 * When provided, the callback receives the plaintext. Returning `null`
	 * defers to the default encryption logic. Any other return value will
	 * be treated as the encryption result.
	 *
	 * @param callable|null $callback Optional callback to override encryption.
	 */
	public static function set_encrypt_override_for_testing( $callback = null ) {
		self::$encrypt_override = $callback;
	}

	/**
	 * Decrypt a credential.
	 *
	 * @param string $encrypted Encrypted credential (base64).
	 * @return string|false Decrypted plaintext or false on failure.
	 */
	public static function decrypt( $encrypted ) {
		if ( empty( $encrypted ) ) {
			return false;
		}

		try {
			// Try to decode as JSON package first (version 2).
			$decoded = base64_decode( $encrypted, true );
			if ( false === $decoded ) {
				return false;
			}

			$package = json_decode( $decoded, true );

			if ( is_array( $package ) && isset( $package['v'] ) && '2' === $package['v'] ) {
				// Version 2: Enhanced encryption.
				return self::decrypt_v2( $package );
			} else {
				// Fallback: Assume simple base64 encoding (legacy or no encryption).
				return base64_decode( $encrypted, true );
			}
		} catch ( Exception $e ) {
			WP_MCP_AI_Logger::log_event( 'encryption_error', 'Failed to decrypt credential', array( 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Decrypt version 2 encrypted credential.
	 *
	 * @param array $package Encryption package.
	 * @return string|false Decrypted plaintext or false on failure.
	 */
	protected static function decrypt_v2( $package ) {
		if ( ! self::is_available() ) {
			return false;
		}

		if ( ! isset( $package['s'], $package['iv'], $package['t'], $package['ct'] ) ) {
			return false;
		}

		$salt = base64_decode( $package['s'], true );
		$iv = base64_decode( $package['iv'], true );
		$tag = base64_decode( $package['t'], true );
		$ciphertext = base64_decode( $package['ct'], true );

		if ( false === $salt || false === $iv || false === $tag || false === $ciphertext ) {
			return false;
		}

		// Derive key using same salt.
		$key = self::derive_key( $salt );
		if ( false === $key ) {
			return false;
		}

		// Decrypt with GCM authentication.
		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER_METHOD,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return $plaintext;
	}

	/**
	 * Rotate the master encryption key and re-encrypt all credentials.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function rotate_master_key() {
		// Generate new master key.
		$new_master_key = self::generate_key();
		if ( false === $new_master_key ) {
			return new WP_Error( 'key_generation_failed', __( 'Failed to generate new encryption key.', 'wp-mcp-ai' ) );
		}

		// Get old master key.
		$old_master_key = get_option( self::MASTER_KEY_OPTION );

		// Find all encrypted credentials.
		$credentials = self::find_all_credentials();

		// Ensure the old key is active before attempting decryption.
		update_option( self::MASTER_KEY_OPTION, $old_master_key, false );

		$processed_credentials = array();
		$errors                 = array();

		foreach ( $credentials as $cred ) {
			// Always use the original master key when decrypting stored credentials.
			update_option( self::MASTER_KEY_OPTION, $old_master_key, false );
			$plaintext = self::decrypt( $cred['value'] );

			if ( false === $plaintext ) {
				$errors[] = array_merge(
					$cred,
					array(
						'error' => 'decrypt_failed',
					)
				);

				break;
			}

			// Switch to the new key for encryption attempts.
			update_option( self::MASTER_KEY_OPTION, $new_master_key, false );
			$encrypted = self::encrypt( $plaintext );

			if ( false === $encrypted ) {
				$errors[] = array_merge(
					$cred,
					array(
						'error' => 'encrypt_failed',
					)
				);

				break;
			}

			$processed_credentials[] = array_merge(
				$cred,
				array(
					'old_value' => $cred['value'],
					'new_value' => $encrypted,
				)
			);

			// Update stored credential with the new ciphertext immediately after confirmation.
			if ( 'option' === $cred['type'] ) {
				update_option( $cred['key'], $encrypted );
			} elseif ( 'postmeta' === $cred['type'] ) {
				update_post_meta( $cred['post_id'], $cred['key'], $encrypted );
			}

			// Restore the old key to prepare for the next credential decryption.
			update_option( self::MASTER_KEY_OPTION, $old_master_key, false );
		}

		if ( ! empty( $errors ) ) {
			// Revert any credentials that were already updated to the new ciphertext.
			foreach ( $processed_credentials as $processed ) {
				if ( 'option' === $processed['type'] ) {
					update_option( $processed['key'], $processed['old_value'] );
				} elseif ( 'postmeta' === $processed['type'] ) {
					update_post_meta( $processed['post_id'], $processed['key'], $processed['old_value'] );
				}
			}

			// Restore the original master key.
			update_option( self::MASTER_KEY_OPTION, $old_master_key, false );

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'encryption_error',
					'Master key rotation failed during credential processing',
					array( 'errors' => $errors )
				);
			}

			return new WP_Error(
				're_encryption_failed',
				__( 'Failed to re-encrypt some credentials.', 'wp-mcp-ai' ),
				array( 'failed' => $errors )
			);
		}

		// Activate the new master key for all future operations.
		update_option( self::MASTER_KEY_OPTION, $new_master_key, false );

		// Track rotation.
		self::track_key_rotation();

		// Log security event.
		if ( class_exists( 'WP_MCP_AI_SIEM_Logger' ) ) {
			WP_MCP_AI_SIEM_Logger::log_security_event(
				WP_MCP_AI_SIEM_Logger::EVENT_API_KEY_ROTATED,
				'Master encryption key rotated successfully',
				array( 'credentials_count' => count( $credentials ) ),
				WP_MCP_AI_SIEM_Logger::SEVERITY_NOTICE
			);
		}

		return true;
	}


	/**
	 * Find all encrypted credentials in the database.
	 *
	 * @return array Array of credential locations.
	 */
	protected static function find_all_credentials() {
		global $wpdb;

		$credentials = array();

		// Find in options table.
		$option_keys = array(
			'wp_mcp_ai_openai_api_key',
			'wp_mcp_ai_gemini_api_key',
			'wp_mcp_ai_auth0_client_secret',
			'wp_mcp_ai_siem_endpoint_token',
		);

		foreach ( $option_keys as $key ) {
			$value = get_option( $key );
			if ( ! empty( $value ) ) {
				$credentials[] = array(
					'type'  => 'option',
					'key'   => $key,
					'value' => $value,
				);
			}
		}

		// Find in post meta (assistant credentials).
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( '_wp_mcp_ai_credential_' ) . '%'
			),
			ARRAY_A
		);

		foreach ( $results as $row ) {
			$credentials[] = array(
				'type'    => 'postmeta',
				'post_id' => $row['post_id'],
				'key'     => $row['meta_key'],
				'value'   => $row['meta_value'],
			);
		}

		return $credentials;
	}

	/**
	 * Track key creation.
	 */
	protected static function track_key_creation() {
		$rotation_data = array(
			'created_at'      => current_time( 'mysql', true ),
			'rotated_at'      => null,
			'rotation_count'  => 0,
			'next_rotation'   => self::calculate_next_rotation(),
		);

		update_option( self::KEY_ROTATION_OPTION, $rotation_data, false );
	}

	/**
	 * Track key rotation.
	 */
	protected static function track_key_rotation() {
		$rotation_data = get_option( self::KEY_ROTATION_OPTION, array() );

		$rotation_data['rotated_at'] = current_time( 'mysql', true );
		$rotation_data['rotation_count'] = isset( $rotation_data['rotation_count'] ) ? $rotation_data['rotation_count'] + 1 : 1;
		$rotation_data['next_rotation'] = self::calculate_next_rotation();

		update_option( self::KEY_ROTATION_OPTION, $rotation_data, false );
	}

	/**
	 * Calculate next recommended rotation date.
	 *
	 * @return string ISO 8601 date.
	 */
	protected static function calculate_next_rotation() {
		/**
		 * Filter the key rotation interval in days.
		 *
		 * @since 1.0.0
		 *
		 * @param int $days Number of days between rotations. Default 90.
		 */
		$interval_days = apply_filters( 'wp_mcp_ai_key_rotation_interval', 90 );

		return gmdate( 'c', time() + ( $interval_days * DAY_IN_SECONDS ) );
	}

	/**
	 * Check if key rotation is due.
	 *
	 * @return bool True if rotation recommended, false otherwise.
	 */
	public static function is_rotation_due() {
		$rotation_data = get_option( self::KEY_ROTATION_OPTION, array() );

		if ( empty( $rotation_data['next_rotation'] ) ) {
			return false;
		}

		$next_rotation = strtotime( $rotation_data['next_rotation'] );
		return time() >= $next_rotation;
	}

	/**
	 * Get days until next rotation.
	 *
	 * @return int Days until rotation (negative if overdue).
	 */
	public static function get_days_until_rotation() {
		$rotation_data = get_option( self::KEY_ROTATION_OPTION, array() );

		if ( empty( $rotation_data['next_rotation'] ) ) {
			return -1;
		}

		$next_rotation = strtotime( $rotation_data['next_rotation'] );
		$days = ceil( ( $next_rotation - time() ) / DAY_IN_SECONDS );

		return (int) $days;
	}

	/**
	 * Get rotation status information.
	 *
	 * @return array Rotation status.
	 */
	public static function get_rotation_status() {
		$rotation_data = get_option( self::KEY_ROTATION_OPTION, array() );

		return array(
			'created_at'      => $rotation_data['created_at'] ?? null,
			'rotated_at'      => $rotation_data['rotated_at'] ?? null,
			'rotation_count'  => $rotation_data['rotation_count'] ?? 0,
			'next_rotation'   => $rotation_data['next_rotation'] ?? null,
			'is_due'          => self::is_rotation_due(),
			'days_remaining'  => self::get_days_until_rotation(),
		);
	}
}
