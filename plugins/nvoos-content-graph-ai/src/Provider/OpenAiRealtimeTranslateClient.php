<?php
/**
 * GPT-Realtime-Translate Client for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/class-wp-mcp-ai-openai-realtime-translate-client.php`
 * (behaviour-preserving; base copy retained permanently — ecosystem port
 * plan D-NOBASE). Language tables, defaults, session shape, and error
 * codes are byte-identical.
 *
 * Decoupling (documented, additive): settings reads go through
 * `get_settings()` (base settings registry in monolith installs, the
 * content-graph settings store in standalone installs) and API-key reads
 * through `get_api_key()` (base settings/resolver in monolith, CG-AI
 * `CredentialResolver` standalone).
 *
 * @package NvoosContentGraphAi\Provider
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Provider;

use NvoosContentGraphAi\Adapter\CredentialResolver;
use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GPT-Realtime-Translate voice provider.
 *
 * @since 1.1.0
 */
class OpenAiRealtimeTranslateClient implements VoiceProviderInterface {

	const TRANSLATION_ENDPOINT = 'https://api.openai.com/v1/realtime/translations';

	const INPUT_LANGUAGES = array(
		'ar'    => 'Arabic',
		'bg'    => 'Bulgarian',
		'zh'    => 'Chinese (Simplified)',
		'zh-TW' => 'Chinese (Traditional)',
		'hr'    => 'Croatian',
		'cs'    => 'Czech',
		'da'    => 'Danish',
		'nl'    => 'Dutch',
		'en'    => 'English',
		'et'    => 'Estonian',
		'fi'    => 'Finnish',
		'fr'    => 'French',
		'de'    => 'German',
		'el'    => 'Greek',
		'he'    => 'Hebrew',
		'hi'    => 'Hindi',
		'hu'    => 'Hungarian',
		'id'    => 'Indonesian',
		'it'    => 'Italian',
		'ja'    => 'Japanese',
		'ko'    => 'Korean',
		'lv'    => 'Latvian',
		'lt'    => 'Lithuanian',
		'ms'    => 'Malay',
		'no'    => 'Norwegian',
		'pl'    => 'Polish',
		'pt'    => 'Portuguese',
		'ro'    => 'Romanian',
		'ru'    => 'Russian',
		'sr'    => 'Serbian',
		'sk'    => 'Slovak',
		'sl'    => 'Slovenian',
		'es'    => 'Spanish',
		'sv'    => 'Swedish',
		'ta'    => 'Tamil',
		'te'    => 'Telugu',
		'th'    => 'Thai',
		'tr'    => 'Turkish',
		'uk'    => 'Ukrainian',
		'ur'    => 'Urdu',
		'vi'    => 'Vietnamese',
	);

	const OUTPUT_LANGUAGES = array(
		'ar' => 'Arabic',
		'zh' => 'Chinese (Mandarin)',
		'nl' => 'Dutch',
		'en' => 'English',
		'fr' => 'French',
		'de' => 'German',
		'hi' => 'Hindi',
		'it' => 'Italian',
		'ja' => 'Japanese',
		'ko' => 'Korean',
		'pl' => 'Polish',
		'pt' => 'Portuguese',
		'es' => 'Spanish',
	);

	const DEFAULT_INPUT_LANGUAGE  = 'en';
	const DEFAULT_OUTPUT_LANGUAGE = 'es';
	const DEFAULT_MODEL           = 'gpt-realtime-translate';

	/**
	 * Retrieve the configured OpenAI API key.
	 *
	 * @return string
	 */
	public function get_api_key() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = \WP_MCP_AI_Admin_Settings::get_settings();
			$key      = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';

			if ( empty( $key ) && class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
				$key = \WP_MCP_AI_Credential_Resolver::get_api_key( 'openai' ) ?? '';
			}

