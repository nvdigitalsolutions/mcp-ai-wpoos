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
		 * Hugging Face client instance.
		 *
		 * @var WP_MCP_AI_Huggingface_Client
		 */
		protected $huggingface_client;

		/**
		 * Cloudflare client instance.
		 *
		 * @var WP_MCP_AI_Cloudflare_Client
		 */
		protected $cloudflare_client;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_OpenAI_Client      $openai_client        OpenAI client instance.
		 * @param WP_MCP_AI_Gemini_Client      $gemini_client        Gemini client instance.
		 * @param WP_MCP_AI_Ollama_Client      $ollama_client        Ollama client instance (optional).
		 * @param WP_MCP_AI_LM_Studio_Client   $lm_studio_client     LM Studio client instance (optional).
		 * @param WP_MCP_AI_Anthropic_Client   $anthropic_client     Anthropic client instance (optional).
		 * @param WP_MCP_AI_Huggingface_Client $huggingface_client   Hugging Face client instance (optional).
		 * @param WP_MCP_AI_Cloudflare_Client  $cloudflare_client    Cloudflare client instance (optional).
		 */
		public function __construct( WP_MCP_AI_OpenAI_Client $openai_client, WP_MCP_AI_Gemini_Client $gemini_client, WP_MCP_AI_Ollama_Client $ollama_client = null, WP_MCP_AI_LM_Studio_Client $lm_studio_client = null, WP_MCP_AI_Anthropic_Client $anthropic_client = null, WP_MCP_AI_Huggingface_Client $huggingface_client = null, WP_MCP_AI_Cloudflare_Client $cloudflare_client = null ) {
			$this->openai_client      = $openai_client;
			$this->gemini_client      = $gemini_client;
			$this->ollama_client      = $ollama_client ? $ollama_client : new WP_MCP_AI_Ollama_Client();
			$this->lm_studio_client   = $lm_studio_client ? $lm_studio_client : new WP_MCP_AI_LM_Studio_Client();
			$this->anthropic_client   = $anthropic_client ? $anthropic_client : new WP_MCP_AI_Anthropic_Client();
			$this->huggingface_client = $huggingface_client ? $huggingface_client : new WP_MCP_AI_Huggingface_Client();
			$this->cloudflare_client  = $cloudflare_client ? $cloudflare_client : new WP_MCP_AI_Cloudflare_Client();
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

			// Log system prompt state before routing to provider.
			// This ensures we can verify that assistant defaults are reaching the LLM clients.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'router_before_llm_call',
					'Language Model Router: Options before LLM client call',
					array(
						'provider'              => $provider,
						'has_system_prompt'     => isset( $options['system_prompt'] ),
						'system_prompt_length'  => isset( $options['system_prompt'] ) ? strlen( (string) $options['system_prompt'] ) : 0,
						'system_prompt_preview' => isset( $options['system_prompt'] ) ? substr( (string) $options['system_prompt'], 0, 100 ) . '...' : 'NOT SET',
						'has_model'             => isset( $options['model'] ),
						'model'                 => isset( $options['model'] ) ? $options['model'] : 'NOT SET',
						'has_temperature'       => isset( $options['temperature'] ),
						'has_tools'             => isset( $options['tools'] ) && ! empty( $options['tools'] ),
						'tools_count'           => isset( $options['tools'] ) && is_array( $options['tools'] ) ? count( $options['tools'] ) : 0,
						'message_count'         => count( $messages ),
					)
				);
			}

			// If provider is explicitly specified, use it directly without fallback.
			if ( ! empty( $provider ) ) {
				return $this->route_to_provider( $provider, $messages, $options );
			}

			// No provider specified - use priority list with fallback.
			$settings      = WP_MCP_AI_Admin_Settings::get_settings();
			$priority_list = isset( $settings['provider_priority_list'] ) && is_array( $settings['provider_priority_list'] )
				? $settings['provider_priority_list']
				: array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio', 'cloudflare', 'embedded' );

			$last_error = null;

			// Try providers in priority order.
			foreach ( $priority_list as $try_provider ) {
				$try_provider = sanitize_key( $try_provider );

				if ( empty( $try_provider ) ) {
					continue;
				}

				$result = $this->route_to_provider( $try_provider, $messages, $options );

				// If successful, return the result.
				if ( ! is_wp_error( $result ) ) {
					// Log successful provider if we had to try multiple.
					if ( $last_error ) {
						WP_MCP_AI_Logger::log_event(
							'provider_fallback_success',
							sprintf( 'Successfully used fallback provider: %s', $try_provider ),
							array(
								'provider'        => $try_provider,
								'previous_errors' => count( (array) $last_error ),
							)
						);
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
			return $last_error ? $last_error : new WP_Error(
				'no_providers_available',
				__( 'No AI providers are available or configured.', 'mcp-ai-wpoos' )
			);
		}

		/**
		 * Get a configured language model client for the given assistant.
		 *
		 * This method prepares the router with assistant-specific configuration
		 * and returns the router instance which can then be used to make chat completion requests.
		 *
		 * @param array $assistant_config Assistant configuration including system_prompt, model, provider, etc.
		 * @return WP_MCP_AI_Language_Model_Router|WP_Error Returns self for method chaining, or WP_Error on failure.
		 */
		public function get_client( array $assistant_config ) {
			// Validate that we have required configuration.
			// The router itself doesn't need specific validation since it delegates to provider clients.
			// Provider-specific validation happens in individual client implementations.

			// Log client initialization for diagnostic purposes.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'router_get_client',
					'Language Model Router: Preparing client for assistant',
					array(
						'has_system_prompt' => ! empty( $assistant_config['system_prompt'] ),
						'system_prompt_len' => ! empty( $assistant_config['system_prompt'] ) ? strlen( (string) $assistant_config['system_prompt'] ) : 0,
						'has_provider'      => ! empty( $assistant_config['provider'] ),
						'provider'          => ! empty( $assistant_config['provider'] ) ? $assistant_config['provider'] : 'default',
						'has_model'         => ! empty( $assistant_config['model'] ),
						'model'             => ! empty( $assistant_config['model'] ) ? $assistant_config['model'] : 'default',
						'has_tools'         => ! empty( $assistant_config['tools'] ),
						'tools_count'       => ! empty( $assistant_config['tools'] ) && is_array( $assistant_config['tools'] ) ? count( $assistant_config['tools'] ) : 0,
					)
				);
			}

			// Return the router instance itself.
			// The router's create_chat_completion method will handle the assistant config via options.
			// This allows the chat service to call: $client->create_chat_completion($messages, $options).
			return $this;
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

				case 'huggingface':
					return $this->huggingface_client->create_chat_completion( $messages, $options );

				case 'ollama':
					return $this->ollama_client->create_chat_completion( $messages, $options );

				case 'lm_studio':
					return $this->lm_studio_client->create_chat_completion( $messages, $options );

				case 'cloudflare':
					return $this->cloudflare_client->create_chat_completion( $messages, $options );

				case 'embedded':
					// Embedded LLM runs client-side in the browser using WebLLM.
					// Server-side chat completion requests for embedded provider are not supported.
					return new WP_Error(
						'wp_mcp_ai_embedded_client_side_only',
						__( 'Embedded LLM provider runs client-side in the browser. Server-side API requests are not supported for this provider.', 'mcp-ai-wpoos' ),
						array( 'status' => 400 )
					);

				case 'openai':
				default:
					return $this->openai_client->create_chat_completion( $messages, $options );
			}
		}
	}
}
