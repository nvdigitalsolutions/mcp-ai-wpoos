<?php
/**
 * OpenAI Realtime API Voice Client for NV oOS.
 *
 * Implements the WP_MCP_AI_Voice_Provider interface for OpenAI's Realtime API.
 * Generates ephemeral session tokens server-side so the API key never reaches
 * the browser. The frontend connects directly to OpenAI's WebSocket endpoint
 * using the short-lived session token.
 *
 * Realtime API reference: https://platform.openai.com/docs/guides/realtime
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

if ( ! class_exists( 'WP_MCP_AI_OpenAI_Realtime_Client' ) ) {
	/**
	 * OpenAI Realtime API client for voice sessions.
	 */
	class WP_MCP_AI_OpenAI_Realtime_Client implements WP_MCP_AI_Voice_Provider {

		/**
		 * OpenAI Realtime session creation endpoint.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const SESSION_ENDPOINT = 'https://api.openai.com/v1/realtime/sessions';

		/**
		 * OpenAI Realtime WebSocket base URL.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const WEBSOCKET_BASE = 'wss://api.openai.com/v1/realtime';

		/**
		 * Default realtime model.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const DEFAULT_MODEL = 'gpt-realtime';

		/**
		 * Default voice preset.
		 *
		 * OpenAI docs recommend 'marin' or 'cedar' for best assistant voice quality.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const DEFAULT_VOICE = 'marin';

		/**
		 * Available voice presets from OpenAI.
		 *
		 * @since 1.2.0
		 * @var array
		 */
		const AVAILABLE_VOICES = array(
			'alloy'   => 'Alloy (neutral, balanced)',
			'ash'     => 'Ash (warm, measured)',
			'ballad'  => 'Ballad (emotional, musical)',
			'coral'   => 'Coral (bright, crisp)',
			'echo'    => 'Echo (deep, resonant)',
			'fable'   => 'Fable (expressive, British)',
			'marin'   => 'Marin (warm, engaging) ⭐ recommended',
			'nova'    => 'Nova (calm, steady)',
			'onyx'    => 'Onyx (authoritative, deep)',
			'sage'    => 'Sage (gentle, wise)',
			'shimmer' => 'Shimmer (clear, friendly)',
			'verse'   => 'Verse (poetic, rhythmic)',
		);

		/**
		 * Session token cache TTL in seconds (50 seconds — well under the 60s token lifetime).
		 *
		 * @since 1.2.0
		 * @var int
		 */
		const CACHE_TTL = 50;

		/**
		 * Retrieve the configured OpenAI API key.
		 *
		 * @return string
		 */
		public function get_api_key() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
		}

		/**
		 * Get the unique provider slug.
		 *
		 * @return string
		 */
		public function get_slug() {
			return 'openai_realtime';
		}

		/**
		 * Get the human-readable provider name.
		 *
		 * @return string
		 */
		public function get_name() {
			return __( 'OpenAI Realtime', 'mcp-ai-wpoos' );
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

			// Check if realtime voice is explicitly enabled in settings.
			$enabled = isset( $settings['voice_mode'] ) && 'realtime' === $settings['voice_mode']
				&& isset( $settings['voice_realtime_provider'] ) && 'openai' === $settings['voice_realtime_provider'];

			/**
			 * Filter whether OpenAI Realtime voice is available.
			 *
			 * @since 1.2.0
			 *
			 * @param bool   $available Whether the provider is available.
			 * @param string $api_key   The configured API key (masked for logging).
			 */
			return (bool) apply_filters( 'wp_mcp_ai_openai_realtime_available', $enabled, ! empty( $api_key ) );
		}

		/**
		 * Get the recommended voice model.
		 *
		 * @return string
		 */
		public function get_default_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['openai_realtime_model'] ) && ! empty( $settings['openai_realtime_model'] )
				? sanitize_text_field( $settings['openai_realtime_model'] )
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

			return isset( $settings['openai_realtime_voice'] ) && ! empty( $settings['openai_realtime_voice'] )
				? sanitize_text_field( $settings['openai_realtime_voice'] )
				: self::DEFAULT_VOICE;
		}

		/**
		 * Resolve the OpenAI API base URL respecting custom base URL configuration.
		 *
		 * @param string $default_url The default endpoint URL.
		 * @return string Resolved endpoint URL.
		 */
		protected function resolve_endpoint( $default_url ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$base_url = isset( $settings['openai_base_url'] ) ? trim( $settings['openai_base_url'] ) : '';

			if ( '' === $base_url ) {
				return $default_url;
			}

			$path = str_replace( 'https://api.openai.com/v1', '', $default_url );

			return untrailingslashit( $base_url ) . $path;
		}

		/**
		 * Build HTTP request headers for the session creation request.
		 *
		 * @param string $api_key The API key.
		 * @return array
		 */
		protected function build_request_headers( $api_key ) {
			$headers = array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			);

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( ! empty( $settings['openai_organization_id'] ) ) {
				$headers['OpenAI-Organization'] = sanitize_text_field( $settings['openai_organization_id'] );
			}

			if ( ! empty( $settings['openai_project_id'] ) ) {
				$headers['OpenAI-Project'] = sanitize_text_field( $settings['openai_project_id'] );
			}

			/**
			 * Filter the OpenAI Realtime session request headers.
			 *
			 * @since 1.2.0
			 *
			 * @param array  $headers  Request headers.
			 * @param string $api_key  The API key.
			 */
			return (array) apply_filters( 'wp_mcp_ai_openai_realtime_request_headers', $headers, $api_key );
		}

		/**
		 * Create a realtime session for the frontend to connect to.
		 *
		 * Generates an ephemeral session token via OpenAI's REST API.
		 * The token is short-lived (~60 seconds) and scoped to this session.
		 *
		 * @param int   $assistant_id The assistant ID.
		 * @param array $options      Optional overrides.
		 * @return array|WP_Error
		 */
		public function create_session( $assistant_id, $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_realtime_no_key',
					__( 'OpenAI API key is not configured.', 'mcp-ai-wpoos' )
				);
			}

			$assistant_id = absint( $assistant_id );
			if ( $assistant_id <= 0 ) {
				return new WP_Error(
					'wp_mcp_ai_realtime_invalid_assistant',
					__( 'Invalid assistant ID.', 'mcp-ai-wpoos' )
				);
			}

			// Generate a cache key for this session.
			$cache_key = 'wp_mcp_ai_realtime_session_' . $assistant_id . '_' . get_current_user_id();

			// Return cached session if still valid.
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['client_secret']['value'] ) ) {
				return $cached;
			}

			// Resolve model and voice.
			$model = isset( $options['model'] ) && ! empty( $options['model'] )
				? sanitize_text_field( $options['model'] )
				: $this->get_default_model();

			$voice = isset( $options['voice'] ) && ! empty( $options['voice'] )
				? sanitize_text_field( $options['voice'] )
				: $this->get_default_voice();

			// Build system instructions from assistant config.
			$instructions = $this->get_assistant_instructions( $assistant_id );

			// Build tool definitions for function calling over the realtime channel.
			$tools = $this->get_assistant_tools_for_realtime( $assistant_id );

			// VAD/turn detection configuration.
			$vad_config = $this->get_vad_config();

			// Build the session creation payload.
			$payload = array(
				'model'               => $model,
				'voice'               => $voice,
				'instructions'        => $instructions,
				'modalities'          => array( 'text', 'audio' ),
				'input_audio_format'  => 'pcm16',
				'output_audio_format' => 'pcm16',
				'temperature'         => 0.8,
			);

			// Add turn detection (VAD) config.
			if ( ! empty( $vad_config ) ) {
				$payload['turn_detection'] = $vad_config;
			}

			// Add tools if available.
			if ( ! empty( $tools ) ) {
				$payload['tools'] = $tools;
			}

			/**
			 * Filter the OpenAI Realtime session creation payload.
			 *
			 * @since 1.2.0
			 *
			 * @param array $payload      Session creation payload.
			 * @param int   $assistant_id The assistant ID.
			 * @param array $options      Requested options.
			 */
			$payload = apply_filters( 'wp_mcp_ai_openai_realtime_session_payload', $payload, $assistant_id, $options );

			// Make the API request.
			$endpoint = $this->resolve_endpoint( self::SESSION_ENDPOINT );

			$response = wp_remote_post(
				$endpoint,
				array(
					'headers' => $this->build_request_headers( $api_key ),
					'body'    => wp_json_encode( $payload ),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );
			$data        = json_decode( $body, true );

			if ( 200 !== $status_code || ! is_array( $data ) ) {
				$error_message = isset( $data['error']['message'] )
					? $data['error']['message']
					: sprintf(
						/* translators: %d: HTTP status code */
						__( 'OpenAI Realtime session creation failed (HTTP %d).', 'mcp-ai-wpoos' ),
						$status_code
					);

				return new WP_Error( 'wp_mcp_ai_realtime_session_failed', $error_message );
			}

			// The session response includes client_secret with the ephemeral token.
			// We return the full config the frontend needs.
			$session_config = array(
				'type'           => 'openai_realtime',
				'transport_mode' => 'realtime',
				'model'          => $model,
				'voice'          => $voice,
				'endpoint'       => self::WEBSOCKET_BASE,
				'client_secret'  => isset( $data['client_secret'] ) ? $data['client_secret'] : null,
				'session_id'     => isset( $data['id'] ) ? $data['id'] : '',
				'expires_at'     => isset( $data['expires_at'] ) ? $data['expires_at'] : null,
			);

			// Cache the session config.
			set_transient( $cache_key, $session_config, self::CACHE_TTL );

			return $session_config;
		}

		/**
		 * Get assistant system instructions for the voice session.
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
				// Fall back to post content.
				$post = get_post( $assistant_id );
				if ( $post && 'mcp_ai_assistant' === $post->post_type ) {
					$instructions = wp_strip_all_tags( $post->post_content );
				}
			}

			// Add voice-specific preamble.
			$voice_prefix = __( 'You are a voice assistant. Keep responses concise and conversational. Use natural spoken language, not markdown.', 'mcp-ai-wpoos' );

			if ( ! empty( $instructions ) ) {
				$instructions = $voice_prefix . "\n\n" . $instructions;
			} else {
				$instructions = $voice_prefix;
			}

			/**
			 * Filter the voice session instructions.
			 *
			 * @since 1.2.0
			 *
			 * @param string $instructions The system instructions.
			 * @param int    $assistant_id The assistant ID.
			 */
			return (string) apply_filters( 'wp_mcp_ai_openai_realtime_instructions', $instructions, $assistant_id );
		}

		/**
		 * Get assistant tool definitions formatted for the Realtime API.
		 *
		 * @param int $assistant_id The assistant ID.
		 * @return array
		 */
		protected function get_assistant_tools_for_realtime( $assistant_id ) {
			$tools = array();

			if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				return $tools;
			}

			// Get enabled tools for this assistant.
			$registry      = WP_MCP_AI_Tool_Registry::get_instance();
			$enabled_tools = array();

			if ( method_exists( $registry, 'get_tools_for_assistant' ) ) {
				$enabled_tools = $registry->get_tools_for_assistant( $assistant_id );
			} elseif ( method_exists( $registry, 'get_tools' ) ) {
				// Fallback: get all tools and filter by assistant capability.
				$all_tools = $registry->get_tools();
				foreach ( $all_tools as $tool_slug => $tool ) {
					$enabled_tools[ $tool_slug ] = $tool;
				}
			}

			// Format tools for OpenAI Realtime API function calling.
			foreach ( $enabled_tools as $tool_slug => $tool ) {
				if ( ! is_object( $tool ) ) {
					continue;
				}

				$definition = array();
				if ( method_exists( $tool, 'get_definition' ) ) {
					$definition = $tool->get_definition();
				}

				if ( empty( $definition ) ) {
					continue;
				}

				$realtime_tool = array(
					'type'        => 'function',
					'name'        => $tool_slug,
					'description' => isset( $definition['description'] )
						? wp_strip_all_tags( $definition['description'] )
						: '',
				);

				if ( isset( $definition['parameters'] ) ) {
					$realtime_tool['parameters'] = $definition['parameters'];
				}

				$tools[] = $realtime_tool;
			}

			// Limit to 128 tools (OpenAI Realtime API limit).
			$tools = array_slice( $tools, 0, 128 );

			return $tools;
		}

		/**
		 * Get VAD (Voice Activity Detection) configuration for turn detection.
		 *
		 * @return array
		 */
		protected function get_vad_config() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			$vad_enabled = isset( $settings['enable_voice_activity_detection'] )
				? (bool) $settings['enable_voice_activity_detection']
				: true;

			if ( ! $vad_enabled ) {
				return array(
					'type' => 'server_vad',
				);
			}

			$silence_threshold = isset( $settings['vad_silence_threshold'] )
				? absint( $settings['vad_silence_threshold'] )
				: 700;

			$prefix_padding = isset( $settings['vad_prefix_padding_ms'] )
				? absint( $settings['vad_prefix_padding_ms'] )
				: 300;

			return array(
				'type'                => 'server_vad',
				'threshold'           => 0.5,
				'prefix_padding_ms'   => $prefix_padding,
				'silence_duration_ms' => $silence_threshold,
			);
		}
	}
}
