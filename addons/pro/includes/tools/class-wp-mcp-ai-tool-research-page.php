<?php
/**
 * Tool for researching page topics using AI and web search.
 *
 * Provides comprehensive research about a WordPress page topic including
 * title, content, SEO metadata, and format ready for creation.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Research Page Tool
 *
 * Uses AI and web search to research comprehensive information about
 * WordPress page topics and generate ready-to-publish content.
 */
class WP_MCP_AI_Tool_Research_Page implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'research_page';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Research Page', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Research comprehensive information about a WordPress page topic using multi-stage web search and AI analysis. Supports configurable research depth (basic/standard/comprehensive) and focus areas for targeted research. Returns title, content, SEO metadata, and formatting instructions based on the selected template (Classic Editor, Block Editor, or Elementor). Optimized for static pages like About, Contact, Services, etc.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'topic'       => array(
					'type'        => 'string',
					'description' => __( 'The page purpose/topic (e.g., "About Us page for tech company", "Privacy Policy for e-commerce", "Contact page with business hours")', 'mcp-ai-wpoos-pro' ),
				),
				'depth'       => array(
					'type'        => 'string',
					'description' => __( 'Research depth level.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'basic', 'standard', 'comprehensive' ),
					'default'     => 'standard',
				),
				'focus_areas' => array(
					'type'        => 'array',
					'description' => __( 'Optional specific aspects to focus on (e.g., "company history", "team", "services", "contact info").', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'page_type'   => array(
					'type'        => 'string',
					'description' => __( 'Type of page to create', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'about', 'contact', 'services', 'privacy-policy', 'terms-conditions', 'faq', 'landing', 'custom' ),
					'default'     => 'custom',
				),
				'word_count'  => array(
					'type'        => 'integer',
					'description' => __( 'Target word count for the content', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 100,
					'maximum'     => 3000,
					'default'     => 800,
				),
				'template'    => array(
					'type'        => 'string',
					'description' => __( 'Template format for content', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'block-editor', 'classic-editor', 'elementor' ),
					'default'     => 'block-editor',
				),
				'include_seo' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include SEO metadata (meta description, keywords)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'tone'        => array(
					'type'        => 'string',
					'description' => __( 'Tone of voice for the content', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'professional', 'friendly', 'formal', 'welcoming', 'corporate' ),
					'default'     => 'professional',
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
		// AI CPT Management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_ai_cpt_management'] );
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

		// Check permissions - requires edit_pages capability.
		if ( ! $user_id || ! user_can( $user_id, 'edit_pages' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to research pages.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate required arguments.
		if ( empty( $arguments['topic'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_topic',
				__( 'Topic is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$topic       = sanitize_text_field( $arguments['topic'] );
		$depth       = isset( $arguments['depth'] ) ? sanitize_text_field( $arguments['depth'] ) : 'standard';
		$focus_areas = isset( $arguments['focus_areas'] ) && is_array( $arguments['focus_areas'] )
			? array_map( 'sanitize_text_field', $arguments['focus_areas'] )
			: array();
		$page_type   = isset( $arguments['page_type'] ) ? sanitize_key( $arguments['page_type'] ) : 'custom';
		$word_count  = isset( $arguments['word_count'] ) ? absint( $arguments['word_count'] ) : 800;
		$template    = isset( $arguments['template'] ) ? sanitize_key( $arguments['template'] ) : 'block-editor';
		$include_seo = isset( $arguments['include_seo'] ) ? (bool) $arguments['include_seo'] : true;
		$tone        = isset( $arguments['tone'] ) ? sanitize_key( $arguments['tone'] ) : 'professional';

		// Validate depth parameter.
		if ( ! in_array( $depth, array( 'basic', 'standard', 'comprehensive' ), true ) ) {
			$depth = 'standard';
		}

		// Validate word count.
		if ( $word_count < 100 || $word_count > 3000 ) {
			$word_count = 800;
		}

		// Validate template.
		if ( ! in_array( $template, array( 'block-editor', 'classic-editor', 'elementor' ), true ) ) {
			$template = 'block-editor';
		}

		// Validate page type.
		$valid_page_types = array( 'about', 'contact', 'services', 'privacy-policy', 'terms-conditions', 'faq', 'landing', 'custom' );
		if ( ! in_array( $page_type, $valid_page_types, true ) ) {
			$page_type = 'custom';
		}

		// Validate tone.
		if ( ! in_array( $tone, array( 'professional', 'friendly', 'formal', 'welcoming', 'corporate' ), true ) ) {
			$tone = 'professional';
		}

		// Check cache first.
		$cache_key = 'page_research_' . md5( $topic . '_' . $depth . '_' . implode( '_', $focus_areas ) . '_' . $page_type . '_' . $word_count . '_' . $template . '_' . $tone );
		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai_page_research' );

		if ( false !== $cached && is_array( $cached ) ) {
			$cached['_from_cache'] = true;
			return $cached;
		}

		// Log research start.
		WP_MCP_AI_Logger::log_event(
			'page_research_started',
			'Starting page research',
			array(
				'topic'       => $topic,
				'depth'       => $depth,
				'focus_areas' => $focus_areas,
				'page_type'   => $page_type,
				'word_count'  => $word_count,
				'template'    => $template,
				'user_id'     => $user_id,
			)
		);

		// Step 1: Gather information through web searches.
		$search_results = $this->gather_page_information( $topic, $page_type, $depth, $focus_areas, $context );

		if ( is_wp_error( $search_results ) ) {
			WP_MCP_AI_Logger::log_error(
				'Page research web search failed: ' . $search_results->get_error_message(),
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
		$prompt = $this->build_research_prompt( $topic, $page_type, $depth, $focus_areas, $search_results, $word_count, $template, $include_seo, $tone );

		// Step 3: Use AI to research the topic and generate content.
		$research_result = $this->perform_ai_research( $prompt, $context );

		if ( is_wp_error( $research_result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Page research failed: ' . $research_result->get_error_message(),
				array(
					'topic' => $topic,
					'error' => $research_result->get_error_code(),
				)
			);
			return $research_result;
		}

		// Parse and validate the research results.
		$page_data = $this->parse_research_results( $research_result, $topic, $page_type, $template );

		if ( is_wp_error( $page_data ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to parse page research results: ' . $page_data->get_error_message(),
				array(
					'topic' => $topic,
				)
			);
			return $page_data;
		}

		// Build user-friendly research report for chat display.
		$page_data['report'] = $this->build_page_report_message( $page_data, $search_results, $word_count, $page_type );

		// Cache the results for 24 hours.
		wp_cache_set( $cache_key, $page_data, 'wp_mcp_ai_page_research', DAY_IN_SECONDS );

		// Log success.
		WP_MCP_AI_Logger::log_event(
			'page_research_completed',
			'Page research completed successfully',
			array(
				'topic'         => $topic,
				'depth'         => $depth,
				'focus_areas'   => $focus_areas,
				'sources_count' => count( $search_results['sources'] ?? array() ),
				'title'         => isset( $page_data['title'] ) ? $page_data['title'] : '',
			)
		);

		return $page_data;
	}

	/**
	 * Gather page information through web searches.
	 *
	 * @param string $topic       Page topic.
	 * @param string $page_type   Page type.
	 * @param string $depth       Research depth.
	 * @param array  $focus_areas Focus areas.
	 * @param array  $context     Execution context.
	 * @return array|WP_Error Search results or error.
	 */
	protected function gather_page_information( $topic, $page_type, $depth, $focus_areas, $context ) {
		// Check if web search tool is available.
		$registry        = WP_MCP_AI_Tool_Registry::get_instance();
		$web_search_tool = $registry->get_tool( 'web_search' );

		if ( ! $web_search_tool ) {
			// Return empty results if web search is not available.
			WP_MCP_AI_Logger::log_event(
				'page_research_no_web_search',
				'Web search tool not available, using AI-only mode',
				array( 'topic' => $topic )
			);
			return array(
				'results' => array(),
				'sources' => array(),
				'queries' => array( $topic ),
			);
		}

		// Generate search queries based on depth and focus areas.
		$search_queries = $this->generate_page_search_queries( $topic, $page_type, $depth, $focus_areas );

		$all_results = array();
		$all_sources = array();

		foreach ( $search_queries as $search_query ) {
			// Execute web search.
			$search_result = $web_search_tool->execute(
				array(
					'query'       => $search_query,
					'max_results' => self::MAX_RESULTS_PER_QUERY,
				),
				$context
			);

			if ( is_wp_error( $search_result ) ) {
				// Log the error but continue with other searches.
				WP_MCP_AI_Logger::log_error(
					'Page research web search failed: ' . $search_result->get_error_message(),
					array(
						'query'      => $search_query,
						'topic'      => $topic,
						'error_code' => $search_result->get_error_code(),
					)
				);
				continue;
			}

			// Collect results.
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

		// Deduplicate sources by URL.
		$all_sources = $this->deduplicate_sources( $all_sources );

		WP_MCP_AI_Logger::log_event(
			'page_research_web_search_complete',
			'Web search completed for page research',
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
	 * Generate search queries for page research.
	 *
	 * @param string $topic       Page topic.
	 * @param string $page_type   Page type.
	 * @param string $depth       Research depth.
	 * @param array  $focus_areas Focus areas.
	 * @return array Search queries.
	 */
	protected function generate_page_search_queries( $topic, $page_type, $depth, $focus_areas ) {
		$queries = array();

		// Main query - always included.
		$queries[] = $topic;

		// Determine total number of queries based on depth.
		// Note: num_queries is the TOTAL including the main query above.
		if ( 'basic' === $depth ) {
			$num_queries = self::QUERIES_BASIC; // Total: 1 query (main only).
		} elseif ( 'comprehensive' === $depth ) {
			$num_queries = self::QUERIES_COMPREHENSIVE; // Total: 3 queries.
		} else {
			$num_queries = self::QUERIES_STANDARD; // Total: 2 queries (standard).
		}

		// Add focus area queries.
		if ( ! empty( $focus_areas ) ) {
			foreach ( $focus_areas as $area ) {
				if ( count( $queries ) >= $num_queries ) {
					break;
				}
				$queries[] = $topic . ' ' . $area;
			}
		}

		// Add page-type and depth-specific queries.
		if ( count( $queries ) < $num_queries ) {
			if ( 'comprehensive' === $depth ) {
				// Add type-specific comprehensive queries.
				if ( 'about' === $page_type ) {
					$queries[] = $topic . ' company history mission values';
				} elseif ( 'contact' === $page_type ) {
					$queries[] = $topic . ' contact information hours location';
				} elseif ( 'services' === $page_type ) {
					$queries[] = $topic . ' services offerings pricing';
				} else {
					$queries[] = $topic . ' best practices examples';
				}
				if ( count( $queries ) < $num_queries ) {
					$queries[] = $topic . ' industry standards templates';
				}
			} elseif ( 'standard' === $depth ) {
				if ( 'about' === $page_type ) {
					$queries[] = $topic . ' company overview';
				} elseif ( 'contact' === $page_type ) {
					$queries[] = $topic . ' contact details';
				} else {
					$queries[] = $topic . ' best practices';
				}
			}
		}

		// Limit to the calculated number of queries (already <= MAX_SEARCH_QUERIES).
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

			$url = $source['url'];

			if ( ! in_array( $url, $seen_urls, true ) ) {
				$unique_sources[] = $source;
				$seen_urls[]      = $url;
			}
		}

		return $unique_sources;
	}

	/**
	 * Build the research prompt for AI.
	 *
	 * @param string $topic          Topic to research.
	 * @param string $page_type      Type of page.
	 * @param string $depth          Research depth.
	 * @param array  $focus_areas    Focus areas.
	 * @param array  $search_results Search results from web search.
	 * @param int    $word_count     Target word count.
	 * @param string $template       Template format.
	 * @param bool   $include_seo    Whether to include SEO.
	 * @param string $tone           Tone of voice.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $topic, $page_type, $depth, $focus_areas, $search_results, $word_count, $template, $include_seo, $tone ) {
		$prompt = sprintf(
			"Create a comprehensive WordPress page about:\n\n**Topic:** %s\n**Page Type:** %s\n**Word Count:** %d words\n**Template:** %s\n**Tone:** %s\n\n",
			$topic,
			$page_type,
			$word_count,
			$template,
			$tone
		);

		// Add context from web search if available.
		if ( ! empty( $search_results['sources'] ) ) {
			$prompt .= "**Available Research Sources:**\n";
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
			$prompt .= "**Research Depth: COMPREHENSIVE** - Include extensive details, multiple sections, and thorough coverage.\n\n";
		} elseif ( 'basic' === $depth ) {
			$prompt .= "**Research Depth: BASIC** - Focus on essential information for the page only.\n\n";
		} else {
			$prompt .= "**Research Depth: STANDARD** - Provide comprehensive coverage appropriate for a static page.\n\n";
		}

		// Add focus areas if specified.
		if ( ! empty( $focus_areas ) ) {
			$prompt .= "**Focus Areas:** " . implode( ', ', $focus_areas ) . "\n\n";
		}

		$prompt .= "Use the provided sources and web search to find current, factually correct information.\n\n";
		$prompt .= "Generate a complete page including:\n\n";
		$prompt .= "1. **Title**: Clear, descriptive title for the page\n";
		$prompt .= "2. **Content**: Well-structured content appropriate for a static page\n";

		if ( $include_seo ) {
			$prompt .= "3. **Meta Description**: SEO meta description (150-160 characters)\n";
			$prompt .= "4. **Focus Keywords**: 3-5 relevant keywords for SEO\n";
		}

		$prompt .= "\n**Page Type Specific Guidelines:**\n\n";

		switch ( $page_type ) {
			case 'about':
				$prompt .= "- Tell the story of the company/organization\n";
				$prompt .= "- Include mission, vision, and values\n";
				$prompt .= "- Mention team/key people if relevant\n";
				$prompt .= "- Add achievements and milestones\n";
				break;

			case 'contact':
				$prompt .= "- Include contact form call-to-action\n";
				$prompt .= "- Add business hours if provided\n";
				$prompt .= "- Include address and contact methods\n";
				$prompt .= "- Make it easy to get in touch\n";
				break;

			case 'services':
				$prompt .= "- List key services/offerings\n";
				$prompt .= "- Describe benefits of each service\n";
				$prompt .= "- Include pricing information if relevant\n";
				$prompt .= "- Add clear call-to-actions\n";
				break;

			case 'privacy-policy':
				$prompt .= "- Cover data collection practices\n";
				$prompt .= "- Explain data usage and storage\n";
				$prompt .= "- Include user rights and opt-out options\n";
				$prompt .= "- Add cookie policy if relevant\n";
				$prompt .= "- Use clear, legal but accessible language\n";
				break;

			case 'terms-conditions':
				$prompt .= "- Define user responsibilities\n";
				$prompt .= "- Explain service terms\n";
				$prompt .= "- Cover liability and disclaimers\n";
				$prompt .= "- Include dispute resolution\n";
				$prompt .= "- Use clear, legal but accessible language\n";
				break;

			case 'faq':
				$prompt .= "- Structure as question and answer format\n";
				$prompt .= "- Group related questions\n";
				$prompt .= "- Provide clear, concise answers\n";
				$prompt .= "- Cover common concerns\n";
				break;

			case 'landing':
				$prompt .= "- Strong headline and value proposition\n";
				$prompt .= "- Benefits-focused content\n";
				$prompt .= "- Clear call-to-action\n";
				$prompt .= "- Social proof or testimonials section\n";
				$prompt .= "- Concise, persuasive copy\n";
				break;

			default:
				$prompt .= "- Create clear, informative content\n";
				$prompt .= "- Structure logically for easy reading\n";
				$prompt .= "- Include relevant sections\n";
				break;
		}

		$prompt .= "\n**Content Structure Guidelines:**\n\n";

		switch ( $template ) {
			case 'block-editor':
				$prompt .= "- Format content for Gutenberg/Block Editor\n";
				$prompt .= "- Use HTML5 elements: <h2>, <h3>, <p>, <ul>, <ol>\n";
				$prompt .= "- Include <!-- wp:paragraph --> and <!-- wp:heading --> block comments where appropriate\n";
				$prompt .= "- Structure with clear sections and headings\n";
				break;

			case 'classic-editor':
				$prompt .= "- Format content for Classic Editor (TinyMCE)\n";
				$prompt .= "- Use simple HTML: <h2>, <h3>, <p>, <ul>, <ol>, <strong>\n";
				$prompt .= "- Keep formatting straightforward and clean\n";
				$prompt .= "- Structure with clear headings\n";
				break;

			case 'elementor':
				$prompt .= "- Format content for Elementor page builder\n";
				$prompt .= "- Use minimal HTML - primarily plain text with clear line breaks\n";
				$prompt .= "- Separate sections clearly (marked with **)\n";
				$prompt .= "- Note: Content will be added to Elementor sections\n";
				$prompt .= "- Keep formatting simple for easy widget insertion\n";
				break;
		}

		$prompt .= "\n**Tone Guidelines:**\n";
		switch ( $tone ) {
			case 'professional':
				$prompt .= "- Maintain a professional, business-appropriate tone\n";
				$prompt .= "- Use clear, confident language\n";
				$prompt .= "- Focus on credibility\n";
				break;

			case 'friendly':
				$prompt .= "- Be warm and approachable\n";
				$prompt .= "- Use welcoming language\n";
				$prompt .= "- Make visitors feel comfortable\n";
				break;

			case 'formal':
				$prompt .= "- Use formal, professional language\n";
				$prompt .= "- Maintain business etiquette\n";
				$prompt .= "- Be respectful and authoritative\n";
				break;

			case 'welcoming':
				$prompt .= "- Create an inviting atmosphere\n";
				$prompt .= "- Use inclusive language\n";
				$prompt .= "- Make visitors feel valued\n";
				break;

			case 'corporate':
				$prompt .= "- Use corporate, business language\n";
				$prompt .= "- Focus on professionalism\n";
				$prompt .= "- Emphasize trust and reliability\n";
				break;
		}

		$prompt .= "\n**IMPORTANT**: Return the information in the following JSON format:\n\n";
		$prompt .= "```json\n";
		$prompt .= "{\n";
		$prompt .= '  "title": "Page Title Here",';
		$prompt .= "\n";
		$prompt .= '  "content": "Full page content with proper HTML formatting based on template...",';
		$prompt .= "\n";
		if ( $include_seo ) {
			$prompt .= '  "meta_description": "SEO meta description...",';
			$prompt .= "\n";
			$prompt .= '  "keywords": ["keyword1", "keyword2", "keyword3"],';
			$prompt .= "\n";
		}
		$prompt .= '  "page_type": "' . $page_type . '",';
		$prompt .= "\n";
		$prompt .= '  "template": "' . $template . '",';
		$prompt .= "\n";
		$prompt .= '  "sources": ["URL1", "URL2"]';
		$prompt .= "\n";
		$prompt .= "}\n";
		$prompt .= "```\n\n";

		$prompt .= 'Research if needed to provide accurate, up-to-date information. ';
		$prompt .= "Include source URLs in the 'sources' array if research was used. ";
		$prompt .= "Ensure content is clear, professional, and appropriate for a static WordPress page.\n";

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
		// Get a suitable AI model for content creation.
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
				'content' => 'You are an expert web content writer specializing in WordPress pages. You create high-quality, professional page content that is clear, informative, and appropriate for static pages. Always respond with valid JSON matching the requested format.',
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
				'temperature' => 0.6, // Slightly lower temperature for more focused, professional content.
				'max_tokens'  => 3000,
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
		// Prefer OpenAI or Gemini for content creation.
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
	 * Parse the AI research results into page data format.
	 *
	 * @param array  $research_result AI research results.
	 * @param string $topic           Original topic.
	 * @param string $page_type       Page type.
	 * @param string $template        Template format.
	 * @return array|WP_Error Parsed page data or error.
	 */
	protected function parse_research_results( $research_result, $topic, $page_type, $template ) {
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
			$data['title'] = $topic;
		}

		if ( empty( $data['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_content',
				__( 'No content was generated.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build page data structure compatible with create_post tool.
		$page_data = array(
			'success'           => true,
			'topic'             => $topic,
			'title'             => sanitize_text_field( $data['title'] ),
			'content'           => wp_kses_post( $data['content'] ),
			'post_type'         => 'page',
			'status'            => 'draft',
			'page_type'         => $page_type,
			'template'          => $template,
			'sources'           => isset( $data['sources'] ) && is_array( $data['sources'] ) ? array_map( 'esc_url_raw', $data['sources'] ) : array(),
			'researched_at'     => current_time( 'mysql' ),
			'research_model'    => $research_result['model'],
			'research_provider' => $research_result['provider'],
		);

		// Add SEO metadata if present.
		if ( isset( $data['meta_description'] ) ) {
			$page_data['meta_description'] = sanitize_text_field( $data['meta_description'] );
		}

		if ( isset( $data['keywords'] ) && is_array( $data['keywords'] ) ) {
			$page_data['keywords'] = array_map( 'sanitize_text_field', $data['keywords'] );
		}

		return $page_data;
	}

	/**
	 * Build user-friendly research report message for chat display.
	 *
	 * @param array  $page_data      Parsed page data.
	 * @param array  $search_results Search results with sources.
	 * @param int    $word_count     Target word count.
	 * @param string $page_type      Page type.
	 * @return string Formatted research report message.
	 */
	protected function build_page_report_message( $page_data, $search_results, $word_count, $page_type ) {
		$report = "## WordPress Page Research Complete\n\n";

		// Page title.
		if ( ! empty( $page_data['title'] ) ) {
			$report .= "**Title:** " . esc_html( $page_data['title'] ) . "\n\n";
		}

		// Page type/purpose.
		if ( ! empty( $page_type ) ) {
			$type_labels = array(
				'about'             => 'About Us',
				'contact'           => 'Contact',
				'services'          => 'Services',
				'privacy-policy'    => 'Privacy Policy',
				'terms-conditions'  => 'Terms & Conditions',
				'faq'               => 'FAQ',
				'landing'           => 'Landing Page',
				'custom'            => 'Custom Page',
			);
			$type_label  = isset( $type_labels[ $page_type ] ) ? $type_labels[ $page_type ] : ucwords( str_replace( '-', ' ', $page_type ) );
			$report     .= "**Page Type:** " . esc_html( $type_label ) . "\n";
		}

		// Target word count.
		if ( ! empty( $word_count ) ) {
			$report .= "**Target Word Count:** " . absint( $word_count ) . " words\n";
		}

		// Template format.
		if ( ! empty( $page_data['template'] ) ) {
			$template_name = ucwords( str_replace( '-', ' ', $page_data['template'] ) );
			$report       .= "**Template:** " . esc_html( $template_name ) . "\n\n";
		}

		// Content sections/structure.
		if ( ! empty( $page_data['content'] ) ) {
			$report .= "### Content Structure\n";
			// Extract headings from content for structure outline.
			$content = $page_data['content'];
			preg_match_all( '/<h[2-4][^>]*>(.*?)<\/h[2-4]>/i', $content, $matches );
			if ( ! empty( $matches[1] ) ) {
				foreach ( $matches[1] as $heading ) {
					$report .= "- " . wp_strip_all_tags( $heading ) . "\n";
				}
			} else {
				// If no headings found, provide a brief excerpt.
				$plain_content = wp_strip_all_tags( $content );
				$excerpt       = substr( $plain_content, 0, 200 );
				if ( strlen( $plain_content ) > 200 ) {
					$excerpt .= '...';
				}
				$report .= $excerpt . "\n";
			}
			$report .= "\n";
		}

		// SEO metadata.
		$has_seo = false;
		if ( ! empty( $page_data['keywords'] ) && is_array( $page_data['keywords'] ) ) {
			if ( ! $has_seo ) {
				$report  .= "### SEO Metadata\n";
				$has_seo  = true;
			}
			$report .= "**Keywords:** " . implode( ', ', array_map( 'esc_html', $page_data['keywords'] ) ) . "\n";
		}

		if ( ! empty( $page_data['meta_description'] ) ) {
			if ( ! $has_seo ) {
				$report  .= "### SEO Metadata\n";
				$has_seo  = true;
			}
			$report .= "**Meta Description:** " . esc_html( $page_data['meta_description'] ) . "\n";
		}

		if ( $has_seo ) {
			$report .= "\n";
		}

		// Call-to-action elements (if detected in content).
		if ( ! empty( $page_data['content'] ) ) {
			$content_lower = strtolower( $page_data['content'] );
			$cta_keywords  = array( 'contact us', 'get started', 'learn more', 'sign up', 'subscribe', 'buy now', 'get in touch', 'request', 'schedule', 'download' );
			$found_ctas    = array();
			foreach ( $cta_keywords as $keyword ) {
				if ( strpos( $content_lower, $keyword ) !== false ) {
					$found_ctas[] = ucfirst( $keyword );
				}
			}
			if ( ! empty( $found_ctas ) ) {
				$report .= "**Call-to-Action Elements:** " . implode( ', ', array_unique( $found_ctas ) ) . "\n\n";
			}
		}

		// Word count estimate (actual content length).
		if ( ! empty( $page_data['content'] ) ) {
			$plain_content = wp_strip_all_tags( $page_data['content'] );
			$actual_count  = str_word_count( $plain_content );
			$report       .= "**Actual Word Count:** " . absint( $actual_count ) . " words\n";
		}

		// Sources count.
		if ( ! empty( $search_results['sources'] ) && is_array( $search_results['sources'] ) ) {
			$source_count = count( $search_results['sources'] );
			$report      .= "**Research Sources:** " . absint( $source_count ) . " source(s)\n";
		}

		// Research metadata.
		if ( ! empty( $page_data['research_provider'] ) && ! empty( $page_data['research_model'] ) ) {
			$report .= "**AI Model:** " . esc_html( $page_data['research_provider'] . ' / ' . $page_data['research_model'] ) . "\n";
		}

		$report .= "\n---\n\n";
		$report .= "*Research completed successfully. Use the `create_page` tool to publish this content to your WordPress site.*";

		return $report;
	}
}
