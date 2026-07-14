<?php
/**
 * Model Service
 *
 * Handles AI model management operations.
 * Separates model logic from token limits class following SoC principles.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model Service class
 *
 * Responsible for:
 * - Model availability and validation
 * - Model listing by provider
 * - Model capability detection
 * - Provider-specific model filtering
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Model_Service {

	/**
	 * Get available models for a specific provider
	 *
	 * @param string $provider Provider name (openai, anthropic, gemini, huggingface, ollama, lm_studio).
	 * @param array  $args     Optional arguments (capability_flags, tool_slug).
	 * @return array Array of model_id => model_name pairs.
	 */
	public function get_models_for_provider( $provider, $args = array() ) {
		$provider = sanitize_key( $provider );

		// Log the request for debugging.
		WP_MCP_AI_Logger::log_event(
			'model_service_get_models',
			'Fetching available models for provider',
			array(
				'provider' => $provider,
				'args'     => $args,
			)
		);

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$models   = array();

		// Extract capability requirements.
		$capability_flags    = isset( $args['capability_flags'] ) ? $args['capability_flags'] : array();
		$requires_vision     = in_array( 'vision', $capability_flags, true ) || in_array( 'requires-vision-model', $capability_flags, true );
		$requires_multimodal = in_array( 'multimodal', $capability_flags, true ) || in_array( 'requires-multimodal-model', $capability_flags, true );

		switch ( $provider ) {
			case 'openai':
				$models = $this->get_openai_models( $settings, $requires_vision, $requires_multimodal );
				break;

			case 'anthropic':
				$models = $this->get_anthropic_models( $settings );
				break;

			case 'gemini':
				$models = $this->get_gemini_models( $settings, $requires_vision, $requires_multimodal, $args );
				break;

			case 'huggingface':
				$models = $this->get_huggingface_models( $settings, $requires_vision, $requires_multimodal );
				break;

			case 'ollama':
				$models = $this->get_ollama_models( $settings, $requires_vision, $requires_multimodal );
				break;

			case 'lm_studio':
				$models = $this->get_lm_studio_models( $settings, $requires_vision, $requires_multimodal );
				break;

			case 'cloudflare':
				$models = $this->get_cloudflare_models( $settings, $requires_vision, $requires_multimodal );
				break;

			case 'nvidia':
				$models = $this->get_nvidia_models( $settings, $requires_vision, $requires_multimodal );
				break;

			case 'embedded':
				$models = $this->get_embedded_models( $settings );
				break;

			case 'deepseek':
				$models = $this->get_deepseek_models( $settings, $requires_vision, $requires_multimodal );
				break;

			case 'openrouter':
				$models = $this->get_openrouter_models( $settings, $requires_vision, $requires_multimodal );
				break;

			case 'digitalocean':
				$models = $this->get_digitalocean_models( $settings, $requires_vision, $requires_multimodal );
				break;

			case 'kimi':
				$models = $this->get_kimi_models( $settings, $requires_vision, $requires_multimodal );
				break;

			case 'baseten':
				$models = $this->get_baseten_models( $settings, $requires_vision, $requires_multimodal );
				break;

			default:
				WP_MCP_AI_Logger::log_event(
					'model_service_invalid_provider',
					'Invalid provider requested',
					array( 'provider' => $provider )
				);
				break;
		}

		/**
		 * Filter models for a specific provider.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $models   Available models for provider.
		 * @param string $provider Provider name.
		 * @param array  $args     Request arguments.
		 */
		$models = apply_filters( 'wp_mcp_ai_models_for_provider', $models, $provider, $args );

		WP_MCP_AI_Logger::log_event(
			'model_service_models_retrieved',
			'Successfully retrieved models for provider',
			array(
				'provider'    => $provider,
				'model_count' => count( $models ),
			)
		);

		return $models;
	}

	/**
	 * Get OpenAI models
	 *
	 * @param array $settings              Plugin settings.
	 * @param bool  $requires_vision       Whether vision capability is required.
	 * @param bool  $requires_multimodal   Whether multimodal capability is required.
	 * @return array Model list.
	 */
	protected function get_openai_models( $settings, $requires_vision, $requires_multimodal ) {
		if ( empty( $settings['openai_api_key'] ) ) {
			return array();
		}

		$models = array();

		// GPT-5.5 series (flagship - Apr 2026) - 1M+ context window, highest reasoning quality.
		$models['gpt-5.5'] = 'GPT-5.5 (Flagship - Apr 2026)';

		// GPT-5.4 series (flagship - Mar/Apr 2026) - 1M+ context window, native computer use.
		$models['gpt-5.4']      = 'GPT-5.4 (1M Context)';
		$models['gpt-5.4-pro']  = 'GPT-5.4 Pro (Enterprise)';
		$models['gpt-5.4-mini'] = 'GPT-5.4 Mini (Budget)';
		$models['gpt-5.4-nano'] = 'GPT-5.4 Nano (Lowest Cost)';

		// GPT-5.4 Codex (coding-optimized, text-only).
		if ( ! $requires_vision && ! $requires_multimodal ) {
			$models['gpt-5.4-codex'] = 'GPT-5.4 Codex (Specialized Coding)';
		}

		// GPT-5.3 series (flagship - Feb 2026) - 922K context window, advanced agentic coding.
		$models['gpt-5.3-codex']       = 'GPT-5.3 Codex (Agentic Coding - Flagship)';
		$models['gpt-5.3-codex-spark'] = 'GPT-5.3 Codex Spark (Ultra-Fast)';

		// GPT-5.2 series (flagship - Dec 2025) - 128K context window.
		$models['gpt-5.2']                = 'GPT-5.2 (Flagship)';
		$models['gpt-5.2-2025-12-11']     = 'GPT-5.2 (Dec 2025)';
		$models['gpt-5.2-pro']            = 'GPT-5.2 Pro (Advanced Reasoning)';
		$models['gpt-5.2-pro-2025-12-11'] = 'GPT-5.2 Pro (Dec 2025)';
		$models['gpt-5.2-instant']        = 'GPT-5.2 Instant (High Throughput)';
		$models['gpt-5.2-thinking']       = 'GPT-5.2 Thinking (Deeper Analysis)';

		// GPT-5.2 Codex (coding-optimized).
		if ( ! $requires_vision && ! $requires_multimodal ) {
			$models['gpt-5.2-codex'] = 'GPT-5.2 Codex (Advanced Coding)';
		}

		// GPT-5.1 series (Nov 2025).
		$models['gpt-5.1']            = 'GPT-5.1';
		$models['gpt-5.1-2025-11-13'] = 'GPT-5.1 (Nov 2025)';
		$models['gpt-5.1-instant']    = 'GPT-5.1 Instant (Fast Responses)';
		$models['gpt-5.1-thinking']   = 'GPT-5.1 Thinking (Deep Reasoning)';

		// GPT-5.1 Codex variants (coding-optimized, text-only).
		if ( ! $requires_vision && ! $requires_multimodal ) {
			$models['gpt-5.1-codex']      = 'GPT-5.1 Codex';
			$models['gpt-5.1-codex-max']  = 'GPT-5.1 Codex Max (Advanced Coding)';
			$models['gpt-5.1-codex-mini'] = 'GPT-5.1 Codex Mini (Cost-Effective Coding)';
		}

		// GPT-5 series (Aug 2025).
		$models['gpt-5']            = 'GPT-5';
		$models['gpt-5-2025-08-07'] = 'GPT-5 (Aug 2025)';
		$models['gpt-5-mini']       = 'GPT-5 Mini';
		$models['gpt-5-nano']       = 'GPT-5 Nano';
		$models['gpt-5-pro']        = 'GPT-5 Pro';

		// GPT-5 Codex variants (coding-optimized, text-only).
		if ( ! $requires_vision && ! $requires_multimodal ) {
			$models['gpt-5-codex']      = 'GPT-5 Codex';
			$models['gpt-5-codex-mini'] = 'GPT-5 Codex Mini';
		}

		// o-series Reasoning Models.
		$models['o3']      = 'o3 (Reasoning)';
		$models['o3-pro']  = 'o3 Pro (Advanced Reasoning)';
		$models['o4-mini'] = 'o4-mini (Multimodal Reasoning)';

		if ( ! $requires_vision && ! $requires_multimodal ) {
			$models['o3-mini'] = 'o3 Mini (Fast Reasoning)';
		}

		// o-series Legacy Reasoning Models.
		$models['o1']     = 'o1 (Legacy Reasoning)';
		$models['o1-pro'] = 'o1 Pro (Legacy Advanced Reasoning)';

		if ( ! $requires_vision && ! $requires_multimodal ) {
			$models['o1-mini'] = 'o1 Mini (Legacy)';
		}

		// GPT-4.5 series (multimodal - released Feb 2025).
		$models['gpt-4.5']         = 'GPT-4.5 (Creative & Conversational)';
		$models['gpt-4.5-preview'] = 'GPT-4.5 Preview';

		// GPT-4.1 series (multimodal - vision capable).
		$models['gpt-4.1']            = 'GPT-4.1 (1M Context, Coding)';
		$models['gpt-4.1-mini']       = 'GPT-4.1 Mini';
		$models['gpt-4.1-nano']       = 'GPT-4.1 Nano';
		$models['gpt-4.1-turbo']      = 'GPT-4.1 Turbo';
		$models['gpt-4.1-2025-04-14'] = 'GPT-4.1 (Apr 2025)';

		// GPT-4o series (multimodal - vision capable) - retiring Feb 2026 from ChatGPT, API still available.
		$models['gpt-4.1']            = 'GPT-4o (Retiring)';
		$models['gpt-4o-mini']       = 'GPT-4o Mini';
		$models['gpt-4o-2024-11-20'] = 'GPT-4o (Nov 2024)';
		$models['gpt-4o-2024-08-06'] = 'GPT-4o (Aug 2024)';
		$models['gpt-4o-2024-05-13'] = 'GPT-4o (May 2024)';
		$models['chatgpt-4o-latest'] = 'ChatGPT-4o (Latest)';

		// Legacy models (text-only).
		if ( ! $requires_vision && ! $requires_multimodal ) {
			$models['gpt-4-turbo']   = 'GPT-4 Turbo (Legacy)';
			$models['gpt-4']         = 'GPT-4 (Legacy)';
			$models['gpt-3.5-turbo'] = 'GPT-3.5 Turbo (Legacy)';
		}

		return $models;
	}

	/**
	 * Get Anthropic (Claude) models
	 *
	 * @param array $settings Plugin settings.
	 * @return array Model list.
	 */
	protected function get_anthropic_models( $settings ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future API key validation.
		// Return models even if API key is not configured, for browsing purposes.
		// The models are static and don't require API access to list.
		$models = array();

		// Claude Mythos (Capybara tier) - Most capable (Apr 2026).
		$models['claude-mythos-preview'] = 'Claude Mythos Preview (Apr 2026) - Most Capable';

		// Claude 4.7 series (multimodal - vision capable) - Latest flagship (Apr 2026).
		$models['claude-opus-4-7'] = 'Claude Opus 4.7 (Apr 2026) - Flagship';

		// Claude 4.6 series (multimodal - vision capable) - Latest (Feb 2026).
		$models['claude-opus-4-6']   = 'Claude Opus 4.6 (Feb 2026) - Flagship';
		$models['claude-sonnet-4-6'] = 'Claude Sonnet 4.6 (Feb 2026) - Recommended';

		// Claude 4.5 series (multimodal - vision capable) - Recent (2025).
		$models['claude-haiku-4-5']           = 'Claude Haiku 4.5 - Fastest';
		$models['claude-sonnet-4-5-20250929'] = 'Claude Sonnet 4.5 (Sep 2025)';
		$models['claude-haiku-4-5-20251001']  = 'Claude Haiku 4.5 (Oct 2025) - Fastest';
		$models['claude-opus-4-5-20251101']   = 'Claude Opus 4.5 (Nov 2025)';

		// Claude 4.1 series (multimodal - vision capable).
		$models['claude-opus-4-1-20250805'] = 'Claude Opus 4.1 (Aug 2025)';

		// Claude 4 series (multimodal - vision capable).
		$models['claude-sonnet-4-20250514'] = 'Claude Sonnet 4 (May 2025)';
		$models['claude-opus-4-20250514']   = 'Claude Opus 4 (May 2025)';

		// Claude 3.7 series (multimodal - vision capable).
		$models['claude-3-7-sonnet-20250219'] = 'Claude 3.7 Sonnet (Feb 2025)';

		// Claude 3.5 series (legacy - for backward compatibility).
		$models['claude-sonnet-5'] = 'Claude 3.5 Sonnet (Legacy)';
		$models['claude-3-5-haiku-20241022']  = 'Claude 3.5 Haiku (Legacy)';

		// Claude 3 series (legacy).
		$models['claude-haiku-4-5'] = 'Claude 3 Haiku (Legacy)';

		return $models;
	}

	/**
	 * Get Google Gemini models
	 *
	 * @param array $settings              Plugin settings.
	 * @param bool  $requires_vision       Whether vision capability is required.
	 * @param bool  $requires_multimodal   Whether multimodal capability is required.
	 * @param array $args                  Additional arguments.
	 * @return array Model list.
	 */
	protected function get_gemini_models( $settings, $requires_vision, $requires_multimodal, $args ) {
		if ( empty( $settings['gemini_api_key'] ) ) {
			return array();
		}

		$models             = array();
		$requires_image_gen = isset( $args['requires_image_gen'] ) ? $args['requires_image_gen'] : false;

		// Gemini 3.5 series (May 2026 GA - latest flagship).
		$models['gemini-3.5-flash'] = 'Gemini 3.5 Flash (Recommended)';

		// Gemini 3.1 series (April 2026 GA).
		$models['gemini-3.1-pro']        = 'Gemini 3.1 Pro';
		$models['gemini-3.1-flash']      = 'Gemini 3.1 Flash';
		$models['gemini-3.1-flash-lite'] = 'Gemini 3.1 Flash Lite (Budget)';

		// Gemini 2.5 series (multimodal - text, image, video) - Stable.
		$models['gemini-2.5-pro']                   = 'Gemini 2.5 Pro';
		$models['gemini-2.5-flash']                 = 'Gemini 2.5 Flash (Recommended)';
		$models['gemini-2.5-flash-lite']            = 'Gemini 2.5 Flash Lite';
		$models['gemini-2.5-flash-preview-09-2025'] = 'Gemini 2.5 Flash (Sep 2025 Preview)';

		// Gemini 2.5 specialized models.
		$models['gemini-live-2.5-flash-preview']                = 'Gemini Live 2.5 Flash (Voice/Multimodal)';
		$models['gemini-2.5-flash-preview-native-audio-dialog'] = 'Gemini 2.5 Native Audio Dialog';
		$models['gemini-2.5-flash-preview-tts']                 = 'Gemini 2.5 Flash TTS';
		$models['gemini-2.5-pro-preview-tts']                   = 'Gemini 2.5 Pro TTS';

		// Image generation model - only for image generation/editing tools.
		if ( $requires_image_gen ) {
			$models['gemini-3.1-flash-image'] = 'Gemini 3.1 Flash Image — Nano Banana 2 (Recommended)';
			$models['gemini-2.5-flash-image'] = 'Gemini 2.5 Flash Image — Nano Banana (Legacy)';
		}

		// Gemini 2.0 series (stable).
		$models['gemini-2.5-flash']      = 'Gemini 2.0 Flash';
		$models['gemini-2.0-flash-lite'] = 'Gemini 2.0 Flash Lite';
		$models['gemini-2.0-flash-exp']  = 'Gemini 2.0 Flash (Experimental)';

		// Experimental models.
		$models['gemini-exp-1206'] = 'Gemini Exp 1206';
		$models['gemini-exp-1121'] = 'Gemini Exp 1121';

		// Gemini 1.5 series (legacy - for backward compatibility).
		$models['gemini-2.5-flash']   = 'Gemini 1.5 Pro (Legacy)';
		$models['gemini-1.5-flash'] = 'Gemini 1.5 Flash (Legacy)';

		// Gemma 4 models (Google's latest open models - multimodal).
		$models['gemma-4-31b-it'] = 'Gemma 4 31B Dense (Multimodal, 256K)';
		$models['gemma-4-26b-it'] = 'Gemma 4 26B MoE (Multimodal, 256K)';
		$models['gemma-4-e4b-it'] = 'Gemma 4 E4B (Multimodal, 128K, Edge)';
		$models['gemma-4-e2b-it'] = 'Gemma 4 E2B (Multimodal, 128K, Edge)';

		// Gemma 2 models (Google's open models - text-only, legacy).
		if ( ! $requires_vision && ! $requires_multimodal ) {
			$models['gemma-2-27b-it'] = 'Gemma 2 27B (Instruct)';
			$models['gemma-2-9b-it']  = 'Gemma 2 9B (Instruct)';
			$models['gemma-2-2b-it']  = 'Gemma 2 2B (Instruct)';
		}

		return $models;
	}

	/**
	 * Get Hugging Face models
	 *
	 * @param array $settings              Plugin settings.
	 * @param bool  $requires_vision       Whether vision capability is required.
	 * @param bool  $requires_multimodal   Whether multimodal capability is required.
	 * @return array Model list.
	 */
	protected function get_huggingface_models( $settings, $requires_vision, $requires_multimodal ) {
		if ( empty( $settings['huggingface_api_key'] ) ) {
			return array();
		}

		if ( empty( $settings['huggingface_endpoint_url'] ) ) {
			return array();
		}

		// Try to fetch models dynamically from Hugging Face API.
		if ( class_exists( 'WP_MCP_AI_Huggingface_Client' ) ) {
			$client = new WP_MCP_AI_Huggingface_Client();
			$result = $client->list_models();

			// If list_models() succeeds and returns an array of models, use it.
			if ( ! is_wp_error( $result ) && is_array( $result ) && ! empty( $result ) ) {
				$models = array();
				foreach ( $result as $model ) {
					if ( isset( $model['id'] ) ) {
						$model_id   = $model['id'];
						$model_name = $model_id;

						// Add owned_by info if available.
						if ( isset( $model['owned_by'] ) && ! empty( $model['owned_by'] ) ) {
							$model_name = $model_id . ' (' . $model['owned_by'] . ')';
						}

						$models[ $model_id ] = $model_name;
					}
				}

				if ( ! empty( $models ) ) {
					WP_MCP_AI_Logger::log_event(
						'model_service_huggingface_dynamic',
						'Successfully fetched Hugging Face models from API',
						array( 'count' => count( $models ) )
					);
					return $models;
				}
			}

			// Log if dynamic fetch failed.
			if ( is_wp_error( $result ) ) {
				WP_MCP_AI_Logger::log_event(
					'model_service_huggingface_fetch_failed',
					'Failed to fetch Hugging Face models from API, falling back to static list',
					array( 'error' => $result->get_error_message() )
				);
			}
		}

		// Fallback: Return common Hugging Face models if dynamic fetch fails.
		$models = array();

		// Add configured model if available.
		if ( ! empty( $settings['huggingface_model'] ) ) {
			$models[ $settings['huggingface_model'] ] = $settings['huggingface_model'];
		}

		// Common Hugging Face Inference API models (text generation).
		if ( ! $requires_vision && ! $requires_multimodal ) {
			$common_models = array(
				// DeepSeek (top performers for reasoning/coding in 2025-2026).
				'deepseek-ai/DeepSeek-R1'                => 'DeepSeek R1 (Reasoning)',
				'deepseek-ai/DeepSeek-V3'                => 'DeepSeek V3',
				'deepseek-ai/DeepSeek-Coder-V2-Instruct' => 'DeepSeek Coder V2 Instruct',
				// Qwen (multilingual, strong coder).
				'Qwen/Qwen3-72B-Instruct'                => 'Qwen 3 72B Instruct',
				'Qwen/Qwen3-32B-Instruct'                => 'Qwen 3 32B Instruct',
				'Qwen/Qwen2.5-72B-Instruct'              => 'Qwen 2.5 72B Instruct',
				'Qwen/Qwen2.5-32B-Instruct'              => 'Qwen 2.5 32B Instruct',
				'Qwen/Qwen2.5-14B-Instruct'              => 'Qwen 2.5 14B Instruct',
				'Qwen/Qwen2.5-7B-Instruct'               => 'Qwen 2.5 7B Instruct',
				// Llama (Meta flagship).
				'meta-llama/Llama-3.3-70B-Instruct'      => 'Llama 3.3 70B Instruct',
				'meta-llama/Llama-3.2-3B-Instruct'       => 'Llama 3.2 3B Instruct',
				'meta-llama/Llama-3.1-8B-Instruct'       => 'Llama 3.1 8B Instruct',
				// Mistral.
				'mistralai/Mistral-7B-Instruct-v0.3'     => 'Mistral 7B Instruct v0.3',
				'mistralai/Mixtral-8x7B-Instruct-v0.1'   => 'Mixtral 8x7B Instruct',
				// Google Gemma.
				'google/gemma-4-31b-it'                  => 'Gemma 4 31B Dense (Multimodal)',
				'google/gemma-4-26b-it'                  => 'Gemma 4 26B MoE (Multimodal)',
				'google/gemma-2-27b-it'                  => 'Gemma 2 27B Instruct',
				'google/gemma-2-9b-it'                   => 'Gemma 2 9B Instruct',
				// Microsoft Phi.
				'microsoft/Phi-3.5-mini-instruct'        => 'Phi-3.5 Mini Instruct',
			);

			foreach ( $common_models as $model_id => $model_name ) {
				if ( ! isset( $models[ $model_id ] ) ) {
					$models[ $model_id ] = $model_name;
				}
			}
		}

		return $models;
	}

	/**
	 * Get Ollama models
	 *
	 * @param array $settings              Plugin settings.
	 * @param bool  $requires_vision       Whether vision capability is required.
	 * @param bool  $requires_multimodal   Whether multimodal capability is required.
	 * @return array Model list.
	 */
	protected function get_ollama_models( $settings, $requires_vision, $requires_multimodal ) {
		if ( empty( $settings['ollama_endpoint_url'] ) ) {
			return array();
		}

		$models             = array();
		$live_fetch_success = false;

		// Try to fetch live models from the configured Ollama endpoint so the
		// dropdown stays accurate when users add or remove models on the server.
		// Falls back to the hard-coded common-model list (plus the configured
		// default) if the endpoint cannot be reached.
		if ( class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
			try {
				$client      = new WP_MCP_AI_Ollama_Client();
				$live_models = $client->list_models();
				if ( ! is_wp_error( $live_models ) && is_array( $live_models ) && ! empty( $live_models ) ) {
					foreach ( $live_models as $model ) {
						if ( empty( $model['name'] ) ) {
							continue;
						}
						$name   = (string) $model['name'];
						$family = ! empty( $model['family'] ) ? (string) $model['family'] : '';
						// Append a cloud indicator to the display label for cloud-hosted models.
						$is_cloud        = ! empty( $model['is_cloud'] );
						$cloud_suffix    = $is_cloud ? ' ☁' : '';
						$models[ $name ] = ( $family ? sprintf( '%s (%s)', $name, $family ) : $name ) . $cloud_suffix;
					}
					$live_fetch_success = true;
				}
			} catch ( \Exception $e ) {
				// Swallow exceptions so the settings UI never breaks if the
				// Ollama server is offline; we'll fall back to defaults below.
				WP_MCP_AI_Logger::log_error(
					'Failed to fetch live Ollama models for dropdown.',
					array( 'error' => $e->getMessage() )
				);
			}
		}

		// Always include the configured default model first if set so it's
		// guaranteed to appear in the dropdown.
		if ( ! empty( $settings['ollama_model'] ) ) {
			$configured = $settings['ollama_model'];
			$models     = array( $configured => $configured ) + $models;
		}

		// When live fetch failed, seed the dropdown with the full common Ollama
		// model list (including cloud models) so users always have a rich
		// selection to choose from, even when the local server is unreachable.
		// Any model already present (configured default or live result) is kept
		// unchanged; common entries only fill the gaps.
		if ( ! $live_fetch_success && ! $requires_vision && ! $requires_multimodal ) {
			$common_ollama_models = array(
				// Latest flagship models (2025-2026).
				'llama4'             => 'Llama 4 (Latest Meta flagship)',
				'deepseek-r1'        => 'DeepSeek R1 (Reasoning)',
				'deepseek-v3'        => 'DeepSeek V3',
				'qwen3'              => 'Qwen 3',
				'qwen3.6'            => 'Qwen 3.6',
				// Established models.
				'llama3.3'           => 'Llama 3.3',
				'llama3.2'           => 'Llama 3.2',
				'llama3.1'           => 'Llama 3.1',
				'llama3'             => 'Llama 3',
				'mistral'            => 'Mistral',
				'mistral-large'      => 'Mistral Large',
				'mixtral'            => 'Mixtral',
				'gemma4'             => 'Gemma 4',
				'gemma3'             => 'Gemma 3',
				'gemma2'             => 'Gemma 2',
				'phi4'               => 'Phi-4',
				'phi3'               => 'Phi-3',
				'codellama'          => 'CodeLlama',
				'qwen2.5'            => 'Qwen 2.5',
				// Cloud-hosted models accessible via Ollama cloud (:cloud suffix).
				'gemma4:31b-cloud'   => 'Gemma 4 31B ☁ (Cloud)',
				'qwen3.5:397b-cloud' => 'Qwen 3.5 397B ☁ (Cloud)',
				'kimi-k2.5:cloud'    => 'Kimi K2.5 ☁ (Cloud)',
				'kimi-k2.6:cloud'    => 'Kimi K2.6 ☁ (Cloud)',
				'glm-5:cloud'        => 'GLM-5 ☁ (Cloud)',
				'minimax-m2.7:cloud' => 'MiniMax M2.7 ☁ (Cloud)',
				'gpt-oss:120b-cloud' => 'GPT-OSS 120B ☁ (Cloud)',
				'gpt-oss:20b-cloud'  => 'GPT-OSS 20B ☁ (Cloud)',
			);

			foreach ( $common_ollama_models as $model_id => $model_name ) {
				if ( ! isset( $models[ $model_id ] ) ) {
					$models[ $model_id ] = $model_name;
				}
			}
		}

		return $models;
	}

	/**
	 * Get LM Studio models
	 *
	 * @param array $settings              Plugin settings.
	 * @param bool  $requires_vision       Whether vision capability is required.
	 * @param bool  $requires_multimodal   Whether multimodal capability is required.
	 * @return array Model list.
	 */
	protected function get_lm_studio_models( $settings, $requires_vision, $requires_multimodal ) {
		if ( empty( $settings['lm_studio_endpoint_url'] ) || empty( $settings['lm_studio_model'] ) ) {
			return array();
		}

		$models = array(
			$settings['lm_studio_model'] => $settings['lm_studio_model'],
		);

		// Add common LM Studio models (popular models from lmstudio.ai - 2025-2026).
		$common_lm_studio_models = array(
			// Llama 4 (Meta's latest flagship - 2026).
			'meta-llama/llama-4-scout-17b-16e-instruct' => 'Llama 4 Scout 17B (Multimodal)',
			'meta-llama/llama-4-maverick-17b-128e-instruct' => 'Llama 4 Maverick 17B',
			// Qwen 3 (top open-source performer 2025-2026).
			'qwen/qwen3-30b-a3b'                        => 'Qwen 3 30B A3B',
			'qwen/qwen3-14b'                            => 'Qwen 3 14B',
			'qwen/qwen3-8b'                             => 'Qwen 3 8B',
			// Qwen 3.6 (latest Qwen release).
			'qwen/qwen3.6-27b'                          => 'Qwen 3.6 27B',
			'qwen/qwen3.6-35b-a3b'                      => 'Qwen 3.6 35B A3B (MoE)',
			// Qwen 2.5 (coding and multilingual models).
			'qwen/qwen3-coder-30b'                      => 'Qwen 3 Coder 30B',
			'qwen/qwen2.5-coder-32b'                    => 'Qwen 2.5 Coder 32B',
			'qwen/qwen2.5-32b'                          => 'Qwen 2.5 32B',
			'qwen/qwen2.5-14b'                          => 'Qwen 2.5 14B',
			'qwen/qwen2.5-7b'                           => 'Qwen 2.5 7B',
			// Llama 3.x (Meta's established models).
			'meta-llama/llama-3.3-70b-instruct'         => 'Llama 3.3 70B Instruct',
			'meta-llama/llama-3.2-3b-instruct'          => 'Llama 3.2 3B Instruct',
			'meta-llama/llama-3.2-1b-instruct'          => 'Llama 3.2 1B Instruct',
			'meta-llama/llama-3.1-8b-instruct'          => 'Llama 3.1 8B Instruct',
			// Mistral (efficient reasoning).
			'mistralai/mistral-large-3'                 => 'Mistral Large 3',
			'mistralai/mistral-large-2411'              => 'Mistral Large 2411',
			'mistralai/mistral-nemo-2407'               => 'Mistral Nemo 2407',
			'mistralai/mistral-7b-instruct-v0.3'        => 'Mistral 7B Instruct v0.3',
			'mistralai/mixtral-8x7b-instruct'           => 'Mixtral 8x7B Instruct',
			'mistralai/mixtral-8x22b-instruct'          => 'Mixtral 8x22B Instruct',
			// DeepSeek (reasoning specialist).
			'deepseek-ai/deepseek-r1'                   => 'DeepSeek R1 (Reasoning)',
			'deepseek-ai/deepseek-r1-distill-qwen-32b'  => 'DeepSeek R1 Distill Qwen 32B',
			'deepseek-ai/deepseek-r1-distill-qwen-14b'  => 'DeepSeek R1 Distill Qwen 14B',
			'deepseek-ai/deepseek-r1-distill-qwen-7b'   => 'DeepSeek R1 Distill Qwen 7B',
			'deepseek-ai/deepseek-v3'                   => 'DeepSeek V3',
			'deepseek-ai/deepseek-coder-33b-instruct'   => 'DeepSeek Coder 33B Instruct',
			// Microsoft Phi (small but capable).
			'microsoft/phi-4'                           => 'Phi-4',
			'microsoft/phi-4-mini-instruct'             => 'Phi-4 Mini Instruct',
			'microsoft/phi-3.5-mini-instruct'           => 'Phi-3.5 Mini Instruct',
			// Google Gemma.
			'google/gemma-4-31b-it'                     => 'Gemma 4 31B Dense (Multimodal)',
			'google/gemma-4-26b-it'                     => 'Gemma 4 26B MoE (Multimodal)',
			'google/gemma-3-12b-it'                     => 'Gemma 3 12B Instruct',
			'google/gemma-2-27b-it'                     => 'Gemma 2 27B Instruct',
			'google/gemma-2-9b-it'                      => 'Gemma 2 9B Instruct',
		);

		// Add common models that match requirements.
		if ( ! $requires_vision && ! $requires_multimodal ) {
			foreach ( $common_lm_studio_models as $model_id => $model_name ) {
				if ( $model_id !== $settings['lm_studio_model'] ) {
					$models[ $model_id ] = $model_name;
				}
			}
		}

		return $models;
	}

	/**
	 * Get Cloudflare Workers AI models
	 *
	 * @param array $settings              Plugin settings.
	 * @param bool  $requires_vision       Whether vision capability is required.
	 * @param bool  $requires_multimodal   Whether multimodal capability is required.
	 * @return array Model list.
	 */
	protected function get_cloudflare_models( $settings, $requires_vision, $requires_multimodal ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameters reserved for capability filtering.
		// Check if Cloudflare provider is enabled and configured.
		if ( empty( $settings['enable_cloudflare'] ) || empty( $settings['cloudflare_api_token'] ) || empty( $settings['cloudflare_account_id'] ) ) {
			return array();
		}

		$models = array();

		// Function Calling Models.
		$models['@cf/meta/llama-4-scout-17b-16e-instruct']      = 'Llama 4 Scout 17B 16E Instruct (Multimodal)';
		$models['@cf/meta/llama-4-maverick-17b-128e-instruct']  = 'Llama 4 Maverick 17B 128E Instruct';
		$models['@cf/meta/llama-3.3-70b-instruct-fp8-fast']     = 'Llama 3.3 70B Instruct FP8 Fast';
		$models['@cf/ibm-granite/granite-4.0-h-micro']          = 'IBM Granite 4.0 H Micro';
		$models['@cf/qwen/qwen3-30b-a3b-fp8']                   = 'Qwen 3 30B A3B FP8';
		$models['@cf/mistralai/mistral-small-3.1-24b-instruct'] = 'Mistral Small 3.1 24B Instruct';
		$models['@hf/nousresearch/hermes-2-pro-mistral-7b']     = 'Hermes 2 Pro Mistral 7B';

		// Text Generation Models.
		$models['@cf/google/gemma-4-26b-it']                    = 'Gemma 4 26B MoE (Multimodal)';
		$models['@cf/aisingapore/gemma-sea-lion-v4-27b-it']     = 'Gemma SEA Lion V4 27B IT';
		$models['@cf/openai/gpt-oss-20b']                       = 'GPT OSS 20B';
		$models['@cf/openai/gpt-oss-120b']                      = 'GPT OSS 120B';
		$models['@cf/google/gemma-3-12b-it']                    = 'Gemma 3 12B IT';
		$models['@cf/qwen/qwq-32b']                             = 'Qwen QwQ 32B';
		$models['@cf/qwen/qwen2.5-coder-32b-instruct']          = 'Qwen 2.5 Coder 32B Instruct';
		$models['@cf/deepseek-ai/deepseek-r1-distill-qwen-32b'] = 'DeepSeek R1 Distill Qwen 32B';
		$models['@cf/meta/llama-3.2-1b-instruct']               = 'Llama 3.2 1B Instruct';
		$models['@cf/meta/llama-3.2-3b-instruct']               = 'Llama 3.2 3B Instruct';

		return $models;
	}

	/**
	 * Get NVIDIA NIM models.
	 *
	 * Returns a static list of NVIDIA NIM models available via integrate.api.nvidia.com.
	 *
	 * @param array $settings             Plugin settings.
	 * @param bool  $requires_vision      Whether vision capability is required.
	 * @param bool  $requires_multimodal  Whether multimodal capability is required.
	 * @return array Array of model_id => model_name pairs.
	 */
	protected function get_nvidia_models( $settings, $requires_vision, $requires_multimodal ) {
		if ( empty( $settings['nvidia_api_key'] ) ) {
			return array();
		}

		$models = array();

		// Meta Llama 4 Models (MoE, Multimodal).
		$models['meta/llama-4-maverick-17b-128e-instruct'] = 'Llama 4 Maverick 17Bx128E (1M context, Vision)';
		$models['meta/llama-4-scout-17b-16e-instruct']     = 'Llama 4 Scout 17Bx16E (1M context, Vision)';

		// Meta Llama 3.x Family.
		$models['meta/llama-3.3-70b-instruct']        = 'Llama 3.3 70B Instruct (Free)';
		$models['meta/llama-3.1-405b-instruct']       = 'Llama 3.1 405B Instruct';
		$models['meta/llama-3.1-70b-instruct']        = 'Llama 3.1 70B Instruct (Free)';
		$models['meta/llama-3.1-8b-instruct']         = 'Llama 3.1 8B Instruct (Free)';
		$models['meta/llama-3.2-3b-instruct']         = 'Llama 3.2 3B Instruct (Free)';
		$models['meta/llama-3.2-1b-instruct']         = 'Llama 3.2 1B Instruct (Free)';
		$models['meta/llama-3.2-90b-vision-instruct'] = 'Llama 3.2 90B Vision Instruct';
		$models['meta/llama-3.2-11b-vision-instruct'] = 'Llama 3.2 11B Vision Instruct (Free)';

		// NVIDIA Nemotron Models.
		$models['nvidia/llama-3.1-nemotron-70b-instruct'] = 'Nemotron 70B Instruct (Free)';
		$models['nvidia/nemotron-3-super-120b-a12b']      = 'Nemotron 3 Super 120B MoE (1M context)';
		$models['nvidia/nemotron-3-nano-30b-a3b']         = 'Nemotron 3 Nano 30B MoE (Free, 1M context)';

		// Mistral AI Models.
		$models['mistralai/mistral-large-2-instruct']        = 'Mistral Large 2 Instruct (Free)';
		$models['mistralai/mixtral-8x22b-instruct-v0.1']     = 'Mixtral 8x22B Instruct (Free)';
		$models['mistralai/mixtral-8x7b-instruct-v0.1']      = 'Mixtral 8x7B Instruct (Free)';
		$models['mistralai/mistral-7b-instruct-v0.3']        = 'Mistral 7B Instruct (Free)';
		$models['mistralai/mistral-small-24b-instruct-2501'] = 'Mistral Small 24B Instruct (Free)';

		// Microsoft Phi-3 Models.
		$models['microsoft/phi-3-medium-128k-instruct'] = 'Phi-3 Medium 128K Instruct (Free)';
		$models['microsoft/phi-3-medium-4k-instruct']   = 'Phi-3 Medium 4K Instruct (Free)';
		$models['microsoft/phi-3-mini-128k-instruct']   = 'Phi-3 Mini 128K Instruct (Free)';
		$models['microsoft/phi-3-mini-4k-instruct']     = 'Phi-3 Mini 4K Instruct (Free)';
		$models['microsoft/phi-3-small-128k-instruct']  = 'Phi-3 Small 128K Instruct (Free)';
		$models['microsoft/phi-3-small-8k-instruct']    = 'Phi-3 Small 8K Instruct (Free)';

		// Google Gemma 4 Models (Multimodal, Apache 2.0).
		$models['google/gemma-4-31b-it'] = 'Gemma 4 31B Dense (Multimodal, 256K)';
		$models['google/gemma-4-26b-it'] = 'Gemma 4 26B MoE (Multimodal, 256K)';
		$models['google/gemma-4-e4b-it'] = 'Gemma 4 E4B (Multimodal, 128K, Edge)';
		$models['google/gemma-4-e2b-it'] = 'Gemma 4 E2B (Multimodal, 128K, Edge)';

		// Google Gemma 2 Models.
		$models['google/gemma-2-27b-it'] = 'Gemma 2 27B IT (Free)';
		$models['google/gemma-2-9b-it']  = 'Gemma 2 9B IT (Free)';
		$models['google/gemma-2-2b-it']  = 'Gemma 2 2B IT (Free)';
		$models['google/codegemma-7b']   = 'CodeGemma 7B (Free)';

		// Google Gemma 3 Models (Multimodal).
		$models['google/gemma-3-27b-it']  = 'Gemma 3 27B IT (Free, Vision)';
		$models['google/gemma-3-12b-it']  = 'Gemma 3 12B IT (Free, Vision)';
		$models['google/gemma-3-4b-it']   = 'Gemma 3 4B IT (Free, Vision)';
		$models['google/gemma-3-1b-it']   = 'Gemma 3 1B IT (Free)';
		$models['google/gemma-3n-e4b-it'] = 'Gemma 3n E4B IT (Free)';

		// Qwen 2.5 Models.
		$models['qwen/qwen2.5-72b-instruct'] = 'Qwen 2.5 72B Instruct (Free)';
		$models['qwen/qwen2.5-32b-instruct'] = 'Qwen 2.5 32B Instruct (Free)';
		$models['qwen/qwen2.5-14b-instruct'] = 'Qwen 2.5 14B Instruct (Free)';
		$models['qwen/qwen2.5-7b-instruct']  = 'Qwen 2.5 7B Instruct (Free)';

		// Qwen 3 Models (Dense + MoE, Thinking).
		$models['qwen/qwen3-235b-a22b'] = 'Qwen 3 235B A22B MoE (Thinking)';
		$models['qwen/qwen3-32b']       = 'Qwen 3 32B (Free, Thinking)';
		$models['qwen/qwen3-30b-a3b']   = 'Qwen 3 30B A3B MoE (Free, Thinking)';
		$models['qwen/qwen3-14b']       = 'Qwen 3 14B (Free, Thinking)';
		$models['qwen/qwen3-8b']        = 'Qwen 3 8B (Free, Thinking)';
		$models['qwen/qwen3-4b']        = 'Qwen 3 4B (Free, Thinking)';

		// Qwen 3.5 Models (Latest, Vision + MoE).
		$models['qwen/qwen3.5-397b-a17b'] = 'Qwen 3.5 397B A17B (Vision, MoE)';
		$models['qwen/qwen3.5-122b-a10b'] = 'Qwen 3.5 122B A10B MoE (Free)';

		// Qwen 3 Specialized Models.
		$models['qwen/qwen3-coder-480b-a35b-instruct'] = 'Qwen 3 Coder 480B A35B (Coding)';

		// DeepSeek Models (Reasoning).
		$models['deepseek-ai/deepseek-r1']                   = 'DeepSeek R1 (Reasoning)';
		$models['deepseek-ai/deepseek-r1-distill-llama-70b'] = 'DeepSeek R1 Distill Llama 70B (Free)';
		$models['deepseek-ai/deepseek-r1-distill-llama-8b']  = 'DeepSeek R1 Distill Llama 8B (Free)';
		$models['deepseek-ai/deepseek-r1-distill-qwen-32b']  = 'DeepSeek R1 Distill Qwen 32B (Free)';
		$models['deepseek-ai/deepseek-r1-distill-qwen-14b']  = 'DeepSeek R1 Distill Qwen 14B (Free)';
		$models['deepseek-ai/deepseek-r1-distill-qwen-7b']   = 'DeepSeek R1 Distill Qwen 7B (Free)';

		// IBM Granite Code Models.
		$models['ibm/granite-34b-code-instruct'] = 'Granite 34B Code Instruct (Free)';
		$models['ibm/granite-8b-code-instruct']  = 'Granite 8B Code Instruct (Free)';

		// Databricks DBRX.
		$models['databricks/dbrx-instruct'] = 'DBRX Instruct 132B MoE (Free)';

		// MiniMax Models.
		$models['minimax/minimax-m1-80k'] = 'MiniMax M1 80K (Free)';

		// Filter by vision/multimodal requirements.
		if ( $requires_vision || $requires_multimodal ) {
			$vision_models = array(
				'meta/llama-4-maverick-17b-128e-instruct',
				'meta/llama-4-scout-17b-16e-instruct',
				'meta/llama-3.2-90b-vision-instruct',
				'meta/llama-3.2-11b-vision-instruct',
				'google/gemma-4-31b-it',
				'google/gemma-4-26b-it',
				'google/gemma-4-e4b-it',
				'google/gemma-4-e2b-it',
				'google/gemma-3-27b-it',
				'google/gemma-3-12b-it',
				'google/gemma-3-4b-it',
				'qwen/qwen3.5-397b-a17b',
			);
			$models        = array_intersect_key( $models, array_flip( $vision_models ) );
		}

		return $models;
	}

	/**
	 * Get embedded models
	 *
	 * Returns client-side WebLLM models that run in the browser using WebGPU/WebAssembly.
	 * These models are loaded from CDN on-demand, not server-side GGUF models.
	 *
	 * @param array $settings Settings array.
	 * @return array Array of model_id => model_name pairs.
	 */
	protected function get_embedded_models( $settings ) {
		// Check if embedded provider is enabled (defaults to true when Pro is active).
		// Auto-enable when Pro is active to match the field's 'default' => true in Pro Providers section.
		$embedded_settings = WP_MCP_AI_Admin_Settings::get_embedded_provider_effective_settings( $settings );
		$enable_embedded   = $embedded_settings['enabled'];
		if ( empty( $enable_embedded ) ) {
			return array();
		}

		// Check if Pro addon is present (embedded only available with Pro).
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return array();
		}

		// Return client-side WebLLM models (run in browser via WebGPU/WebAssembly).
		// These models are loaded from CDN automatically when first used.
		// All available models are listed. Models marked with * support function calling.
		$models = array(
			'Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC'      => __( 'Hermes 2 Pro Llama 3 8B (~4.5GB) - Recommended*', 'mcp-ai-wpoos' ),
			'Hermes-3-Llama-3.1-8B-q4f16_1-MLC'        => __( 'Hermes 3 Llama 3.1 8B (~4.9GB)*', 'mcp-ai-wpoos' ),
			'DeepSeek-R1-Distill-Llama-8B-q4f16_1-MLC' => __( 'DeepSeek R1 Distill Llama 8B (~5GB)', 'mcp-ai-wpoos' ),
			'DeepSeek-R1-Distill-Qwen-7B-q4f16_1-MLC'  => __( 'DeepSeek R1 Distill Qwen 7B (~5.1GB)', 'mcp-ai-wpoos' ),
			'Qwen3-8B-q4f16_1-MLC'                     => __( 'Qwen3 8B (~5GB)*', 'mcp-ai-wpoos' ),
			'Qwen2.5-7B-Instruct-q4f16_1-MLC'          => __( 'Qwen2.5 7B Instruct (~4.5GB)*', 'mcp-ai-wpoos' ),
			'Qwen3-4B-q4f16_1-MLC'                     => __( 'Qwen3 4B (~2.5GB)*', 'mcp-ai-wpoos' ),
			'Phi-3.5-mini-instruct-q4f16_1-MLC'        => __( 'Phi-3.5 Mini Instruct (~2.5GB)*', 'mcp-ai-wpoos' ),
			'gemma-2-2b-it-q4f16_1-MLC'                => __( 'Gemma 2 2B Instruct (~1.9GB)', 'mcp-ai-wpoos' ),
			'Llama-3.2-3B-Instruct-q4f16_1-MLC'        => __( 'Llama 3.2 3B Instruct (~2GB)', 'mcp-ai-wpoos' ),
			'SmolLM2-1.7B-Instruct-q4f16_1-MLC'        => __( 'SmolLM2 1.7B Instruct (~1.8GB)', 'mcp-ai-wpoos' ),
			'Qwen3-1.7B-q4f16_1-MLC'                   => __( 'Qwen3 1.7B (~1.1GB)*', 'mcp-ai-wpoos' ),
			'Qwen2.5-1.5B-Instruct-q4f16_1-MLC'        => __( 'Qwen2.5 1.5B Instruct (~1GB)*', 'mcp-ai-wpoos' ),
			'Llama-3.2-1B-Instruct-q4f16_1-MLC'        => __( 'Llama 3.2 1B Instruct (~800MB)', 'mcp-ai-wpoos' ),
			'Qwen3-0.6B-q4f16_1-MLC'                   => __( 'Qwen3 0.6B (~400MB)', 'mcp-ai-wpoos' ),
			'Qwen2.5-0.5B-Instruct-q4f16_1-MLC'        => __( 'Qwen2.5 0.5B Instruct (~400MB)', 'mcp-ai-wpoos' ),
		);

		// Append any server-side GGUF models that have been downloaded.
		if ( class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
			$client     = new WP_MCP_AI_Embedded_Client();
			$downloaded = $client->get_downloaded_models();
			foreach ( $downloaded as $slug => $model ) {
				// Prefix slug to distinguish server-side models from client-side ones.
				$models[ $slug ] = sprintf(
					/* translators: %s: model name */
					__( '[Server] %s', 'mcp-ai-wpoos' ),
					$model['name']
				);
			}
		}

		return $models;
	}

	/**
	 * Validate model for provider
	 *
	 * @param string $model    Model ID.
	 * @param string $provider Provider name.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	public function validate_model_for_provider( $model, $provider ) {
		$models = $this->get_models_for_provider( $provider );

		if ( empty( $models ) ) {
			WP_MCP_AI_Logger::log_event(
				'model_service_no_models',
				'No models available for provider',
				array( 'provider' => $provider )
			);

			return new WP_Error(
				'wp_mcp_ai_no_models',
				sprintf(
					/* translators: %s: Provider name */
					__( 'No models available for provider: %s', 'mcp-ai-wpoos' ),
					$provider
				)
			);
		}

		if ( ! isset( $models[ $model ] ) ) {
			WP_MCP_AI_Logger::log_event(
				'model_service_invalid_model',
				'Invalid model for provider',
				array(
					'model'    => $model,
					'provider' => $provider,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_invalid_model',
				sprintf(
					/* translators: 1: Model ID, 2: Provider name */
					__( 'Invalid model "%1$s" for provider "%2$s"', 'mcp-ai-wpoos' ),
					$model,
					$provider
				)
			);
		}

		return true;
	}

	/**
	 * Get default model for provider.
	 *
	 * Returns the stable default model for the given provider.
	 * Use get_provider_default_models_by_lane() for lane-specific defaults.
	 *
	 * @param string $provider Provider name.
	 * @return string Default model ID.
	 */
	public function get_default_model_for_provider( $provider ) {
		$lanes = $this->get_provider_default_models_by_lane( $provider );

		$default = isset( $lanes['stable'] ) ? $lanes['stable'] : '';

		/**
		 * Filter default model for provider.
		 *
		 * @since 1.0.0
		 *
		 * @param string $default  Default model ID.
		 * @param string $provider Provider name.
		 */
		return apply_filters( 'wp_mcp_ai_default_model_for_provider', $default, $provider );
	}

	/**
	 * Get DeepSeek models.
	 *
	 * @param array $settings             Plugin settings.
	 * @param bool  $requires_vision      Whether vision capability is required.
	 * @param bool  $requires_multimodal  Whether multimodal capability is required.
	 * @return array Array of model_id => model_name pairs.
	 */
	protected function get_deepseek_models( $settings, $requires_vision, $requires_multimodal ) {
		if ( empty( $settings['enable_deepseek'] ) || empty( $settings['deepseek_api_key'] ) ) {
			return array();
		}

		$models = array();

		// DeepSeek V4 series (current flagship).
		$models['deepseek-v4-flash'] = 'DeepSeek V4 Flash (1M Context, Fast)';
		$models['deepseek-v4-pro']   = 'DeepSeek V4 Pro (Enhanced Reasoning)';

		// DeepSeek V3 (legacy).
		$models['deepseek-chat'] = 'DeepSeek V3 (Chat)';

		// DeepSeek R1 (legacy reasoning, no tool calling).
		if ( empty( $requires_vision ) && empty( $requires_multimodal ) ) {
			$models['deepseek-reasoner'] = 'DeepSeek R1 (Reasoning, No Tools)';
		}

		return $models;
	}

	/**
	 * Get OpenRouter models.
	 *
	 * OpenRouter provides access to many models from different providers.
	 * This returns a curated selection of popular models.
	 *
	 * @param array $settings             Plugin settings.
	 * @param bool  $requires_vision      Whether vision capability is required.
	 * @param bool  $requires_multimodal  Whether multimodal capability is required.
	 * @return array Array of model_id => model_name pairs.
	 */
	protected function get_openrouter_models( $settings, $requires_vision, $requires_multimodal ) {
		if ( empty( $settings['enable_openrouter'] ) || empty( $settings['openrouter_api_key'] ) ) {
			return array();
		}

		$models = array();

		// OpenAI models via OpenRouter.
		$models['openai/gpt-5.4']      = 'OpenAI GPT-5.4 (1M Context)';
		$models['openai/gpt-5.4-mini'] = 'OpenAI GPT-5.4 Mini (Budget)';
		$models['openai/gpt-4.1']      = 'OpenAI GPT-4.1';

		// Anthropic models via OpenRouter.
		$models['anthropic/claude-sonnet-4-6'] = 'Anthropic Claude Sonnet 4.6';
		$models['anthropic/claude-haiku-4-5']  = 'Anthropic Claude Haiku 4.5';

		// Google models via OpenRouter.
		$models['google/gemini-2.5-pro']   = 'Google Gemini 2.5 Pro';
		$models['google/gemini-2.5-flash'] = 'Google Gemini 2.5 Flash';

		// Meta models via OpenRouter.
		$models['meta-llama/llama-4-maverick-17b-128e-instruct'] = 'Meta Llama 4 Maverick 17Bx128E';
		$models['meta-llama/llama-4-scout-17b-16e-instruct']     = 'Meta Llama 4 Scout 17Bx16E';

		// DeepSeek models via OpenRouter.
		$models['deepseek/deepseek-chat']                 = 'DeepSeek V3 (Chat)';
		$models['deepseek/deepseek-r1']                   = 'DeepSeek R1 (Reasoning)';
		$models['deepseek/deepseek-r1-distill-llama-70b'] = 'DeepSeek R1 Distill Llama 70B';

		return $models;
	}

	/**
	 * Get DigitalOcean Serverless Inference models.
	 *
	 * @param array $settings             Plugin settings.
	 * @param bool  $requires_vision      Whether vision capability is required.
	 * @param bool  $requires_multimodal  Whether multimodal capability is required.
	 * @return array Array of model_id => model_name pairs.
	 */
	protected function get_digitalocean_models( $settings, $requires_vision, $requires_multimodal ) {
		if ( empty( $settings['enable_digitalocean'] ) || empty( $settings['digitalocean_api_key'] ) ) {
			return array();
		}

		$models = array();

		// Llama models.
		$models['meta-llama/llama-3.3-70b-instruct'] = 'Llama 3.3 70B Instruct';
		$models['meta-llama/llama-3.1-8b-instruct']  = 'Llama 3.1 8B Instruct';

		// Mistral models.
		$models['mistralai/mistral-large-latest'] = 'Mistral Large (Latest)';

		return $models;
	}

	/**
	 * Get Kimi (Moonshot AI) models.
	 *
	 * @param array $settings             Plugin settings.
	 * @param bool  $requires_vision      Whether vision capability is required.
	 * @param bool  $requires_multimodal  Whether multimodal capability is required.
	 * @return array Array of model_id => model_name pairs.
	 */
	protected function get_kimi_models( $settings, $requires_vision, $requires_multimodal ) {
		if ( empty( $settings['enable_kimi'] ) || empty( $settings['kimi_api_key'] ) ) {
			return array();
		}

		$models = array();

		$models['kimi-latest']      = 'Kimi Latest (1M Context)';
		$models['moonshot-v1-8k']   = 'Moonshot V1 (8K)';
		$models['moonshot-v1-32k']  = 'Moonshot V1 (32K)';
		$models['moonshot-v1-128k'] = 'Moonshot V1 (128K)';

		return $models;
	}

	/**
	 * Get Baseten models.
	 *
	 * @param array $settings             Plugin settings.
	 * @param bool  $requires_vision      Whether vision capability is required.
	 * @param bool  $requires_multimodal  Whether multimodal capability is required.
	 * @return array Array of model_id => model_name pairs.
	 */
	protected function get_baseten_models( $settings, $requires_vision, $requires_multimodal ) {
		if ( empty( $settings['enable_baseten'] ) || empty( $settings['baseten_api_key'] ) ) {
			return array();
		}

		$models = array();

		// Note: Baseten hosts custom models. The model ID is typically the deployment ID.
		// Users should configure specific models via the Baseten dashboard.
		// We provide common deployment patterns as defaults.

		return $models;
	}

	/**
	 * Get provider default models organized by lane.
	 *
	 * Returns an associative array of default models for each lane:
	 * - stable:  Proven production default.
	 * - latest:  Current recommended / preview model.
	 * - budget:  Cost-effective / fast alternative.
	 * - vision:  Default model for vision tasks (if applicable).
	 * - image:   Default model for image generation (if applicable).
	 * - audio_in:  Default model for speech-to-text (if applicable).
	 * - audio_out: Default model for text-to-speech (if applicable).
	 *
	 * @since 2.0.0
	 *
	 * @param string $provider Provider name.
	 * @return array Associative array of lane => model ID.
	 */
	public function get_provider_default_models_by_lane( $provider ) {
		$provider_lanes = array(
			'openai'       => array(
				'stable'    => 'gpt-4.1',
				'latest'    => 'gpt-5.4',
				'budget'    => 'gpt-5.4-mini',
				'vision'    => 'gpt-4.1',
				'image'     => 'gpt-image-2',
				'audio_in'  => 'gpt-4o-mini-transcribe',
				'audio_out' => 'gpt-4o-mini-tts',
			),
			'anthropic'    => array(
				'stable' => 'claude-sonnet-4-6',
				'latest' => 'claude-opus-4-6',
				'budget' => 'claude-haiku-4-5',
				'vision' => 'claude-sonnet-4-6',
			),
			'gemini'       => array(
				'stable' => 'gemini-2.5-flash',
				'latest' => 'gemini-2.5-pro',
				'budget' => 'gemini-2.5-flash',
				'vision' => 'gemini-2.5-flash',
				'image'  => 'gemini-3.1-flash-image',
			),
			'huggingface'  => array(
				'stable' => 'meta-llama/Llama-3.3-70B-Instruct',
			),
			'ollama'       => array(
				'stable' => 'llama4',
			),
			'lm_studio'    => array(
				'stable' => 'meta-llama/llama-4-scout-17b-16e-instruct',
			),
			'cloudflare'   => array(
				'stable' => '@cf/meta/llama-4-scout-17b-16e-instruct',
				'latest' => '@cf/meta/llama-4-scout-17b-16e-instruct',
				'budget' => '@cf/meta/llama-3.2-3b-instruct',
				'image'  => '@cf/black-forest-labs/flux-2-dev',
			),
			'embedded'     => array(
				'stable' => 'gemma-2-2b-it-q4f16_1-MLC',
			),
			'deepseek'     => array(
				'stable' => 'deepseek-v4-flash',
				'latest' => 'deepseek-v4-pro',
				'budget' => 'deepseek-v4-flash',
			),
			'openrouter'   => array(
				'stable' => 'openai/gpt-4.1',
				'latest' => 'openai/gpt-5.4',
				'budget' => 'openai/gpt-5.4-mini',
			),
			'digitalocean' => array(
				'stable' => 'meta-llama/llama-3.3-70b-instruct',
			),
			'kimi'         => array(
				'stable' => 'kimi-latest',
			),
			'baseten'      => array(
				'stable' => '',
			),
		);

		$lanes = isset( $provider_lanes[ $provider ] ) ? $provider_lanes[ $provider ] : array();

		/**
		 * Filter provider default models by lane.
		 *
		 * @since 2.0.0
		 *
		 * @param array  $lanes    Associative array of lane => model ID.
		 * @param string $provider Provider name.
		 */
		return apply_filters( 'wp_mcp_ai_provider_default_models_by_lane', $lanes, $provider );
	}
}
