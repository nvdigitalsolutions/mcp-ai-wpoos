<?php
/**
 * Voice REST Controller for NV oOS.
 *
 * Provides REST endpoints for voice session management:
 * - GET  /voice/config        — Get voice configuration for current assistant
 * - POST /voice/session       — Create a realtime voice session
 * - GET  /voice/providers     — List available voice providers
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

if ( ! class_exists( 'WP_MCP_AI_REST_Voice_Controller' ) ) {
	/**
	 * Voice REST API controller.
	 */
	class WP_MCP_AI_REST_Voice_Controller {

		/**
		 * REST namespace.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const REST_NAMESPACE = 'mcp-ai/v1';

		/**
		 * Registered voice providers.
		 *
		 * @since 1.2.0
		 * @var array<string, WP_MCP_AI_Voice_Provider>
		 */
		protected $providers = array();

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		/**
		 * Register a voice provider.
		 *
		 * @param WP_MCP_AI_Voice_Provider $provider The provider instance.
		 */
		public function register_provider( $provider ) {
			if ( $provider instanceof WP_MCP_AI_Voice_Provider ) {
				$this->providers[ $provider->get_slug() ] = $provider;
			}
		}

		/**
		 * Get all registered voice providers.
		 *
		 * @return array<string, WP_MCP_AI_Voice_Provider>
		 */
		public function get_providers() {
			return $this->providers;
		}

		/**
		 * Get the currently active voice provider based on settings.
		 *
		 * @return WP_MCP_AI_Voice_Provider|null
		 */
		public function get_active_provider() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$mode     = isset( $settings['voice_mode'] ) ? $settings['voice_mode'] : 'chained';

			if ( 'realtime' === $mode ) {
				$provider_slug = isset( $settings['voice_realtime_provider'] )
					? $settings['voice_realtime_provider']
					: 'openai';

				// Map provider slugs to voice provider slugs.
				$map = array(
					'openai'           => 'openai_realtime',
					'openai_translate' => 'openai_realtime_translate',
					'openai_whisper'   => 'openai_realtime_whisper',
					'gemini'           => 'gemini_live',
				);

				$voice_slug = isset( $map[ $provider_slug ] ) ? $map[ $provider_slug ] : $provider_slug;

				if ( isset( $this->providers[ $voice_slug ] ) && $this->providers[ $voice_slug ]->is_available() ) {
					return $this->providers[ $voice_slug ];
				}
			}

			return null;
		}

		/**
		 * Register REST API routes.
		 */
		public function register_routes() {
			// GET /voice/config — Get voice configuration for the current assistant.
			register_rest_route(
				self::REST_NAMESPACE,
				'/voice/config',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_voice_config' ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);

			// GET /voice/providers — List available voice providers.
			register_rest_route(
				self::REST_NAMESPACE,
				'/voice/providers',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_voice_providers' ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);

			// POST /voice/session — Create a realtime voice session.
			register_rest_route(
				self::REST_NAMESPACE,
				'/voice/session',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_voice_session' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'assistant_id'      => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'provider'          => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'model'             => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'voice'             => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'reasoning_effort'  => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'enum'              => array( 'minimal', 'low', 'medium', 'high', 'xhigh' ),
						),
						'connection_method' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'enum'              => array( 'webrtc_ephemeral', 'webrtc_unified', 'websocket' ),
						),
					),
				)
			);

					// POST /realtime/token — Create ephemeral token for WebRTC.
					register_rest_route(
						self::REST_NAMESPACE,
						'/realtime/token',
						array(
							'methods'             => 'POST',
							'callback'            => array( $this, 'create_realtime_token' ),
							'permission_callback' => array( $this, 'check_permission' ),
							'args'                => array(
								'assistant_id'     => array(
									'required'          => true,
									'type'              => 'integer',
									'sanitize_callback' => 'absint',
								),
								'model'            => array(
									'required'          => false,
									'type'              => 'string',
									'sanitize_callback' => 'sanitize_text_field',
								),
								'voice'            => array(
									'required'          => false,
									'type'              => 'string',
									'sanitize_callback' => 'sanitize_text_field',
								),
								'reasoning_effort' => array(
									'required'          => false,
									'type'              => 'string',
									'sanitize_callback' => 'sanitize_text_field',
									'enum'              => array( 'minimal', 'low', 'medium', 'high', 'xhigh' ),
								),
							),
						)
					);

					// POST /realtime/session — SDP relay for unified WebRTC.
					register_rest_route(
						self::REST_NAMESPACE,
						'/realtime/session',
						array(
							'methods'             => 'POST',
							'callback'            => array( $this, 'create_realtime_session' ),
							'permission_callback' => array( $this, 'check_permission' ),
							'args'                => array(
								'assistant_id' => array(
									'required'          => true,
									'type'              => 'integer',
									'sanitize_callback' => 'absint',
								),
							),
						)
					);
		}

		/**
		 * Permission callback for voice endpoints.
		 *
		 * @param WP_REST_Request $request The request.
		 * @return bool|WP_Error
		 */
		public function check_permission( $request ) {
			// For GET requests, allow any authenticated user or guest with valid token.
			if ( 'GET' === $request->get_method() ) {
				// Check nonce.
				$nonce = $request->get_header( 'X-WP-Nonce' );
				if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
					return true;
				}

				// Allow if user is logged in.
				if ( is_user_logged_in() ) {
					return true;
				}

				// Allow if guest token is valid (handled by main REST auth).
				if ( class_exists( 'WP_MCP_AI_REST_Authenticator' ) ) {
					$authenticator = new WP_MCP_AI_REST_Authenticator();
					if ( method_exists( $authenticator, 'authenticate' ) ) {
						$result = $authenticator->authenticate( $request );
						if ( true === $result || ( is_wp_error( $result ) === false && $result ) ) {
							return true;
						}
					}
				}

				return new WP_Error(
					'rest_forbidden',
					__( 'Authentication required.', 'mcp-ai-wpoos' ),
					array( 'status' => 401 )
				);
			}

			// For POST requests, require nonce or logged-in user.
			if ( is_user_logged_in() ) {
				$nonce = $request->get_header( 'X-WP-Nonce' );
				if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
					return true;
				}

				// Allow for logged-in users with capability.
				if ( current_user_can( 'read' ) ) {
					return true;
				}
			}

			return new WP_Error(
				'rest_forbidden',
				__( 'Authentication required.', 'mcp-ai-wpoos' ),
				array( 'status' => 401 )
			);
		}

		/**
		 * GET /voice/config — Get voice configuration.
		 *
		 * @param WP_REST_Request $request The request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function get_voice_config( $request ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			$voice_mode = isset( $settings['voice_mode'] ) ? $settings['voice_mode'] : 'chained';

			// Build available modes with their status.
			$modes = array(
				'realtime' => array(
					'label'     => __( 'Realtime Speech-to-Speech', 'mcp-ai-wpoos' ),
					'available' => false,
					'providers' => array(),
				),
				'chained'  => array(
					'label'     => __( 'Chained Pipeline (STT → LLM → TTS)', 'mcp-ai-wpoos' ),
					'available' => true, // Chained is always available if speech tools enabled.
				),
				'browser'  => array(
					'label'     => __( 'Browser Speech API', 'mcp-ai-wpoos' ),
					'available' => true, // Browser API is client-side, always available.
				),
				'off'      => array(
					'label'     => __( 'Text Only', 'mcp-ai-wpoos' ),
					'available' => true,
				),
			);

			// Check realtime provider availability.
			foreach ( $this->providers as $slug => $provider ) {
				if ( $provider->is_available() ) {
					$provider_info = array(
						'slug'          => $slug,
						'name'          => $provider->get_name(),
						'model'         => $provider->get_default_model(),
						'voices'        => $provider->get_available_voices(),
						'default_voice' => $provider->get_default_voice(),
					);

					// Add reasoning efforts for OpenAI realtime provider.
					if ( method_exists( $provider, 'get_reasoning_efforts' ) ) {
						$provider_info['reasoning_efforts']        = $provider->get_reasoning_efforts();
						$provider_info['default_reasoning_effort'] = $provider->get_default_reasoning_effort();
					}

					// Add translation languages for translate provider.
					if ( method_exists( $provider, 'get_input_languages' ) ) {
						$provider_info['input_languages']         = $provider->get_input_languages();
						$provider_info['output_languages']        = $provider->get_output_languages();
						$provider_info['default_input_language']  = $provider->get_default_input_language();
						$provider_info['default_output_language'] = $provider->get_default_output_language();
					}

					$modes['realtime']['available']   = true;
					$modes['realtime']['providers'][] = $provider_info;
				}
			}

			// Active provider info.
			$active_provider = $this->get_active_provider();
			$active_info     = null;

			if ( $active_provider ) {
				$active_info = array(
					'slug'  => $active_provider->get_slug(),
					'name'  => $active_provider->get_name(),
					'model' => $active_provider->get_default_model(),
					'voice' => $active_provider->get_default_voice(),
				);

				if ( method_exists( $active_provider, 'get_default_reasoning_effort' ) ) {
					$active_info['reasoning_effort'] = $active_provider->get_default_reasoning_effort();
				}
			}

			// Build response.
			$config = array(
				'voice_mode'                    => $voice_mode,
				'voice_auto_play'               => isset( $settings['voice_auto_play'] )
					? (bool) $settings['voice_auto_play']
					: false,
				'voice_interruptions'           => isset( $settings['voice_interruptions'] )
					? (bool) $settings['voice_interruptions']
					: true,
				'chat_enable_speech_button'     => isset( $settings['chat_enable_speech_button'] )
					? (bool) $settings['chat_enable_speech_button']
					: true,
				'chat_enable_transcribe_button' => isset( $settings['chat_enable_transcribe_button'] )
					? (bool) $settings['chat_enable_transcribe_button']
					: true,
				'modes'                         => $modes,
				'active_provider'               => $active_info,
			);

			/**
			 * Filter the voice configuration response.
			 *
			 * @since 1.2.0
			 *
			 * @param array           $config  Voice configuration.
			 * @param WP_REST_Request $request The REST request.
			 */
			$config = apply_filters( 'wp_mcp_ai_voice_config_response', $config, $request );

			return rest_ensure_response( $config );
		}

		/**
		 * GET /voice/providers — List available voice providers.
		 *
		 * @param WP_REST_Request $request The request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function get_voice_providers( $request ) {
			// Satisfy required REST API signature.
			$request;
			$providers = array();

			foreach ( $this->providers as $slug => $provider ) {
				$providers[] = array(
					'slug'           => $slug,
					'name'           => $provider->get_name(),
					'transport_mode' => $provider->get_transport_mode(),
					'available'      => $provider->is_available(),
					'model'          => $provider->get_default_model(),
					'voices'         => $provider->get_available_voices(),
					'default_voice'  => $provider->get_default_voice(),
				);
			}

			return rest_ensure_response( $providers );
		}

		/**
		 * POST /voice/session — Create a realtime voice session.
		 *
		 * @param WP_REST_Request $request The request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function create_voice_session( $request ) {
			$assistant_id  = $request->get_param( 'assistant_id' );
			$provider_slug = $request->get_param( 'provider' );

			// Default to the configured realtime provider.
			if ( empty( $provider_slug ) ) {
				$settings     = WP_MCP_AI_Admin_Settings::get_settings();
				$provider_key = isset( $settings['voice_realtime_provider'] )
					? $settings['voice_realtime_provider']
					: 'openai';

				$map = array(
					'openai'           => 'openai_realtime',
					'openai_translate' => 'openai_realtime_translate',
					'openai_whisper'   => 'openai_realtime_whisper',
					'gemini'           => 'gemini_live',
				);

				$provider_slug = isset( $map[ $provider_key ] )
					? $map[ $provider_key ]
					: 'openai_realtime';
			}

			if ( ! isset( $this->providers[ $provider_slug ] ) ) {
				return new WP_Error(
					'wp_mcp_ai_voice_unknown_provider',
					sprintf(
						/* translators: %s: provider slug */
						__( 'Unknown voice provider: %s', 'mcp-ai-wpoos' ),
						$provider_slug
					),
					array( 'status' => 400 )
				);
			}

			$provider = $this->providers[ $provider_slug ];

			if ( ! $provider->is_available() ) {
				return new WP_Error(
					'wp_mcp_ai_voice_unavailable',
					__( 'Voice provider is not available. Check your API key and settings.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$options   = array();
			$model     = $request->get_param( 'model' );
			$voice     = $request->get_param( 'voice' );
			$reasoning = $request->get_param( 'reasoning_effort' );
			$conn      = $request->get_param( 'connection_method' );

			if ( ! empty( $model ) ) {
				$options['model'] = $model;
			}
			if ( ! empty( $voice ) ) {
				$options['voice'] = $voice;
			}
			if ( ! empty( $reasoning ) ) {
				$options['reasoning_effort'] = $reasoning;
			}
			if ( ! empty( $conn ) ) {
				$options['connection_method'] = $conn;
			}

			$session = $provider->create_session( $assistant_id, $options );

			if ( is_wp_error( $session ) ) {
				return $session;
			}

			return rest_ensure_response( $session );
		}

		/**
		 * POST /realtime/token — Create an ephemeral token for WebRTC connection.
		 *
		 * @since 1.3.0
		 *
		 * @param WP_REST_Request $request The request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function create_realtime_token( $request ) {
			$assistant_id = $request->get_param( 'assistant_id' );

			$provider = $this->get_active_provider();
			if ( ! $provider || 'openai_realtime' !== $provider->get_slug() ) {
				return new WP_Error(
					'wp_mcp_ai_realtime_unavailable',
					__( 'OpenAI Realtime provider is not active.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$options = array();
			$model   = $request->get_param( 'model' );
			$voice   = $request->get_param( 'voice' );
			$effort  = $request->get_param( 'reasoning_effort' );

			if ( ! empty( $model ) ) {
				$options['model'] = $model;
			}
			if ( ! empty( $voice ) ) {
				$options['voice'] = $voice;
			}
			if ( ! empty( $effort ) ) {
				$options['reasoning_effort'] = $effort;
			}

			if ( ! method_exists( $provider, 'create_ephemeral_token' ) ) {
				return new WP_Error(
					'wp_mcp_ai_realtime_not_supported',
					__( 'Ephemeral token creation is not supported by this provider.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$token = $provider->create_ephemeral_token( $assistant_id, $options );

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			return rest_ensure_response( $token );
		}

		/**
		 * POST /realtime/session — SDP relay for unified WebRTC interface.
		 *
		 * Accepts a raw SDP offer in the request body and relays it to OpenAI.
		 * Returns the SDP answer for the browser to set as remote description.
		 *
		 * @since 1.3.0
		 *
		 * @param WP_REST_Request $request The request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function create_realtime_session( $request ) {
			$assistant_id = $request->get_param( 'assistant_id' );
			$sdp_offer    = $request->get_body();

			if ( empty( $sdp_offer ) ) {
				return new WP_Error(
					'wp_mcp_ai_realtime_missing_sdp',
					__( 'Missing SDP offer in request body.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$provider = $this->get_active_provider();
			if ( ! $provider || 'openai_realtime' !== $provider->get_slug() ) {
				return new WP_Error(
					'wp_mcp_ai_realtime_unavailable',
					__( 'OpenAI Realtime provider is not active.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			if ( ! method_exists( $provider, 'create_unified_session' ) ) {
				return new WP_Error(
					'wp_mcp_ai_realtime_not_supported',
					__( 'Unified session creation is not supported by this provider.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$sdp_answer = $provider->create_unified_session( $sdp_offer, $assistant_id );

			if ( is_wp_error( $sdp_answer ) ) {
				return $sdp_answer;
			}

			return new WP_REST_Response(
				$sdp_answer,
				200,
				array( 'Content-Type' => 'application/sdp' )
			);
		}
	}
}
