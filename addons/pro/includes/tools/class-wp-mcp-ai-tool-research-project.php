<?php
/**
 * Tool for researching projects using AI and web search.
 *
 * Provides comprehensive research about projects, including methodologies,
 * timelines, resources, milestones, and implementation details.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Research Project Tool
 *
 * Uses AI and web search to research comprehensive information about
 * projects and project management approaches.
 */
class WP_MCP_AI_Tool_Research_Project implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'research_project';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Research Project', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Research comprehensive information about a project using AI and web search. Returns title, description, objectives, timeline, resources, milestones, deliverables, and implementation details ready for creating a project entry.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'          => array(
					'type'        => 'string',
					'description' => __( 'The project to research (e.g., "Website Redesign", "Product Launch Campaign", "Employee Training Program")', 'mcp-ai-wpoos-pro' ),
				),
				'project_type'   => array(
					'type'        => 'string',
					'description' => __( 'Type of project (e.g., "Marketing", "Development", "Training", "Event")', 'mcp-ai-wpoos-pro' ),
				),
				'include_phases' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include detailed project phases and milestones', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
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
		// Project management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_project_management'] );
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
				__( 'You do not have permission to research projects.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate required arguments.
		if ( empty( $arguments['query'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_query',
				__( 'Search query is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$query          = sanitize_text_field( $arguments['query'] );
		$project_type   = isset( $arguments['project_type'] ) ? sanitize_text_field( $arguments['project_type'] ) : '';
		$include_phases = isset( $arguments['include_phases'] ) ? (bool) $arguments['include_phases'] : true;

		// Check cache first.
		$cache_key = 'project_research_' . md5( $query . '_' . $project_type );
		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai_project_research' );

		if ( false !== $cached && is_array( $cached ) ) {
			$cached['_from_cache'] = true;
			return $cached;
		}

		// Log research start.
		WP_MCP_AI_Logger::log_event(
			'project_research_started',
			'Starting project research',
			array(
				'query'        => $query,
				'project_type' => $project_type,
				'user_id'      => $user_id,
			)
		);

		// Build research prompt.
		$prompt = $this->build_research_prompt( $query, $project_type, $include_phases );

		// Use AI to research the project.
		$research_result = $this->perform_ai_research( $prompt, $context );

		if ( is_wp_error( $research_result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Project research failed: ' . $research_result->get_error_message(),
				array(
					'query' => $query,
					'error' => $research_result->get_error_code(),
				)
			);
			return $research_result;
		}

		// Parse and validate the research results.
		$project_data = $this->parse_research_results( $research_result, $query );

		if ( is_wp_error( $project_data ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to parse project research results: ' . $project_data->get_error_message(),
				array(
					'query' => $query,
				)
			);
			return $project_data;
		}

		// Cache the results for 24 hours.
		wp_cache_set( $cache_key, $project_data, 'wp_mcp_ai_project_research', DAY_IN_SECONDS );

		// Log success.
		WP_MCP_AI_Logger::log_event(
			'project_research_completed',
			'Project research completed successfully',
			array(
				'query' => $query,
				'title' => isset( $project_data['title'] ) ? $project_data['title'] : '',
			)
		);

		return $project_data;
	}

	/**
	 * Build the research prompt for AI.
	 *
	 * @param string $query          Search query.
	 * @param string $project_type   Project type.
	 * @param bool   $include_phases Whether to include phases.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $query, $project_type, $include_phases ) {
		$prompt = sprintf(
			"Research comprehensive information about the following project:\n\n**Project:** %s\n",
			$query
		);

		if ( ! empty( $project_type ) ) {
			$prompt .= sprintf( "**Project Type:** %s\n", $project_type );
		}

		$prompt .= "\nExtract and research the following information:\n\n";
		$prompt .= "1. **Title**: Name of the project\n";
		$prompt .= "2. **Description**: Comprehensive description (200-400 words) including purpose, goals, and key outcomes\n";
		$prompt .= "3. **Objectives**: 3-5 key project objectives or goals\n";
		$prompt .= "4. **Status**: Current or typical status (e.g., Planning, In Progress, On Hold)\n";
		$prompt .= "5. **Priority**: Typical priority level (e.g., High, Medium, Low)\n";
		$prompt .= "6. **Timeline**: Expected duration (e.g., 3 months, 6 weeks)\n";
		$prompt .= "7. **Budget**: Typical budget range or considerations\n";
		$prompt .= "8. **Resources**: Required resources, team size, and skills needed\n";
		$prompt .= "9. **Stakeholders**: Types of stakeholders typically involved\n";
		$prompt .= "10. **Deliverables**: Key deliverables and outputs\n";
		$prompt .= "11. **Success Criteria**: How success is typically measured\n";
		$prompt .= "12. **Risks**: Common risks and mitigation strategies\n";

		if ( $include_phases ) {
			$prompt .= "13. **Phases**: Key project phases or stages\n";
			$prompt .= "14. **Milestones**: Important milestones and checkpoints\n";
		}

		$prompt .= "\n**IMPORTANT**: Return the information in the following JSON format:\n\n";
		$prompt .= "```json\n";
		$prompt .= "{\n";
		$prompt .= '  "title": "Project Name",';
		$prompt .= "\n";
		$prompt .= '  "description": "Detailed description...",';
		$prompt .= "\n";
		$prompt .= '  "objectives": ["Objective 1", "Objective 2", "Objective 3"],';
		$prompt .= "\n";
		$prompt .= '  "status": "Planning",';
		$prompt .= "\n";
		$prompt .= '  "priority": "High",';
		$prompt .= "\n";
		$prompt .= '  "timeline": "3 months",';
		$prompt .= "\n";
		$prompt .= '  "budget": "$50,000 - $75,000",';
		$prompt .= "\n";
		$prompt .= '  "resources": "Project manager, 2-3 developers, 1 designer",';
		$prompt .= "\n";
		$prompt .= '  "stakeholders": "Management team, marketing department, end users",';
		$prompt .= "\n";
		$prompt .= '  "deliverables": ["Deliverable 1", "Deliverable 2"],';
		$prompt .= "\n";
		$prompt .= '  "success_criteria": "Criteria for measuring success",';
		$prompt .= "\n";
		$prompt .= '  "risks": ["Risk 1 and mitigation", "Risk 2 and mitigation"],';
		$prompt .= "\n";
		if ( $include_phases ) {
			$prompt .= '  "phases": ["Phase 1: Discovery", "Phase 2: Design", "Phase 3: Implementation"],';
			$prompt .= "\n";
			$prompt .= '  "milestones": ["Milestone 1", "Milestone 2", "Milestone 3"],';
			$prompt .= "\n";
		}
		$prompt .= '  "sources": ["URL1", "URL2"]';
		$prompt .= "\n";
		$prompt .= "}\n";
		$prompt .= "```\n\n";

		$prompt .= 'Use web search to find accurate, up-to-date information from reputable project management and business sources. ';
		$prompt .= "Include source URLs in the 'sources' array. ";
		$prompt .= "If information is not available, use null for that field.\n";

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
				'content' => 'You are a helpful AI assistant and project management specialist. You research projects and project management approaches. Always respond with valid JSON matching the requested format. Use web search when available to find accurate and up-to-date information from reputable project management and business sources.',
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
		// Check if OpenAI is configured (preferred for research).
		if ( ! empty( $settings['openai_api_key'] ) ) {
			return 'openai';
		}

		// Check if Gemini is configured.
		if ( ! empty( $settings['gemini_api_key'] ) ) {
			return 'gemini';
		}

		// Check if Ollama is configured.
		if ( ! empty( $settings['enable_ollama'] ) && ! empty( $settings['ollama_url'] ) ) {
			return 'ollama';
		}

		return new WP_Error(
			'wp_mcp_ai_no_provider',
			__( 'No AI provider configured. Please configure OpenAI, Gemini, or Ollama in the plugin settings.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Get the best model for research from the provider.
	 *
	 * @param string $provider Provider name.
	 * @param array  $settings Plugin settings.
	 * @return string|WP_Error Model name or error.
	 */
	protected function get_research_model( $provider, $settings ) {
		switch ( $provider ) {
			case 'openai':
				// Prefer GPT-4 for research if available, otherwise use GPT-3.5.
				$model = isset( $settings['openai_model'] ) ? $settings['openai_model'] : 'gpt-3.5-turbo';
				return $model;

			case 'gemini':
				// Use Gemini Pro for research.
				$model = isset( $settings['gemini_model'] ) ? $settings['gemini_model'] : 'gemini-pro';
				return $model;

			case 'ollama':
				// Use configured Ollama model.
				$model = isset( $settings['ollama_model'] ) ? $settings['ollama_model'] : 'llama2';
				return $model;

			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_provider',
					__( 'Invalid AI provider.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Get the AI client for the provider.
	 *
	 * @param string $provider Provider name.
	 * @param array  $settings Plugin settings.
	 * @return object|WP_Error Client instance or error.
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
				return new WP_MCP_AI_OpenAI_Client( $settings['openai_api_key'] );

			case 'gemini':
				if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Gemini client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Gemini_Client( $settings['gemini_api_key'] );

			case 'ollama':
				if ( ! class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Ollama client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Ollama_Client( $settings['ollama_url'] );

			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_provider',
					__( 'Invalid AI provider.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Parse AI research results into structured project data.
	 *
	 * @param array  $research_result AI research result.
	 * @param string $query           Original query.
	 * @return array|WP_Error Parsed project data or error.
	 */
	protected function parse_research_results( $research_result, $query ) {
		$content = $research_result['content'];

		// Try to extract JSON from the response.
		$json_pattern = '/```(?:json)?\s*(\{.*?\})\s*```/s';
		if ( preg_match( $json_pattern, $content, $matches ) ) {
			$json_str = $matches[1];
		} else {
			// Try to find JSON without code blocks.
			$json_pattern = '/(\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\})/s';
			if ( preg_match( $json_pattern, $content, $matches ) ) {
				$json_str = $matches[1];
			} else {
				return new WP_Error(
					'wp_mcp_ai_parse_failed',
					__( 'Failed to extract JSON from AI response.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Decode JSON.
		$data = json_decode( $json_str, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_json',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Invalid JSON in AI response: %s', 'mcp-ai-wpoos-pro' ),
					json_last_error_msg()
				)
			);
		}

		// Validate required fields.
		if ( empty( $data['title'] ) ) {
			$data['title'] = $query;
		}

		if ( empty( $data['description'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_description',
				__( 'AI response missing project description.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Ensure arrays are arrays.
		$array_fields = array( 'objectives', 'deliverables', 'risks', 'phases', 'milestones', 'sources' );
		foreach ( $array_fields as $field ) {
			if ( isset( $data[ $field ] ) && ! is_array( $data[ $field ] ) ) {
				$data[ $field ] = array( $data[ $field ] );
			}
		}

		return $data;
	}
}
