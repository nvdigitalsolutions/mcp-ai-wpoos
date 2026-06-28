<?php
/**
 * Credential Resolver — graphify-aware API key resolution.
 *
 * Mirrors the base plugin's WP_MCP_AI_Credential_Resolver but reads
 * from nvoos_graphify_settings first, then falls back through the
 * base plugin's resolver, environment variables, and PHP constants.
 *
 * When the base plugin (mcp-ai-wpoos) is not active, the WP 7.0
 * Connector DB and base-plugin settings fallbacks are skipped
 * gracefully — the resolver still works with env vars and constants.
 *
 * @package NvoosGraphifyAi
 * @since   1.0.0
 */

declare(strict_types=1);

namespace NvoosGraphifyAi\Adapter;

/**
 * Resolves provider API keys for the graphify ecosystem.
 *
 * Priority chain:
 *  1. nvoos_graphify_settings → ai_api_key_{provider} (primary)
 *  1b. nvoos_graphify_settings → openai_api_key (bare, legacy fallback
 *      from the core General → Build section)
 *  2. wp_mcp_ai_settings (via base plugin's Credential_Resolver)
 *  3. WP 7.0 Connector DB (via base plugin's Credential_Resolver)
 *  4. {PROVIDER}_API_KEY environment variable
 *  5. {PROVIDER}_API_KEY PHP constant
 *
 * When the base plugin is unavailable, priorities 2–3 are skipped
 * and the resolver falls directly to env/constant.
 */
final class CredentialResolver {

	/**
	 * Cache of resolved API keys per provider per request.
	 *
	 * @var array<string, string|null>
	 */
	private static array $key_cache = array();

	/**
	 * Provider slug aliases — graphify slug → base plugin slug.
	 *
	 * When Graphify uses a different slug than the base plugin for
	 * the same provider, the alias is tried after the original slug
	 * fails on each priority level.
	 *
	 * @var array<string, string>
	 */
	private const SLUG_ALIASES = array(
		'gemini'     => 'google',
		'nvidia_nim' => 'nvidia',
	);

	/**
	 * Providers that don't require an API key.
	 *
	 * @var array<int, string>
	 */
	private const NO_KEY_PROVIDERS = array(
		'ollama',
		'lm_studio',
		'embedded',
	);

	/**
	 * Get the API key for a provider.
	 *
	 * Checks sources in priority order and returns the first non-empty
	 * value found. Returns null when no key is configured anywhere.
	 *
	 * @param string $provider Provider slug (e.g., 'openai', 'gemini').
	 * @return string|null The API key, or null if not found.
	 */
	public static function getApiKey( string $provider ): ?string {
		if ( array_key_exists( $provider, self::$key_cache ) ) {
			return self::$key_cache[ $provider ];
		}

		$key                          = self::resolve( $provider );
		self::$key_cache[ $provider ] = $key;

		return $key;
	}

	/**
	 * Resolve an API key without caching.
	 *
	 * @param string $provider Provider slug.
	 * @return string|null
	 */
	private static function resolve( string $provider ): ?string {
		// Priority 1 — Graphify own settings.
		$key = self::fromGraphifySettings( $provider );
		if ( null !== $key ) {
			return $key;
		}

		// Priority 2 — Base plugin's Credential_Resolver (handles
		// wp_mcp_ai_settings + WP 7.0 Connector DB internally).
		$key = self::fromBasePluginResolver( $provider );
		if ( null !== $key ) {
			return $key;
		}

		// Priority 3 — Environment variable.
		$key = self::fromEnv( $provider );
		if ( null !== $key ) {
			return $key;
		}

		// Priority 4 — PHP constant.
		return self::fromConstant( $provider );
	}

	// ─── Source readers ────────────────────────────────────────────

	/**
	 * Read API key from nvoos_graphify_settings.
	 *
	 * @param string $provider Provider slug.
	 * @return string|null
	 */
	private static function fromGraphifySettings( string $provider ): ?string {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}

		$settings = get_option( 'nvoos_graphify_settings', array() );
		if ( ! is_array( $settings ) ) {
			return null;
		}

		// Graphify stores keys with ai_api_key_ prefix (primary).
		$keys_to_try = array( $provider );
		if ( isset( self::SLUG_ALIASES[ $provider ] ) ) {
			$keys_to_try[] = self::SLUG_ALIASES[ $provider ];
		}

		foreach ( $keys_to_try as $slug ) {
			$key = $settings[ "ai_api_key_{$slug}" ] ?? '';
			if ( is_string( $key ) && '' !== $key ) {
				return $key;
			}
		}

