<?php
/**
 * Credential Store — encrypted storage for AI provider API keys.
 *
 * Mirrors the base+pro plugin's WP_MCP_AI_Api_Key_Store for the
 * content-graph ecosystem:
 *
 *  - Keys are encrypted at rest (via the parent plugin's Remote\Crypto —
 *    AES-256-GCM with a `gcm:` prefix and transparent legacy handling).
 *  - Keys live in a separate non-autoload option, never inside the
 *    general nvoos_content_graph_settings option.
 *  - Stored keys are never rendered back into admin forms — a masked
 *    placeholder is used instead.
 *  - Legacy plaintext keys (pre-1.0.4 saves in nvoos_content_graph_settings)
 *    are transparently migrated to the encrypted store on first read.
 *
 * @package NvoosContentGraphAi
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Security;

/**
 * Encrypted API key store.
 *
 * Usage:
 *   CredentialStore::set( 'openai', 'sk-…' );
 *   $key = CredentialStore::get( 'openai' );
 */
final class CredentialStore {

	/**
	 * Option name holding encrypted provider keys (autoload=false).
	 */
	public const OPTION_NAME = 'nvoos_content_graph_ai_credentials';

	/**
	 * Flag option marking the one-time plaintext migration as complete.
	 */
	public const MIGRATION_FLAG = 'nvoos_content_graph_ai_credentials_migrated';

	/**
	 * Placeholder rendered in password fields instead of the stored key.
	 *
	 * Identical to the base plugin's MASKED_SECRET_PLACEHOLDER so masked
	 * field behavior is consistent across the NV oOS stack.
	 */
	public const MASKED_PLACEHOLDER = '**************';

	/**
	 * Provider settings suffixes mapped to the router provider slugs.
	 *
	 * Suffixes match the `ai_api_key_{suffix}` settings keys declared by
	 * the ApiKeys admin section. Router slugs differ for two providers
	 * (lm_studio → lmstudio, nvidia_nim → nvidia); CredentialResolver owns
	 * the provider-slug side of that mapping — this store speaks settings
	 * suffixes only.
	 *
	 * @var array<string, string>
	 */
	public const SUFFIX_TO_PROVIDER = array(
		'openai'       => 'openai',
		'gemini'       => 'gemini',
		'ollama'       => 'ollama',
		'anthropic'    => 'anthropic',
		'deepseek'     => 'deepseek',
		'openrouter'   => 'openrouter',
		'huggingface'  => 'huggingface',
		'cloudflare'   => 'cloudflare',
		'lmstudio'     => 'lm_studio',
		'nvidia'       => 'nvidia_nim',
		'digitalocean' => 'digitalocean',
		'kimi'         => 'kimi',
		'baseten'      => 'baseten',
	);

	/**
	 * Register hardening hooks.
	 *
	 *  - Route secret fields out of every nvoos_content_graph_settings save.
	 *  - Mask secret field values wherever the core renders them.
	 *  - Run the one-time plaintext → encrypted migration.
	 *  - Warn when the OpenSSL extension is unavailable.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter(
			'pre_update_option_nvoos_content_graph_settings',
			array( self::class, 'routeSecretsOnSettingsSave' ),
			10,
			3
		);
		add_filter(
			'nvoos_content_graph/section_field_value',
			array( self::class, 'maskRenderedFieldValue' ),
			10,
			3
		);
		add_action( 'admin_init', array( self::class, 'maybeRunMigration' ) );
		add_action( 'admin_notices', array( self::class, 'maybeRenderCryptoNotice' ) );
	}

	/**
	 * Retrieve and decrypt a stored key for a provider settings suffix.
	 *
	 * Transparently migrates plaintext keys found in the legacy settings
	 * option into the encrypted store on first read.
	 *
	 * @param string $suffix Provider settings suffix (e.g. 'openai', 'nvidia').
	 * @return string|null Decrypted key, or null when not configured.
	 */
	public static function get( string $suffix ): ?string {
		$stored = self::readOption();

		if ( isset( $stored[ $suffix ] ) && is_string( $stored[ $suffix ] ) && '' !== $stored[ $suffix ] ) {
			$raw       = $stored[ $suffix ];
			$decrypted = self::decrypt( $raw );

			// Decryption failed (salts rotated, tampered payload, or the
			// OpenSSL extension missing). Treat the key as absent rather
			// than forwarding garbage to the provider.
			if ( null === $decrypted ) {
				self::log( "decrypt_failed: {$suffix}" );
				return null;
			}

			// Value was stored plaintext (pre-1.0.4 writes) — re-encrypt it.
			if ( self::isPlaintext( $raw ) ) {
				self::set( $suffix, $decrypted );
			}

			return $decrypted;
		}

		// Migrate-on-read from the legacy plaintext settings location.
		$legacy = self::findPlaintextInSettings( $suffix );
		if ( null !== $legacy && '' !== $legacy['value'] ) {
			self::set( $suffix, $legacy['value'] );
			self::stripFromSettings( array( $legacy['key'] ) );

			return $legacy['value'];
		}

		return null;
	}

