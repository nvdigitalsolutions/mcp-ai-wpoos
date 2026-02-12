<?php
/**
 * Tool for researching quiz topics using AI and web search.
 *
 * Provides comprehensive research about a quiz topic including
 * suggested questions, answers, difficulty levels, and educational context.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Research Quiz Topic Tool
 *
 * Uses AI and web search to research comprehensive information about
 * educational topics and generate quiz content.
 */
class WP_MCP_AI_Tool_Research_Quiz_Topic implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'research_quiz_topic';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Research Quiz Topic', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Research comprehensive information about an educational topic and generate quiz questions with answers using multi-stage web search and AI analysis. Supports configurable research depth (basic/standard/comprehensive) and focus areas for targeted research. Returns title, description, difficulty level, suggested questions with multiple choice answers, and educational metadata ready for creating a quiz.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'topic'                => array(
					'type'        => 'string',
					'description' => __( 'The topic to research (e.g., "World War II", "Basic Algebra", "Shakespeare Works")', 'mcp-ai-wpoos-pro' ),
				),
				'depth'                => array(
					'type'        => 'string',
					'description' => __( 'Research depth level.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'basic', 'standard', 'comprehensive' ),
					'default'     => 'standard',
				),
				'focus_areas'          => array(
					'type'        => 'array',
					'description' => __( 'Optional specific aspects to focus on (e.g., "historical context", "key concepts", "practical applications").', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'question_count'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of questions to generate', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'difficulty'           => array(
					'type'        => 'string',
					'description' => __( 'Difficulty level for questions', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'beginner', 'intermediate', 'advanced' ),
					'default'     => 'intermediate',
				),
				'include_explanations' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include explanations for correct answers', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'topic' ),
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
		// Quiz system is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_quiz_system'] );
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
				__( 'You do not have permission to research quiz topics.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate required arguments.
		if ( empty( $arguments['topic'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_topic',
				__( 'Topic is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$topic                = sanitize_text_field( $arguments['topic'] );
		$depth                = isset( $arguments['depth'] ) ? sanitize_text_field( $arguments['depth'] ) : 'standard';
		$focus_areas          = isset( $arguments['focus_areas'] ) && is_array( $arguments['focus_areas'] )
			? array_map( 'sanitize_text_field', $arguments['focus_areas'] )
			: array();
		$question_count       = isset( $arguments['question_count'] ) ? absint( $arguments['question_count'] ) : 10;
		$difficulty           = isset( $arguments['difficulty'] ) ? sanitize_key( $arguments['difficulty'] ) : 'intermediate';
		$include_explanations = isset( $arguments['include_explanations'] ) ? (bool) $arguments['include_explanations'] : true;

		// Validate question count.
		if ( $question_count < 1 || $question_count > 50 ) {
			$question_count = 10;
		}

		// Validate difficulty.
		if ( ! in_array( $difficulty, array( 'beginner', 'intermediate', 'advanced' ), true ) ) {
			$difficulty = 'intermediate';
		}

		// Validate depth parameter.
		if ( ! in_array( $depth, array( 'basic', 'standard', 'comprehensive' ), true ) ) {
			$depth = 'standard';
		}

		// Check cache first.
		$cache_key = 'quiz_research_' . md5( $topic . '_' . $depth . '_' . implode( '_', $focus_areas ) . '_' . $question_count . '_' . $difficulty );
		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai_quiz_research' );

		if ( false !== $cached && is_array( $cached ) ) {
			$cached['_from_cache'] = true;
			return $cached;
		}

		// Log research start.
		WP_MCP_AI_Logger::log_event(
			'quiz_research_started',
			'Starting quiz topic research',
			array(
				'topic'          => $topic,
				'depth'          => $depth,
				'focus_areas'    => $focus_areas,
				'question_count' => $question_count,
				'difficulty'     => $difficulty,
				'user_id'        => $user_id,
			)
		);

		// Step 1: Gather information through web searches.
		$search_results = $this->gather_quiz_topic_information( $topic, $depth, $focus_areas, $context );

		if ( is_wp_error( $search_results ) ) {
			WP_MCP_AI_Logger::log_error(
				'Quiz research web search failed: ' . $search_results->get_error_message(),
				array(
					'topic' => $topic,
					'depth' => $depth,
					'error' => $search_results->get_error_code(),
				)
			);
			// Fall back to AI-only research if web search fails.
			$search_results = array(
				'results' => array(),
				'sources' => array(),
				'queries' => array( $topic ),
			);
		}

		// Step 2: Build research prompt with gathered information.
		$prompt = $this->build_research_prompt( $topic, $question_count, $difficulty, $include_explanations, $depth, $focus_areas, $search_results );

		// Use AI to research the topic and generate questions.
		$research_result = $this->perform_ai_research( $prompt, $context );

		if ( is_wp_error( $research_result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Quiz research failed: ' . $research_result->get_error_message(),
				array(
					'topic' => $topic,
					'error' => $research_result->get_error_code(),
				)
			);
			return $research_result;
		}

		// Parse and validate the research results.
		$quiz_data = $this->parse_research_results( $research_result, $topic, $question_count, $difficulty );

		if ( is_wp_error( $quiz_data ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to parse quiz research results: ' . $quiz_data->get_error_message(),
				array(
					'topic' => $topic,
				)
			);
			return $quiz_data;
		}

		// Cache the results for 24 hours.
		wp_cache_set( $cache_key, $quiz_data, 'wp_mcp_ai_quiz_research', DAY_IN_SECONDS );

		// Log success.
		WP_MCP_AI_Logger::log_event(
			'quiz_research_completed',
			'Quiz research completed successfully',
			array(
				'topic'          => $topic,
				'depth'          => $depth,
				'focus_areas'    => $focus_areas,
				'sources_count'  => count( $search_results['sources'] ?? array() ),
				'question_count' => count( isset( $quiz_data['questions'] ) ? $quiz_data['questions'] : array() ),
			)
		);

		return $quiz_data;
	}

	/**
	 * Gather quiz topic information through web searches.
	 *
	 * @param string $topic       Quiz topic.
	 * @param string $depth       Research depth.
	 * @param array  $focus_areas Focus areas.
	 * @param array  $context     Execution context.
	 * @return array|WP_Error Search results or error.
	 */
	protected function gather_quiz_topic_information( $topic, $depth, $focus_areas, $context ) {
		$registry        = WP_MCP_AI_Tool_Registry::get_instance();
		$web_search_tool = $registry->get_tool( 'web_search' );

		if ( ! $web_search_tool ) {
			WP_MCP_AI_Logger::log_event(
				'quiz_research_no_web_search',
				'Web search tool not available, using AI-only mode',
				array( 'topic' => $topic )
			);
			return array(
				'results' => array(),
				'sources' => array(),
				'queries' => array( $topic ),
			);
		}

		$search_queries = $this->generate_quiz_topic_search_queries( $topic, $depth, $focus_areas );
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
					'Quiz research web search failed: ' . $search_result->get_error_message(),
					array(
						'query'      => $search_query,
						'topic'      => $topic,
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
			'quiz_research_web_search_complete',
			'Web search completed for quiz research',
			array(
				'topic'         => $topic,
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
	 * Generate search queries for quiz topic research.
	 *
	 * @param string $topic       Quiz topic.
	 * @param string $depth       Research depth.
	 * @param array  $focus_areas Focus areas.
	 * @return array Search queries.
	 */
	protected function generate_quiz_topic_search_queries( $topic, $depth, $focus_areas ) {
		$queries   = array();
		$queries[] = $topic;

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
				$queries[] = $topic . ' ' . $area;
			}
		}

		if ( count( $queries ) < $num_queries ) {
			if ( 'comprehensive' === $depth ) {
				$queries[] = $topic . ' quiz questions assessment';
				if ( count( $queries ) < $num_queries ) {
					$queries[] = $topic . ' educational standards';
				}
			} elseif ( 'standard' === $depth ) {
				$queries[] = $topic . ' quiz questions practice';
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
	 * @param string $topic                Topic to research.
	 * @param int    $question_count       Number of questions.
	 * @param string $difficulty           Difficulty level.
	 * @param bool   $include_explanations Whether to include explanations.
	 * @param string $depth                Research depth.
	 * @param array  $focus_areas          Focus areas.
	 * @param array  $search_results       Search results from web search.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $topic, $question_count, $difficulty, $include_explanations, $depth, $focus_areas, $search_results ) {
		$prompt = sprintf(
			"Research the following educational topic and generate quiz questions:\n\n**Topic:** %s\n**Number of Questions:** %d\n**Difficulty Level:** %s\n",
			$topic,
			$question_count,
			$difficulty
		);

		// Add context from web search if available.
		if ( ! empty( $search_results['sources'] ) ) {
			$prompt .= "\n**Available Research Sources:**\n";
			$source_count = min( self::MAX_DISPLAYED_SOURCES, count( $search_results['sources'] ) );
			for ( $i = 0; $i < $source_count; $i++ ) {
				$source  = $search_results['sources'][ $i ];
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
			$prompt .= "**Research Depth: COMPREHENSIVE** - Use extensive research to create well-validated questions with detailed explanations and educational context.\n\n";
		} elseif ( 'basic' === $depth ) {
			$prompt .= "**Research Depth: BASIC** - Focus on essential topic information for core quiz questions.\n\n";
		} else {
			$prompt .= "**Research Depth: STANDARD** - Provide thorough research appropriate for educational quiz content.\n\n";
		}

		// Add focus areas if specified.
		if ( ! empty( $focus_areas ) ) {
			$prompt .= "**Focus Areas:** " . implode( ', ', $focus_areas ) . "\n\n";
		}

		$prompt .= "Use the provided sources and web search to find current, factually correct information.\n\n";

		$prompt .= "Generate a comprehensive quiz covering key aspects of this topic. Include:\n\n";
		$prompt .= "1. **Quiz Title**: Engaging title for the quiz\n";
		$prompt .= "2. **Description**: Brief description of what the quiz covers (100-200 words)\n";
		$prompt .= "3. **Subject**: Primary subject area (e.g., History, Math, Science, Literature)\n";
		$prompt .= "4. **Questions**: Generate exactly {$question_count} multiple choice questions\n\n";

		$prompt .= "For each question, provide:\n";
		$prompt .= "- Question text\n";
		$prompt .= "- 4 answer options (A, B, C, D)\n";
		$prompt .= "- Correct answer\n";
		if ( $include_explanations ) {
			$prompt .= "- Brief explanation of why the answer is correct\n";
		}
		$prompt .= "\n";

		$prompt .= "Question difficulty guidelines:\n";
		$prompt .= "- **Beginner**: Basic knowledge, straightforward questions\n";
		$prompt .= "- **Intermediate**: Requires understanding of concepts and relationships\n";
		$prompt .= "- **Advanced**: Complex analysis, critical thinking, expert-level knowledge\n\n";

		$prompt .= "**IMPORTANT**: Return the information in the following JSON format:\n\n";
		$prompt .= "```json\n";
		$prompt .= "{\n";
		$prompt .= '  "title": "Quiz Title",';
		$prompt .= "\n";
		$prompt .= '  "description": "Quiz description...",';
		$prompt .= "\n";
		$prompt .= '  "subject": "History",';
		$prompt .= "\n";
		$prompt .= '  "difficulty": "' . $difficulty . '",';
		$prompt .= "\n";
		$prompt .= '  "time_limit": 30,';
		$prompt .= "\n";
		$prompt .= '  "pass_score": 70,';
		$prompt .= "\n";
		$prompt .= '  "questions": [';
		$prompt .= "\n";
		$prompt .= '    {';
		$prompt .= "\n";
		$prompt .= '      "question": "Question text here?",';
		$prompt .= "\n";
		$prompt .= '      "options": {';
		$prompt .= "\n";
		$prompt .= '        "A": "First option",';
		$prompt .= "\n";
		$prompt .= '        "B": "Second option",';
		$prompt .= "\n";
		$prompt .= '        "C": "Third option",';
		$prompt .= "\n";
		$prompt .= '        "D": "Fourth option"';
		$prompt .= "\n";
		$prompt .= '      },';
		$prompt .= "\n";
		$prompt .= '      "correct_answer": "A",';
		$prompt .= "\n";
		if ( $include_explanations ) {
			$prompt .= '      "explanation": "Explanation of correct answer..."';
			$prompt .= "\n";
		}
		$prompt .= '    }';
		$prompt .= "\n";
		$prompt .= '  ],';
		$prompt .= "\n";
		$prompt .= '  "sources": ["URL1", "URL2"]';
		$prompt .= "\n";
		$prompt .= "}\n";
		$prompt .= "```\n\n";

		$prompt .= 'Use web search to find accurate, up-to-date information. ';
		$prompt .= "Include source URLs in the 'sources' array. ";
		$prompt .= "Ensure all questions are factually correct and educationally sound.\n";

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
				'content' => 'You are a helpful AI assistant and educational content creator. You research topics and generate high-quality quiz questions with accurate information. Always respond with valid JSON matching the requested format. Use web search when available to ensure accuracy.',
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
				'temperature' => 0.3, // Low temperature for factual, educational content.
				'max_tokens'  => 4000, // Allow for more questions.
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
	 * Parse the AI research results into quiz data format.
	 *
	 * @param array  $research_result AI research results.
	 * @param string $topic           Original topic.
	 * @param int    $question_count  Requested question count.
	 * @param string $difficulty      Difficulty level.
	 * @return array|WP_Error Parsed quiz data or error.
	 */
	protected function parse_research_results( $research_result, $topic, $question_count, $difficulty ) {
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
			$data['title'] = $topic . ' Quiz';
		}

		if ( empty( $data['questions'] ) || ! is_array( $data['questions'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_questions',
				__( 'No questions were generated.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build quiz data structure.
		$quiz_data = array(
			'success'           => true,
			'topic'             => $topic,
			'title'             => sanitize_text_field( $data['title'] ),
			'description'       => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
			'subject'           => isset( $data['subject'] ) ? sanitize_text_field( $data['subject'] ) : '',
			'difficulty'        => $difficulty,
			'time_limit'        => isset( $data['time_limit'] ) ? absint( $data['time_limit'] ) : 30,
			'pass_score'        => isset( $data['pass_score'] ) ? absint( $data['pass_score'] ) : 70,
			'questions'         => array(),
			'sources'           => isset( $data['sources'] ) && is_array( $data['sources'] ) ? array_map( 'esc_url_raw', $data['sources'] ) : array(),
			'researched_at'     => current_time( 'mysql' ),
			'research_model'    => $research_result['model'],
			'research_provider' => $research_result['provider'],
		);

		// Process questions.
		foreach ( $data['questions'] as $q ) {
			if ( empty( $q['question'] ) || empty( $q['options'] ) || empty( $q['correct_answer'] ) ) {
				continue; // Skip invalid questions.
			}

			$question = array(
				'question'       => sanitize_text_field( $q['question'] ),
				'options'        => array(),
				'correct_answer' => sanitize_text_field( $q['correct_answer'] ),
			);

			// Sanitize options.
			if ( is_array( $q['options'] ) ) {
				foreach ( $q['options'] as $key => $value ) {
					$question['options'][ sanitize_key( $key ) ] = sanitize_text_field( $value );
				}
			}

			// Add explanation if present.
			if ( ! empty( $q['explanation'] ) ) {
				$question['explanation'] = sanitize_text_field( $q['explanation'] );
			}

			$quiz_data['questions'][] = $question;
		}

		return $quiz_data;
	}
}