		// Legacy fallback: core Graphify stores a bare 'openai_api_key'
		// in the General → Build section (no ai_ prefix). This is the
		// "optional fallback" key described as "Leave blank to use the
		// global oOS key." Check it after the prefixed key.
		if ( 'openai' === $provider ) {
			$bare_key = $settings['openai_api_key'] ?? '';
			if ( is_string( $bare_key ) && '' !== $bare_key ) {
				return $bare_key;
			}
		}

		return null;
	}

	/**
	 * Read API key from the base plugin's Credential_Resolver.
	 *
	 * The base resolver handles wp_mcp_ai_settings, WP 7.0 Connector DB,
	 * env vars, and constants internally. We delegate to it so graphify
	 * benefits from all of those sources without duplicating logic.
	 *
	 * Tries both the graphify slug and its alias (e.g., gemini → google).
	 *
	 * @param string $provider Provider slug.
	 * @return string|null
	 */
	private static function fromBasePluginResolver( string $provider ): ?string {
		if ( ! class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			return null;
		}

		$slugs_to_try = array( $provider );
		if ( isset( self::SLUG_ALIASES[ $provider ] ) ) {
			$slugs_to_try[] = self::SLUG_ALIASES[ $provider ];
		}

		foreach ( $slugs_to_try as $slug ) {
			$key = \WP_MCP_AI_Credential_Resolver::get_api_key( $slug );
			if ( null !== $key ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Read API key from an environment variable.
	 *
	 * Convention: {PROVIDER}_API_KEY (uppercase with underscores).
	 * For providers with aliases, both names are tried.
	 *
	 * @param string $provider Provider slug.
	 * @return string|null
	 */
	private static function fromEnv( string $provider ): ?string {
		$names_to_try = array( self::envName( $provider ) );
		if ( isset( self::SLUG_ALIASES[ $provider ] ) ) {
			$names_to_try[] = self::envName( self::SLUG_ALIASES[ $provider ] );
		}

		foreach ( $names_to_try as $env_var ) {
			$key = getenv( $env_var );
			if ( is_string( $key ) && '' !== $key ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Read API key from a PHP constant.
	 *
	 * Convention: {PROVIDER}_API_KEY (uppercase with underscores).
	 *
	 * @param string $provider Provider slug.
	 * @return string|null
	 */
	private static function fromConstant( string $provider ): ?string {
		$names_to_try = array( self::envName( $provider ) );
		if ( isset( self::SLUG_ALIASES[ $provider ] ) ) {
			$names_to_try[] = self::envName( self::SLUG_ALIASES[ $provider ] );
		}

		foreach ( $names_to_try as $constant_name ) {
			if ( ! defined( $constant_name ) ) {
				continue;
			}

			$key = constant( $constant_name );
			if ( is_string( $key ) && '' !== $key ) {
				return $key;
			}
		}

		return null;
	}

	// ─── Public helpers ────────────────────────────────────────────

	/**
	 * Check whether a provider has usable credentials.
	 *
	 * Returns true when:
	 *  - An API key is found (via any source), OR
	 *  - The provider uses 'none' authentication (e.g., Ollama, LM Studio).
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public static function hasCredentials( string $provider ): bool {
		if ( in_array( $provider, self::NO_KEY_PROVIDERS, true ) ) {
			return true;
		}

		return null !== self::getApiKey( $provider );
	}

	/**
	 * Get the source of the resolved API key for a provider.
	 *
	 * Returns a machine-readable label indicating which source supplied
	 * the active key. Useful for admin UI indicators and debugging.
	 *
	 * @param string $provider Provider slug.
	 * @return string One of: 'graphify_settings', 'base_plugin', 'env_var', 'constant', 'none'.
	 */
	public static function getKeySource( string $provider ): string {
		// Check each source in priority order.
		$key = self::fromGraphifySettings( $provider );
		if ( null !== $key ) {
			return 'graphify_settings';
		}

		$key = self::fromBasePluginResolver( $provider );
		if ( null !== $key ) {
			return 'base_plugin';
		}

		$key = self::fromEnv( $provider );
		if ( null !== $key ) {
			return 'env_var';
		}

		$key = self::fromConstant( $provider );
		if ( null !== $key ) {
			return 'constant';
		}

		return 'none';
	}

	/**
	 * Clear the internal key cache.
	 *
	 * Useful in tests or when settings have been updated mid-request.
	 */
	public static function clearCache(): void {
		self::$key_cache = array();
	}

	// ─── Internal utilities ────────────────────────────────────────

	/**
	 * Convert a provider slug to the conventional environment variable
	 * / constant name.
	 *
	 * E.g., 'nvidia_nim' → 'NVIDIA_NIM', 'openai' → 'OPENAI'.
	 *
	 * @param string $provider Provider slug.
	 * @return string Uppercased env/constant name.
	 */
	private static function envName( string $provider ): string {
		return strtoupper( $provider ) . '_API_KEY';
	}
}