	/**
	 * Store an encrypted API key for a provider settings suffix.
	 *
	 * Writes only the credentials option — it never rewrites the general
	 * settings option. (Rewriting settings from inside a save would
	 * re-enter the pre_update_option filter against stale database state,
	 * causing unbounded recursion when several secrets exist. The filter
	 * and the migration routines strip the settings option themselves.)
	 *
	 * @param string $suffix Provider settings suffix (e.g. 'openai').
	 * @param string $value  Plaintext value to encrypt and store.
	 * @return bool True on success, false on encryption failure.
	 */
	public static function set( string $suffix, string $value ): bool {
		$value = \sanitize_text_field( $value );

		if ( '' === $value ) {
			self::delete( $suffix );
			return true;
		}

		// Placeholder means "keep the stored value" — never persist it.
		if ( self::MASKED_PLACEHOLDER === $value ) {
			return true;
		}

		return self::storeEncrypted( $suffix, $value );
	}

	/**
	 * Delete a stored key for a provider settings suffix.
	 *
	 * @param string $suffix Provider settings suffix.
	 * @return void
	 */
	public static function delete( string $suffix ): void {
		$stored = self::readOption();

		if ( isset( $stored[ $suffix ] ) ) {
			unset( $stored[ $suffix ] );

			if ( empty( $stored ) ) {
				\delete_option( self::OPTION_NAME );
			} else {
				\update_option( self::OPTION_NAME, $stored, false );
			}
		}

		self::log( "key_deleted: {$suffix}" );
	}

	/**
	 * Whether a key exists for the suffix — in the encrypted store or the
	 * legacy plaintext location. Read-only: performs no migration.
	 *
	 * @param string $suffix Provider settings suffix.
	 * @return bool
	 */
	public static function has( string $suffix ): bool {
		$stored = self::readOption();

		if ( isset( $stored[ $suffix ] ) && is_string( $stored[ $suffix ] ) && '' !== $stored[ $suffix ] ) {
			return true;
		}

		$legacy = self::findPlaintextInSettings( $suffix );

		return null !== $legacy && '' !== $legacy['value'];
	}

	/**
	 * Get all managed provider settings suffixes.
	 *
	 * @return array<int, string>
	 */
	public static function getManagedSuffixes(): array {
		return array_keys( self::SUFFIX_TO_PROVIDER );
	}

	/**
	 * Migrate all remaining plaintext keys to the encrypted store.
	 *
	 * Runs on plugin activation / upgrade (guarded by a flag option) and
	 * on demand via the `wp nvoos-cg-ai migrate-keys` WP-CLI command.
	 *
	 * @return array{
	 *     migrated: int,
	 *     failures: array<int, string>,
	 * }
	 */
	public static function migrateAll(): array {
		$migrated = 0;
		$failures = array();

		// Pass 1 — legacy plaintext keys in nvoos_content_graph_settings.
		foreach ( self::getManagedSuffixes() as $suffix ) {
			$stored = self::readOption();

			if ( isset( $stored[ $suffix ] ) && is_string( $stored[ $suffix ] ) && '' !== $stored[ $suffix ] ) {
				continue; // Already stored.
			}

			$legacy = self::findPlaintextInSettings( $suffix );
			if ( null === $legacy || '' === $legacy['value'] ) {
				continue;
			}

			if ( self::set( $suffix, $legacy['value'] ) ) {
				++$migrated;
			} else {
				$failures[] = $suffix;
			}
		}

		// Pass 2 — plaintext values already inside the store (pre-1.0.4 writes).
		$stored = self::readOption();
		foreach ( $stored as $suffix => $raw ) {
			if ( is_string( $raw ) && self::isPlaintext( $raw ) ) {
				if ( self::set( (string) $suffix, $raw ) ) {
					++$migrated;
				} else {
					$failures[] = (string) $suffix;
				}
			}
		}

		// Strip every secret field from the settings option in one write.
		// The written array contains no secrets, so the pre_update_option
		// filter completes without routing anything further.
		$keys = array( 'openai_api_key' );
		foreach ( self::getManagedSuffixes() as $suffix ) {
			$keys[] = "ai_api_key_{$suffix}";
		}
		self::stripFromSettings( $keys );

		self::log( 'migration_complete: ' . $migrated . ' migrated, ' . count( $failures ) . ' failed.' );

		return array(
			'migrated' => $migrated,
			'failures' => $failures,
		);
	}

