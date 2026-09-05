<?php
/**
 * Research AI Model tool (D8 Cluster 2c port of the base plugin's
 * WP_MCP_AI_Tool_Research_Model — byte-identical slug, schema, error
 * codes, envelope, cache group, and prompt; per-mode AI-call seam).
 *
 * In monolith installs the base provider clients
 * (WP_MCP_AI_OpenAI_Client etc.) serve the research completion; the
 * ported tool delegates to them to stay byte-identical. Standalone, the
 * nvoos-core ProviderRouter clients (via CoreBridge) are used — their
 * chat() responses are already normalized to the OpenAI
 * choices[0].message.content shape the parser expects.
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\CoreBridge;

/**
 * Researches an AI model's specifications via AI and returns
 * orchestration-ready configuration data.
 */
class ResearchModelTool extends AbstractAiTool {

	/**
	 * Provider slugs accepted by the base tool.
	 *
	 * @var string[]
	 */
	private const VALID_PROVIDERS = array( 'openai', 'anthropic', 'gemini', 'huggingface', 'nvidia', 'deepseek', 'openrouter', 'digitalocean', 'kimi', 'baseten', 'ollama', 'lm_studio', 'cloudflare', 'embedded' );

	/**
	 * Cache group for research results (base-identical).
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'wp_mcp_ai_model_research';

	public function getSlug(): string {
		return 'research_model';
	}

	public function getName(): string {
		return __( 'Research AI Model', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Research an AI model\'s specifications and capabilities using AI to extract information from provider documentation and APIs. Returns configuration data needed for orchestration layer integration.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'model_id'       => array(
					'type'        => 'string',
					'description' => __( 'Model identifier (e.g., gpt-5.2, claude-opus-4-6, gemini-3.1-pro-preview).', 'nvoos-content-graph-ai' ),
				),
				'provider'       => array(
					'type'        => 'string',
					'description' => __( 'AI provider name.', 'nvoos-content-graph-ai' ),
					'enum'        => self::VALID_PROVIDERS,
				),
				'use_web_search' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to use web search to find model documentation (requires web search capability).', 'nvoos-content-graph-ai' ),
					'default'     => true,
				),
			),
			'required'             => array( 'model_id', 'provider' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function getCapabilityFlags(): array {
		return array( 'requires-credentials', 'consumes-tokens', 'external-api', 'network-dependent', 'may-timeout', 'cacheable', 'write' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to research AI models. This tool requires administrator privileges.', 'nvoos-content-graph-ai' )
			);
		}

		if ( empty( $arguments['model_id'] ) || empty( $arguments['provider'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_missing_arguments',
				__( 'Both model_id and provider are required.', 'nvoos-content-graph-ai' )
			);
		}

		$model_id   = sanitize_text_field( $arguments['model_id'] );
		$provider   = sanitize_key( $arguments['provider'] );
		$use_search = isset( $arguments['use_web_search'] ) ? (bool) $arguments['use_web_search'] : true;

		if ( ! in_array( $provider, self::VALID_PROVIDERS, true ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_provider',
				sprintf(
					/* translators: %s: provider name */
					__( 'Invalid provider: %s.', 'nvoos-content-graph-ai' ),
					$provider
				)
			);
		}

		$cache_key = 'model_research_' . md5( $provider . '_' . $model_id );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached && is_array( $cached ) ) {
			$cached['_from_cache'] = true;
			return $cached;
		}

		$this->log_event(
			'model_research_started',
			'Starting AI model research',
			array(
				'model_id'   => $model_id,
				'provider'   => $provider,
				'user_id'    => $user_id,
				'use_search' => $use_search,
			)
		);

		$prompt = $this->build_research_prompt( $model_id, $provider, $use_search );

		$research_result = $this->perform_ai_research( $prompt );

		if ( is_wp_error( $research_result ) ) {
			$this->log_error(
				'Model research failed: ' . $research_result->get_error_message(),
				array(
					'model_id' => $model_id,
					'provider' => $provider,
					'error'    => $research_result->get_error_code(),
				)
			);
			return $research_result;
		}

		$model_config = $this->parse_research_results( $research_result, $model_id, $provider );

		if ( is_wp_error( $model_config ) ) {
			$this->log_error(
				'Failed to parse model research results: ' . $model_config->get_error_message(),
				array(
					'model_id' => $model_id,
					'provider' => $provider,
				)
			);
			return $model_config;
		}

		wp_cache_set( $cache_key, $model_config, self::CACHE_GROUP, 7 * DAY_IN_SECONDS );

		$this->log_event(
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
	 * Build the research prompt for the AI (base-identical wording).
	 *
	 * @param string $model_id   Model identifier.
	 * @param string $provider   Provider name.
	 * @param bool   $use_search Whether to include web search instructions.
	 * @return string Research prompt.
	 */
	private function build_research_prompt( $model_id, $provider, $use_search ) {
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
	 * Per-mode seam: base provider clients in monolith installs, the
	 * nvoos-core ProviderRouter clients standalone. Both return the
	 * OpenAI-shaped `choices[0].message.content` payload the parser
	 * consumes.
	 *
	 * @param string $prompt Research prompt.
	 * @return array|\WP_Error Research results or error.
	 */
	private function perform_ai_research( $prompt ) {
		$provider = $this->get_research_provider();

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$model = $this->get_research_model( $provider );

		if ( is_wp_error( $model ) ) {
			return $model;
		}

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

		$options = array(
			'model'       => $model,
			'temperature' => 0.2, // Low temperature for factual information.
			'max_tokens'  => 2000,
		);

		// Monolith seam: reuse the base provider clients verbatim.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			$client = $this->get_base_client( $provider );

			if ( is_wp_error( $client ) ) {
				return $client;
			}

			$result = $client->create_chat_completion( $messages, $options );
		} else {
			// Standalone: nvoos-core ProviderRouter clients.
			$bridge = CoreBridge::instance();
			$client = $bridge->providers->get( $provider );

			if ( null === $client ) {
				return new \WP_Error(
					'wp_mcp_ai_client_unavailable',
					sprintf(
						/* translators: %s: provider name */
						__( 'AI client not available for provider: %s', 'nvoos-content-graph-ai' ),
						$provider
					)
				);
			}

			$result = $client->chat( $messages, $options );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! isset( $result['choices'][0]['message']['content'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from AI provider.', 'nvoos-content-graph-ai' )
			);
		}

		return array(
			'content'  => $result['choices'][0]['message']['content'],
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * Get the base provider client for monolith installs.
	 *
	 * @param string $provider Provider name.
	 * @return object|\WP_Error Client instance or error.
	 */
	private function get_base_client( $provider ) {
		switch ( $provider ) {
			case 'openai':
				return new \WP_MCP_AI_OpenAI_Client();

			case 'gemini':
				if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
					return new \WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Gemini client not available.', 'nvoos-content-graph-ai' )
					);
				}
				return new \WP_MCP_AI_Gemini_Client();

			case 'anthropic':
				if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
					return new \WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Anthropic client not available.', 'nvoos-content-graph-ai' )
					);
				}
				return new \WP_MCP_AI_Anthropic_Client();

			default:
				return new \WP_Error(
					'wp_mcp_ai_unsupported_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'AI client not available for provider: %s', 'nvoos-content-graph-ai' ),
						$provider
					)
				);
		}
	}

	/**
	 * Get the best available provider for research.
	 *
	 * @return string|\WP_Error Provider name or error.
	 */
	private function get_research_provider() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = \WP_MCP_AI_Admin_Settings::get_settings();
			$settings = is_array( $settings ) ? $settings : array();

			if ( ! empty( $settings['openai_api_key'] ) ) {
				return 'openai';
			}
			if ( ! empty( $settings['gemini_api_key'] ) ) {
				return 'gemini';
			}
			if ( ! empty( $settings['anthropic_api_key'] ) ) {
				return 'anthropic';
			}

			return new \WP_Error(
				'wp_mcp_ai_no_provider',
				__( 'No AI provider configured. Please configure OpenAI, Gemini, or Anthropic API keys in plugin settings.', 'nvoos-content-graph-ai' )
			);
		}

		// Standalone: Content Graph credential resolver.
		$settings = CoreBridge::instance()->settings;

		if ( $settings->hasCredentials( 'openai' ) ) {
			return 'openai';
		}
		if ( $settings->hasCredentials( 'gemini' ) ) {
			return 'gemini';
		}
		if ( $settings->hasCredentials( 'anthropic' ) ) {
			return 'anthropic';
		}

		return new \WP_Error(
			'wp_mcp_ai_no_provider',
			__( 'No AI provider configured. Please configure OpenAI, Gemini, or Anthropic API keys in plugin settings.', 'nvoos-content-graph-ai' )
		);
	}

	/**
	 * Get the best model for research from a provider.
	 *
	 * @param string $provider Provider name.
	 * @return string|\WP_Error Model identifier or error.
	 */
	private function get_research_model( $provider ) {
		switch ( $provider ) {
			case 'openai':
				if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
					$settings = \WP_MCP_AI_Admin_Settings::get_settings();
					$settings = is_array( $settings ) ? $settings : array();

					return ! empty( $settings['openai_default_model'] ) ? $settings['openai_default_model'] : 'gpt-4.1';
				}

				// Standalone: the Content Graph store's default model.
				$default = CoreBridge::instance()->settings->getDefaultModel();

				return '' !== $default ? $default : 'gpt-4.1';

			case 'gemini':
				if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
					$settings = \WP_MCP_AI_Admin_Settings::get_settings();
					$settings = is_array( $settings ) ? $settings : array();

					return ! empty( $settings['gemini_default_model'] ) ? $settings['gemini_default_model'] : 'gemini-2.5-flash';
				}

				// The Content Graph store has no gemini-specific default.
				return 'gemini-2.5-flash';

			case 'anthropic':
				return 'claude-sonnet-4-5-20250929';

			default:
				return new \WP_Error(
					'wp_mcp_ai_unsupported_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'Provider not supported for research: %s', 'nvoos-content-graph-ai' ),
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
	 * @return array|\WP_Error Parsed model configuration or error.
	 */
	private function parse_research_results( $research_result, $model_id, $provider ) {
		$content = $research_result['content'];

		// Extract JSON from markdown code blocks if present.
		if ( preg_match( '/```json\s*(.*?)\s*```/s', $content, $matches ) ) {
			$json = $matches[1];
		} elseif ( preg_match( '/```\s*(.*?)\s*```/s', $content, $matches ) ) {
			$json = $matches[1];
		} else {
			$json = $content;
		}

		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error(
				'wp_mcp_ai_parse_error',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Failed to parse AI response as JSON: %s', 'nvoos-content-graph-ai' ),
					json_last_error_msg()
				)
			);
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'wp_mcp_ai_parse_error',
				__( 'Failed to parse AI response as JSON: invalid document.', 'nvoos-content-graph-ai' )
			);
		}

		$required_fields = array( 'name', 'provider', 'context_window' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				return new \WP_Error(
					'wp_mcp_ai_missing_field',
					sprintf(
						/* translators: %s: field name */
						__( 'Required field missing from AI response: %s', 'nvoos-content-graph-ai' ),
						$field
					)
				);
			}
		}

		// Build configuration matching the base Model_Config format.
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

	/**
	 * Log an activity event (per-mode seam).
	 *
	 * @param string $type    Event type.
	 * @param string $message Event message.
	 * @param array  $data    Event context.
	 * @return void
	 */
	private function log_event( $type, $message, array $data = array() ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $type, $message, $data );
		}
	}

	/**
	 * Log an error event (per-mode seam).
	 *
	 * @param string $message Error message.
	 * @param array  $data    Error context.
	 * @return void
	 */
	private function log_error( $message, array $data = array() ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_error( $message, $data );
		}
	}
}
