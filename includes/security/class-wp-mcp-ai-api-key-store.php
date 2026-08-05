<?php
/**
 * API Key Store — Transparent encrypted storage for third-party API credentials.
 *
 * Wraps WP_MCP_AI_Encryption to provide a drop-in replacement for plaintext
 * `get_option('wp_mcp_ai_*_api_key')` calls. Detects plaintext values already
 * stored in the database and transparently migrates them to AES-256-GCM.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Api_Key_Store' ) ) {
	/**
	 * Encrypted API key store.
	 *
	 * Usage:
	 *   $key = WP_MCP_AI_Api_Key_Store::get( 'openai_api_key' );
	 *   WP_MCP_AI_Api_Key_Store::set( 'openai_api_key', 'sk-abc123...' );
	 */
	class WP_MCP_AI_Api_Key_Store {

		/**
		 * Option name prefix for plaintext keys recognized by this store.
		 *
		 * Every key managed by this store lives under this option prefix
		 * (e.g. wp_mcp_ai_openai_api_key, wp_mcp_ai_stability_api_key).
		 */
		const OPTION_PREFIX = 'wp_mcp_ai_';

		/**
		 * Known keys that should be encrypted at rest.
		 *
		 * Key   = option suffix (e.g. 'openai_api_key').
		 * Value = human-readable label for admin display and logging.
		 *
		 * @var array<string, string>
		 */
		const MANAGED_KEYS = array(
			'openai_api_key'               => 'OpenAI API Key',
			'stability_api_key'            => 'Stability AI API Key',
			'google_maps_api_key'          => 'Google Maps API Key',
			'removebg_api_key'             => 'remove.bg API Key',
			'yahoo_client_secret'          => 'Yahoo OAuth Client Secret',
			'webhook_secret'               => 'Webhook HMAC Secret',
			'pro_chat_continuation_secret' => 'Chat Continuation Webhook Secret',
		);

		/**
		 * Retrieve a decrypted API key value.
		 *
		 * Transparently migrates plaintext values to encrypted on first read.
		 *
		 * @param string $key_suffix Option suffix (e.g. 'openai_api_key').
		 * @return string Decrypted value, or empty string if not set.
		 */
		public static function get( $key_suffix ) {
			$option_name = self::OPTION_PREFIX . $key_suffix;
			$raw         = get_option( $option_name, '' );

			if ( empty( $raw ) ) {
				return '';
			}

			// Already encrypted — decrypt and return.
			if ( WP_MCP_AI_Encryption::is_encrypted( $raw ) ) {
				$decrypted = WP_MCP_AI_Encryption::decrypt( $raw );
				return false !== $decrypted ? $decrypted : '';
			}

			// Plaintext — migrate to encrypted, then return.
			self::migrate_to_encrypted( $option_name, $raw, $key_suffix );

			return $raw;
		}

		/**
		 * Store an encrypted API key value.
		 *
		 * @param string $key_suffix Option suffix (e.g. 'openai_api_key').
		 * @param string $value      Plaintext value to encrypt and store.
		 * @return bool True on success, false on encryption failure.
		 */
		public static function set( $key_suffix, $value ) {
			$option_name = self::OPTION_PREFIX . $key_suffix;

			if ( empty( $value ) ) {
				delete_option( $option_name );
				return true;
			}

			$encrypted = WP_MCP_AI_Encryption::encrypt( $value );

			if ( false === $encrypted ) {
				self::log( 'encrypt_failed', "Failed to encrypt value for {$key_suffix}" );
				return false;
			}

			update_option( $option_name, $encrypted, false );

			self::log( 'key_stored', "Stored encrypted value for {$key_suffix}" );

			return true;
		}

		/**
		 * Delete a stored API key.
		 *
		 * @param string $key_suffix Option suffix.
		 * @return void
		 */
		public static function delete( $key_suffix ) {
			delete_option( self::OPTION_PREFIX . $key_suffix );
			self::log( 'key_deleted', "Deleted stored key for {$key_suffix}" );
		}

		/**
		 * Get the human-readable label for a managed key.
		 *
		 * @param string $key_suffix Option suffix.
		 * @return string Label, or the suffix itself if unknown.
		 */
		public static function get_label( $key_suffix ) {
			return isset( self::MANAGED_KEYS[ $key_suffix ] )
				? self::MANAGED_KEYS[ $key_suffix ]
				: $key_suffix;
		}

		/**
		 * Get all managed key suffixes.
		 *
		 * @return array<string>
		 */
		public static function get_managed_key_suffixes() {
			return array_keys( self::MANAGED_KEYS );
		}

		/**
		 * Check whether any plaintext keys remain in the database.
		 *
		 * @return array<string> List of option suffixes still storing plaintext.
		 */
		public static function find_remaining_plaintext() {
			$plaintext = array();

			foreach ( self::MANAGED_KEYS as $suffix => $label ) {
				$raw = get_option( self::OPTION_PREFIX . $suffix, '' );
				if ( ! empty( $raw ) && ! WP_MCP_AI_Encryption::is_encrypted( $raw ) ) {
					$plaintext[] = $suffix;
				}
			}

			return $plaintext;
		}

		/**
		 * Migrate all remaining plaintext keys to encrypted format.
		 *
		 * Called on plugin upgrade or via WP-CLI command.
		 *
		 * @return array {
		 *   @type int    $migrated  Number of keys migrated.
		 *   @type array  $failures  Key suffixes that failed migration.
		 * }
		 */
		public static function migrate_all() {
			$migrated = 0;
			$failures = array();

			foreach ( self::MANAGED_KEYS as $suffix => $label ) {
				$option_name = self::OPTION_PREFIX . $suffix;
				$raw         = get_option( $option_name, '' );

				if ( empty( $raw ) || WP_MCP_AI_Encryption::is_encrypted( $raw ) ) {
					continue;
				}

				$encrypted = WP_MCP_AI_Encryption::encrypt( $raw );

				if ( false === $encrypted ) {
					$failures[] = $suffix;
					continue;
				}

				update_option( $option_name, $encrypted, false );
				++$migrated;
			}

			self::log(
				'migration_complete',
				"API key encryption migration: {$migrated} encrypted, " . count( $failures ) . ' failed.',
				array( 'failures' => $failures )
			);

			return array(
				'migrated' => $migrated,
				'failures' => $failures,
			);
		}

		/**
		 * Transparently migrate a single plaintext key to encrypted storage.
		 *
		 * @param string $option_name Full option name.
		 * @param string $plaintext   The plaintext value.
		 * @param string $key_suffix  Suffix for logging.
		 * @return void
		 */
		private static function migrate_to_encrypted( $option_name, $plaintext, $key_suffix ) {
			$encrypted = WP_MCP_AI_Encryption::encrypt( $plaintext );

			if ( false !== $encrypted ) {
				update_option( $option_name, $encrypted, false );
				self::log(
					'key_migrated',
					"Migrated plaintext key to encrypted: {$key_suffix}"
				);
			}
		}

		/**
		 * Log an event via the plugin's logger.
		 *
		 * @param string $type    Event type.
		 * @param string $message Human-readable message.
		 * @param array  $context Additional context (never contains key values).
		 * @return void
		 */
		private static function log( $type, $message, $context = array() ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'api_key_store_' . $type,
					$message,
					$context
				);
			}
		}
	}
}
