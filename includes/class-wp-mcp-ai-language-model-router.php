<?php
/**
 * Language model router.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Language_Model_Router' ) ) {
	/**
	 * Routes chat completion requests to the configured language model provider.
	 */
	class WP_MCP_AI_Language_Model_Router {

		/**
		 * OpenAI client instance.
		 *
		 * @var WP_MCP_AI_OpenAI_Client
		 */
		protected $openai_client;

		/**
		 * Gemini client instance.
		 *
		 * @var WP_MCP_AI_Gemini_Client
		 */
		protected $gemini_client;

		/**
		 * Anthropic client instance.
		 *
		 * @var WP_MCP_AI_Anthropic_Client
		 */
		protected $anthropic_client;

		/**
		 * Ollama client instance.
		 *
		 * @var WP_MCP_AI_Ollama_Client
		 */
		protected $ollama_client;

		/**
		 * LM Studio client instance.
		 *
		 * @var WP_MCP_AI_LM_Studio_Client
		 */
		protected $lm_studio_client;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_OpenAI_Client    $openai_client     OpenAI client instance.
		 * @param WP_MCP_AI_Gemini_Client    $gemini_client     Gemini client instance.
		 * @param WP_MCP_AI_Ollama_Client    $ollama_client     Ollama client instance (optional).
		 * @param WP_MCP_AI_LM_Studio_Client $lm_studio_client  LM Studio client instance (optional).
		 * @param WP_MCP_AI_Anthropic_Client $anthropic_client  Anthropic client instance (optional).
		 */
		public function __construct( WP_MCP_AI_OpenAI_Client $openai_client, WP_MCP_AI_Gemini_Client $gemini_client, WP_MCP_AI_Ollama_Client $ollama_client = null, WP_MCP_AI_LM_Studio_Client $lm_studio_client = null, WP_MCP_AI_Anthropic_Client $anthropic_client = null ) {
			$this->openai_client    = $openai_client;
			$this->gemini_client    = $gemini_client;
			$this->ollama_client    = $ollama_client ? $ollama_client : new WP_MCP_AI_Ollama_Client();
			$this->lm_studio_client = $lm_studio_client ? $lm_studio_client : new WP_MCP_AI_LM_Studio_Client();
			$this->anthropic_client = $anthropic_client ? $anthropic_client : new WP_MCP_AI_Anthropic_Client();
		}

		/**
		 * Dispatch a chat completion request to the appropriate provider.
		 *
		 * Uses the provider specified in options, or falls back to the configured
		 * provider priority list. Will try providers in order until one succeeds.
		 *
		 * @param array $messages Sanitized message payload.
		 * @param array $options  Request options.
		 * @return array|WP_Error
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			$provider = isset( $options['provider'] ) ? sanitize_key( $options['provider'] ) : '';

			// If provider is explicitly specified, use it directly without fallback.
			if ( ! empty( $provider ) ) {
				return $this->route_to_provider_with_tracking( $provider, $messages, $options, false );
			}

			// No provider specified - use priority list with fallback.
			$settings       = WP_MCP_AI_Admin_Settings::get_settings();
			$priority_list  = isset( $settings['provider_priority_list'] ) && is_array( $settings['provider_priority_list'] )
				? $settings['provider_priority_list']
				: array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' );

			$last_error        = null;
			$skipped_providers = array();

			// Try providers in priority order.
			foreach ( $priority_list as $try_provider ) {
				$try_provider = sanitize_key( $try_provider );
				
				if ( empty( $try_provider ) ) {
					continue;
				}

				// Check circuit breaker before attempting.
				if ( class_exists( 'WP_MCP_AI_Circuit_Breaker' ) && ! WP_MCP_AI_Circuit_Breaker::is_available( $try_provider ) ) {
					$skipped_providers[] = $try_provider;
					WP_MCP_AI_Logger::log_debug(
						sprintf( 'Skipping provider %s due to circuit breaker.', $try_provider ),
						array( 'provider' => $try_provider )
					);
					continue;
				}

				$result = $this->route_to_provider_with_tracking( $try_provider, $messages, $options, true );

				// If successful, return the result.
				if ( ! is_wp_error( $result ) ) {
					// Log successful provider if we had to try multiple.
					if ( $last_error ) {
						WP_MCP_AI_Logger::log_event(
							'provider_fallback_success',
							sprintf( 'Successfully used fallback provider: %s', $try_provider ),
							array(
								'provider'          => $try_provider,
								'previous_errors'   => count( (array) $last_error ),
								'skipped_providers' => $skipped_providers,
							)
						);

						// Record metrics.
						if ( class_exists( 'WP_MCP_AI_Metrics' ) ) {
							WP_MCP_AI_Metrics::increment(
								WP_MCP_AI_Metrics::CATEGORY_API_CALLS,
								'fallback_success',
								1,
								array( 'provider' => $try_provider )
							);
						}
					}
					return $result;
				}

				// Store error and continue to next provider.
				$last_error = $result;
				
				WP_MCP_AI_Logger::log_error(
					sprintf( 'Provider %s failed, trying next in priority list.', $try_provider ),
					array(
						'provider' => $try_provider,
						'error'    => $result->get_error_message(),
					)
				);
			}

			// All providers failed, return the last error.
			if ( ! empty( $skipped_providers ) ) {
				WP_MCP_AI_Logger::log_warning(
					'All providers failed or were skipped by circuit breaker.',
					array(
						'skipped_providers' => $skipped_providers,
						'last_error'        => $last_error ? $last_error->get_error_message() : 'None',
					)
				);
			}

			return $last_error ? $last_error : new WP_Error(
				'no_providers_available',
				__( 'No AI providers are available or configured.', 'wp-mcp-ai' )
			);
		}

		/**
		 * Route a request to a specific provider with health tracking.
		 *
		 * @param string $provider       Provider key.
		 * @param array  $messages       Messages array.
		 * @param array  $options        Request options.
		 * @param bool   $track_circuit  Whether to track circuit breaker state.
		 * @return array|WP_Error
		 */
		protected function route_to_provider_with_tracking( $provider, array $messages, array $options, $track_circuit = true ) {
			$start_time = microtime( true );

			$result = $this->route_to_provider( $provider, $messages, $options );

			$duration = microtime( true ) - $start_time;

			// Track metrics and circuit breaker state.
			if ( $track_circuit ) {
				$success = ! is_wp_error( $result );

				// Record API call metrics.
				$error_info = array();
				if ( ! $success ) {
					$error_data = $result->get_error_data();
					$error_info = array(
						'type'   => $result->get_error_code(),
						'status' => isset( $error_data['status'] ) ? $error_data['status'] : 0,
					);

					// Check if this was a timeout.
					if ( in_array( $result->get_error_code(), array( 'http_request_timeout', 'wp_mcp_ai_wordpress_timeout' ), true ) ) {
						if ( class_exists( 'WP_MCP_AI_Metrics' ) ) {
							WP_MCP_AI_Metrics::record_timeout( $provider, 'chat_completion' );
						}
					}
				}

				if ( class_exists( 'WP_MCP_AI_Metrics' ) ) {
					WP_MCP_AI_Metrics::record_api_call( $provider, 'chat_completion', $success, $duration, $error_info );
				}

				// Update circuit breaker.
				if ( class_exists( 'WP_MCP_AI_Circuit_Breaker' ) ) {
					if ( $success ) {
						WP_MCP_AI_Circuit_Breaker::record_success( $provider );
					} else {
						WP_MCP_AI_Circuit_Breaker::record_failure( $provider, $error_info );
					}
				}
			}

			return $result;
		}

		/**
		 * Route a request to a specific provider.
		 *
		 * @param string $provider Provider key.
		 * @param array  $messages Messages array.
		 * @param array  $options  Request options.
		 * @return array|WP_Error
		 */
		protected function route_to_provider( $provider, array $messages, array $options ) {
			switch ( $provider ) {
				case 'anthropic':
					return $this->anthropic_client->create_chat_completion( $messages, $options );

				case 'gemini':
					return $this->gemini_client->create_chat_completion( $messages, $options );

				case 'ollama':
					return $this->ollama_client->create_chat_completion( $messages, $options );

				case 'lm_studio':
					return $this->lm_studio_client->create_chat_completion( $messages, $options );

				case 'openai':
				default:
					return $this->openai_client->create_chat_completion( $messages, $options );
			}
		}
	}
}
