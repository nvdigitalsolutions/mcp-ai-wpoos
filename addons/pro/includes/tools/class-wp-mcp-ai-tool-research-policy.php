<?php
/**
 * Tool for researching insurance policies using AI and web search.
 *
 * Provides comprehensive research about insurance policy types including
 * coverage details, requirements, terms, and comparison information.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Research Policy Tool
 *
 * Uses AI and web search to research comprehensive information about
 * insurance policies and coverage options.
 */
class WP_MCP_AI_Tool_Research_Policy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'research_policy';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Research Insurance Policy', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Research comprehensive information about an insurance policy type using AI and web search. Returns policy name, description, coverage details, requirements, premiums, deductibles, exclusions, and terms ready for creating a policy template.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'              => array(
					'type'        => 'string',
					'description' => __( 'The policy type to research (e.g., "Pet Health Insurance", "Life Insurance for Families", "Dental Insurance with Orthodontics")', 'mcp-ai-wpoos-pro' ),
				),
				'coverage_focus'     => array(
					'type'        => 'string',
					'description' => __( 'Specific coverage areas to focus on (e.g., "preventive care", "major medical", "orthodontic coverage")', 'mcp-ai-wpoos-pro' ),
				),
				'include_comparison' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include comparison with similar policies', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-credentials',
			'consumes-tokens',
			'external-api',
			'network-dependent',
			'may-timeout',
			'cacheable',
			'read-only',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Health & Wellness management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
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

		// Check permissions - requires read capability.
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to research policies.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate required arguments.
		if ( empty( $arguments['query'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_query',
				__( 'Search query is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$query              = sanitize_text_field( $arguments['query'] );
		$coverage_focus     = isset( $arguments['coverage_focus'] ) ? sanitize_text_field( $arguments['coverage_focus'] ) : '';
		$include_comparison = isset( $arguments['include_comparison'] ) ? (bool) $arguments['include_comparison'] : false;

		// Check cache first.
		$cache_key = 'policy_research_' . md5( $query . '_' . $coverage_focus );
		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai_policy_research' );

		if ( false !== $cached && is_array( $cached ) ) {
			$cached['_from_cache'] = true;
			return $cached;
		}

		// Log research start.
		WP_MCP_AI_Logger::log_event(
			'policy_research_started',
			'Starting policy research',
			array(
				'query'          => $query,
				'coverage_focus' => $coverage_focus,
				'user_id'        => $user_id,
			)
		);

		// Build research prompt.
		$prompt = $this->build_research_prompt( $query, $coverage_focus, $include_comparison );

		// Use AI to research the policy.
		$research_result = $this->perform_ai_research( $prompt, $context );

		if ( is_wp_error( $research_result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Policy research failed: ' . $research_result->get_error_message(),
				array(
					'query' => $query,
					'error' => $research_result->get_error_code(),
				)
			);
			return $research_result;
		}

		// Parse and validate the research results.
		$policy_data = $this->parse_research_results( $research_result, $query );

		if ( is_wp_error( $policy_data ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to parse policy research results: ' . $policy_data->get_error_message(),
				array(
					'query' => $query,
				)
			);
			return $policy_data;
		}

		// Cache the results for 7 days (policies don't change as frequently).
		wp_cache_set( $cache_key, $policy_data, 'wp_mcp_ai_policy_research', 7 * DAY_IN_SECONDS );

		// Log success.
		WP_MCP_AI_Logger::log_event(
			'policy_research_completed',
			'Policy research completed successfully',
			array(
				'query'       => $query,
				'policy_name' => isset( $policy_data['policy_name'] ) ? $policy_data['policy_name'] : '',
			)
		);

		return $policy_data;
	}

	/**
	 * Build the research prompt for AI.
	 *
	 * @param string $query              Search query.
	 * @param string $coverage_focus     Coverage focus areas.
	 * @param bool   $include_comparison Whether to include comparison.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $query, $coverage_focus, $include_comparison ) {
		$prompt = sprintf(
			"Research comprehensive information about the following insurance policy type:\n\n**Policy Type:** %s\n",
			$query
		);

		if ( ! empty( $coverage_focus ) ) {
			$prompt .= sprintf( "**Coverage Focus:** %s\n", $coverage_focus );
		}

		$prompt .= "\nExtract and research the following information:\n\n";
		$prompt .= "1. **Policy Name**: Official name of the policy type\n";
		$prompt .= "2. **Description**: Comprehensive description (200-400 words) of coverage and purpose\n";
		$prompt .= "3. **Policy Type**: Category (e.g., Health, Life, Dental, Pet, Disability)\n";
		$prompt .= "4. **Coverage Details**: What is covered under this policy\n";
		$prompt .= "5. **Coverage Limits**: Typical coverage limits and maximums\n";
		$prompt .= "6. **Deductible**: Common deductible amounts or ranges\n";
		$prompt .= "7. **Premium Range**: Typical premium costs (monthly/annual)\n";
		$prompt .= "8. **Waiting Period**: Any waiting periods before coverage begins\n";
		$prompt .= "9. **Exclusions**: Common exclusions and what's not covered\n";
		$prompt .= "10. **Requirements**: Eligibility requirements or prerequisites\n";
		$prompt .= "11. **Benefits**: Key benefits and advantages\n";
		$prompt .= "12. **Claim Process**: Overview of how to file claims\n";

		if ( $include_comparison ) {
			$prompt .= "13. **Comparison**: Brief comparison with similar policy types\n";
		}

		$prompt .= "\n**IMPORTANT**: Return the information in the following JSON format:\n\n";
		$prompt .= "```json\n";
		$prompt .= "{\n";
		$prompt .= '  "policy_name": "Policy Name",';
		$prompt .= "\n";
		$prompt .= '  "description": "Detailed description...",';
		$prompt .= "\n";
		$prompt .= '  "policy_type": "Health",';
		$prompt .= "\n";
		$prompt .= '  "coverage_details": "What is covered...",';
		$prompt .= "\n";
		$prompt .= '  "coverage_limits": "$50,000 annual maximum",';
		$prompt .= "\n";
		$prompt .= '  "deductible": "$500 per year",';
		$prompt .= "\n";
		$prompt .= '  "premium_range": "$50-150 per month",';
		$prompt .= "\n";
		$prompt .= '  "waiting_period": "30 days",';
		$prompt .= "\n";
		$prompt .= '  "exclusions": ["Pre-existing conditions", "Cosmetic procedures"],';
		$prompt .= "\n";
		$prompt .= '  "requirements": ["Must be under 65", "Health questionnaire required"],';
		$prompt .= "\n";
		$prompt .= '  "benefits": ["Preventive care covered 100%", "Prescription drug coverage"],';
		$prompt .= "\n";
		$prompt .= '  "claim_process": "Submit claims online or by mail...",';
		$prompt .= "\n";
		if ( $include_comparison ) {
			$prompt .= '  "comparison": "Comparison with similar policies...",';
			$prompt .= "\n";
		}
		$prompt .= '  "sources": ["URL1", "URL2"]';
		$prompt .= "\n";
		$prompt .= "}\n";
		$prompt .= "```\n\n";

		$prompt .= 'Use web search to find accurate, up-to-date information from reputable insurance sources. ';
		$prompt .= "Include source URLs in the 'sources' array. ";
		$prompt .= 'If information is not available, use null for that field. ';
		$prompt .= "Provide general information; do not recommend specific insurance companies or plans.\n";

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
				'content' => 'You are a helpful AI assistant and insurance policy specialist. You research insurance policy types and coverage options. Always respond with valid JSON matching the requested format. Use web search when available to find accurate information from reputable insurance sources. Provide general educational information; do not recommend specific companies or plans.',
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
				'max_tokens'  => 2500,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Extract the content from the response.
		if ( ! isset( $result['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from AI provider.', 'mcp-ai-wpoos-pro' )
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
		// Prefer OpenAI or Gemini for research tasks.
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
			__( 'No AI provider configured. Please configure OpenAI, Gemini, or Anthropic API keys in plugin settings.', 'mcp-ai-wpoos-pro' )
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
				return ! empty( $settings['openai_default_model'] ) ? $settings['openai_default_model'] : 'gpt-4o';

			case 'gemini':
				return ! empty( $settings['gemini_default_model'] ) ? $settings['gemini_default_model'] : 'gemini-2.5-flash';

			case 'anthropic':
				return 'claude-sonnet-4-5-20250929';

			default:
				return new WP_Error(
					'wp_mcp_ai_unsupported_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'Provider not supported for research: %s', 'mcp-ai-wpoos-pro' ),
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
						__( 'OpenAI client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_OpenAI_Client();

			case 'gemini':
				if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Gemini client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Gemini_Client();

			case 'anthropic':
				if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Anthropic client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Anthropic_Client();

			default:
				return new WP_Error(
					'wp_mcp_ai_unsupported_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'AI client not available for provider: %s', 'mcp-ai-wpoos-pro' ),
						$provider
					)
				);
		}
	}

	/**
	 * Parse the AI research results into policy data format.
	 *
	 * @param array  $research_result AI research results.
	 * @param string $query           Original search query.
	 * @return array|WP_Error Parsed policy data or error.
	 */
	protected function parse_research_results( $research_result, $query ) {
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
					__( 'Failed to parse AI response as JSON: %s', 'mcp-ai-wpoos-pro' ),
					json_last_error_msg()
				)
			);
		}

		// Ensure minimum required fields.
		if ( empty( $data['policy_name'] ) ) {
			$data['policy_name'] = $query;
		}

		// Build policy data structure.
		$policy_data = array(
			'success'           => true,
			'query'             => $query,
			'policy_name'       => sanitize_text_field( $data['policy_name'] ),
			'description'       => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
			'policy_type'       => isset( $data['policy_type'] ) ? sanitize_text_field( $data['policy_type'] ) : '',
			'coverage_details'  => isset( $data['coverage_details'] ) ? wp_kses_post( $data['coverage_details'] ) : '',
			'coverage_limits'   => isset( $data['coverage_limits'] ) ? sanitize_text_field( $data['coverage_limits'] ) : '',
			'deductible'        => isset( $data['deductible'] ) ? sanitize_text_field( $data['deductible'] ) : '',
			'premium_range'     => isset( $data['premium_range'] ) ? sanitize_text_field( $data['premium_range'] ) : '',
			'waiting_period'    => isset( $data['waiting_period'] ) ? sanitize_text_field( $data['waiting_period'] ) : '',
			'exclusions'        => isset( $data['exclusions'] ) && is_array( $data['exclusions'] ) ? array_map( 'sanitize_text_field', $data['exclusions'] ) : array(),
			'requirements'      => isset( $data['requirements'] ) && is_array( $data['requirements'] ) ? array_map( 'sanitize_text_field', $data['requirements'] ) : array(),
			'benefits'          => isset( $data['benefits'] ) && is_array( $data['benefits'] ) ? array_map( 'sanitize_text_field', $data['benefits'] ) : array(),
			'claim_process'     => isset( $data['claim_process'] ) ? wp_kses_post( $data['claim_process'] ) : '',
			'comparison'        => isset( $data['comparison'] ) ? wp_kses_post( $data['comparison'] ) : '',
			'sources'           => isset( $data['sources'] ) && is_array( $data['sources'] ) ? array_map( 'esc_url_raw', $data['sources'] ) : array(),
			'researched_at'     => current_time( 'mysql' ),
			'research_model'    => $research_result['model'],
			'research_provider' => $research_result['provider'],
		);

		return $policy_data;
	}
}
