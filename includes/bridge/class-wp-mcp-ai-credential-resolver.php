<?php
/**
 * Credential Resolver — unified API key lookup across multiple sources.
 *
 * Replaces direct reads of $settings['{provider}_api_key'] with a single
 * resolution point that respects:
 *
 *  Priority 1. NV oOS settings (wp_mcp_ai_settings → {provider}_api_key)
 *  Priority 2. WP 7.0 Connector DB option (connectors_ai_{provider}_api_key)
 *  Priority 3. Environment variable ({PROVIDER_ID}_API_KEY)
 *  Priority 4. PHP constant ({PROVIDER_ID}_API_KEY)
 *
 * When WP 7.0 is not available, only priority 1 is checked (the legacy
 * path), so existing sites are completely unaffected.
 *
 * @package WP_MCP_AI
 * @since   1.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) :

	/**
	 * Resolves provider API keys from the configured source chain.
	 */
	final class WP_MCP_AI_Credential_Resolver {

		/**
		 * Cache of resolved API keys per provider per request.
		 *
		 * @since 1.8.0
		 * @var array<string, string|null>
		 */
		private static $key_cache = array();

		/**
		 * Get the API key for a provider.
		 *
		 * Checks sources in priority order and returns the first non-empty
		 * value found. Returns null when no key is configured anywhere.
		 *
		 * @since 1.8.0
		 * @param string $provider Provider slug (e.g., 'openai', 'deepseek').
		 * @return string|null The API key, or null if not found.
		 */
		public static function get_api_key( string $provider ): ?string {
			if ( array_key_exists( $provider, self::$key_cache ) ) {
				return self::$key_cache[ $provider ];
			}

			$key = self::resolve( $provider );
			self::$key_cache[ $provider ] = $key;

			return $key;
		}

		/**
		 * Resolve an API key without caching.
		 *
		 * @since 1.8.0
		 * @param string $provider Provider slug.
		 * @return string|null
		 */
		private static function resolve( string $provider ): ?string {
			// Priority 1 — NV oOS own settings.
			$key = self::from_nvoos_settings( $provider );
			if ( ! empty( $key ) ) {
				return $key;
			}

			// Priority 2 — WP 7.0 Connector database option.
			if ( WP_MCP_AI_WP70_Bridge::is_available() ) {
				$key = self::from_connector_db( $provider );
				if ( ! empty( $key ) ) {
					return $key;
				}
			}

			// Priority 3 — environment variable.
			$key = self::from_env( $provider );
			if ( ! empty( $key ) ) {
				return $key;
			}

			// Priority 4 — PHP constant.
			$key = self::from_constant( $provider );
			if ( ! empty( $key ) ) {
				return $key;
			}

			return null;
		}

		/**
		 * Read API key from NV oOS settings.
		 *
		 * @since 1.8.0
		 * @param string $provider Provider slug.
		 * @return string|null
		 */
		private static function from_nvoos_settings( string $provider ): ?string {
			$settings = get_option( 'wp_mcp_ai_settings', array() );
			$key      = $settings[ "{$provider}_api_key" ] ?? '';

			return '' !== $key ? $key : null;
		}

		/**
		 * Read API key from the WP 7.0 Connector database option.
		 *
		 * Uses wp_get_connector() to discover the correct setting_name,
		 * then reads that option directly.
		 *
		 * @since 1.8.0
		 * @param string $provider Provider slug.
		 * @return string|null
		 */
		private static function from_connector_db( string $provider ): ?string {
			if ( ! function_exists( 'wp_get_connector' ) ) {
				return null;
			}

			$setting_name = WP_MCP_AI_WP70_Bridge::get_connector_setting_name( $provider );
			$key          = get_option( $setting_name, '' );

			return '' !== $key ? $key : null;
		}

		/**
		 * Read API key from an environment variable.
		 *
		 * WP 7.0 convention: {PROVIDER_ID}_API_KEY
		 * (e.g., ANTHROPIC_API_KEY, OPENAI_API_KEY)
		 *
		 * @since 1.8.0
		 * @param string $provider Provider slug.
		 * @return string|null
		 */
		private static function from_env( string $provider ): ?string {
			$env_var = strtoupper( $provider ) . '_API_KEY';
			$key     = getenv( $env_var );

			return is_string( $key ) && '' !== $key ? $key : null;
		}

		/**
		 * Read API key from a PHP constant.
		 *
		 * WP 7.0 convention: {PROVIDER_ID}_API_KEY
		 * (e.g., define( 'ANTHROPIC_API_KEY', 'sk-...' ))
		 *
		 * @since 1.8.0
		 * @param string $provider Provider slug.
		 * @return string|null
		 */
		private static function from_constant( string $provider ): ?string {
			$constant_name = strtoupper( $provider ) . '_API_KEY';

			if ( ! defined( $constant_name ) ) {
				return null;
			}

			$key = constant( $constant_name );

			return is_string( $key ) && '' !== $key ? $key : null;
		}

		/**
		 * Check whether a provider has a configured API key (or doesn't need one).
		 *
		 * Returns true when:
		 *  - An API key is found (via any source), OR
		 *  - The provider uses 'none' authentication (e.g., Ollama, LM Studio,
		 *    Embedded LLM — no API key needed).
		 *
		 * This is the method intended to replace checks like:
		 *   ! empty( $settings['openai_api_key'] )
		 *
		 * @since 1.8.0
		 * @param string $provider Provider slug.
		 * @return bool
		 */
		public static function has_credentials( string $provider ): bool {
			// Providers that don't need an API key.
			static $no_key_providers = array(
				'ollama',
				'lm_studio',
				'embedded',
			);

			if ( in_array( $provider, $no_key_providers, true ) ) {
				return true;
			}

			return null !== self::get_api_key( $provider );
		}

		/**
		 * Clear the internal key cache.
		 *
		 * Useful in tests or when settings have been updated mid-request.
		 *
		 * @since 1.8.0
		 */
		public static function clear_cache(): void {
			self::$key_cache = array();
		}
	}

endif;