	/**
	 * Run the one-time plaintext migration (admin_init).
	 *
	 * The flag is set even when zero keys migrate so the routine only
	 * runs once per site; WP-CLI remains available for manual reruns.
	 *
	 * @return void
	 */
	public static function maybeRunMigration(): void {
		if ( \get_option( self::MIGRATION_FLAG ) ) {
			return;
		}

		self::migrateAll();
		\update_option( self::MIGRATION_FLAG, true, false );
	}

	/**
	 * Strip secret fields from a nvoos_content_graph_settings save.
	 *
	 * Defense in depth: regardless of which tab or section triggered the
	 * save, secret values are routed into the encrypted store and never
	 * written into the general settings option. A non-empty submitted
	 * value (including one typed into the legacy General → Build field)
	 * is migrated; blank/placeholder submissions keep the stored key.
	 *
	 * Only the credentials option is written here — the settings option
	 * itself is cleaned by unsetting the keys from the value in flight,
	 * which avoids re-entrant settings writes.
	 *
	 * Hooked to `pre_update_option_nvoos_content_graph_settings`.
	 *
	 * @param mixed  $value     New option value.
	 * @param mixed  $old_value Old option value.
	 * @param string $option    Option name.
	 * @return mixed Option value without secret fields.
	 */
	public static function routeSecretsOnSettingsSave( $value, $old_value, $option ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress hook signature.
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $raw ) {
			$suffix = self::suffixForKey( (string) $key );
			if ( null === $suffix ) {
				continue;
			}

			$clean = is_string( $raw ) ? \sanitize_text_field( $raw ) : '';

			if ( '' !== $clean && self::MASKED_PLACEHOLDER !== $clean ) {
				self::storeEncrypted( $suffix, $clean );
			}

			unset( $value[ $key ] );
		}

