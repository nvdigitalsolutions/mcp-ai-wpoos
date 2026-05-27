<?php
/**
 * Gemini Multimodal Live API Voice Client for NV oOS.
 *
 * Implements the WP_MCP_AI_Voice_Provider interface for Google Gemini's
 * Multimodal Live API. Provides bidirectional WebSocket-based voice
 * interaction with Gemini models that support the live modality.
 *
 * Gemini Live API reference: https://ai.google.dev/gemini-api/docs/live
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

if ( ! class_exists( 'WP_MCP_AI_Gemini_Live_Client' ) ) {
	/**
	 * Gemini Multimodal Live API client for voice sessions.
	 */
	class WP_MCP_AI_Gemini_Live_Client implements WP_MCP_AI_Voice_Provider {

		/**
		 * Gemini Live WebSocket base URL.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const WEBSOCKET_BASE = 'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1alpha.GenerativeService.BidiGenerateContent';

		/**
		 * Default live model.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const DEFAULT_MODEL = 'gemini-2.5-flash-live';

		/**
		 * Default voice preset name for Gemini.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const DEFAULT_VOICE = 'Puck';

		/**
		 * Available voice presets from Gemini.
		 *
		 * Gemini currently supports these named voices.
		 *
		 * @since 1.2.0
		 * @var array
		 */
		const AVAILABLE_VOICES = array(
			'Puck'   => 'Puck (default, neutral)',
			'Charon' => 'Charon (deep, authoritative)',
			'Kore'   => 'Kore (warm, feminine)',
			'Fenrir' => 'Fenrir (bright, energetic)',
			'Aoede'  => 'Aoede (calm, melodic)',
		);

		/**
		 * Session config cache TTL in seconds.
		 *
		 * @since 1.2.0
		 * @var int
		 */
		const CACHE_TTL = 300; // 5 minutes.

		/**
		 * Retrieve the configured Gemini API key.
		 *
		 * @return string
		 */
		public function get_api_key() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';
		}

		/**
		 * Get the unique provider slug.
		 *
		 * @return string
		 */
		public function get_slug() {
			return 'gemini_live';
		}

		/**
		 * Get the human-readable provider name.
		 *
		 * @return string
		 */
		public function get_name() {
			return __( 'Gemini Multimodal Live', 'mcp-ai-wpoos' );
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
			$api_key  = $this->get_api_key();
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $api_key ) ) {
				return false;
			}

			// Check if realtime voice with Gemini is explicitly enabled.
			$enabled = isset( $settings['voice_mode'] ) && 'realtime' === $settings['voice_mode']
				&& isset( $settings['voice_realtime_provider'] ) && 'gemini' === $settings['voice_realtime_provider'];

			/**
			 * Filter whether Gemini Live voice is available.
			 *
			 * @since 1.2.0
			 *
			 * @param bool   $available Whether the provider is available.
			 * @param string $has_key   Whether an API key is configured.
			 */
			return (bool) apply_filters( 'wp_mcp_ai_gemini_live_available', $enabled, ! empty( $api_key ) );
		}

		/**
		 * Get the recommended voice model.
		 *
		 * @return string
		 */
		public function get_default_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['gemini_live_model'] ) && ! empty( $settings['gemini_live_model'] )
				? sanitize_text_field( $settings['gemini_live_model'] )
				: self::DEFAULT_MODEL;
		}

		/**
		 * Get available voice names.
		 *
		 * @return array
		 */
		public function get_available_voices() {
			return self::AVAILABLE_VOICES;
		}

		/**
		 * Get the default voice name.
		 *
		 * @return string
		 */
		public function get_default_voice() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['gemini_live_voice'] ) && ! empty( $settings['gemini_live_voice'] )
				? sanitize_text_field( $settings['gemini_live_voice'] )
				: self::DEFAULT_VOICE;
		}

		/**
		 * Get assistant system instructions.
		 *
		 * @param int $assistant_id The assistant ID.
		 * @return string
		 */
		protected function get_assistant_instructions( $assistant_id ) {
			$instructions = '';

			if ( function_exists( 'wp_mcp_ai_get_assistant_prompt' ) ) {
				$instructions = wp_mcp_ai_get_assistant_prompt( $assistant_id );
			}

			if ( empty( $instructions ) ) {
				$post = get_post( $assistant_id );
				if ( $post && 'mcp_ai_assistant' === $post->post_type ) {
					$instructions = wp_strip_all_tags( $post->post_content );
				}
			}

			$voice_prefix = __( 'You are a voice assistant. Keep responses concise and conversational. Use natural spoken language.', 'mcp-ai-wpoos' );

			if ( ! empty( $instructions ) ) {
				$instructions = $voice_prefix . "\n\n" . $instructions;
			} else {
				$instructions = $voice_prefix;
			}

			/**
			 * Filter the Gemini Live session instructions.
			 *
			 * @since 1.2.0
			 *
			 * @param string $instructions The system instructions.
			 * @param int    $assistant_id The assistant ID.
			 */
			return (string) apply_filters( 'wp_mcp_ai_gemini_live_instructions', $instructions, $assistant_id );
		}

		/**
		 * Create a Gemini Live session configuration for the frontend.
		 *
		 * Unlike OpenAI's ephemeral token approach, Gemini Live requires
		 * the API key to be included in the WebSocket URL. We generate the
		 * full connection URL server-side and return it to the frontend.
		 *
		 * @param int   $assistant_id The assistant ID.
		 * @param array $options      Optional overrides.
		 * @return array|WP_Error
		 */
		public function create_session( $assistant_id, $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_gemini_live_no_key',
					__( 'Gemini API key is not configured.', 'mcp-ai-wpoos' )
				);
			}

			$assistant_id = absint( $assistant_id );
			if ( $assistant_id <= 0 ) {
				return new WP_Error(
					'wp_mcp_ai_gemini_live_invalid_assistant',
					__( 'Invalid assistant ID.', 'mcp-ai-wpoos' )
				);
			}

			// Cache key.
			$cache_key = 'wp_mcp_ai_gemini_live_session_' . $assistant_id . '_' . get_current_user_id();

			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['ws_url'] ) ) {
				return $cached;
			}

			$model = isset( $options['model'] ) && ! empty( $options['model'] )
				? sanitize_text_field( $options['model'] )
				: $this->get_default_model();

			$voice = isset( $options['voice'] ) && ! empty( $options['voice'] )
				? sanitize_text_field( $options['voice'] )
				: $this->get_default_voice();

			$instructions = $this->get_assistant_instructions( $assistant_id );

			// Build Gemini Live setup config.
			$setup = array(
				'model'             => 'models/' . $model,
				'generationConfig'  => array(
					'responseModalities' => array( 'AUDIO' ),
					'speechConfig'       => array(
						'voiceConfig' => array(
							'prebuiltVoiceConfig' => array(
								'voiceName' => $voice,
							),
						),
					),
					'temperature'        => 0.8,
				),
				'systemInstruction' => array(
					'parts' => array(
						array( 'text' => $instructions ),
					),
				),
			);

			/**
			 * Filter the Gemini Live session setup config.
			 *
			 * @since 1.2.0
			 *
			 * @param array $setup        Setup configuration.
			 * @param int   $assistant_id The assistant ID.
			 * @param array $options      Requested options.
			 */
			$setup = apply_filters( 'wp_mcp_ai_gemini_live_setup', $setup, $assistant_id, $options );

			// Build WebSocket URL with API key.
			$ws_url = self::WEBSOCKET_BASE . '?key=' . rawurlencode( $api_key );

			$session_config = array(
				'type'           => 'gemini_live',
				'transport_mode' => 'realtime',
				'model'          => $model,
				'voice'          => $voice,
				'ws_url'         => $ws_url,
				'setup'          => $setup,
				'expires_at'     => time() + self::CACHE_TTL,
			);

			set_transient( $cache_key, $session_config, self::CACHE_TTL );

			/**
			 * NOTE: The API key is included in the WebSocket URL returned to the
			 * frontend. This is the currently documented approach for Gemini Live.
			 * Google's recommendation is to use a proxy server in production to
			 * avoid exposing the key. See:
			 * https://ai.google.dev/gemini-api/docs/live#security
			 *
			 * For production deployments, implement a server-side WebSocket proxy
			 * or use Gemini's Enterprise/Vertex AI endpoint with service accounts.
			 */

			return $session_config;
		}
	}
}
