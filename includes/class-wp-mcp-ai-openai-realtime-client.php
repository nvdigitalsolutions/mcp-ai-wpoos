<?php
/**
 * OpenAI Realtime API Voice Client for NV oOS.
 *
 * Implements the WP_MCP_AI_Voice_Provider interface for OpenAI's Realtime API.
 * Uses the GA (Generally Available) Realtime API endpoints and session format
 * as of May 2026. Generates ephemeral tokens server-side for WebRTC connections
 * and supports the unified WebRTC interface via SDP relay.
 *
 * Realtime API reference: https://platform.openai.com/docs/guides/realtime
 * WebRTC connection guide: https://platform.openai.com/docs/guides/realtime-webrtc
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @since   1.3.0 Migrated from beta to GA Realtime API: nested session format,
 *               WebRTC ephemeral token support, Safety-Identifier header,
 *               reasoning effort control, updated voice list, gpt-realtime-2 default.
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
		 * OpenAI Realtime ephemeral token creation endpoint (GA).
		 *
		 * Used for WebRTC browser connections. The browser uses the returned
		 * ephemeral key to establish a peer connection directly with OpenAI.
		 *
		 * @since 1.2.0
		 * @since 1.3.0 Changed from beta `/v1/realtime/sessions` to GA `/v1/realtime/client_secrets`.
		 * @var string
		 */
		const CLIENT_SECRETS_ENDPOINT = 'https://api.openai.com/v1/realtime/client_secrets';

		/**
		 * OpenAI Realtime WebRTC unified interface endpoint (GA).
		 *
		 * Server-side SDP relay: the server authenticates with its API key,
		 * forwards the browser's SDP offer, and returns the SDP answer.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		const CALLS_ENDPOINT = 'https://api.openai.com/v1/realtime/calls';

		/**
		 * OpenAI Realtime WebSocket base URL (GA — kept as fallback).
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const WEBSOCKET_BASE = 'wss://api.openai.com/v1/realtime';

		/**
		 * Default realtime model.
		 *
		 * @since 1.2.0
		 * @since 1.3.0 Changed from 'gpt-realtime' to 'gpt-realtime-2'.
		 * @var string
		 */
		const DEFAULT_MODEL = 'gpt-realtime-2';

		/**
		 * Supported realtime models.
		 *
		 * @since 1.3.0
		 * @var array
		 */
		const SUPPORTED_MODELS = array(
			'gpt-realtime-2'   => 'GPT-Realtime-2 (reasoning voice, recommended)',
			'gpt-realtime-1.5' => 'GPT-Realtime-1.5 (non-reasoning, fast)',
		);

		/**
		 * Available reasoning effort levels for gpt-realtime-2.
		 *
		 * @since 1.3.0
		 * @var array
		 */
		const REASONING_EFFORTS = array(
			'minimal' => 'Minimal (fastest, simple commands)',
			'low'     => 'Low (responsive + basic reasoning — recommended)',
			'medium'  => 'Medium (multi-step tasks)',
			'high'    => 'High (complex workflows)',
			'xhigh'   => 'XHigh (maximum reasoning, highest latency)',
		);

		/**
		 * Default reasoning effort.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		const DEFAULT_REASONING_EFFORT = 'low';

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
		 * Available voice presets from OpenAI (GA voice list).
		 *
		 * Removed deprecated voices: fable, nova, onyx.
		 * Added new voice: cedar.
		 *
		 * @since 1.2.0
		 * @since 1.3.0 Updated for GA: removed deprecated, added cedar.
		 * @var array
		 */
		const AVAILABLE_VOICES = array(
			'alloy'   => 'Alloy (neutral, balanced)',
			'ash'     => 'Ash (warm, measured)',
			'ballad'  => 'Ballad (emotional, musical)',
			'cedar'   => 'Cedar (natural, warm) ⭐ recommended',
			'coral'   => 'Coral (bright, crisp)',
			'echo'    => 'Echo (deep, resonant)',
			'marin'   => 'Marin (warm, engaging) ⭐ recommended',
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
		 * Get available reasoning effort levels.
		 *
		 * @since 1.3.0
		 * @return array
		 */
		public function get_reasoning_efforts() {
			return self::REASONING_EFFORTS;
		}

		/**
		 * Get the default reasoning effort.
		 *
		 * @since 1.3.0
		 * @return string
		 */
		public function get_default_reasoning_effort() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['realtime_reasoning_effort'] ) && ! empty( $settings['realtime_reasoning_effort'] )
				? sanitize_text_field( $settings['realtime_reasoning_effort'] )
				: self::DEFAULT_REASONING_EFFORT;
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
		 * Build a safety identifier for the current user.
		 *
		 * Uses a hashed user ID for stable, privacy-preserving identification.
		 * Required by OpenAI for abuse monitoring on Realtime API sessions.
		 *
		 * @since 1.3.0
		 * @return string Hashed user identifier.
		 */
		protected function build_safety_identifier() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			$enabled = isset( $settings['realtime_safety_identifier_enabled'] )
				? (bool) $settings['realtime_safety_identifier_enabled']
				: false;

			if ( ! $enabled ) {
				return '';
			}

			$user_id = get_current_user_id();
			if ( 0 === $user_id ) {
				return '';
			}

			return wp_hash( 'wp_mcp_ai_safety_' . $user_id );
		}

		/**
		 * Build HTTP request headers for API requests.
		 *
		 * @since 1.2.0
		 * @since 1.3.0 Added Safety-Identifier header; removed deprecated OpenAI-Beta header.
		 *
		 * @param string $api_key The API key.
		 * @return array
		 */
		protected function build_request_headers( $api_key ) {
			$headers = array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			);

			$safety_id = $this->build_safety_identifier();
			if ( '' !== $safety_id ) {
				$headers['OpenAI-Safety-Identifier'] = $safety_id;
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( ! empty( $settings['openai_organization_id'] ) ) {
				$headers['OpenAI-Organization'] = sanitize_text_field( $settings['openai_organization_id'] );
			}

			if ( ! empty( $settings['openai_project_id'] ) ) {
				$headers['OpenAI-Project'] = sanitize_text_field( $settings['openai_project_id'] );
			}

			/**
			 * Filter the OpenAI Realtime request headers.
			 *
			 * @since 1.2.0
			 *
			 * @param array  $headers  Request headers.
			 * @param string $api_key  The API key.
			 */
			return (array) apply_filters( 'wp_mcp_ai_openai_realtime_request_headers', $headers, $api_key );
		}

		/**
		 * Resolve the model from options or default.
		 *
		 * @since 1.3.0
		 *
		 * @param array $options Requested options.
		 * @return string Model identifier.
		 */
		protected function resolve_model( $options ) {
			if ( isset( $options['model'] ) && ! empty( $options['model'] ) ) {
				return sanitize_text_field( $options['model'] );
			}

			return $this->get_default_model();
		}

		/**
		 * Resolve the voice from options or default.
		 *
		 * @since 1.3.0
		 *
		 * @param array $options Requested options.
		 * @return string Voice name.
		 */
		protected function resolve_voice( $options ) {
			if ( isset( $options['voice'] ) && ! empty( $options['voice'] ) ) {
				return sanitize_text_field( $options['voice'] );
			}

			return $this->get_default_voice();
		}

		/**
		 * Resolve the reasoning effort from options or default.
		 *
		 * @since 1.3.0
		 *
		 * @param array $options Requested options.
		 * @return string Reasoning effort level.
		 */
		protected function resolve_reasoning_effort( $options ) {
			if ( isset( $options['reasoning_effort'] ) && ! empty( $options['reasoning_effort'] ) ) {
				$effort = sanitize_text_field( $options['reasoning_effort'] );

				if ( array_key_exists( $effort, self::REASONING_EFFORTS ) ) {
					return $effort;
				}
			}

			return $this->get_default_reasoning_effort();
		}

		/**
		 * Build the GA session payload for token minting.
		 *
		 * Uses the nested session format required by the GA Realtime API:
		 * - session.type, session.model, session.output_modalities
		 * - session.audio.input.format, session.audio.input.turn_detection
		 * - session.audio.output.format, session.audio.output.voice
		 * - session.reasoning.effort (for gpt-realtime-2)
		 * - session.instructions, session.tools
		 *
		 * @since 1.3.0
		 *
		 * @param int   $assistant_id The assistant ID.
		 * @param array $options      Optional overrides.
		 * @return array GA session payload.
		 */
		protected function build_session_payload( $assistant_id, $options ) {
			$model     = $this->resolve_model( $options );
			$voice     = $this->resolve_voice( $options );
			$reasoning = $this->resolve_reasoning_effort( $options );
			$vad       = $this->get_vad_config();
			$tools     = $this->get_assistant_tools_for_realtime( $assistant_id );

			$session = array(
				'type'                => 'realtime',
				'model'               => $model,
				'output_modalities'   => array( 'audio', 'text' ),
				'audio'               => array(
					'input'  => array(
						'format'         => array(
							'type' => 'audio/pcm',
							'rate' => 24000,
						),
						'turn_detection' => $vad,
					),
					'output' => array(
						'format' => array( 'type' => 'audio/pcm' ),
						'voice'  => $voice,
					),
				),
				'instructions'        => $this->get_assistant_instructions( $assistant_id ),
				'tools'               => ! empty( $tools ) ? $tools : array(),
				'tool_choice'         => 'auto',
				'parallel_tool_calls' => true,
				'temperature'         => 0.8,
			);

			// Add reasoning configuration for gpt-realtime-2.
			if ( 'gpt-realtime-2' === $model ) {
				$session['reasoning'] = array( 'effort' => $reasoning );
			}

			/**
			 * Filter the GA realtime session configuration.
			 *
			 * @since 1.3.0
			 *
			 * @param array $session      The session configuration array.
			 * @param int   $assistant_id The assistant ID.
			 * @param array $options      Requested options.
			 */
			$session = apply_filters( 'wp_mcp_ai_openai_realtime_ga_session_config', $session, $assistant_id, $options );

			return array( 'session' => $session );
		}

		/**
		 * Create an ephemeral token for WebRTC browser connections.
		 *
		 * Calls the GA endpoint POST /v1/realtime/client_secrets to mint a
		 * short-lived (~60s) ephemeral key. The browser uses this key to
		 * establish a direct WebRTC peer connection with OpenAI.
		 *
		 * @since 1.3.0
		 *
		 * @param int   $assistant_id The assistant ID.
		 * @param array $options      Optional overrides (model, voice, reasoning_effort).
		 * @return array|WP_Error Ephemeral token data or error.
		 */
		public function create_ephemeral_token( $assistant_id, $options = array() ) {
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

			// Cache key for ephemeral tokens.
			$cache_key = 'wp_mcp_ai_realtime_ephemeral_' . $assistant_id . '_' . get_current_user_id();

			// Return cached token if still valid.
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['client_secret']['value'] ) ) {
				return $cached;
			}

			$payload = $this->build_session_payload( $assistant_id, $options );

			$endpoint = $this->resolve_endpoint( self::CLIENT_SECRETS_ENDPOINT );

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
						__( 'OpenAI Realtime ephemeral token creation failed (HTTP %d).', 'mcp-ai-wpoos' ),
						$status_code
					);

				return new WP_Error( 'wp_mcp_ai_realtime_token_failed', $error_message );
			}

			$model     = $this->resolve_model( $options );
			$voice     = $this->resolve_voice( $options );
			$reasoning = $this->resolve_reasoning_effort( $options );

			$token_data = array(
				'type'              => 'openai_realtime',
				'transport_mode'    => 'realtime',
				'connection_method' => 'webrtc_ephemeral',
				'model'             => $model,
				'voice'             => $voice,
				'reasoning_effort'  => $reasoning,
				'client_secret'     => isset( $data['client_secret'] ) ? $data['client_secret'] : null,
				'session_id'        => isset( $data['id'] ) ? $data['id'] : '',
				'expires_at'        => isset( $data['expires_at'] ) ? $data['expires_at'] : null,
			);

			// Cache the token data.
			set_transient( $cache_key, $token_data, self::CACHE_TTL );

			return $token_data;
		}

		/**
		 * Create a unified WebRTC session via SDP relay.
		 *
		 * The server authenticates with its API key and relays the browser's
		 * SDP offer to OpenAI's /v1/realtime/calls endpoint. Returns the SDP
		 * answer for the browser to set as its remote description.
		 *
		 * @since 1.3.0
		 *
		 * @param string $sdp_offer    The SDP offer from the browser.
		 * @param int    $assistant_id The assistant ID.
		 * @param array  $options      Optional overrides.
		 * @return string|WP_Error SDP answer or error.
		 */
		public function create_unified_session( $sdp_offer, $assistant_id, $options = array() ) {
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

			$session_config = wp_json_encode( $this->build_session_payload( $assistant_id, $options ) );

			$endpoint = $this->resolve_endpoint( self::CALLS_ENDPOINT );

			// Build multipart form data manually.
			$boundary = wp_generate_password( 24, false );
			$body     = '';

			// SDP offer part.
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="sdp"' . "\r\n";
			$body .= 'Content-Type: application/sdp' . "\r\n\r\n";
			$body .= $sdp_offer . "\r\n";

			// Session config part.
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="session"' . "\r\n";
			$body .= 'Content-Type: application/json' . "\r\n\r\n";
			$body .= $session_config . "\r\n";
			$body .= '--' . $boundary . '--';

			$headers = array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			);

			$safety_id = $this->build_safety_identifier();
			if ( '' !== $safety_id ) {
				$headers['OpenAI-Safety-Identifier'] = $safety_id;
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( ! empty( $settings['openai_organization_id'] ) ) {
				$headers['OpenAI-Organization'] = sanitize_text_field( $settings['openai_organization_id'] );
			}
			if ( ! empty( $settings['openai_project_id'] ) ) {
				$headers['OpenAI-Project'] = sanitize_text_field( $settings['openai_project_id'] );
			}

			$response = wp_remote_post(
				$endpoint,
				array(
					'headers' => $headers,
					'body'    => $body,
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( 200 !== $status_code ) {
				$data          = json_decode( $body, true );
				$error_message = isset( $data['error']['message'] )
					? $data['error']['message']
					: sprintf(
						/* translators: %d: HTTP status code */
						__( 'OpenAI Realtime session creation failed (HTTP %d).', 'mcp-ai-wpoos' ),
						$status_code
					);

				return new WP_Error( 'wp_mcp_ai_realtime_session_failed', $error_message );
			}

			return $body;
		}

		/**
		 * Create a realtime session for the frontend to connect to.
		 *
		 * Maintains backward compatibility with the existing WebSocket flow.
		 * For WebRTC connections, use create_ephemeral_token() or create_unified_session().
		 *
		 * @since 1.2.0
		 * @since 1.3.0 Delegates to create_ephemeral_token() for token minting;
		 *              keeps WebSocket endpoint for backward compatibility.
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

			// Determine connection method.
			$connection_method = isset( $options['connection_method'] )
				? sanitize_text_field( $options['connection_method'] )
				: 'webrtc_ephemeral';

			// Default to ephemeral token flow for WebRTC.
			if ( 'webrtc_unified' === $connection_method && isset( $options['sdp_offer'] ) ) {
				$sdp_answer = $this->create_unified_session( $options['sdp_offer'], $assistant_id, $options );

				if ( is_wp_error( $sdp_answer ) ) {
					return $sdp_answer;
				}

				return array(
					'type'              => 'openai_realtime',
					'transport_mode'    => 'realtime',
					'connection_method' => 'webrtc_unified',
					'sdp_answer'        => $sdp_answer,
				);
			}

			// Default: return ephemeral token for WebRTC.
			return $this->create_ephemeral_token( $assistant_id, $options );
		}

		/**
		 * Get assistant system instructions for the voice session.
		 *
		 * Uses a structured prompt template aligned with OpenAI's recommended
		 * Realtime 2.0 prompting guide. Individual sections can be filtered
		 * via wp_mcp_ai_realtime_prompt_{section} hooks.
		 *
		 * @since 1.2.0
		 * @since 1.3.0 Replaced flat prompt with structured labeled-section template.
		 *
		 * @param int $assistant_id The assistant ID.
		 * @return string
		 */
		protected function get_assistant_instructions( $assistant_id ) {
			$base_instructions = '';

			if ( function_exists( 'wp_mcp_ai_get_assistant_prompt' ) ) {
				$base_instructions = wp_mcp_ai_get_assistant_prompt( $assistant_id );
			}

			if ( empty( $base_instructions ) ) {
				$post = get_post( $assistant_id );
				if ( $post && 'mcp_ai_assistant' === $post->post_type ) {
					$base_instructions = wp_strip_all_tags( $post->post_content );
				}
			}

			$role_section = __( 'You are a helpful voice assistant integrated into a WordPress site. Keep responses concise and conversational. Use natural spoken language, not markdown.', 'mcp-ai-wpoos' );

			if ( ! empty( $base_instructions ) ) {
				$role_section = $base_instructions;
			}

			/**
			 * Filter the role/objective section of the realtime prompt.
			 *
			 * @since 1.3.0
			 *
			 * @param string $role         Role description.
			 * @param int    $assistant_id The assistant ID.
			 */
			$role_section = apply_filters( 'wp_mcp_ai_realtime_prompt_role', $role_section, $assistant_id );

			$tone_section = __( '- Friendly, calm, and approachable.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- Warm, concise, confident — never fawning.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- 2–3 sentences per turn for direct answers.', 'mcp-ai-wpoos' );

			/**
			 * Filter the personality/tone section.
			 *
			 * @since 1.3.0
			 */
			$tone_section = apply_filters( 'wp_mcp_ai_realtime_prompt_tone', $tone_section, $assistant_id );

			$language_section = __( '- Default to the site language. Do not infer language from accent alone. Only switch languages if the user explicitly asks.', 'mcp-ai-wpoos' );

			/**
			 * Filter the language section.
			 *
			 * @since 1.3.0
			 */
			$language_section = apply_filters( 'wp_mcp_ai_realtime_prompt_language', $language_section, $assistant_id );

			$reasoning_section = __( '- For direct answers and simple lookups, respond quickly without extended reasoning.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- For multi-step tasks, tool decisions, or troubleshooting, reason before acting.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- Do not reason when audio is unclear — ask for clarification instead.', 'mcp-ai-wpoos' );

			/**
			 * Filter the reasoning section.
			 *
			 * @since 1.3.0
			 */
			$reasoning_section = apply_filters( 'wp_mcp_ai_realtime_prompt_reasoning', $reasoning_section, $assistant_id );

			$channels_section = __( '- Use the commentary channel for short preambles and tool-call progress updates.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- Use the final channel for the user-facing response.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- Keep commentary brief: one sentence maximum.', 'mcp-ai-wpoos' );

			/**
			 * Filter the message channels section.
			 *
			 * @since 1.3.0
			 */
			$channels_section = apply_filters( 'wp_mcp_ai_realtime_prompt_channels', $channels_section, $assistant_id );

			$preambles_section = __( '- Use short preambles when about to call a tool that may take noticeable time.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- Keep preambles natural and concise. Vary wording across turns.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- Do not use preambles for direct answers, corrections, or unclear audio.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- Preferred: "I\'ll check that now." "Let me look that up."', 'mcp-ai-wpoos' );

			/**
			 * Filter the preambles section.
			 *
			 * @since 1.3.0
			 */
			$preambles_section = apply_filters( 'wp_mcp_ai_realtime_prompt_preambles', $preambles_section, $assistant_id );

			$verbosity_section = __( '- Direct answers: 1–2 short sentences.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- Tool results: Summarize result first, then give next action.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- Troubleshooting: One step at a time unless user asks for the full procedure.', 'mcp-ai-wpoos' );

			/**
			 * Filter the verbosity section.
			 *
			 * @since 1.3.0
			 */
			$verbosity_section = apply_filters( 'wp_mcp_ai_realtime_prompt_verbosity', $verbosity_section, $assistant_id );

			$tools_section = __( '- Use only tools explicitly provided in the current tool list.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- For read-only tools: call when intent and required fields are clear.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- For write tools: summarize intended action and ask for confirmation.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- For exact identifiers: confirm digit-by-digit before calling tools.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- If a tool fails: briefly explain in user-friendly language and give a next step.', 'mcp-ai-wpoos' );

			/**
			 * Filter the tools section.
			 *
			 * @since 1.3.0
			 */
			$tools_section = apply_filters( 'wp_mcp_ai_realtime_prompt_tools', $tools_section, $assistant_id );

			$unclear_section = __( '- Only respond to clear audio or text.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- If audio is unclear, ask: "Sorry, could you repeat that?"', 'mcp-ai-wpoos' ) . "\n" .
				__( '- Do not guess, reason, call tools, or generate preambles on unclear audio.', 'mcp-ai-wpoos' );

			/**
			 * Filter the unclear audio section.
			 *
			 * @since 1.3.0
			 */
			$unclear_section = apply_filters( 'wp_mcp_ai_realtime_prompt_unclear_audio', $unclear_section, $assistant_id );

			$entity_section = __( '- Collect one value at a time.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- Convert clearly spoken digits into numeric values.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- Confirm high-precision identifiers digit-by-digit before tool calls.', 'mcp-ai-wpoos' );

			/**
			 * Filter the entity capture section.
			 *
			 * @since 1.3.0
			 */
			$entity_section = apply_filters( 'wp_mcp_ai_realtime_prompt_entity_capture', $entity_section, $assistant_id );

			$long_context_section = __( '- Use the most recent information for decisions.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- Distinguish current state from historical background.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- When sources conflict, prefer the most recently retrieved source.', 'mcp-ai-wpoos' );

			/**
			 * Filter the long context section.
			 *
			 * @since 1.3.0
			 */
			$long_context_section = apply_filters( 'wp_mcp_ai_realtime_prompt_long_context', $long_context_section, $assistant_id );

			$escalation_section = __( '- Escalate to human if: safety risk, user explicitly requests, repeated tool failures.', 'mcp-ai-wpoos' ) . "\n" .
				__( '- When escalating, say: "Let me connect you with someone who can help further."', 'mcp-ai-wpoos' );

			/**
			 * Filter the escalation section.
			 *
			 * @since 1.3.0
			 */
			$escalation_section = apply_filters( 'wp_mcp_ai_realtime_prompt_escalation', $escalation_section, $assistant_id );

			$instructions  = "# Role and Objective\n" . $role_section . "\n\n";
			$instructions .= "# Personality and Tone\n" . $tone_section . "\n\n";
			$instructions .= "# Language\n" . $language_section . "\n\n";
			$instructions .= "# Reasoning\n" . $reasoning_section . "\n\n";
			$instructions .= "# Message Channels\n" . $channels_section . "\n\n";
			$instructions .= "# Preambles\n" . $preambles_section . "\n\n";
			$instructions .= "# Verbosity\n" . $verbosity_section . "\n\n";
			$instructions .= "# Tools\n" . $tools_section . "\n\n";
			$instructions .= "# Unclear Audio\n" . $unclear_section . "\n\n";
			$instructions .= "# Entity Capture\n" . $entity_section . "\n\n";
			$instructions .= "# Long Context Behavior\n" . $long_context_section . "\n\n";
			$instructions .= "# Escalation\n" . $escalation_section;

			/**
			 * Filter the complete voice session instructions.
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
				// Fallback: get all tools.
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
		 * Returns semantic_vad configuration for GA sessions (preferred over
		 * server_vad for gpt-realtime-2).
		 *
		 * @since 1.2.0
		 * @since 1.3.0 Changed default VAD type to 'semantic_vad' for GA.
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
					'type' => 'semantic_vad',
				);
			}

			$silence_threshold = isset( $settings['vad_silence_threshold'] )
				? absint( $settings['vad_silence_threshold'] )
				: 700;

			$prefix_padding = isset( $settings['vad_prefix_padding_ms'] )
				? absint( $settings['vad_prefix_padding_ms'] )
				: 300;

			return array(
				'type'                => 'semantic_vad',
				'threshold'           => 0.5,
				'prefix_padding_ms'   => $prefix_padding,
				'silence_duration_ms' => $silence_threshold,
			);
		}
	}
}
