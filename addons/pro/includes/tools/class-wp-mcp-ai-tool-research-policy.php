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
		return __( 'Research comprehensive information about an insurance policy type using multi-stage web search and AI analysis. Supports configurable research depth (basic/standard/comprehensive) and focus areas for targeted research. Returns policy name, description, coverage details, requirements, premiums, deductibles, exclusions, and terms ready for creating a policy template.', 'mcp-ai-wpoos-pro' );
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
				'depth'              => array(
					'type'        => 'string',
					'description' => __( 'Research depth level.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'basic', 'standard', 'comprehensive' ),
					'default'     => 'standard',
				),
				'focus_areas'        => array(
					'type'        => 'array',
					'description' => __( 'Optional specific aspects to focus on (e.g., "coverage details", "legal requirements", "industry standards").', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
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
		$depth              = isset( $arguments['depth'] ) ? sanitize_text_field( $arguments['depth'] ) : 'standard';
		$focus_areas        = isset( $arguments['focus_areas'] ) && is_array( $arguments['focus_areas'] )
			? array_map( 'sanitize_text_field', $arguments['focus_areas'] )
			: array();
		$coverage_focus     = isset( $arguments['coverage_focus'] ) ? sanitize_text_field( $arguments['coverage_focus'] ) : '';
		$include_comparison = isset( $arguments['include_comparison'] ) ? (bool) $arguments['include_comparison'] : false;

		// Validate depth parameter.
		if ( ! in_array( $depth, array( 'basic', 'standard', 'comprehensive' ), true ) ) {
			$depth = 'standard';
		}

		// Check cache first.
		$cache_key = 'policy_research_' . md5( $query . '_' . $depth . '_' . implode( '_', $focus_areas ) . '_' . $coverage_focus );
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
				'depth'          => $depth,
				'focus_areas'    => $focus_areas,
				'coverage_focus' => $coverage_focus,
				'user_id'        => $user_id,
			)
		);

		// Step 1: Gather information through web searches.
		$search_results = $this->gather_policy_information( $query, $coverage_focus, $depth, $focus_areas, $context );

		if ( is_wp_error( $search_results ) ) {
			WP_MCP_AI_Logger::log_error(
				'Policy research web search failed: ' . $search_results->get_error_message(),
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
		$prompt = $this->build_research_prompt( $query, $coverage_focus, $include_comparison, $depth, $focus_areas, $search_results );

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

		// Build user-friendly report.
		$policy_data['report'] = $this->build_policy_report_message( $policy_data, $search_results );

		// Cache the results for 7 days (policies don't change as frequently).
		wp_cache_set( $cache_key, $policy_data, 'wp_mcp_ai_policy_research', 7 * DAY_IN_SECONDS );

		// Log success.
		WP_MCP_AI_Logger::log_event(
			'policy_research_completed',
			'Policy research completed successfully',
			array(
				'query'         => $query,
				'depth'         => $depth,
				'focus_areas'   => $focus_areas,
				'sources_count' => count( $search_results['sources'] ?? array() ),
				'policy_name'   => isset( $policy_data['policy_name'] ) ? $policy_data['policy_name'] : '',
			)
		);

		return $policy_data;
	}

	/**
	 * Gather policy information through web searches.
	 *
	 * @param string $query          Policy query.
	 * @param string $coverage_focus Coverage focus.
	 * @param string $depth          Research depth.
	 * @param array  $focus_areas    Focus areas.
	 * @param array  $context        Execution context.
	 * @return array|WP_Error Search results or error.
	 */
	protected function gather_policy_information( $query, $coverage_focus, $depth, $focus_areas, $context ) {
		$registry        = WP_MCP_AI_Tool_Registry::get_instance();
		$web_search_tool = $registry->get_tool( 'web_search' );

		if ( ! $web_search_tool ) {
			WP_MCP_AI_Logger::log_event(
				'policy_research_no_web_search',
				'Web search tool not available, using AI-only mode',
				array( 'query' => $query )
			);
			return array(
				'results' => array(),
				'sources' => array(),
				'queries' => array( $query ),
			);
		}

		$search_queries = $this->generate_policy_search_queries( $query, $coverage_focus, $depth, $focus_areas );
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
					'Policy research web search failed: ' . $search_result->get_error_message(),
					array(
						'query'      => $search_query,
						'policy'     => $query,
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
			'policy_research_web_search_complete',
			'Web search completed for policy research',
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
	 * Generate search queries for policy research.
	 *
	 * @param string $query          Policy query.
	 * @param string $coverage_focus Coverage focus.
	 * @param string $depth          Research depth.
	 * @param array  $focus_areas    Focus areas.
	 * @return array Search queries.
	 */
	protected function generate_policy_search_queries( $query, $coverage_focus, $depth, $focus_areas ) {
		$queries   = array();
		$queries[] = $query . ( $coverage_focus ? ' ' . $coverage_focus : '' );

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
				$queries[] = $query . ' ' . $area . ( $coverage_focus ? ' ' . $coverage_focus : '' );
			}
		}

		if ( count( $queries ) < $num_queries ) {
			if ( 'comprehensive' === $depth ) {
				$queries[] = $query . ' legal requirements compliance' . ( $coverage_focus ? ' ' . $coverage_focus : '' );
				if ( count( $queries ) < $num_queries ) {
					$queries[] = $query . ' industry standards best practices' . ( $coverage_focus ? ' ' . $coverage_focus : '' );
				}
			} elseif ( 'standard' === $depth ) {
				$queries[] = $query . ' policy requirements' . ( $coverage_focus ? ' ' . $coverage_focus : '' );
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
	 * @param string $coverage_focus     Coverage focus areas.
	 * @param bool   $include_comparison Whether to include comparison.
	 * @param string $depth              Research depth.
	 * @param array  $focus_areas        Focus areas.
	 * @param array  $search_results     Search results from web search.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $query, $coverage_focus, $include_comparison, $depth, $focus_areas, $search_results ) {
		$prompt = sprintf(
			"Research comprehensive information about the following insurance policy type:\n\n**Policy Type:** %s\n",
			$query
		);

		if ( ! empty( $coverage_focus ) ) {
			$prompt .= sprintf( "**Coverage Focus:** %s\n", $coverage_focus );
		}

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
			$prompt .= "**Research Depth: COMPREHENSIVE** - Include extensive legal requirements, compliance details, and industry standards information.\n\n";
		} elseif ( 'basic' === $depth ) {
			$prompt .= "**Research Depth: BASIC** - Focus on essential policy information only.\n\n";
		} else {
			$prompt .= "**Research Depth: STANDARD** - Provide thorough information appropriate for policy planning.\n\n";
		}

		// Add focus areas if specified.
		if ( ! empty( $focus_areas ) ) {
			$prompt .= "**Focus Areas:** " . implode( ', ', $focus_areas ) . "\n\n";
		}

		$prompt .= "Use the provided sources and web search to find current, factually correct information.\n";

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

	/**
	 * Build a user-friendly report message for policy research.
	 *
	 * @param array $policy_data    Policy research data.
	 * @param array $search_results Search results from web search.
	 * @return string Markdown-formatted report.
	 */
	protected function build_policy_report_message( $policy_data, $search_results ) {
		$report = "## Insurance Policy Research Complete\n\n";

		// Policy name.
		if ( ! empty( $policy_data['policy_name'] ) ) {
			$report .= "**Policy Name:** " . esc_html( $policy_data['policy_name'] ) . "\n\n";
		}

		// Policy type.
		if ( ! empty( $policy_data['policy_type'] ) ) {
			$report .= "**Policy Type:** " . esc_html( $policy_data['policy_type'] ) . "\n\n";
		}

		// Description.
		if ( ! empty( $policy_data['description'] ) ) {
			$report .= "### Description\n";
			$report .= $policy_data['description'] . "\n\n";
		}

		// Coverage details.
		if ( ! empty( $policy_data['coverage_details'] ) ) {
			$report .= "### Coverage Details\n";
			$report .= $policy_data['coverage_details'] . "\n\n";
		}

		// Coverage limits.
		if ( ! empty( $policy_data['coverage_limits'] ) ) {
			$report .= "**Coverage Limits:** " . esc_html( $policy_data['coverage_limits'] ) . "\n\n";
		}

		// Terms and conditions section.
		if ( ! empty( $policy_data['deductible'] ) || ! empty( $policy_data['premium_range'] ) || ! empty( $policy_data['waiting_period'] ) ) {
			$report .= "### Terms & Conditions\n";

			if ( ! empty( $policy_data['deductible'] ) ) {
				$report .= "**Deductible:** " . esc_html( $policy_data['deductible'] ) . "\n";
			}

			if ( ! empty( $policy_data['premium_range'] ) ) {
				$report .= "**Premium Range:** " . esc_html( $policy_data['premium_range'] ) . "\n";
			}

			if ( ! empty( $policy_data['waiting_period'] ) ) {
				$report .= "**Waiting Period:** " . esc_html( $policy_data['waiting_period'] ) . "\n";
			}

			$report .= "\n";
		}

		// Requirements.
		if ( ! empty( $policy_data['requirements'] ) && is_array( $policy_data['requirements'] ) ) {
			$report .= "### Requirements\n";
			foreach ( $policy_data['requirements'] as $requirement ) {
				$report .= "- " . esc_html( $requirement ) . "\n";
			}
			$report .= "\n";
		}

		// Exclusions.
		if ( ! empty( $policy_data['exclusions'] ) && is_array( $policy_data['exclusions'] ) ) {
			$report .= "### Exclusions\n";
			foreach ( $policy_data['exclusions'] as $exclusion ) {
				$report .= "- " . esc_html( $exclusion ) . "\n";
			}
			$report .= "\n";
		}

		// Benefits.
		if ( ! empty( $policy_data['benefits'] ) && is_array( $policy_data['benefits'] ) ) {
			$report .= "### Key Benefits\n";
			foreach ( $policy_data['benefits'] as $benefit ) {
				$report .= "- " . esc_html( $benefit ) . "\n";
			}
			$report .= "\n";
		}

		// Claim process.
		if ( ! empty( $policy_data['claim_process'] ) ) {
			$report .= "### Claim Process\n";
			$report .= $policy_data['claim_process'] . "\n\n";
		}

		// Comparison.
		if ( ! empty( $policy_data['comparison'] ) ) {
			$report .= "### Comparison with Similar Policies\n";
			$report .= $policy_data['comparison'] . "\n\n";
		}

		// Sources.
		if ( ! empty( $search_results['sources'] ) ) {
			$sources_count = count( $search_results['sources'] );
			$report       .= "### Research Sources\n";
			$report       .= "Research based on **" . absint( $sources_count ) . "** source" . ( $sources_count > 1 ? 's' : '' ) . ".\n\n";

			// Show limited sources.
			$max_display = 3;
			for ( $i = 0; $i < min( $max_display, $sources_count ); $i++ ) {
				$source = $search_results['sources'][ $i ];
				if ( ! empty( $source['title'] ) && ! empty( $source['url'] ) ) {
					$report .= "- [" . esc_html( $source['title'] ) . "](" . esc_url( $source['url'] ) . ")\n";
				} elseif ( ! empty( $source['url'] ) ) {
					$report .= "- " . esc_url( $source['url'] ) . "\n";
				}
			}

			if ( $sources_count > $max_display ) {
				$remaining = $sources_count - $max_display;
				$report   .= "\n*... and " . absint( $remaining ) . " more source" . ( $remaining > 1 ? 's' : '' ) . "*\n";
			}
		}

		$report .= "\n---\n";
		$report .= "*This research is for informational purposes only. Please consult with insurance professionals for personalized advice.*\n";

		return $report;
	}
}
