<?php
/**
 * GPT-Realtime-Whisper Client for NV oOS.
 *
 * Implements the WP_MCP_AI_Voice_Provider interface for OpenAI's
 * GPT-Realtime-Whisper model. Provides streaming speech-to-text
 * with configurable latency — lower delay = earlier partial text,
 * higher delay = better transcription quality.
 *
 * Transcription sessions emit transcript deltas only; there is no
 * model-generated spoken response. Use this for live captions,
 * meeting notes, and voice agents that need continuous transcription.
 *
 * Reference: https://platform.openai.com/docs/guides/realtime-transcription
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

if ( ! class_exists( 'WP_MCP_AI_OpenAI_Realtime_Whisper_Client' ) ) {
	/**
	 * GPT-Realtime-Whisper voice provider.
	 */
	class WP_MCP_AI_OpenAI_Realtime_Whisper_Client implements WP_MCP_AI_Voice_Provider {

		/**
		 * Default model.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		const DEFAULT_MODEL = 'gpt-realtime-whisper';

		/**
		 * Default latency delay in seconds.
		 *
		 * Lower values produce earlier partial text.
		 * Higher values can improve transcript quality.
		 *
		 * @since 1.3.0
		 * @var float
		 */
		const DEFAULT_LATENCY_DELAY = 1.0;

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
			return 'openai_realtime_whisper';
		}

		/**
		 * Get the human-readable provider name.
		 *
		 * @return string
		 */
		public function get_name() {
			return __( 'OpenAI Realtime Whisper', 'mcp-ai-wpoos' );
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
				&& isset( $settings['voice_realtime_provider'] ) && 'openai_whisper' === $settings['voice_realtime_provider'];

			return (bool) apply_filters( 'wp_mcp_ai_openai_whisper_available', $enabled, ! empty( $api_key ) );
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
		 * Get available voices (not applicable for transcription).
		 *
		 * @return array
		 */
		public function get_available_voices() {
			return array();
		}

		/**
		 * Get default voice (not applicable for transcription).
		 *
		 * @return string
		 */
		public function get_default_voice() {
			return '';
		}

		/**
		 * Get the default latency delay from settings or constant.
		 *
		 * @since 1.3.0
		 * @return float
		 */
		public function get_default_latency_delay() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( isset( $settings['realtime_whisper_latency_delay'] ) && is_numeric( $settings['realtime_whisper_latency_delay'] ) ) {
				return (float) $settings['realtime_whisper_latency_delay'];
			}

			return self::DEFAULT_LATENCY_DELAY;
		}

		/**
		 * Create a transcription session.
		 *
		 * Returns the session config the frontend needs to establish
		 * a WebRTC or WebSocket connection for streaming transcription.
		 *
		 * @since 1.3.0
		 *
		 * @param int   $assistant_id The assistant ID (informational).
		 * @param array $options      Optional overrides (latency_delay).
		 * @return array|WP_Error
		 */
		public function create_session( $assistant_id, $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_whisper_no_key',
					__( 'OpenAI API key is not configured.', 'mcp-ai-wpoos' )
				);
			}

			$latency_delay = isset( $options['latency_delay'] ) && is_numeric( $options['latency_delay'] )
				? (float) $options['latency_delay']
				: $this->get_default_latency_delay();

			return array(
				'type'           => 'openai_whisper',
				'transport_mode' => 'realtime',
				'model'          => self::DEFAULT_MODEL,
				'latency_delay'  => $latency_delay,
			);
		}
	}
}