			return $key;
		}

		$resolved = CredentialResolver::getApiKey( 'openai' );

		return null !== $resolved ? $resolved : '';
	}

	/**
	 * Read the active settings map (per-install-mode seam).
	 *
	 * @return array
	 */
	protected static function get_settings() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return \WP_MCP_AI_Admin_Settings::get_settings();
		}

		return CoreBridge::instance()->settings->all();
	}

	/**
	 * Get the unique provider slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'openai_realtime_translate';
	}

	/**
	 * Get the human-readable provider name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'OpenAI Realtime Translate', 'nvoos-content-graph-ai' );
	}

	/**
	 * Get the transport mode.
	 *
	 * @return string
	 */
	public function get_transport_mode() {
		return 'realtime';
	}

	/**
	 * Check if this voice provider is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return false;
		}

		$settings = static::get_settings();

		$enabled = isset( $settings['voice_mode'] ) && 'realtime' === $settings['voice_mode']
			&& isset( $settings['voice_realtime_provider'] ) && 'openai_translate' === $settings['voice_realtime_provider'];

		return (bool) apply_filters( 'wp_mcp_ai_openai_translate_available', $enabled, ! empty( $api_key ) );
	}

	/**
	 * Get the default model.
	 *
	 * @return string
	 */
	public function get_default_model() {
		return self::DEFAULT_MODEL;
	}

	/**
	 * Get available voices (not applicable for translation).
	 *
	 * @return array
	 */
	public function get_available_voices() {
		return array();
	}

	/**
	 * Get default voice (not applicable for translation).
	 *
	 * @return string
	 */
	public function get_default_voice() {
		return '';
	}

	/**
	 * Get available input languages.
	 *
	 * @return array
	 */
	public function get_input_languages() {
		return self::INPUT_LANGUAGES;
	}

	/**
	 * Get available output languages.
	 *
	 * @return array
	 */
	public function get_output_languages() {
		return self::OUTPUT_LANGUAGES;
	}

	/**
	 * Get the default input language from settings or constant.
	 *
	 * @return string
	 */
	public function get_default_input_language() {
		$settings = static::get_settings();

		return isset( $settings['realtime_translate_input_lang'] ) && ! empty( $settings['realtime_translate_input_lang'] )
			? sanitize_text_field( $settings['realtime_translate_input_lang'] )
			: self::DEFAULT_INPUT_LANGUAGE;
	}

	/**
	 * Get the default output language from settings or constant.
	 *
	 * @return string
	 */
	public function get_default_output_language() {
		$settings = static::get_settings();

		return isset( $settings['realtime_translate_output_lang'] ) && ! empty( $settings['realtime_translate_output_lang'] )
			? sanitize_text_field( $settings['realtime_translate_output_lang'] )
			: self::DEFAULT_OUTPUT_LANGUAGE;
	}

	/**
	 * Create a translation session.
	 *
	 * @param int   $assistant_id The assistant ID (informational — translation is not assistant-based).
	 * @param array $options      Optional overrides (input_language, output_language).
	 * @return array|\WP_Error
	 */
	public function create_session( $assistant_id, $options = array() ) {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'wp_mcp_ai_translate_no_key',
				__( 'OpenAI API key is not configured.', 'nvoos-content-graph-ai' )
			);
		}

		$input_lang  = isset( $options['input_language'] ) && ! empty( $options['input_language'] )
			? sanitize_text_field( $options['input_language'] )
			: $this->get_default_input_language();
		$output_lang = isset( $options['output_language'] ) && ! empty( $options['output_language'] )
			? sanitize_text_field( $options['output_language'] )
			: $this->get_default_output_language();

		return array(
			'type'             => 'openai_translate',
			'transport_mode'   => 'realtime',
			'model'            => self::DEFAULT_MODEL,
			'endpoint'         => self::TRANSLATION_ENDPOINT,
			'input_language'   => $input_lang,
			'output_language'  => $output_lang,
			'input_languages'  => self::INPUT_LANGUAGES,
			'output_languages' => self::OUTPUT_LANGUAGES,
		);
	}
}
