<?php
/**
 * Tool that researches an AI model's specifications and capabilities.
 *
 * This tool uses the plugin's own AI capabilities to research model specifications
 * from provider documentation and APIs, extracting key information needed for
 * orchestration configuration.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Research AI Model Tool
 *
 * Uses AI to research and extract model specifications including:
 * - Context window size
 * - Rate limits (TPM, RPM, TPD, RPD)
 * - Pricing information
 * - Capabilities (vision, multimodal, function calling)
 * - Provider-specific details
 */
class WP_MCP_AI_Tool_Research_Model implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'research_model';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Research AI Model', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Research an AI model\'s specifications and capabilities using AI to extract information from provider documentation and APIs. Returns configuration data needed for orchestration layer integration.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'model_id'       => array(
					'type'        => 'string',
					'description' => __( 'Model identifier (e.g., gpt-4.5-turbo, claude-opus-4.2, gemini-3-pro).', 'mcp-ai-wpoos' ),
				),
				'provider'       => array(
					'type'        => 'string',
					'description' => __( 'AI provider name.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio', 'cloudflare', 'embedded' ),
				),
				'use_web_search' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to use web search to find model documentation (requires web search capability).', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'             => array( 'model_id', 'provider' ),
			'additionalProperties' => false,
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ai_model_management',

			'pattern_compatibility' => array( 'experimentation', 'orchestrator' ),

			'profession_tags'       => array( 'ai_researcher' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials',  // Needs API keys.
			'consumes-tokens',       // Uses AI model tokens.
			'external-api',          // Makes external API calls.
			'network-dependent',     // Requires internet.
			'may-timeout',           // Research can take time.
			'cacheable',             // Results can be cached.
			'write',                 // May write to cache/options.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check permissions - requires manage_options capability.
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to research AI models. This tool requires administrator privileges.', 'mcp-ai-wpoos' )
			);
		}

		// Validate required arguments.
		if ( empty( $arguments['model_id'] ) || empty( $arguments['provider'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_arguments',
				__( 'Both model_id and provider are required.', 'mcp-ai-wpoos' )
			);
		}

		$model_id   = sanitize_text_field( $arguments['model_id'] );
		$provider   = sanitize_key( $arguments['provider'] );
		$use_search = isset( $arguments['use_web_search'] ) ? (bool) $arguments['use_web_search'] : true;

		// Validate provider.
		$valid_providers = array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio', 'cloudflare', 'embedded' );
		if ( ! in_array( $provider, $valid_providers, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_provider',
				sprintf(
					/* translators: %s: provider name */
					__( 'Invalid provider: %s. Must be one of: openai, anthropic, gemini, huggingface, ollama, lm_studio, cloudflare, embedded', 'mcp-ai-wpoos' ),
					$provider
				)
			);
		}

		// Check cache first.
		$cache_key = 'model_research_' . md5( $provider . '_' . $model_id );
		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai_model_research' );

		if ( false !== $cached && is_array( $cached ) ) {
			$cached['_from_cache'] = true;
			return $cached;
		}

		// Log research start.
		WP_MCP_AI_Logger::log_event(
			'model_research_started',
			'Starting AI model research',
			array(
				'model_id'   => $model_id,
				'provider'   => $provider,
				'user_id'    => $user_id,
				'use_search' => $use_search,
			)
		);

		// Build research prompt.
		$prompt = $this->build_research_prompt( $model_id, $provider, $use_search );

		// Use the plugin's AI to research the model.
		$research_result = $this->perform_ai_research( $prompt, $context );

		if ( is_wp_error( $research_result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Model research failed: ' . $research_result->get_error_message(),
				array(
					'model_id' => $model_id,
					'provider' => $provider,
					'error'    => $research_result->get_error_code(),
				)
			);
			return $research_result;
		}

		// Parse and validate the research results.
		$model_config = $this->parse_research_results( $research_result, $model_id, $provider );

		if ( is_wp_error( $model_config ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to parse model research results: ' . $model_config->get_error_message(),
				array(
					'model_id' => $model_id,
					'provider' => $provider,
				)
			);
			return $model_config;
		}

		// Cache the results for 7 days.
		wp_cache_set( $cache_key, $model_config, 'wp_mcp_ai_model_research', 7 * DAY_IN_SECONDS );

		// Log success.
		WP_MCP_AI_Logger::log_event(
			'model_research_completed',
			'AI model research completed successfully',
			array(
				'model_id' => $model_id,
				'provider' => $provider,
				'config'   => $model_config,
			)
		);

		return $model_config;
	}

	/**
	 * Build the research prompt for the AI.
	 *
	 * @param string $model_id   Model identifier.
	 * @param string $provider   Provider name.
	 * @param bool   $use_search Whether to include web search instructions.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $model_id, $provider, $use_search ) {
		$prompt = sprintf(
			"Research the AI model '%s' from provider '%s' and extract the following specifications:\n\n",
			$model_id,
			$provider
		);

		$prompt .= "1. **Context Window**: Maximum context window size in tokens (input + output)\n";
		$prompt .= "2. **Output Tokens**: Maximum output/completion tokens\n";
		$prompt .= "3. **Rate Limits**:\n";
		$prompt .= "   - TPM (Tokens Per Minute)\n";
		$prompt .= "   - RPM (Requests Per Minute)\n";
		$prompt .= "   - TPD (Tokens Per Day)\n";
		$prompt .= "   - RPD (Requests Per Day)\n";
		$prompt .= "4. **Pricing**: Cost per 1K tokens (input and output if different)\n";
		$prompt .= "5. **Capabilities**: List all capabilities (vision, multimodal, function-calling, audio, video, etc.)\n";
		$prompt .= "6. **Status**: Current status (active, deprecated, experimental, preview)\n";
		$prompt .= "7. **Release Date**: When the model was released\n";
		$prompt .= "8. **Fallback Model**: Suggested fallback model if this one fails\n";
		$prompt .= "9. **Description**: Brief description of the model's purpose and use cases\n\n";

		if ( $use_search ) {
			$prompt .= 'Use web search to find the official documentation for this model. ';
			$prompt .= "Look for the provider's official documentation, pricing pages, and API reference.\n\n";
		} else {
			$prompt .= 'Use your knowledge of this model from training data. ';
			$prompt .= "If you don't have reliable information, indicate that with 'unknown' values.\n\n";
		}

		$prompt .= "**IMPORTANT**: Return the information in the following JSON format:\n\n";
		$prompt .= "```json\n";
		$prompt .= "{\n";
		$prompt .= '  "name": "Model Display Name",';
		$prompt .= "\n";
		$prompt .= '  "provider": "' . $provider . '",';
		$prompt .= "\n";
		$prompt .= '  "context_window": 128000,';
		$prompt .= "\n";
		$prompt .= '  "max_output_tokens": 16384,';
		$prompt .= "\n";
		$prompt .= '  "tpm": 80000,';
		$prompt .= "\n";
		$prompt .= '  "rpm": 500,';
		$prompt .= "\n";
		$prompt .= '  "tpd": 5000000,';
		$prompt .= "\n";
		$prompt .= '  "rpd": 10000,';
		$prompt .= "\n";
		$prompt .= '  "cost_per_1k_input": 0.005,';
		$prompt .= "\n";
		$prompt .= '  "cost_per_1k_output": 0.015,';
		$prompt .= "\n";
		$prompt .= '  "capabilities": ["vision", "multimodal", "function-calling"],';
		$prompt .= "\n";
		$prompt .= '  "status": "active",';
		$prompt .= "\n";
		$prompt .= '  "release_date": "2024-11-20",';
		$prompt .= "\n";
		$prompt .= '  "fallback_model": "gpt-4o",';
		$prompt .= "\n";
		$prompt .= '  "description": "Brief description of the model",';
		$prompt .= "\n";
		$prompt .= '  "confidence": 95,';
		$prompt .= "\n";
		$prompt .= '  "sources": ["https://..."]';
		$prompt .= "\n";
		$prompt .= "}\n";
		$prompt .= "```\n\n";

		$prompt .= 'Use reasonable defaults if exact values are not available. ';
		$prompt .= 'Include a confidence score (0-100) indicating how certain you are about the information. ';
		$prompt .= "List your sources if web search was used.\n";

		return $prompt;
	}

	/**
	 * Perform AI research using the plugin's AI capabilities.
	 *
	 * @param string $prompt  Research prompt.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Research results or error.
	 */
	protected function perform_ai_research( $prompt, $context ) {
		// Get a suitable AI model for research.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$provider = $this->get_research_provider( $settings );
		$model    = $this->get_research_model( $provider, $settings );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		if ( is_wp_error( $model ) ) {
			return $model;
		}

		// Build messages array.
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful AI assistant that researches and extracts AI model specifications from documentation. Always respond with valid JSON matching the requested format.',
			),
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		// Call the appropriate AI client.
		$client = $this->get_ai_client( $provider, $settings );

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Make the API call.
		$result = $client->create_chat_completion(
			$messages,
			array(
				'model'       => $model,
				'temperature' => 0.2, // Low temperature for factual information.
				'max_tokens'  => 2000,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Extract the content from the response.
		if ( ! isset( $result['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from AI provider.', 'mcp-ai-wpoos' )
			);
		}

		return array(
			'content'  => $result['choices'][0]['message']['content'],
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * Get the best available provider for research.
	 *
	 * @param array $settings Plugin settings.
	 * @return string|WP_Error Provider name or error.
	 */
	protected function get_research_provider( $settings ) {
		// Prefer OpenAI or Gemini for research tasks (best documentation access).
		if ( ! empty( $settings['openai_api_key'] ) ) {
			return 'openai';
		}

		if ( ! empty( $settings['gemini_api_key'] ) ) {
			return 'gemini';
		}

		if ( ! empty( $settings['anthropic_api_key'] ) ) {
			return 'anthropic';
		}

		return new WP_Error(
			'wp_mcp_ai_no_provider',
			__( 'No AI provider configured. Please configure OpenAI, Gemini, or Anthropic API keys in plugin settings.', 'mcp-ai-wpoos' )
		);
	}

	/**
	 * Get the best model for research from a provider.
	 *
	 * @param string $provider Provider name.
	 * @param array  $settings Plugin settings.
	 * @return string|WP_Error Model identifier or error.
	 */
	protected function get_research_model( $provider, $settings ) {
		switch ( $provider ) {
			case 'openai':
				// Use GPT-4 or better for research.
				return ! empty( $settings['openai_default_model'] ) ? $settings['openai_default_model'] : 'gpt-4o';

			case 'gemini':
				// Use Gemini Pro for research.
				return ! empty( $settings['gemini_default_model'] ) ? $settings['gemini_default_model'] : 'gemini-2.5-flash';

			case 'anthropic':
				// Use Claude Sonnet for research.
				return 'claude-sonnet-4-5-20250929';

			default:
				return new WP_Error(
					'wp_mcp_ai_unsupported_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'Provider not supported for research: %s', 'mcp-ai-wpoos' ),
						$provider
					)
				);
		}
	}

	/**
	 * Get the appropriate AI client for a provider.
	 *
	 * @param string $provider Provider name.
	 * @param array  $settings Plugin settings.
	 * @return object|WP_Error AI client instance or error.
	 */
	protected function get_ai_client( $provider, $settings ) {
		switch ( $provider ) {
			case 'openai':
				if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'OpenAI client not available.', 'mcp-ai-wpoos' )
					);
				}
				return new WP_MCP_AI_OpenAI_Client();

			case 'gemini':
				if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Gemini client not available.', 'mcp-ai-wpoos' )
					);
				}
				return new WP_MCP_AI_Gemini_Client();

			case 'anthropic':
				if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Anthropic client not available.', 'mcp-ai-wpoos' )
					);
				}
				return new WP_MCP_AI_Anthropic_Client();

			default:
				return new WP_Error(
					'wp_mcp_ai_unsupported_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'AI client not available for provider: %s', 'mcp-ai-wpoos' ),
						$provider
					)
				);
		}
	}

	/**
	 * Parse the AI research results into model configuration format.
	 *
	 * @param array  $research_result AI research results.
	 * @param string $model_id        Model identifier.
	 * @param string $provider        Provider name.
	 * @return array|WP_Error Parsed model configuration or error.
	 */
	protected function parse_research_results( $research_result, $model_id, $provider ) {
		$content = $research_result['content'];

		// Extract JSON from markdown code blocks if present.
		if ( preg_match( '/```json\s*(.*?)\s*```/s', $content, $matches ) ) {
			$json = $matches[1];
		} elseif ( preg_match( '/```\s*(.*?)\s*```/s', $content, $matches ) ) {
			$json = $matches[1];
		} else {
			$json = $content;
		}

		// Parse JSON.
		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'wp_mcp_ai_parse_error',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Failed to parse AI response as JSON: %s', 'mcp-ai-wpoos' ),
					json_last_error_msg()
				)
			);
		}

		// Validate required fields.
		$required_fields = array( 'name', 'provider', 'context_window' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_field',
					sprintf(
						/* translators: %s: field name */
						__( 'Required field missing from AI response: %s', 'mcp-ai-wpoos' ),
						$field
					)
				);
			}
		}

		// Build configuration matching WP_MCP_AI_Model_Config format.
		$config = array(
			'name'           => sanitize_text_field( $data['name'] ),
			'provider'       => sanitize_key( $data['provider'] ),
			'context_window' => absint( $data['context_window'] ),
			'tpm'            => isset( $data['tpm'] ) ? absint( $data['tpm'] ) : 80000,
			'rpm'            => isset( $data['rpm'] ) ? absint( $data['rpm'] ) : 500,
			'tpd'            => isset( $data['tpd'] ) ? absint( $data['tpd'] ) : 5000000,
			'rpd'            => isset( $data['rpd'] ) ? absint( $data['rpd'] ) : 10000,
			'status'         => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'active',
		);

		// Add pricing information (average if input/output differ).
		if ( isset( $data['cost_per_1k_input'] ) && isset( $data['cost_per_1k_output'] ) ) {
			$config['cost_per_1k'] = ( floatval( $data['cost_per_1k_input'] ) + floatval( $data['cost_per_1k_output'] ) ) / 2;
		} elseif ( isset( $data['cost_per_1k_input'] ) ) {
			$config['cost_per_1k'] = floatval( $data['cost_per_1k_input'] );
		} elseif ( isset( $data['cost_per_1k_output'] ) ) {
			$config['cost_per_1k'] = floatval( $data['cost_per_1k_output'] );
		} else {
			$config['cost_per_1k'] = 0.0;
		}

		// Add fallback model if specified.
		if ( ! empty( $data['fallback_model'] ) ) {
			$config['fallback_model'] = sanitize_text_field( $data['fallback_model'] );
		}

		// Add metadata for reference.
		$config['_research_metadata'] = array(
			'researched_at'     => current_time( 'mysql' ),
			'research_model'    => $research_result['model'],
			'research_provider' => $research_result['provider'],
			'confidence'        => isset( $data['confidence'] ) ? absint( $data['confidence'] ) : 0,
			'sources'           => isset( $data['sources'] ) && is_array( $data['sources'] ) ? $data['sources'] : array(),
			'capabilities'      => isset( $data['capabilities'] ) && is_array( $data['capabilities'] ) ? $data['capabilities'] : array(),
			'description'       => isset( $data['description'] ) ? sanitize_text_field( $data['description'] ) : '',
			'release_date'      => isset( $data['release_date'] ) ? sanitize_text_field( $data['release_date'] ) : '',
		);

		return $config;
	}
}
