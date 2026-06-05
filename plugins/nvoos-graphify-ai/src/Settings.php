<?php
declare(strict_types=1);

namespace NvoosGraphifyAi;

/**
 * Static accessor for AI addon settings.
 *
 * AI-specific settings (API keys, models, provider selection) are stored
 * in the core plugin's grouped `nvoos_graphify_settings` option via the
 * `nvoos_graphify/default_settings` filter. This class reads them back.
 *
 * @since 1.0.0
 */
final class Settings {

	/**
	 * Get the active provider slug from settings.
	 *
	 * @return string Default 'openai'.
	 */
	public static function getDefaultProvider(): string {
		return self::get( 'ai_default_provider', 'openai' );
	}

	/**
	 * Get the API key for a given provider.
	 *
	 * @param string $providerSlug The provider slug (e.g. 'openai', 'gemini').
	 * @return string Empty string if not set.
	 */
	public static function getApiKey( string $providerSlug ): string {
		return self::get( "ai_api_key_{$providerSlug}", '' );
	}

	/**
	 * Get the default model for the active provider.
	 *
	 * @return string Model identifier string.
	 */
	public static function getDefaultModel(): string {
		return self::get( 'ai_default_model', 'gpt-4o' );
	}

	/**
	 * Get a setting from the core plugin's grouped settings.
	 *
	 * Reads from `nvoos_graphify_settings` option (populated by
	 * the core plugin's `Settings::all()` with defaults merged via
	 * `nvoos_graphify/default_settings` filter).
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		if ( function_exists( 'nvoos_graphify_get_setting' ) ) {
			return nvoos_graphify_get_setting( $key, $default );
		}

		// Fallback: read directly from the option.
		$settings = get_option( 'nvoos_graphify_settings', array() );
		return $settings[ $key ] ?? $default;
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
