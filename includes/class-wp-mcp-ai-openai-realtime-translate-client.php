<?php
/**
 * GPT-Realtime-Translate Client for NV oOS.
 *
 * Implements the WP_MCP_AI_Voice_Provider interface for OpenAI's
 * GPT-Realtime-Translate model. Provides live speech translation
 * supporting 70+ input languages → 13 output languages.
 *
 * Uses the dedicated /v1/realtime/translations endpoint. Translation
 * sessions are continuous — the client streams audio in and the
 * service streams translated audio and transcript deltas out.
 * There is no assistant turn lifecycle; translation happens as the
 * speaker talks.
 *
 * Reference: https://platform.openai.com/docs/guides/realtime-translation
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_OpenAI_Realtime_Translate_Client' ) ) {
	/**
	 * GPT-Realtime-Translate voice provider.
	 */
	class WP_MCP_AI_OpenAI_Realtime_Translate_Client implements WP_MCP_AI_Voice_Provider {

		/**
		 * Translation session creation endpoint.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		const TRANSLATION_ENDPOINT = 'https://api.openai.com/v1/realtime/translations';

		/**
		 * Supported input languages (subset of 70+ supported by the model).
		 *
		 * @since 1.3.0
		 * @var array
		 */
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

		/**
		 * Supported output languages (13 languages).
		 *
		 * @since 1.3.0
		 * @var array
		 */
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

		/**
		 * Default input language.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		const DEFAULT_INPUT_LANGUAGE = 'en';

		/**
		 * Default output language.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		const DEFAULT_OUTPUT_LANGUAGE = 'es';

		/**
		 * Default model.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		const DEFAULT_MODEL = 'gpt-realtime-translate';

		/**
		 * Retrieve the configured OpenAI API key.
		 *
		 * @return string
		 */
		public function get_api_key() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$key      = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';

			if ( empty( $key ) && class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
				$key = WP_MCP_AI_Credential_Resolver::get_api_key( 'openai' ) ?? '';
			}

			return $key;
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
			return __( 'OpenAI Realtime Translate', 'mcp-ai-wpoos' );
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

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

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
		 * @since 1.3.0
		 * @return array
		 */
		public function get_input_languages() {
			return self::INPUT_LANGUAGES;
		}

		/**
		 * Get available output languages.
		 *
		 * @since 1.3.0
		 * @return array
		 */
		public function get_output_languages() {
			return self::OUTPUT_LANGUAGES;
		}

		/**
		 * Get the default input language from settings or constant.
		 *
		 * @since 1.3.0
		 * @return string
		 */
		public function get_default_input_language() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['realtime_translate_input_lang'] ) && ! empty( $settings['realtime_translate_input_lang'] )
				? sanitize_text_field( $settings['realtime_translate_input_lang'] )
				: self::DEFAULT_INPUT_LANGUAGE;
		}

		/**
		 * Get the default output language from settings or constant.
		 *
		 * @since 1.3.0
		 * @return string
		 */
		public function get_default_output_language() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['realtime_translate_output_lang'] ) && ! empty( $settings['realtime_translate_output_lang'] )
				? sanitize_text_field( $settings['realtime_translate_output_lang'] )
				: self::DEFAULT_OUTPUT_LANGUAGE;
		}

		/**
		 * Create a translation session.
		 *
		 * Returns the session config the frontend needs to establish
		 * a WebRTC or WebSocket connection to the translation endpoint.
		 *
		 * @since 1.3.0
		 *
		 * @param int   $assistant_id The assistant ID (informational — translation is not assistant-based).
		 * @param array $options      Optional overrides (input_language, output_language).
		 * @return array|WP_Error
		 */
		public function create_session( $assistant_id, $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_translate_no_key',
					__( 'OpenAI API key is not configured.', 'mcp-ai-wpoos' )
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
}
