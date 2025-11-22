<?php
/**
 * Model Service
 *
 * Handles AI model management operations.
 * Separates model logic from token limits class following SoC principles.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
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
	 * @param string $provider Provider name (openai, anthropic, gemini, ollama, lm_studio).
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
		$capability_flags   = isset( $args['capability_flags'] ) ? $args['capability_flags'] : array();
		$requires_vision    = in_array( 'vision', $capability_flags, true ) || in_array( 'requires-vision-model', $capability_flags, true );
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

			case 'ollama':
				$models = $this->get_ollama_models( $settings, $requires_vision, $requires_multimodal );
				break;

			case 'lm_studio':
				$models = $this->get_lm_studio_models( $settings, $requires_vision, $requires_multimodal );
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
				'provider'     => $provider,
				'model_count'  => count( $models ),
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

		// GPT-5 series (flagship - 2025).
		$models['gpt-5.1']              = 'GPT-5.1 (Flagship)';
		$models['gpt-5.1-2025-11-13']   = 'GPT-5.1 (Nov 2025)';
		$models['gpt-5']                = 'GPT-5';
		$models['gpt-5-2025-08-07']     = 'GPT-5 (Aug 2025)';
		$models['gpt-5-mini']           = 'GPT-5 Mini';
		$models['gpt-5-nano']           = 'GPT-5 Nano';
		$models['gpt-5-pro']            = 'GPT-5 Pro';

		// GPT-5 Codex variants (coding-optimized, text-only).
		if ( ! $requires_vision && ! $requires_multimodal ) {
			$models['gpt-5-codex']      = 'GPT-5 Codex';
			$models['gpt-5-codex-mini'] = 'GPT-5 Codex Mini';
		}

		// GPT-4o series (multimodal - vision capable).
		$models['gpt-4o']               = 'GPT-4o';
		$models['gpt-4o-mini']          = 'GPT-4o Mini';
		$models['gpt-4o-2024-11-20']    = 'GPT-4o (Nov 2024)';
		$models['gpt-4o-2024-08-06']    = 'GPT-4o (Aug 2024)';
		$models['gpt-4o-2024-05-13']    = 'GPT-4o (May 2024)';
		$models['chatgpt-4o-latest']    = 'ChatGPT-4o (Latest)';

		// Legacy models (text-only).
		if ( ! $requires_vision && ! $requires_multimodal ) {
			$models['gpt-4-turbo']    = 'GPT-4 Turbo (Legacy)';
			$models['gpt-4']          = 'GPT-4 (Legacy)';
			$models['gpt-3.5-turbo']  = 'GPT-3.5 Turbo (Legacy)';
		}

		return $models;
	}

	/**
	 * Get Anthropic (Claude) models
	 *
	 * @param array $settings Plugin settings.
	 * @return array Model list.
	 */
	protected function get_anthropic_models( $settings ) {
		if ( empty( $settings['anthropic_api_key'] ) ) {
			return array();
		}

		$models = array();

		// Claude 4 series (multimodal - vision capable) - 2025.
		$models['claude-sonnet-4.5']         = 'Claude Sonnet 4.5 (Recommended)';
		$models['claude-sonnet-4-5-20250929'] = 'Claude Sonnet 4.5 (Sep 2025)';
		$models['claude-haiku-4.5']          = 'Claude Haiku 4.5 (Fastest)';
		$models['claude-opus-4.1']           = 'Claude Opus 4.1 (Flagship)';
		$models['claude-opus-4.0']           = 'Claude Opus 4.0';

		// Claude 3.5 series (legacy - for backward compatibility).
		$models['claude-3-5-sonnet-20241022'] = 'Claude 3.5 Sonnet (Legacy)';
		$models['claude-3-5-haiku-20241022']  = 'Claude 3.5 Haiku (Legacy)';

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

		$models = array();
		$requires_image_gen = isset( $args['requires_image_gen'] ) ? $args['requires_image_gen'] : false;
		$requires_audio = isset( $args['capability_flags'] ) && in_array( 'audio', $args['capability_flags'], true );

		// Gemini 3 series (multimodal - latest generation) - Preview.
		$models['gemini-3-pro-preview']   = 'Gemini 3 Pro (Preview)';

		// Gemini 2.5 series (multimodal - text, image, video) - Stable.
		$models['gemini-2.5-pro']         = 'Gemini 2.5 Pro';
		$models['gemini-2.5-flash']       = 'Gemini 2.5 Flash (Recommended)';
		$models['gemini-2.5-flash-lite']  = 'Gemini 2.5 Flash Lite';
		$models['gemini-2.5-flash-preview-09-2025'] = 'Gemini 2.5 Flash (Sep 2025 Preview)';

		// Gemini 2.5 specialized models.
		$models['gemini-live-2.5-flash-preview'] = 'Gemini Live 2.5 Flash (Voice/Multimodal)';
		$models['gemini-2.5-flash-preview-native-audio-dialog'] = 'Gemini 2.5 Native Audio Dialog';
		$models['gemini-2.5-flash-preview-tts'] = 'Gemini 2.5 Flash TTS';
		$models['gemini-2.5-pro-preview-tts']   = 'Gemini 2.5 Pro TTS';

		// Music generation models - only for audio/music generation tools.
		if ( $requires_audio ) {
			$models['gemini-lyria']          = 'Gemini Lyria Music Generation';
			$models['gemini-lyria-realtime'] = 'Gemini Lyria RealTime (WebSocket)';
		}

		// Image generation model - only for image generation/editing tools.
		if ( $requires_image_gen ) {
			$models['gemini-2.5-flash-image'] = 'Gemini 2.5 Flash Image';
		}

		// Gemini 2.0 series (stable).
		$models['gemini-2.0-flash']       = 'Gemini 2.0 Flash';
		$models['gemini-2.0-flash-lite']  = 'Gemini 2.0 Flash Lite';
		$models['gemini-2.0-flash-exp']   = 'Gemini 2.0 Flash (Experimental)';

		// Experimental models.
		$models['gemini-exp-1206']        = 'Gemini Exp 1206';
		$models['gemini-exp-1121']        = 'Gemini Exp 1121';

		// Gemini 1.5 series (legacy - for backward compatibility).
		$models['gemini-1.5-pro']         = 'Gemini 1.5 Pro (Legacy)';
		$models['gemini-1.5-flash']       = 'Gemini 1.5 Flash (Legacy)';

		// Gemma models (Google's open models - text-only).
		if ( ! $requires_vision && ! $requires_multimodal ) {
			$models['gemma-2-27b-it']     = 'Gemma 2 27B (Instruct)';
			$models['gemma-2-9b-it']      = 'Gemma 2 9B (Instruct)';
			$models['gemma-2-2b-it']      = 'Gemma 2 2B (Instruct)';
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
		if ( empty( $settings['ollama_endpoint_url'] ) || empty( $settings['ollama_model'] ) ) {
			return array();
		}

		$models = array(
			$settings['ollama_model'] => $settings['ollama_model'],
		);

		// Add common Ollama models.
		$common_ollama_models = array(
			'llama3.2'          => 'Llama 3.2',
			'llama3.1'          => 'Llama 3.1',
			'llama3'            => 'Llama 3',
			'llama2'            => 'Llama 2',
			'mistral'           => 'Mistral',
			'mixtral'           => 'Mixtral',
			'gemma2'            => 'Gemma 2',
			'gemma'             => 'Gemma',
			'codellama'         => 'CodeLlama',
			'deepseek-coder'    => 'DeepSeek Coder',
			'phi3'              => 'Phi-3',
			'qwen2.5'           => 'Qwen 2.5',
		);

		// Add common models that match requirements.
		if ( ! $requires_vision && ! $requires_multimodal ) {
			foreach ( $common_ollama_models as $model_id => $model_name ) {
				if ( $model_id !== $settings['ollama_model'] ) {
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

		// Add common LM Studio models (popular models from lmstudio.ai - 2025).
		$common_lm_studio_models = array(
			// Qwen models (function calling, coding, vision) - Top performers.
			'qwen/qwen3-coder-30b'        => 'Qwen 3 Coder 30B',
			'qwen/qwen3-vl-30b'           => 'Qwen 3 Vision-Language 30B',
			'qwen/qwen2.5-coder-32b'      => 'Qwen 2.5 Coder 32B',
			'qwen/qwen2.5-32b'            => 'Qwen 2.5 32B',
			'qwen/qwen2.5-14b'            => 'Qwen 2.5 14B',
			'qwen/qwen2.5-7b'             => 'Qwen 2.5 7B',
			// Llama models (Meta's flagship).
			'meta-llama/llama-3.3-70b-instruct' => 'Llama 3.3 70B Instruct',
			'meta-llama/llama-3.2-3b-instruct'  => 'Llama 3.2 3B Instruct',
			'meta-llama/llama-3.2-1b-instruct'  => 'Llama 3.2 1B Instruct',
			'meta-llama/llama-3.1-8b-instruct'  => 'Llama 3.1 8B Instruct',
			// Mistral models (efficient reasoning).
			'mistralai/mistral-large-2411'      => 'Mistral Large 2411',
			'mistralai/mistral-nemo-2407'       => 'Mistral Nemo 2407',
			'mistralai/mistral-7b-instruct-v0.3' => 'Mistral 7B Instruct v0.3',
			'mistralai/mixtral-8x7b-instruct'   => 'Mixtral 8x7B Instruct',
			'mistralai/mixtral-8x22b-instruct'  => 'Mixtral 8x22B Instruct',
			// DeepSeek models (coding specialist).
			'deepseek-ai/deepseek-coder-33b-instruct' => 'DeepSeek Coder 33B Instruct',
			'deepseek-ai/deepseek-v3'                 => 'DeepSeek V3',
			'deepseek-ai/deepseek-r1'                 => 'DeepSeek R1 (Reasoning)',
			// Microsoft Phi models (small but capable).
			'microsoft/phi-4'                   => 'Phi-4',
			'microsoft/phi-3.5-mini-instruct'   => 'Phi-3.5 Mini Instruct',
			// Google Gemma models.
			'google/gemma-3-12b-it'             => 'Gemma 3 12B Instruct',
			'google/gemma-2-27b-it'             => 'Gemma 2 27B Instruct',
			'google/gemma-2-9b-it'              => 'Gemma 2 9B Instruct',
			'google/gemma-2-2b-it'              => 'Gemma 2 2B Instruct',
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
					__( 'No models available for provider: %s', 'wp-mcp-ai' ),
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
					__( 'Invalid model "%1$s" for provider "%2$s"', 'wp-mcp-ai' ),
					$model,
					$provider
				)
			);
		}

		return true;
	}

	/**
	 * Get default model for provider
	 *
	 * @param string $provider Provider name.
	 * @return string Default model ID.
	 */
	public function get_default_model_for_provider( $provider ) {
		$defaults = array(
			'openai'    => 'gpt-5.1',
			'anthropic' => 'claude-sonnet-4.5',
			'gemini'    => 'gemini-2.5-flash',
			'ollama'    => 'llama3.2',
			'lm_studio' => 'qwen/qwen2.5-7b',
		);

		$default = isset( $defaults[ $provider ] ) ? $defaults[ $provider ] : '';

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
}
