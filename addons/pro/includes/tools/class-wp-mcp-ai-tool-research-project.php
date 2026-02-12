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
	 * Maximum number of search queries to perform.
	 *
	 * @var int
	 */
	const MAX_SEARCH_QUERIES = 3;

	/**
	 * Maximum results per search query.
	 *
	 * @var int
	 */
	const MAX_RESULTS_PER_QUERY = 5;

	/**
	 * Maximum number of sources to display in prompt.
	 *
	 * @var int
	 */
	const MAX_DISPLAYED_SOURCES = 5;

	/**
	 * Number of queries for basic depth research.
	 *
	 * @var int
	 */
	const QUERIES_BASIC = 1;

	/**
	 * Number of queries for standard depth research.
	 *
	 * @var int
	 */
	const QUERIES_STANDARD = 2;

	/**
	 * Number of queries for comprehensive depth research.
	 *
	 * @var int
	 */
	const QUERIES_COMPREHENSIVE = 3;

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
		return __( 'Research comprehensive information about a project using multi-stage web search and AI analysis. Supports configurable research depth (basic/standard/comprehensive) and focus areas for targeted research. Returns title, description, objectives, timeline, resources, milestones, deliverables, and implementation details ready for creating a project entry.', 'mcp-ai-wpoos-pro' );
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
				'depth'          => array(
					'type'        => 'string',
					'description' => __( 'Research depth level.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'basic', 'standard', 'comprehensive' ),
					'default'     => 'standard',
				),
				'focus_areas'    => array(
					'type'        => 'array',
					'description' => __( 'Optional specific aspects to focus on (e.g., "methodology", "timeline", "resources", "risks").', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
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
		$depth          = isset( $arguments['depth'] ) ? sanitize_text_field( $arguments['depth'] ) : 'standard';
		$focus_areas    = isset( $arguments['focus_areas'] ) && is_array( $arguments['focus_areas'] )
			? array_map( 'sanitize_text_field', $arguments['focus_areas'] )
			: array();
		$project_type   = isset( $arguments['project_type'] ) ? sanitize_text_field( $arguments['project_type'] ) : '';
		$include_phases = isset( $arguments['include_phases'] ) ? (bool) $arguments['include_phases'] : true;

		// Validate depth parameter.
		if ( ! in_array( $depth, array( 'basic', 'standard', 'comprehensive' ), true ) ) {
			$depth = 'standard';
		}

		// Check cache first.
		$cache_key = 'project_research_' . md5( $query . '_' . $depth . '_' . implode( '_', $focus_areas ) . '_' . $project_type );
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
				'depth'        => $depth,
				'focus_areas'  => $focus_areas,
				'project_type' => $project_type,
				'user_id'      => $user_id,
			)
		);

		// Step 1: Gather information through web searches.
		$search_results = $this->gather_project_information( $query, $project_type, $depth, $focus_areas, $context );

		if ( is_wp_error( $search_results ) ) {
			WP_MCP_AI_Logger::log_error(
				'Project research web search failed: ' . $search_results->get_error_message(),
				array(
					'query' => $query,
					'depth' => $depth,
					'error' => $search_results->get_error_code(),
				)
			);
			// Fall back to AI-only research if web search fails.
			$search_results = array(
				'results' => array(),
				'sources' => array(),
				'queries' => array( $query ),
			);
		}

		// Step 2: Build research prompt with gathered information.
		$prompt = $this->build_research_prompt( $query, $project_type, $depth, $focus_areas, $search_results, $include_phases );

		// Step 3: Use AI to research the project.
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
				'query'         => $query,
				'depth'         => $depth,
				'focus_areas'   => $focus_areas,
				'sources_count' => count( $search_results['sources'] ?? array() ),
				'title'         => isset( $project_data['title'] ) ? $project_data['title'] : '',
			)
		);

		return $project_data;
	}

	/**
	 * Gather project information through web searches.
	 *
	 * @param string $query        Project query.
	 * @param string $project_type Project type.
	 * @param string $depth        Research depth.
	 * @param array  $focus_areas  Focus areas.
	 * @param array  $context      Execution context.
	 * @return array|WP_Error Search results or error.
	 */
	protected function gather_project_information( $query, $project_type, $depth, $focus_areas, $context ) {
		$registry        = WP_MCP_AI_Tool_Registry::get_instance();
		$web_search_tool = $registry->get_tool( 'web_search' );

		if ( ! $web_search_tool ) {
			WP_MCP_AI_Logger::log_event(
				'project_research_no_web_search',
				'Web search tool not available, using AI-only mode',
				array( 'query' => $query )
			);
			return array(
				'results' => array(),
				'sources' => array(),
				'queries' => array( $query ),
			);
		}

		$search_queries = $this->generate_project_search_queries( $query, $project_type, $depth, $focus_areas );
		$all_results    = array();
		$all_sources    = array();

		foreach ( $search_queries as $search_query ) {
			$search_result = $web_search_tool->execute(
				array(
					'query'       => $search_query,
					'max_results' => self::MAX_RESULTS_PER_QUERY,
				),
				$context
			);

			if ( is_wp_error( $search_result ) ) {
				WP_MCP_AI_Logger::log_error(
					'Project research web search failed: ' . $search_result->get_error_message(),
					array(
						'query'      => $search_query,
						'project'    => $query,
						'error_code' => $search_result->get_error_code(),
					)
				);
				continue;
			}

			if ( ! empty( $search_result['results'] ) && is_array( $search_result['results'] ) ) {
				foreach ( $search_result['results'] as $result ) {
					$all_results[] = $result;
					if ( ! empty( $result['url'] ) ) {
						$all_sources[] = array(
							'url'     => $result['url'],
							'title'   => isset( $result['title'] ) ? $result['title'] : '',
							'snippet' => isset( $result['snippet'] ) ? $result['snippet'] : '',
						);
					}
				}
			}
		}

		$all_sources = $this->deduplicate_sources( $all_sources );

		WP_MCP_AI_Logger::log_event(
			'project_research_web_search_complete',
			'Web search completed for project research',
			array(
				'query'         => $query,
				'queries_count' => count( $search_queries ),
				'results_count' => count( $all_results ),
				'sources_count' => count( $all_sources ),
			)
		);

		return array(
			'results' => $all_results,
			'sources' => $all_sources,
			'queries' => $search_queries,
		);
	}

	/**
	 * Generate search queries for project research.
	 *
	 * @param string $query        Project query.
	 * @param string $project_type Project type.
	 * @param string $depth        Research depth.
	 * @param array  $focus_areas  Focus areas.
	 * @return array Search queries.
	 */
	protected function generate_project_search_queries( $query, $project_type, $depth, $focus_areas ) {
		$queries = array();
		$queries[] = $query;

		if ( 'basic' === $depth ) {
			$num_queries = self::QUERIES_BASIC;
		} elseif ( 'comprehensive' === $depth ) {
			$num_queries = self::QUERIES_COMPREHENSIVE;
		} else {
			$num_queries = self::QUERIES_STANDARD;
		}

		if ( ! empty( $focus_areas ) ) {
			foreach ( $focus_areas as $area ) {
				if ( count( $queries ) >= $num_queries ) {
					break;
				}
				$queries[] = $query . ' ' . $area;
			}
		}

		if ( count( $queries ) < $num_queries ) {
			if ( 'comprehensive' === $depth ) {
				$queries[] = $query . ' ' . ( $project_type ? $project_type . ' ' : '' ) . 'methodology best practices';
				if ( count( $queries ) < $num_queries ) {
					$queries[] = $query . ' timeline resources risks';
				}
			} elseif ( 'standard' === $depth ) {
				$queries[] = $query . ' ' . ( $project_type ? $project_type . ' ' : '' ) . 'planning guide';
			}
		}

		return array_slice( $queries, 0, $num_queries );
	}

	/**
	 * Deduplicate sources by URL.
	 *
	 * @param array $sources Sources array.
	 * @return array Deduplicated sources.
	 */
	protected function deduplicate_sources( $sources ) {
		$unique_sources = array();
		$seen_urls      = array();

		foreach ( $sources as $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}

			if ( ! in_array( $source['url'], $seen_urls, true ) ) {
				$unique_sources[] = $source;
				$seen_urls[]      = $source['url'];
			}
		}

		return $unique_sources;
	}

	/**
	 * Build the research prompt for AI.
	 *
	 * @param string $query          Search query.
	 * @param string $project_type   Project type.
	 * @param string $depth          Research depth.
	 * @param array  $focus_areas    Focus areas.
	 * @param array  $search_results Search results from web search.
	 * @param bool   $include_phases Whether to include phases.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $query, $project_type, $depth, $focus_areas, $search_results, $include_phases ) {
		$prompt = sprintf(
			"Research comprehensive information about the following project:\n\n**Project:** %s\n",
			$query
		);

		if ( ! empty( $project_type ) ) {
			$prompt .= sprintf( "**Project Type:** %s\n", $project_type );
		}

		// Add context from web search if available.
		if ( ! empty( $search_results['sources'] ) ) {
			$prompt .= "\n**Available Research Sources:**\n";
			$source_count = min( self::MAX_DISPLAYED_SOURCES, count( $search_results['sources'] ) );
			for ( $i = 0; $i < $source_count; $i++ ) {
				$source = $search_results['sources'][ $i ];
				$prompt .= sprintf(
					"[%d] %s - %s\n",
					$i + 1,
					$source['title'],
					$source['snippet']
				);
			}
			$prompt .= "\n";
		}

		// Add depth-specific instructions.
		if ( 'comprehensive' === $depth ) {
			$prompt .= "**Research Depth: COMPREHENSIVE** - Include extensive project details, risk analysis, and detailed planning.\n\n";
		} elseif ( 'basic' === $depth ) {
			$prompt .= "**Research Depth: BASIC** - Focus on essential project information only.\n\n";
		} else {
			$prompt .= "**Research Depth: STANDARD** - Provide comprehensive project planning information.\n\n";
		}

		// Add focus areas if specified.
		if ( ! empty( $focus_areas ) ) {
			$prompt .= "**Focus Areas:** " . implode( ', ', $focus_areas ) . "\n\n";
		}

		$prompt .= "Use the provided sources and web search to find current, factually correct information.\n\n";
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