		return $value;
	}

	/**
	 * Mask secret field values before the core renders them.
	 *
	 * Guarantees a stored key is never echoed back into the settings-page
	 * HTML, including the legacy `openai_api_key` field rendered by the
	 * core General → Build section.
	 *
	 * Hooked to `nvoos_content_graph/section_field_value`.
	 *
	 * @param mixed $value Current field value.
	 * @param mixed $key   Setting key.
	 * @param array $field Field definition.
	 * @return mixed Masked value for secret fields, original value otherwise.
	 */
	public static function maskRenderedFieldValue( $value, $key, $field ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter signature.
		$suffix = is_string( $key ) ? self::suffixForKey( $key ) : null;
		if ( null === $suffix ) {
			return $value;
		}

		// Never echo a stored secret back into the form.
		if ( is_string( $value ) && '' !== $value ) {
			return self::MASKED_PLACEHOLDER;
		}

		if ( self::has( $suffix ) ) {
			return self::MASKED_PLACEHOLDER;
		}

		return $value;
	}

	/**
	 * Warn when OpenSSL is unavailable and keys therefore use the weaker
	 * base64 fallback encoding.
	 *
	 * @return void
	 */
	public static function maybeRenderCryptoNotice(): void {
		if ( class_exists( '\NvoosContentGraph\Remote\Crypto' ) && \NvoosContentGraph\Remote\Crypto::isAvailable() ) {
			return;
		}

		if ( ! self::isOnSettingsPage() ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'NV oOS Content Graph — AI: the OpenSSL PHP extension is unavailable, so API keys are stored with a weaker fallback encoding. Enable OpenSSL, then re-save your keys to encrypt them properly.', 'nvoos-content-graph-ai' )
		);
	}

	// ─── Internal utilities ────────────────────────────────────────

	/**
	 * Encrypt and store a value in the credentials option only.
	 *
	 * Shared by set() and the settings-save filter. Never touches the
	 * general settings option.
	 *
	 * @param string $suffix    Provider settings suffix.
	 * @param string $plaintext Plaintext value to encrypt and store.
	 * @return bool True on success, false on encryption failure.
	 */
	private static function storeEncrypted( string $suffix, string $plaintext ): bool {
		$encrypted = self::encrypt( $plaintext );
		if ( null === $encrypted ) {
			self::log( "encrypt_failed: {$suffix}" );
			return false;
		}

		$stored            = self::readOption();
		$stored[ $suffix ] = $encrypted;
		\update_option( self::OPTION_NAME, $stored, false );

		self::log( "key_stored: {$suffix}" );

		return true;
	}

	/**
	 * Read the encrypted credentials option.
	 *
	 * @return array<string, string>
	 */
	private static function readOption(): array {
		$stored = \get_option( self::OPTION_NAME, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Resolve the parent plugin's settings option name.
	 *
	 * @return string
	 */
	private static function settingsOptionName(): string {
		return class_exists( '\NvoosContentGraph\Schema' )
			? \NvoosContentGraph\Schema::OPTION_SETTINGS
			: 'nvoos_content_graph_settings';
	}

	/**
	 * Map a setting key to a provider settings suffix, when it is a secret.
	 *
	 * @param string $key Setting key.
	 * @return string|null Suffix, or null when the key is not secret.
	 */
	private static function suffixForKey( string $key ): ?string {
		if ( 'openai_api_key' === $key ) {
			return 'openai';
		}

		if ( 0 === strpos( $key, 'ai_api_key_' ) ) {
			$suffix = substr( $key, strlen( 'ai_api_key_' ) );
			return '' !== $suffix ? $suffix : null;
		}

		return null;
	}

	/**
	 * List the settings keys a suffix may live under (including the
	 * legacy bare openai_api_key for the openai suffix).
	 *
	 * @param string $suffix Provider settings suffix.
	 * @return array<int, string>
	 */
	private static function settingsKeysForSuffix( string $suffix ): array {
		$keys = array( "ai_api_key_{$suffix}" );

		if ( 'openai' === $suffix ) {
			$keys[] = 'openai_api_key';
		}

		return $keys;
	}

	/**
	 * Find a plaintext key for a suffix in the legacy settings option.
	 *
	 * @param string $suffix Provider settings suffix.
	 * @return array{key: string, value: string}|null
	 */
	private static function findPlaintextInSettings( string $suffix ): ?array {
		$settings = \get_option( self::settingsOptionName(), array() );
		if ( ! is_array( $settings ) ) {
			return null;
		}

		foreach ( self::settingsKeysForSuffix( $suffix ) as $key ) {
			$value = $settings[ $key ] ?? '';
			if ( is_string( $value ) && '' !== $value ) {
				return array(
					'key'   => $key,
					'value' => $value,
				);
			}
		}

		return null;
	}

	/**
	 * Remove the given keys from the legacy settings option.
	 *
	 * @param array<int, string> $keys Setting keys to remove.
	 * @return void
	 */
	private static function stripFromSettings( array $keys ): void {
		$settings = \get_option( self::settingsOptionName(), array() );
		if ( ! is_array( $settings ) ) {
			return;
		}

		$changed = false;

		foreach ( $keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				unset( $settings[ $key ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			\update_option( self::settingsOptionName(), $settings, false );
		}
	}

	/**
	 * Encrypt a plaintext value via the parent plugin's crypto primitive.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string|null Encrypted value, or null on failure.
	 */
	private static function encrypt( string $plaintext ): ?string {
		if ( ! class_exists( '\NvoosContentGraph\Remote\Crypto' ) ) {
			return null;
		}

		$encrypted = \NvoosContentGraph\Remote\Crypto::encrypt( $plaintext );

		return '' !== $encrypted ? $encrypted : null;
	}

	/**
	 * Decrypt a stored raw value.
	 *
	 * Returns null when decryption failed — detected by the known `gcm:`
	 * / `b64:` prefixes surviving in Crypto::decrypt()'s return value.
	 *
	 * @param string $raw Stored value.
	 * @return string|null Plaintext, or null on failure.
	 */
	private static function decrypt( string $raw ): ?string {
		if ( ! class_exists( '\NvoosContentGraph\Remote\Crypto' ) ) {
			return self::isPlaintext( $raw ) ? $raw : null;
		}

		$decrypted = \NvoosContentGraph\Remote\Crypto::decrypt( $raw );

		if ( ! is_string( $decrypted ) || '' === $decrypted ) {
			return null;
		}

		if ( 0 === strpos( $decrypted, 'gcm:' ) || 0 === strpos( $decrypted, 'b64:' ) ) {
			return null;
		}

		return $decrypted;
	}

	/**
	 * Whether a stored raw value is plaintext (no known cipher prefix).
	 *
	 * @param string $raw Stored value.
	 * @return bool
	 */
	private static function isPlaintext( string $raw ): bool {
		return 0 !== strpos( $raw, 'gcm:' ) && 0 !== strpos( $raw, 'b64:' );
	}

	/**
	 * Whether the current admin screen is the parent's settings page.
	 *
	 * @return bool
	 */
	private static function isOnSettingsPage(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = \get_current_screen();

		return null !== $screen && 'toplevel_page_nvoos-content-graph' === $screen->id;
	}

	/**
	 * Log a key-store event (never containing key values).
	 *
	 * @param string $message Event description.
	 * @return void
	 */
	private static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic logging gated behind WP_DEBUG; messages never contain key values.
			error_log( '[Content Graph AI] ' . $message );
		}
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
