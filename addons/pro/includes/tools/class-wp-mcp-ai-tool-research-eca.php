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
	use WP_MCP_AI_Tool_Chat_Response;

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
		return __( 'Research comprehensive information about an extra-curricular activity or educational program using multi-stage web search and AI analysis. Supports configurable research depth (basic/standard/comprehensive) and focus areas for targeted research. Returns title, description, category, schedule, materials, learning objectives, and implementation details ready for creating an ECA entry.', 'mcp-ai-wpoos-pro' );
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
				'depth'              => array(
					'type'        => 'string',
					'description' => __( 'Research depth level.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'basic', 'standard', 'comprehensive' ),
					'default'     => 'standard',
				),
				'focus_areas'        => array(
					'type'        => 'array',
					'description' => __( 'Optional specific aspects to focus on (e.g., "educational value", "age appropriateness", "learning objectives", "curriculum standards").', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
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
		$depth              = isset( $arguments['depth'] ) ? sanitize_text_field( $arguments['depth'] ) : 'standard';
		$focus_areas        = isset( $arguments['focus_areas'] ) && is_array( $arguments['focus_areas'] )
			? array_map( 'sanitize_text_field', $arguments['focus_areas'] )
			: array();
		$age_group          = isset( $arguments['age_group'] ) ? sanitize_text_field( $arguments['age_group'] ) : '';
		$include_curriculum = isset( $arguments['include_curriculum'] ) ? (bool) $arguments['include_curriculum'] : true;

		// Validate depth parameter.
		if ( ! in_array( $depth, array( 'basic', 'standard', 'comprehensive' ), true ) ) {
			$depth = 'standard';
		}

		// Check cache first.
		$cache_key = 'eca_research_' . md5( $query . '_' . $depth . '_' . implode( '_', $focus_areas ) . '_' . $age_group );
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
				'query'       => $query,
				'depth'       => $depth,
				'focus_areas' => $focus_areas,
				'age_group'   => $age_group,
				'user_id'     => $user_id,
			)
		);

		// Step 1: Gather information through web searches.
		$search_results = $this->gather_eca_information( $query, $age_group, $depth, $focus_areas, $context );

		if ( is_wp_error( $search_results ) ) {
			WP_MCP_AI_Logger::log_error(
				'ECA research web search failed: ' . $search_results->get_error_message(),
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
		$prompt = $this->build_research_prompt( $query, $age_group, $depth, $focus_areas, $search_results, $include_curriculum );

		// Step 3: Use AI to research the ECA.
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
				'query'         => $query,
				'depth'         => $depth,
				'focus_areas'   => $focus_areas,
				'sources_count' => count( $search_results['sources'] ?? array() ),
				'title'         => isset( $eca_data['title'] ) ? $eca_data['title'] : '',
			)
		);

		return $eca_data;
	}

	/**
	 * Gather ECA information through web searches.
	 *
	 * @param string $query       ECA query.
	 * @param string $age_group   Age group.
	 * @param string $depth       Research depth.
	 * @param array  $focus_areas Focus areas.
	 * @param array  $context     Execution context.
	 * @return array|WP_Error Search results or error.
	 */
	protected function gather_eca_information( $query, $age_group, $depth, $focus_areas, $context ) {
		$registry        = WP_MCP_AI_Tool_Registry::get_instance();
		$web_search_tool = $registry->get_tool( 'web_search' );

		if ( ! $web_search_tool ) {
			WP_MCP_AI_Logger::log_event(
				'eca_research_no_web_search',
				'Web search tool not available, using AI-only mode',
				array( 'query' => $query )
			);
			return array(
				'results' => array(),
				'sources' => array(),
				'queries' => array( $query ),
			);
		}

		$search_queries = $this->generate_eca_search_queries( $query, $age_group, $depth, $focus_areas );
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
					'ECA research web search failed: ' . $search_result->get_error_message(),
					array(
						'query'      => $search_query,
						'eca'        => $query,
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
			'eca_research_web_search_complete',
			'Web search completed for ECA research',
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
	 * Generate search queries for ECA research.
	 *
	 * @param string $query       ECA query.
	 * @param string $age_group   Age group.
	 * @param string $depth       Research depth.
	 * @param array  $focus_areas Focus areas.
	 * @return array Search queries.
	 */
	protected function generate_eca_search_queries( $query, $age_group, $depth, $focus_areas ) {
		$queries = array();
		$queries[] = $query . ( $age_group ? ' ' . $age_group : '' );

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
				$queries[] = $query . ' ' . $area . ( $age_group ? ' ' . $age_group : '' );
			}
		}

		if ( count( $queries ) < $num_queries ) {
			if ( 'comprehensive' === $depth ) {
				$queries[] = $query . ' educational value curriculum standards' . ( $age_group ? ' ' . $age_group : '' );
				if ( count( $queries ) < $num_queries ) {
					$queries[] = $query . ' learning objectives age appropriateness' . ( $age_group ? ' ' . $age_group : '' );
				}
			} elseif ( 'standard' === $depth ) {
				$queries[] = $query . ' educational activities' . ( $age_group ? ' ' . $age_group : '' );
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
	 * @param string $query              Search query.
	 * @param string $age_group          Age group.
	 * @param string $depth              Research depth.
	 * @param array  $focus_areas        Focus areas.
	 * @param array  $search_results     Search results from web search.
	 * @param bool   $include_curriculum Whether to include curriculum.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $query, $age_group, $depth, $focus_areas, $search_results, $include_curriculum ) {
		$prompt = sprintf(
			"Research comprehensive information about the following extra-curricular activity or educational program:\n\n**Activity:** %s\n",
			$query
		);

		if ( ! empty( $age_group ) ) {
			$prompt .= sprintf( "**Age Group:** %s\n", $age_group );
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
			$prompt .= "**Research Depth: COMPREHENSIVE** - Include extensive educational details, curriculum standards alignment, and detailed implementation guidance.\n\n";
		} elseif ( 'basic' === $depth ) {
			$prompt .= "**Research Depth: BASIC** - Focus on essential information for the ECA only.\n\n";
		} else {
			$prompt .= "**Research Depth: STANDARD** - Provide comprehensive information appropriate for educational program planning.\n\n";
		}

		// Add focus areas if specified.
		if ( ! empty( $focus_areas ) ) {
			$prompt .= "**Focus Areas:** " . implode( ', ', $focus_areas ) . "\n\n";
		}

		$prompt .= "Use the provided sources and web search to find current, factually correct information.\n\n";
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

		// Build user-friendly research report message.
		$report_message        = $this->build_eca_report_message( $eca_data );
		$eca_data['report']    = $report_message;

		return $eca_data;
	}

	/**
	 * Build a user-friendly ECA research report message.
	 *
	 * @param array $data ECA data array.
	 * @return string Markdown-formatted report message.
	 */
	protected function build_eca_report_message( $data ) {
		$report = "## ECA Research Complete\n\n";

		// Activity title.
		if ( ! empty( $data['title'] ) ) {
			$report .= "**Activity:** " . esc_html( $data['title'] ) . "\n";
		}

		// Category/Type.
		if ( ! empty( $data['category'] ) ) {
			$report .= "**Category:** " . esc_html( $data['category'] ) . "\n";
		}

		// Age range.
		if ( ! empty( $data['age_range'] ) ) {
			$report .= "**Age Range:** " . esc_html( $data['age_range'] ) . "\n";
		}

		$report .= "\n";

		// Description.
		if ( ! empty( $data['description'] ) ) {
			$report .= "### Description\n";
			$report .= wp_strip_all_tags( $data['description'] ) . "\n\n";
		}

		// Schedule Information.
		if ( ! empty( $data['duration'] ) || ! empty( $data['session_length'] ) || ! empty( $data['frequency'] ) ) {
			$report .= "### Schedule\n";
			if ( ! empty( $data['duration'] ) ) {
				$report .= "- **Program Duration:** " . esc_html( $data['duration'] ) . "\n";
			}
			if ( ! empty( $data['session_length'] ) ) {
				$report .= "- **Session Length:** " . esc_html( $data['session_length'] ) . "\n";
			}
			if ( ! empty( $data['frequency'] ) ) {
				$report .= "- **Frequency:** " . esc_html( $data['frequency'] ) . "\n";
			}
			$report .= "\n";
		}

		// Group size.
		if ( ! empty( $data['group_size'] ) ) {
			$report .= "**Recommended Group Size:** " . esc_html( $data['group_size'] ) . "\n\n";
		}

		// Learning objectives.
		if ( ! empty( $data['learning_objectives'] ) && is_array( $data['learning_objectives'] ) ) {
			$report .= "### Learning Objectives\n";
			foreach ( $data['learning_objectives'] as $objective ) {
				$report .= "- " . esc_html( $objective ) . "\n";
			}
			$report .= "\n";
		}

		// Materials required.
		if ( ! empty( $data['materials'] ) && is_array( $data['materials'] ) ) {
			$report .= "### Materials Required\n";
			$material_count = 0;
			foreach ( $data['materials'] as $material ) {
				if ( $material_count >= 10 ) {
					$remaining = count( $data['materials'] ) - $material_count;
					$report .= "- *...and " . absint( $remaining ) . " more*\n";
					break;
				}
				$report .= "- " . esc_html( $material ) . "\n";
				$material_count++;
			}
			$report .= "\n";
		}

		// Space requirements.
		if ( ! empty( $data['space_requirements'] ) ) {
			$report .= "**Space Requirements:** " . esc_html( $data['space_requirements'] ) . "\n\n";
		}

		// Instructor requirements.
		if ( ! empty( $data['instructor_requirements'] ) ) {
			$report .= "**Instructor Requirements:** " . esc_html( $data['instructor_requirements'] ) . "\n\n";
		}

		// Curriculum outline (if available).
		if ( ! empty( $data['curriculum_outline'] ) ) {
			$report .= "### Curriculum Outline\n";
			$report .= wp_strip_all_tags( $data['curriculum_outline'] ) . "\n\n";
		}

		// Sources.
		if ( ! empty( $data['sources'] ) && is_array( $data['sources'] ) ) {
			$report .= "**Research Sources:** " . count( $data['sources'] ) . " reference source(s)\n";
		}

		$report .= "\n---\n\n";
		$report .= "*Research completed successfully. This ECA information can be used to create an activity entry in your system.*";

		return $report;
	}
}
