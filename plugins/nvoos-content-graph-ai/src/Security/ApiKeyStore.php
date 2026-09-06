<?php
/**
 * API Key Store for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/security/class-wp-mcp-ai-api-key-store.php` (behaviour-
 * preserving; base copy retained permanently — ecosystem port plan
 * D-NOBASE). Option prefix, managed-key map, migration semantics, and
 * the audit log event types keep their base names and behaviour.
 *
 * Decoupling (documented, additive):
 * - Encryption goes through `is_encrypted()` / `encrypt_value()` /
 *   `decrypt_value()` — the base `WP_MCP_AI_Encryption` (AES-256-GCM,
 *   `v2:` prefix) in monolith installs, the parent plugin's
 *   `NvoosContentGraph\Remote\Crypto` (AES-256-GCM, `gcm:`/`b64:`
 *   prefixes) in standalone installs.
 * - Logging forwards to the base `WP_MCP_AI_Logger` in monolith installs
 *   only.
 *
 * Note: this class manages the base plugin's legacy `wp_mcp_ai_*_api_key`
 * option namespace (Stability, Google Maps, remove.bg, Yahoo, webhook
 * secrets). The CG-AI provider API keys are managed by the plugin's own
 * `CredentialStore` — this port exists so standalone installs retain the
 * base tool ecosystem's key handling once those tools port in later waves.
 *
 * @package NvoosContentGraphAi\Security
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypted API key store.
 *
 * Usage:
 *   $key = ApiKeyStore::get( 'openai_api_key' );
 *   ApiKeyStore::set( 'openai_api_key', 'sk-abc123...' );
 *
 * @since 1.1.0
 */
class ApiKeyStore {

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
		if ( static::is_encrypted( $raw ) ) {
			$decrypted = static::decrypt_value( $raw );
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

		$encrypted = static::encrypt_value( $value );

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
			if ( ! empty( $raw ) && ! static::is_encrypted( $raw ) ) {
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
	 *   @type int   $migrated  Number of keys migrated.
	 *   @type array $failures  Key suffixes that failed migration.
	 * }
	 */
	public static function migrate_all() {
		$migrated = 0;
		$failures = array();

		foreach ( self::MANAGED_KEYS as $suffix => $label ) {
			$option_name = self::OPTION_PREFIX . $suffix;
			$raw         = get_option( $option_name, '' );

			if ( empty( $raw ) || static::is_encrypted( $raw ) ) {
				continue;
			}

			$encrypted = static::encrypt_value( $raw );

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
		$encrypted = static::encrypt_value( $plaintext );

		if ( false !== $encrypted ) {
			update_option( $option_name, $encrypted, false );
			self::log(
				'key_migrated',
				"Migrated plaintext key to encrypted: {$key_suffix}"
			);
		}
	}

	/**
	 * Check whether a stored value is encrypted (per-install-mode seam).
	 *
	 * @param mixed $value Stored option value.
	 * @return bool
	 */
	protected static function is_encrypted( $value ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Encryption' ) ) {
			return \WP_MCP_AI_Encryption::is_encrypted( $value );
		}

		// Standalone: the parent plugin's Crypto prefixes ciphertext with
		// `gcm:` (AES-256-GCM) or `b64:` (fallback encoding) — anything
		// else is plaintext.
		$raw = (string) $value;
		return 0 === strpos( $raw, 'gcm:' ) || 0 === strpos( $raw, 'b64:' );
	}

	/**
	 * Encrypt a plaintext value (per-install-mode seam).
	 *
	 * @param string $plaintext Plaintext value.
	 * @return string|false Encrypted value or false on failure.
	 */
	protected static function encrypt_value( $plaintext ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Encryption' ) ) {
			return \WP_MCP_AI_Encryption::encrypt( $plaintext );
		}

		if ( class_exists( '\NvoosContentGraph\Remote\Crypto' ) ) {
			$encrypted = \NvoosContentGraph\Remote\Crypto::encrypt( (string) $plaintext );
			return '' !== $encrypted ? $encrypted : false;
		}

		return false;
	}

	/**
	 * Decrypt a stored value (per-install-mode seam).
	 *
	 * @param string $raw Stored ciphertext.
	 * @return string|false Plaintext or false on failure.
	 */
	protected static function decrypt_value( $raw ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Encryption' ) ) {
			return \WP_MCP_AI_Encryption::decrypt( $raw );
		}

		if ( class_exists( '\NvoosContentGraph\Remote\Crypto' ) ) {
			$decrypted = \NvoosContentGraph\Remote\Crypto::decrypt( (string) $raw );
			if ( '' === $decrypted || 0 === strpos( $decrypted, 'gcm:' ) || 0 === strpos( $decrypted, 'b64:' ) ) {
				return false;
			}
			return $decrypted;
		}

		return false;
	}

	/**
	 * Log an event via the plugin's logger (monolith installs only).
	 *
	 * @param string $type    Event type.
	 * @param string $message Human-readable message.
	 * @param array  $context Additional context (never contains key values).
	 * @return void
	 */
	private static function log( $type, $message, $context = array() ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event(
				'api_key_store_' . $type,
				$message,
				$context
			);
		}
	}
}
