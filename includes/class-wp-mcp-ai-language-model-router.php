<?php
/**
 * Language model router.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
		 * NVIDIA NIM client instance.
		 *
		 * @var WP_MCP_AI_Nvidia_Client
		 */
		protected $nvidia_client;

		/**
		 * DeepSeek client instance.
		 *
		 * @var WP_MCP_AI_DeepSeek_Client
		 */
		protected $deepseek_client;

		/**
		 * OpenRouter client instance.
		 *
		 * @var WP_MCP_AI_OpenRouter_Client
		 */
		protected $openrouter_client;

		/**
		 * DigitalOcean Serverless Inference client instance.
		 *
		 * @var WP_MCP_AI_DigitalOcean_Client
		 */
		protected $digitalocean_client;

		/**
		 * Kimi (Moonshot AI) client instance.
		 *
		 * @var WP_MCP_AI_Kimi_Client
		 */
		protected $kimi_client;

		/**
		 * Baseten client instance.
		 *
		 * @var WP_MCP_AI_Baseten_Client
		 */
		protected $baseten_client;

		/**
		 * Z.AI (GLM) client instance.
		 *
		 * @var WP_MCP_AI_ZAI_Client
		 */
		protected $zai_client;

		/**
		 * Embedded LLM client instance
		 * Embedded LLM client instance (server-side GGUF inference, Pro-only).
		 *
		 * Null when the Pro addon is not present.
		 *
		 * @var WP_MCP_AI_Embedded_Client|null
		 */
		protected $embedded_client;

		/**
		 * Tier constant: draft tier uses fast/cheap models.
		 *
		 * @var string
		 */
		const TIER_DRAFT = 'draft';

		/**
		 * Tier constant: verification tier uses capable/thorough models.
		 *
		 * @var string
		 */
		const TIER_VERIFICATION = 'verification';

		/**
		 * Tier constant: auto selection based on depth scheduler or fallback.
		 *
		 * @var string
		 */
		const TIER_AUTO = 'auto';

		/**
		 * Depth scheduler instance for tiered routing decisions.
		 *
		 * @var WP_MCP_AI_Orchestration_Depth_Scheduler|null
		 */
		protected $depth_scheduler = null;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_OpenAI_Client       $openai_client        OpenAI client instance.
		 * @param WP_MCP_AI_Gemini_Client       $gemini_client        Gemini client instance.
		 * @param WP_MCP_AI_Ollama_Client       $ollama_client        Ollama client instance (optional).
		 * @param WP_MCP_AI_LM_Studio_Client    $lm_studio_client     LM Studio client instance (optional).
		 * @param WP_MCP_AI_Anthropic_Client    $anthropic_client     Anthropic client instance (optional).
		 * @param WP_MCP_AI_Huggingface_Client  $huggingface_client   Hugging Face client instance (optional).
		 * @param WP_MCP_AI_Cloudflare_Client   $cloudflare_client    Cloudflare client instance (optional).
		 * @param object|null                   $embedded_client      Embedded LLM client instance (Pro-only, optional).
		 * @param WP_MCP_AI_Nvidia_Client       $nvidia_client        NVIDIA NIM client instance (optional).
		 * @param WP_MCP_AI_DeepSeek_Client     $deepseek_client      DeepSeek client instance (optional).
		 * @param WP_MCP_AI_OpenRouter_Client   $openrouter_client    OpenRouter client instance (optional).
		 * @param WP_MCP_AI_DigitalOcean_Client $digitalocean_client DigitalOcean client instance (optional).
		 * @param WP_MCP_AI_Kimi_Client         $kimi_client         Kimi client instance (optional).
		 * @param WP_MCP_AI_Baseten_Client      $baseten_client      Baseten client instance (optional).
		 * @param WP_MCP_AI_ZAI_Client          $zai_client          Z.AI client instance (optional).
		 */
		public function __construct( WP_MCP_AI_OpenAI_Client $openai_client, WP_MCP_AI_Gemini_Client $gemini_client, WP_MCP_AI_Ollama_Client $ollama_client = null, WP_MCP_AI_LM_Studio_Client $lm_studio_client = null, WP_MCP_AI_Anthropic_Client $anthropic_client = null, WP_MCP_AI_Huggingface_Client $huggingface_client = null, WP_MCP_AI_Cloudflare_Client $cloudflare_client = null, $embedded_client = null, WP_MCP_AI_Nvidia_Client $nvidia_client = null, WP_MCP_AI_DeepSeek_Client $deepseek_client = null, WP_MCP_AI_OpenRouter_Client $openrouter_client = null, WP_MCP_AI_DigitalOcean_Client $digitalocean_client = null, WP_MCP_AI_Kimi_Client $kimi_client = null, WP_MCP_AI_Baseten_Client $baseten_client = null, WP_MCP_AI_ZAI_Client $zai_client = null ) {
			$this->openai_client       = $openai_client;
			$this->gemini_client       = $gemini_client;
			$this->ollama_client       = $ollama_client ? $ollama_client : new WP_MCP_AI_Ollama_Client();
			$this->lm_studio_client    = $lm_studio_client ? $lm_studio_client : new WP_MCP_AI_LM_Studio_Client();
			$this->anthropic_client    = $anthropic_client ? $anthropic_client : new WP_MCP_AI_Anthropic_Client();
			$this->huggingface_client  = $huggingface_client ? $huggingface_client : new WP_MCP_AI_Huggingface_Client();
			$this->cloudflare_client   = $cloudflare_client ? $cloudflare_client : new WP_MCP_AI_Cloudflare_Client();
			$this->nvidia_client       = $nvidia_client ? $nvidia_client : new WP_MCP_AI_Nvidia_Client();
			$this->deepseek_client     = $deepseek_client ? $deepseek_client : new WP_MCP_AI_DeepSeek_Client();
			$this->openrouter_client   = $openrouter_client ? $openrouter_client : new WP_MCP_AI_OpenRouter_Client();
			$this->digitalocean_client = $digitalocean_client ? $digitalocean_client : new WP_MCP_AI_DigitalOcean_Client();
			$this->kimi_client         = $kimi_client ? $kimi_client : ( class_exists( 'WP_MCP_AI_Kimi_Client' ) ? new WP_MCP_AI_Kimi_Client() : null );
			$this->baseten_client      = $baseten_client ? $baseten_client : new WP_MCP_AI_Baseten_Client();
			$this->zai_client          = $zai_client ? $zai_client : ( class_exists( 'WP_MCP_AI_ZAI_Client' ) ? new WP_MCP_AI_ZAI_Client() : null );
			// Embedded client is Pro-only; only instantiate when the class is available.
			$this->embedded_client = $embedded_client ?? ( class_exists( 'WP_MCP_AI_Embedded_Client' ) ? new WP_MCP_AI_Embedded_Client() : null );
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
				: array( 'openai', 'anthropic', 'gemini', 'huggingface', 'nvidia', 'deepseek', 'openrouter', 'baseten', 'digitalocean', 'ollama', 'lm_studio', 'cloudflare', 'embedded' );

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
			/**
			 * Filter to allow add-ons to handle routing for custom provider IDs.
			 *
			 * Return a non-null value (chat-completion array or WP_Error) to short-circuit
			 * the default routing switch. Used by the NV oOS Cloud Pro module to register
			 * the `nv_hosted` provider, but available to any add-on that wants to add a
			 * new provider id without forking the base router.
			 *
			 * @since 2026.05
			 *
			 * @param array|WP_Error|null $result   Pre-routed result. Default null = fall through to switch.
			 * @param string              $provider Sanitised provider key.
			 * @param array               $messages Chat messages array.
			 * @param array               $options  Request options.
			 */
			$pre = apply_filters( 'wp_mcp_ai_route_to_provider', null, $provider, $messages, $options );
			if ( null !== $pre ) {
				return $pre;
			}

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

				case 'nvidia':
					return $this->nvidia_client->create_chat_completion( $messages, $options );

				case 'deepseek':
					return $this->deepseek_client->create_chat_completion( $messages, $options );

				case 'openrouter':
					return $this->openrouter_client->create_chat_completion( $messages, $options );

				case 'digitalocean':
					return $this->digitalocean_client->create_chat_completion( $messages, $options );

				case 'embedded':
					// Server-side embedded LLM using GGUF models via llama.cpp.
					// Delegates to the Embedded addon (or Pro addon) via filter.
					// Falls back to direct instantiation when the class is available.
					$result = apply_filters( 'wp_mcp_ai_embedded_chat_completion', null, $messages, $options );
					if ( null !== $result ) {
						return $result;
					}
					// Legacy fallback: direct instantiation when class is loaded by Pro addon.
					if ( null !== $this->embedded_client ) {
						return $this->embedded_client->create_chat_completion( $messages, $options );
					}
					return new WP_Error(
						'embedded_client_unavailable',
						__( 'Embedded LLM requires the NV oOS Embedded addon or Pro addon.', 'mcp-ai-wpoos' )
					);

				case 'kimi':
					if ( null === $this->kimi_client ) {
						return new WP_Error(
							'wp_mcp_ai_kimi_unavailable',
							__( 'Kimi client is not available. Ensure the Kimi API key is configured.', 'mcp-ai-wpoos' )
						);
					}
					return $this->kimi_client->create_chat_completion( $messages, $options );

				case 'baseten':
					return $this->baseten_client->create_chat_completion( $messages, $options );

				case 'zai':
					if ( null === $this->zai_client ) {
						return new WP_Error(
							'wp_mcp_ai_zai_unavailable',
							__( 'Z.AI client is not available. Ensure the Z.AI API key is configured.', 'mcp-ai-wpoos' )
						);
					}
					return $this->zai_client->create_chat_completion( $messages, $options );

				case 'openai':
				default:
					return $this->openai_client->create_chat_completion( $messages, $options );
			}
		}

		/**
		 * Route a request through tiered model selection.
		 *
		 * Selects a draft (fast/cheap) or verification (capable) model based on the
		 * requested tier, confidence score, and optional depth scheduler hints.
		 *
		 * @since 2026.07
		 *
		 * @param string $task_type  Task category (e.g. 'chat', 'tool', 'research').
		 * @param float  $confidence Confidence score between 0.0 and 1.0.
		 * @param string $tier       Requested tier: self::TIER_DRAFT, self::TIER_VERIFICATION, or self::TIER_AUTO.
		 * @param array  $options    Additional routing options (provider, model hints, etc.).
		 * @return array {
		 *     @type string $model          Selected model identifier.
		 *     @type string $provider       Selected provider key.
		 *     @type string $tier           Resolved tier (draft or verification).
		 *     @type float  $confidence     Confidence score used for the decision.
		 *     @type bool   $auto_escalated Whether the tier was auto-escalated from draft to verification.
		 * }
		 */
		public function route_with_tier( string $task_type, float $confidence = 0.5, string $tier = 'auto', array $options = array() ): array {
			$auto_escalated = false;

			// Resolve 'auto' tier via depth scheduler or fallback.
			if ( self::TIER_AUTO === $tier ) {
				if ( null !== $this->depth_scheduler ) {
					$tier = $this->depth_scheduler->determine_tier( $task_type, $confidence );
				} else {
					// Fallback: use a simple heuristic.
					$tier = $confidence >= 0.7 ? self::TIER_DRAFT : self::TIER_VERIFICATION;
				}

				// Auto-escalate draft to verification when confidence is low.
				if ( self::TIER_DRAFT === $tier && $confidence < 0.5 ) {
					$tier           = self::TIER_VERIFICATION;
					$auto_escalated = true;
				}
			}

			// Resolve an explicit or hinted provider, falling back to the priority list.
			$provider = isset( $options['provider'] ) ? sanitize_key( $options['provider'] ) : '';
			if ( empty( $provider ) ) {
				$settings      = WP_MCP_AI_Admin_Settings::get_settings();
				$priority_list = isset( $settings['provider_priority_list'] ) && is_array( $settings['provider_priority_list'] )
					? $settings['provider_priority_list']
					: array( 'openai', 'anthropic', 'gemini', 'huggingface', 'nvidia', 'deepseek', 'openrouter', 'baseten', 'digitalocean', 'ollama', 'lm_studio', 'cloudflare', 'embedded' );
				$provider      = isset( $priority_list[0] ) ? sanitize_key( $priority_list[0] ) : 'openai';
			}

			// Select the appropriate model for the resolved tier and provider.
			if ( self::TIER_VERIFICATION === $tier ) {
				$config = $this->get_verification_model_for_provider( $provider );
			} else {
				$config = $this->get_draft_model_for_provider( $provider );
			}

			/**
			 * Filter the tiered model selection result before returning.
			 *
			 * Allows add-ons to override model selection, tier, or confidence for
			 * specific task types or providers.
			 *
			 * @since 2026.07
			 *
			 * @param array  $config {
			 *     @type string $model          Selected model identifier.
			 *     @type string $provider       Selected provider key.
			 *     @type string $tier           Resolved tier.
			 *     @type float  $confidence     Confidence score.
			 *     @type bool   $auto_escalated Whether auto-escalated.
			 * }
			 * @param string $task_type  Task category.
			 * @param array  $options    Original routing options.
			 */
			return apply_filters(
				'wp_mcp_ai_tiered_model_selection',
				array(
					'model'          => $config['model'],
					'provider'       => $config['provider'],
					'tier'           => $tier,
					'confidence'     => $confidence,
					'auto_escalated' => $auto_escalated,
				),
				$task_type,
				$options
			);
		}

		/**
		 * Get the cheapest (draft-tier) model configuration for a provider.
		 *
		 * Returns the fastest, least expensive model that is still capable of
		 * producing acceptable output for draft / quick-turnaround tasks.
		 *
		 * @since 2026.07
		 *
		 * @param string $provider Provider key (e.g. 'openai', 'gemini').
		 * @return array {
		 *     @type string $model    Draft model identifier.
		 *     @type string $provider Provider key.
		 * }
		 */
		public function get_draft_model_for_provider( string $provider ): array {
			$draft_models = array(
				'openai'       => 'gpt-4.1-nano',
				'gemini'       => 'gemini-2.5-flash',
				'anthropic'    => 'claude-haiku-4-5',
				'deepseek'     => 'deepseek-v4-flash',
				'ollama'       => 'llama3.2:3b',
				'huggingface'  => 'microsoft/phi-4-mini-instruct',
				'nvidia'       => 'nvidia/llama-3.2-nv-qa-1b',
				'lm_studio'    => 'llama-3.2-3b-instruct',
				'cloudflare'   => '@cf/meta/llama-3.2-3b-instruct',
				'baseten'      => 'llama-3.2-3b-instruct',
				'digitalocean' => 'llama-3.2-3b-instruct',
				'kimi'         => 'kimi-k2.6',
				'zai'          => 'glm-4-flash',
				'openrouter'   => 'openai/gpt-4.1-mini',
				'embedded'     => 'llama-3.2-3b-instruct',
			);

			/**
			 * Filter the draft-tier model map.
			 *
			 * Allows add-ons to replace or extend the draft model for any provider.
			 *
			 * @since 2026.07
			 *
			 * @param array  $draft_models Provider-keyed map of draft model identifiers.
			 * @param string $provider     Provider for which a model is being resolved.
			 */
			$draft_models = apply_filters( 'wp_mcp_ai_draft_models', $draft_models, $provider );

			$model = isset( $draft_models[ $provider ] ) ? $draft_models[ $provider ] : 'gpt-4o-mini';

			return array(
				'model'    => $model,
				'provider' => $provider,
			);
		}

		/**
		 * Get the most capable (verification-tier) model configuration for a provider.
		 *
		 * Returns the most thorough, highest-quality model for tasks requiring
		 * careful reasoning, complex analysis, or verification of draft output.
		 *
		 * @since 2026.07
		 *
		 * @param string $provider Provider key (e.g. 'openai', 'gemini').
		 * @return array {
		 *     @type string $model    Verification model identifier.
		 *     @type string $provider Provider key.
		 * }
		 */
		public function get_verification_model_for_provider( string $provider ): array {
			$verification_models = array(
				'openai'       => 'gpt-4.1',
				'gemini'       => 'gemini-3.1-pro',
				'anthropic'    => 'claude-opus-5',
				'deepseek'     => 'deepseek-v4-pro',
				'ollama'       => 'qwen2.5:72b',
				'huggingface'  => 'meta-llama/Llama-3.3-70B-Instruct',
				'nvidia'       => 'nvidia/llama-3.3-nemotron-70b-instruct',
				'lm_studio'    => 'qwen2.5-72b-instruct',
				'cloudflare'   => '@cf/meta/llama-3.3-70b-instruct',
				'baseten'      => 'llama-3.3-70b-instruct',
				'digitalocean' => 'llama-3.3-70b-instruct',
				'kimi'         => 'kimi-k3',
				'zai'          => 'glm-4-plus',
				'openrouter'   => 'anthropic/claude-sonnet-5',
				'embedded'     => 'qwen2.5-72b-instruct',
			);

			/**
			 * Filter the verification-tier model map.
			 *
			 * Allows add-ons to replace or extend the verification model for any provider.
			 *
			 * @since 2026.07
			 *
			 * @param array  $verification_models Provider-keyed map of verification model identifiers.
			 * @param string $provider            Provider for which a model is being resolved.
			 */
			$verification_models = apply_filters( 'wp_mcp_ai_verification_models', $verification_models, $provider );

			$model = isset( $verification_models[ $provider ] ) ? $verification_models[ $provider ] : 'gpt-4.1';

			return array(
				'model'    => $model,
				'provider' => $provider,
			);
		}

		/**
		 * Set the depth scheduler used for auto-tier determination.
		 *
		 * When provided, the scheduler's determine_tier() method is called during
		 * `route_with_tier()` when the tier argument is `auto`.
		 *
		 * @since 2026.07
		 *
		 * @param WP_MCP_AI_Orchestration_Depth_Scheduler $scheduler Depth scheduler instance.
		 * @return void
		 */
		public function set_depth_scheduler( WP_MCP_AI_Orchestration_Depth_Scheduler $scheduler ): void {
			$this->depth_scheduler = $scheduler;
		}
	}
}
