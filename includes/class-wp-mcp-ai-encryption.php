<?php
/**
 * Encryption Helper for WP MCP AI
 *
 * Provides encryption/decryption for sensitive data with master key rotation support.
 *
 * @package WP_MCP_AI
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
		 * Encryption method.
		 */
		const CIPHER_METHOD = 'aes-256-cbc';

		/**
		 * Get or generate the master encryption key.
		 *
		 * @return string The master encryption key.
		 */
		public static function get_master_key() {
			$key = get_option( self::MASTER_KEY_OPTION );

			if ( empty( $key ) ) {
				$key = self::generate_key();
				update_option( self::MASTER_KEY_OPTION, $key, false );
			}

			return $key;
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
		 * Encrypt data using the master key.
		 *
		 * @param string      $data The data to encrypt.
		 * @param string|null $key  Optional. The encryption key to use. Defaults to master key.
		 * @return string|false Encrypted data or false on failure.
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

			$iv = random_bytes( 16 );

			$encrypted = openssl_encrypt(
				$data,
				self::CIPHER_METHOD,
				$key,
				OPENSSL_RAW_DATA,
				$iv
			);

			if ( false === $encrypted ) {
				return false;
			}

			// Prepend IV to encrypted data.
			return base64_encode( $iv . $encrypted );
		}

		/**
		 * Decrypt data using the master key.
		 *
		 * @param string      $encrypted The encrypted data.
		 * @param string|null $key       Optional. The encryption key to use. Defaults to master key.
		 * @return string|false Decrypted data or false on failure.
		 */
		public static function decrypt( $encrypted, $key = null ) {
			if ( empty( $encrypted ) ) {
				return false;
			}

			if ( null === $key ) {
				$key = self::get_master_key();
			}

			$key = base64_decode( $key );
			if ( false === $key || strlen( $key ) !== 32 ) {
				return false;
			}

			$data = base64_decode( $encrypted );
			if ( false === $data || strlen( $data ) < 17 ) {
				return false;
			}

			// Extract IV and encrypted data.
			$iv        = substr( $data, 0, 16 );
			$encrypted = substr( $data, 16 );

			$decrypted = openssl_decrypt(
				$encrypted,
				self::CIPHER_METHOD,
				$key,
				OPENSSL_RAW_DATA,
				$iv
			);

			return $decrypted;
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
							__( 'Failed to decrypt secret with ID %d during key rotation. Rotation aborted and rolled back.', 'wp-mcp-ai' ),
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
							__( 'Failed to re-encrypt secret with ID %d during key rotation. Rotation aborted and rolled back.', 'wp-mcp-ai' ),
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
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->update(
					$wpdb->postmeta,
					array( 'meta_value' => $new_encrypted ),
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
							__( 'Failed to update secret with ID %d in database during key rotation. Rotation aborted and rolled back.', 'wp-mcp-ai' ),
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
					'secret_count'   => count( $re_encrypted ),
					'rotated_at'     => current_time( 'mysql', true ),
					'rotated_by'     => get_current_user_id(),
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
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$result = $wpdb->update(
						$wpdb->postmeta,
						array( 'meta_value' => $original_values[ $meta_id ] ),
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

			// Check if it's valid base64 and has minimum length.
			$decoded = base64_decode( $value, true );
			return false !== $decoded && strlen( $decoded ) >= 17;
		}
	}
}
