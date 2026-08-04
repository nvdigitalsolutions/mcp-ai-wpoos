<?php
/**
 * Encryption Helper for WP MCP AI
 *
 * Provides encryption/decryption for sensitive data with master key rotation support.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Encryption' ) ) {
	/**
	 * Handles encryption and decryption of sensitive data with master key support.
	 */
	class WP_MCP_AI_Encryption {
		/**
		 * Option key for storing the master key.
		 */
		const MASTER_KEY_OPTION = 'wp_mcp_ai_master_key';

		/**
		 * Meta key for storing encrypted secrets.
		 */
		const ENCRYPTED_SECRET_META_KEY = 'wp_mcp_ai_encrypted_secret';

		/**
		 * Encryption method for new encryptions — AES-256-GCM (AEAD).
		 *
		 * Provides authenticated encryption so ciphertext cannot be silently
		 * tampered with (no padding-oracle or bit-flipping attacks).
		 */
		const CIPHER_METHOD = 'aes-256-gcm';

		/**
		 * Legacy cipher method retained for decrypting pre-existing data.
		 *
		 * New data is never encrypted with CBC; this constant exists only so the
		 * backward-compatible decrypt path can reference the cipher name clearly.
		 */
		const CIPHER_METHOD_LEGACY = 'aes-256-cbc';

		/**
		 * Prefix prepended to GCM-encrypted payloads so decrypt() can identify the format.
		 */
		const GCM_PREFIX = 'v2:';

		/**
		 * Get or generate the master encryption key.
		 *
		 * Sites may keep the key out of the database by defining the
		 * WP_MCP_AI_MASTER_KEY constant in wp-config.php (base64-encoded
		 * 32-byte key, as produced by WP_MCP_AI_Encryption::generate_key()).
		 * When defined, the constant takes precedence over the stored option.
		 *
		 * @return string The master encryption key.
		 */
		public static function get_master_key() {
			if ( defined( 'WP_MCP_AI_MASTER_KEY' ) && is_string( WP_MCP_AI_MASTER_KEY ) && '' !== WP_MCP_AI_MASTER_KEY ) {
				return WP_MCP_AI_MASTER_KEY;
			}

			$key = get_option( self::MASTER_KEY_OPTION );

			if ( empty( $key ) ) {
				$key = self::generate_key();
				update_option( self::MASTER_KEY_OPTION, $key, false );
			}

			return $key;
		}

		/**
		 * Whether the master key is managed externally via wp-config.php.
		 *
		 * @since 1.1.44
		 *
		 * @return bool
		 */
		public static function is_master_key_externally_managed() {
			return defined( 'WP_MCP_AI_MASTER_KEY' ) && is_string( WP_MCP_AI_MASTER_KEY ) && '' !== WP_MCP_AI_MASTER_KEY;
		}

		/**
		 * Generate a new encryption key.
		 *
		 * @return string A new random encryption key.
		 */
		public static function generate_key() {
			return base64_encode( random_bytes( 32 ) );
		}

		/**
		 * Encrypt data using AES-256-GCM (AEAD).
		 *
		 * The returned string is prefixed with "v2:" so decrypt() can identify the
		 * format and apply the correct algorithm. The binary layout inside the
		 * base64 payload is: nonce[12] . ciphertext[N] . auth_tag[16].
		 *
		 * @param string      $data The data to encrypt.
		 * @param string|null $key  Optional. The encryption key to use. Defaults to master key.
		 * @return string|false Encrypted data (prefixed with "v2:") or false on failure.
		 */
		public static function encrypt( $data, $key = null ) {
			if ( empty( $data ) ) {
				return false;
			}

			if ( null === $key ) {
				$key = self::get_master_key();
			}

			$key = base64_decode( $key );
			if ( false === $key || strlen( $key ) !== 32 ) {
				return false;
			}

			// GCM uses a 12-byte nonce (96 bits — the recommended length for GCM).
			$nonce      = random_bytes( 12 );
			$tag        = '';
			$ciphertext = openssl_encrypt(
				$data,
				self::CIPHER_METHOD,
				$key,
				OPENSSL_RAW_DATA,
				$nonce,
				$tag,
				'',   // AAD (additional authenticated data) — not required here.
				16    // 128-bit authentication tag.
			);

			if ( false === $ciphertext ) {
				return false;
			}

			// Layout: nonce[12] . ciphertext[N] . tag[16].
			return self::GCM_PREFIX . base64_encode( $nonce . $ciphertext . $tag );
		}

		/**
		 * Decrypt data using the stored master key.
		 *
		 * Supports two formats transparently:
		 *  - "v2:<base64>" — AES-256-GCM with authentication tag verification (new format).
		 *  - "<base64>"    — AES-256-CBC legacy format (existing stored values).
		 *
		 * @param string      $encrypted The encrypted data.
		 * @param string|null $key       Optional. The encryption key to use. Defaults to master key.
		 * @return string|false Decrypted data or false on failure / authentication error.
		 */
		public static function decrypt( $encrypted, $key = null ) {
			if ( empty( $encrypted ) ) {
				return false;
			}

			if ( null === $key ) {
				$key = self::get_master_key();
			}

			$key_raw = base64_decode( $key );
			if ( false === $key_raw || strlen( $key_raw ) !== 32 ) {
				return false;
			}

			// New format: "v2:<base64(nonce[12] . ciphertext . tag[16])>".
			if ( 0 === strpos( $encrypted, self::GCM_PREFIX ) ) {
				$payload = base64_decode( substr( $encrypted, strlen( self::GCM_PREFIX ) ) );
				// Minimum: 12-byte nonce + 0-byte plaintext + 16-byte tag = 28 bytes.
				if ( false === $payload || strlen( $payload ) < 28 ) {
					return false;
				}

				$nonce      = substr( $payload, 0, 12 );
				$tag        = substr( $payload, -16 );
				$ciphertext = substr( $payload, 12, strlen( $payload ) - 28 );

				$decrypted = openssl_decrypt(
					$ciphertext,
					self::CIPHER_METHOD,
					$key_raw,
					OPENSSL_RAW_DATA,
					$nonce,
					$tag
				);

				// openssl_decrypt returns false when the GCM tag check fails.
				return $decrypted;
			}

			// Legacy format: base64(iv[16] . ciphertext) — AES-256-CBC, no authentication.
			$data = base64_decode( $encrypted );
			if ( false === $data || strlen( $data ) < 17 ) {
				return false;
			}

			$iv             = substr( $data, 0, 16 );
			$ciphertext_cbc = substr( $data, 16 );

			return openssl_decrypt(
				$ciphertext_cbc,
				self::CIPHER_METHOD_LEGACY,
				$key_raw,
				OPENSSL_RAW_DATA,
				$iv
			);
		}

		/**
		 * Log an encryption event safely.
		 *
		 * @param string $type    Event type.
		 * @param string $message Event message.
		 * @param array  $context Event context.
		 */
		private static function log_event( $type, $message, $context = array() ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event( $type, $message, $context );
			}
		}

		/**
		 * Rotate the master encryption key and re-encrypt all secrets.
		 *
		 * This function handles the rotation of the master encryption key, re-encrypting
		 * all stored secrets with the new key. If any re-encryption fails, it rolls back
		 * all changes to maintain data integrity.
		 *
		 * @return bool|WP_Error True on success, WP_Error on failure.
		 */
		public static function rotate_master_key() {
			global $wpdb;

			// When the key is managed via wp-config.php the operator must rotate
			// it manually (generate a new key, decrypt/re-encrypt secrets offline
			// or temporarily define the old key). Rotating here would desync the
			// config-defined key from the stored ciphertexts.
			if ( self::is_master_key_externally_managed() ) {
				return new WP_Error(
					'wp_mcp_ai_master_key_externally_managed',
					__( 'The master encryption key is defined in wp-config.php (WP_MCP_AI_MASTER_KEY) and cannot be rotated by the plugin. Rotate the constant manually and re-encrypt stored secrets.', 'mcp-ai-wpoos' )
				);
			}

			// Get current master key.
			$old_key = self::get_master_key();

			// Generate new master key.
			$new_key = self::generate_key();

			// Get all posts with encrypted meta data.
			$query = $wpdb->prepare(
				"SELECT post_id, meta_id, meta_value
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s",
				self::ENCRYPTED_SECRET_META_KEY
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type. Query string built dynamically from sanitized/validated components; $wpdb->prepare() applied for all value placeholders.
			$results = $wpdb->get_results( $query );

			if ( empty( $results ) ) {
				// No secrets to rotate, just update the key.
				update_option( self::MASTER_KEY_OPTION, $new_key, false );

				self::log_event(
					'master_key_rotated',
					'Master encryption key rotated successfully (no secrets to re-encrypt)',
					array( 'secret_count' => 0 )
				);

				return true;
			}

			// Store original values for rollback.
			$original_values = array();
			$re_encrypted    = array();

			// Phase 1: Decrypt with old key and re-encrypt with new key.
			foreach ( $results as $row ) {
				$encrypted = $row->meta_value;

				// Store original for rollback.
				$original_values[ $row->meta_id ] = $encrypted;

				// Decrypt with old key.
				$decrypted = self::decrypt( $encrypted, $old_key );

				// Check if decrypt failed.
				if ( false === $decrypted ) {
					// Decrypt failed - trigger rollback.
					self::rollback_rotation( $original_values, $re_encrypted, $old_key );

					return new WP_Error(
						'wp_mcp_ai_decrypt_failed',
						sprintf(
							/* translators: %d: meta ID */
							__( 'Failed to decrypt secret with ID %d during key rotation. Rotation aborted and rolled back.', 'mcp-ai-wpoos' ),
							$row->meta_id
						),
						array(
							'meta_id' => $row->meta_id,
							'post_id' => $row->post_id,
						)
					);
				}

				// Re-encrypt with new key.
				$new_encrypted = self::encrypt( $decrypted, $new_key );

				if ( false === $new_encrypted ) {
					// Re-encryption failed - trigger rollback.
					self::rollback_rotation( $original_values, $re_encrypted, $old_key );

					return new WP_Error(
						'wp_mcp_ai_encrypt_failed',
						sprintf(
							/* translators: %d: meta ID */
							__( 'Failed to re-encrypt secret with ID %d during key rotation. Rotation aborted and rolled back.', 'mcp-ai-wpoos' ),
							$row->meta_id
						),
						array(
							'meta_id' => $row->meta_id,
							'post_id' => $row->post_id,
						)
					);
				}

				$re_encrypted[ $row->meta_id ] = $new_encrypted;
			}

			// Phase 2: Update all secrets with new encrypted values.
			foreach ( $re_encrypted as $meta_id => $new_encrypted ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type.
				$updated = $wpdb->update(
					$wpdb->postmeta,
					array( 'meta_value' => $new_encrypted ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- meta_value lookup required to find users by encrypted plugin token; no alternative lookup method available.
					array( 'meta_id' => $meta_id ),
					array( '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					// Database update failed - trigger rollback.
					self::rollback_rotation( $original_values, $re_encrypted, $old_key );

					return new WP_Error(
						'wp_mcp_ai_db_update_failed',
						sprintf(
							/* translators: %d: meta ID */
							__( 'Failed to update secret with ID %d in database during key rotation. Rotation aborted and rolled back.', 'mcp-ai-wpoos' ),
							$meta_id
						),
						array( 'meta_id' => $meta_id )
					);
				}
			}

			// Phase 3: Only update master key after all re-encryptions succeed.
			update_option( self::MASTER_KEY_OPTION, $new_key, false );

			// Clear any caches.
			wp_cache_flush();

			self::log_event(
				'master_key_rotated',
				'Master encryption key rotated successfully',
				array(
					'secret_count' => count( $re_encrypted ),
					'rotated_at'   => current_time( 'mysql', true ),
					'rotated_by'   => get_current_user_id(),
				)
			);

			return true;
		}

		/**
		 * Rollback a failed key rotation.
		 *
		 * @param array  $original_values Original encrypted values before rotation.
		 * @param array  $re_encrypted    Re-encrypted values that were updated.
		 * @param string $old_key         The old master key.
		 */
		private static function rollback_rotation( $original_values, $re_encrypted, $old_key ) {
			global $wpdb;

			$rollback_failures = array();

			// Restore all updated secrets to their original values.
			foreach ( $re_encrypted as $meta_id => $new_encrypted ) {
				if ( isset( $original_values[ $meta_id ] ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type.
					$result = $wpdb->update(
						$wpdb->postmeta,
						array( 'meta_value' => $original_values[ $meta_id ] ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- meta_value lookup required to find users by encrypted plugin token; no alternative lookup method available.
						array( 'meta_id' => $meta_id ),
						array( '%s' ),
						array( '%d' )
					);

					// Track rollback failures.
					if ( false === $result ) {
						$rollback_failures[] = $meta_id;
					}
				}
			}

			// Ensure master key is set back to old key.
			update_option( self::MASTER_KEY_OPTION, $old_key, false );

			// Clear caches.
			wp_cache_flush();

			// Log rollback with failure information if any.
			$log_data = array(
				'rolled_back_count' => count( $re_encrypted ),
				'rollback_at'       => current_time( 'mysql', true ),
			);

			if ( ! empty( $rollback_failures ) ) {
				$log_data['rollback_failures'] = $rollback_failures;
				$log_data['failure_count']     = count( $rollback_failures );

				self::log_event(
					'master_key_rotation_rollback',
					'Master key rotation failed and was partially rolled back with errors',
					$log_data
				);
			} else {
				self::log_event(
					'master_key_rotation_rollback',
					'Master key rotation failed and was rolled back successfully',
					$log_data
				);
			}
		}

		/**
		 * Check if a value is encrypted.
		 *
		 * @param string $value The value to check.
		 * @return bool True if encrypted, false otherwise.
		 */
		public static function is_encrypted( $value ) {
			if ( ! is_string( $value ) || empty( $value ) ) {
				return false;
			}

			// GCM-encrypted values are prefixed with "v2:"; strip it before base64-decoding.
			$check = $value;
			if ( 0 === strpos( $check, self::GCM_PREFIX ) ) {
				$check = substr( $check, strlen( self::GCM_PREFIX ) );
			}

			// Check if it's valid base64 and has minimum length.
			$decoded = base64_decode( $check, true );
			return false !== $decoded && strlen( $decoded ) >= 17;
		}
	}
}
