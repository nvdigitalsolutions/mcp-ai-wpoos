<?php
/**
 * Tool for researching extra-curricular activities using AI and web search.
 *
 * Provides comprehensive research about ECAs, programs, curricula, and activities
 * including schedule, materials, objectives, and implementation details.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Research ECA Tool
 *
 * Uses AI and web search to research comprehensive information about
 * extra-curricular activities and educational programs.
 */
class WP_MCP_AI_Tool_Research_ECA implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'research_eca';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Research Extra-Curricular Activity', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Research comprehensive information about an extra-curricular activity or educational program using AI and web search. Returns title, description, category, schedule, materials, learning objectives, and implementation details ready for creating an ECA entry.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'The ECA to research (e.g., "Robotics Club for High School", "Debate Team Middle School", "Art Program Elementary")', 'mcp-ai-wpoos-pro' ),
				),
				'age_group'          => array(
					'type'        => 'string',
					'description' => __( 'Target age group (e.g., "Elementary", "Middle School", "High School", "Mixed Ages")', 'mcp-ai-wpoos-pro' ),
				),
				'include_curriculum' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include detailed curriculum and session plans', 'mcp-ai-wpoos-pro' ),
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
		// ECA management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_eca_management'] );
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
				__( 'You do not have permission to research ECAs.', 'mcp-ai-wpoos-pro' )
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
		$age_group          = isset( $arguments['age_group'] ) ? sanitize_text_field( $arguments['age_group'] ) : '';
		$include_curriculum = isset( $arguments['include_curriculum'] ) ? (bool) $arguments['include_curriculum'] : true;

		// Check cache first.
		$cache_key = 'eca_research_' . md5( $query . '_' . $age_group );
		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai_eca_research' );

		if ( false !== $cached && is_array( $cached ) ) {
			$cached['_from_cache'] = true;
			return $cached;
		}

		// Log research start.
		WP_MCP_AI_Logger::log_event(
			'eca_research_started',
			'Starting ECA research',
			array(
				'query'     => $query,
				'age_group' => $age_group,
				'user_id'   => $user_id,
			)
		);

		// Build research prompt.
		$prompt = $this->build_research_prompt( $query, $age_group, $include_curriculum );

		// Use AI to research the ECA.
		$research_result = $this->perform_ai_research( $prompt, $context );

		if ( is_wp_error( $research_result ) ) {
			WP_MCP_AI_Logger::log_error(
				'ECA research failed: ' . $research_result->get_error_message(),
				array(
					'query' => $query,
					'error' => $research_result->get_error_code(),
				)
			);
			return $research_result;
		}

		// Parse and validate the research results.
		$eca_data = $this->parse_research_results( $research_result, $query );

		if ( is_wp_error( $eca_data ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to parse ECA research results: ' . $eca_data->get_error_message(),
				array(
					'query' => $query,
				)
			);
			return $eca_data;
		}

		// Cache the results for 24 hours.
		wp_cache_set( $cache_key, $eca_data, 'wp_mcp_ai_eca_research', DAY_IN_SECONDS );

		// Log success.
		WP_MCP_AI_Logger::log_event(
			'eca_research_completed',
			'ECA research completed successfully',
			array(
				'query' => $query,
				'title' => isset( $eca_data['title'] ) ? $eca_data['title'] : '',
			)
		);

		return $eca_data;
	}

	/**
	 * Build the research prompt for AI.
	 *
	 * @param string $query              Search query.
	 * @param string $age_group          Target age group.
	 * @param bool   $include_curriculum Whether to include curriculum.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $query, $age_group, $include_curriculum ) {
		$prompt = sprintf(
			"Research comprehensive information about the following extra-curricular activity or educational program:\n\n**Activity:** %s\n",
			$query
		);

		if ( ! empty( $age_group ) ) {
			$prompt .= sprintf( "**Age Group:** %s\n", $age_group );
		}

		$prompt .= "\nExtract and research the following information:\n\n";
		$prompt .= "1. **Title**: Name of the activity/program\n";
		$prompt .= "2. **Description**: Comprehensive description (200-400 words) including purpose, benefits, and key activities\n";
		$prompt .= "3. **Category**: Type of ECA (e.g., Academic, Sports, Arts, STEM, Leadership, Service)\n";
		$prompt .= "4. **Age Range**: Recommended age range (e.g., 10-14 years)\n";
		$prompt .= "5. **Duration**: Program duration (e.g., 12 weeks, full school year)\n";
		$prompt .= "6. **Session Length**: Typical session length (e.g., 90 minutes)\n";
		$prompt .= "7. **Frequency**: Meeting frequency (e.g., twice weekly, every Tuesday)\n";
		$prompt .= "8. **Group Size**: Recommended group size\n";
		$prompt .= "9. **Learning Objectives**: 3-5 key learning objectives\n";
		$prompt .= "10. **Materials Required**: List of materials and equipment needed\n";
		$prompt .= "11. **Space Requirements**: Type of space needed (e.g., classroom, gym, outdoor area)\n";
		$prompt .= "12. **Instructor Requirements**: Qualifications/skills needed for instructor\n";

		if ( $include_curriculum ) {
			$prompt .= "13. **Curriculum Outline**: Brief outline of topics/activities covered\n";
			$prompt .= "14. **Sample Session Plan**: Example of a typical session structure\n";
		}

		$prompt .= "\n**IMPORTANT**: Return the information in the following JSON format:\n\n";
		$prompt .= "```json\n";
		$prompt .= "{\n";
		$prompt .= '  "title": "Activity Name",';
		$prompt .= "\n";
		$prompt .= '  "description": "Detailed description...",';
		$prompt .= "\n";
		$prompt .= '  "category": "STEM",';
		$prompt .= "\n";
		$prompt .= '  "age_range": "12-15 years",';
		$prompt .= "\n";
		$prompt .= '  "duration": "12 weeks",';
		$prompt .= "\n";
		$prompt .= '  "session_length": "90 minutes",';
		$prompt .= "\n";
		$prompt .= '  "frequency": "Twice weekly",';
		$prompt .= "\n";
		$prompt .= '  "group_size": "15-20 students",';
		$prompt .= "\n";
		$prompt .= '  "learning_objectives": ["Objective 1", "Objective 2", "Objective 3"],';
		$prompt .= "\n";
		$prompt .= '  "materials": ["Material 1", "Material 2"],';
		$prompt .= "\n";
		$prompt .= '  "space_requirements": "Classroom with tables and power outlets",';
		$prompt .= "\n";
		$prompt .= '  "instructor_requirements": "Background in robotics or computer science",';
		$prompt .= "\n";
		if ( $include_curriculum ) {
			$prompt .= '  "curriculum_outline": "Week-by-week or topic-based outline...",';
			$prompt .= "\n";
			$prompt .= '  "sample_session": "Introduction (10 min), Activity (60 min), Reflection (20 min)",';
			$prompt .= "\n";
		}
		$prompt .= '  "sources": ["URL1", "URL2"]';
		$prompt .= "\n";
		$prompt .= "}\n";
		$prompt .= "```\n\n";

		$prompt .= 'Use web search to find accurate, up-to-date information from reputable educational sources. ';
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
				'content' => 'You are a helpful AI assistant and educational program specialist. You research extra-curricular activities and educational programs. Always respond with valid JSON matching the requested format. Use web search when available to find accurate and up-to-date information from reputable educational sources.',
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
	 * Parse the AI research results into ECA data format.
	 *
	 * @param array  $research_result AI research results.
	 * @param string $query           Original search query.
	 * @return array|WP_Error Parsed ECA data or error.
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
		if ( empty( $data['title'] ) ) {
			$data['title'] = $query;
		}

		// Build ECA data structure.
		$eca_data = array(
			'success'                 => true,
			'query'                   => $query,
			'title'                   => sanitize_text_field( $data['title'] ),
			'description'             => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
			'category'                => isset( $data['category'] ) ? sanitize_text_field( $data['category'] ) : '',
			'age_range'               => isset( $data['age_range'] ) ? sanitize_text_field( $data['age_range'] ) : '',
			'duration'                => isset( $data['duration'] ) ? sanitize_text_field( $data['duration'] ) : '',
			'session_length'          => isset( $data['session_length'] ) ? sanitize_text_field( $data['session_length'] ) : '',
			'frequency'               => isset( $data['frequency'] ) ? sanitize_text_field( $data['frequency'] ) : '',
			'group_size'              => isset( $data['group_size'] ) ? sanitize_text_field( $data['group_size'] ) : '',
			'learning_objectives'     => isset( $data['learning_objectives'] ) && is_array( $data['learning_objectives'] ) ? array_map( 'sanitize_text_field', $data['learning_objectives'] ) : array(),
			'materials'               => isset( $data['materials'] ) && is_array( $data['materials'] ) ? array_map( 'sanitize_text_field', $data['materials'] ) : array(),
			'space_requirements'      => isset( $data['space_requirements'] ) ? sanitize_text_field( $data['space_requirements'] ) : '',
			'instructor_requirements' => isset( $data['instructor_requirements'] ) ? sanitize_text_field( $data['instructor_requirements'] ) : '',
			'curriculum_outline'      => isset( $data['curriculum_outline'] ) ? wp_kses_post( $data['curriculum_outline'] ) : '',
			'sample_session'          => isset( $data['sample_session'] ) ? wp_kses_post( $data['sample_session'] ) : '',
			'sources'                 => isset( $data['sources'] ) && is_array( $data['sources'] ) ? array_map( 'esc_url_raw', $data['sources'] ) : array(),
			'researched_at'           => current_time( 'mysql' ),
			'research_model'          => $research_result['model'],
			'research_provider'       => $research_result['provider'],
		);

		return $eca_data;
	}
}
