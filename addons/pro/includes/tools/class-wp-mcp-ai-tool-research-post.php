<?php
/**
 * Tool for researching post topics using AI and web search.
 *
 * Provides comprehensive research about a blog post topic including
 * title, content, SEO metadata, and format ready for creation.
 *
 * Supports four template formats:
 * - block-editor: Gutenberg/Block Editor with wp:* block comments and semantic HTML5
 * - classic-editor: Classic Editor (TinyMCE) with clean, simple HTML
 * - elementor: Elementor page builder with section-oriented plain text
 * - custom: Custom format (e.g., Telegram Mini App, headless CMS, REST API consumer)
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-tool-research-template-analysis.php';

/**
 * Research Post Tool
 *
 * Uses AI and web search to research comprehensive information about
 * blog post topics and generate ready-to-publish content.
 */
class WP_MCP_AI_Tool_Research_Post implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Research_Template_Analysis;

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
	 * Maximum length for template_data JSON input (characters).
	 *
	 * @var int
	 */
	const MAX_TEMPLATE_DATA_LENGTH = 10000;

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
		return 'research_post';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Research Post', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Research comprehensive information about a blog post topic using multi-stage web search and AI analysis. Supports configurable research depth (basic/standard/comprehensive) and focus areas for targeted research. Returns title, content, excerpt, SEO metadata, and formatting instructions based on the selected template (Classic Editor, Block Editor, Elementor, or Custom formats like Telegram Mini App). Accepts reference template files (Elementor JSON, Block Editor patterns, or custom JSON layouts) to guide content structure — auto-detects template type and extracts structural summary for smarter AI prompts. Supports output_format option to export research as PDF or Word document.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'The topic to research (e.g., "AI technology trends", "Sustainable living tips", "Starting a podcast")', 'mcp-ai-wpoos-pro' ),
				),
				'depth'       => array(
					'type'        => 'string',
					'description' => __( 'Research depth level.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'basic', 'standard', 'comprehensive' ),
					'default'     => 'standard',
				),
				'focus_areas' => array(
					'type'        => 'array',
					'description' => __( 'Optional specific aspects to focus on (e.g., "SEO", "examples", "statistics", "case studies").', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'word_count'  => array(
					'type'        => 'integer',
					'description' => __( 'Target word count for the content', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 100,
					'maximum'     => 5000,
					'default'     => 1000,
				),
				'template'                  => array(
					'type'        => 'string',
					'description' => __( 'Template format for content. Use "custom" for non-standard formats like Telegram Mini App, headless CMS, or REST API consumers.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'block-editor', 'classic-editor', 'elementor', 'custom' ),
					'default'     => 'block-editor',
				),
				'custom_format_description' => array(
					'type'        => 'string',
					'description' => __( 'Description of the custom format when template is "custom" (e.g., "Telegram Mini App", "Headless CMS JSON", "React component"). Ignored unless template is "custom".', 'mcp-ai-wpoos-pro' ),
				),
				'template_data'            => array(
					'type'        => 'string',
					'description' => __( 'Reference template structure as a JSON string. Accepts Elementor template JSON, Block Editor (Gutenberg) block pattern JSON, or any structured JSON layout. The AI will use this as a structural guide when generating content. Template type is auto-detected from JSON structure. Maximum 10 000 characters.', 'mcp-ai-wpoos-pro' ),
				),
				'output_format'            => array(
					'type'        => 'string',
					'description' => __( 'Output format for the research results. "json" returns structured data (default). "pdf" generates a downloadable PDF document. "docx" generates a Word document.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'json', 'pdf', 'docx' ),
					'default'     => 'json',
				),
				'include_seo'              => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include SEO metadata (meta description, keywords)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'tone'                     => array(
					'type'        => 'string',
					'description' => __( 'Tone of voice for the content', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'professional', 'casual', 'friendly', 'authoritative', 'conversational' ),
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

		// Check permissions - requires edit_posts capability.
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to research posts.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate required arguments.
		if ( empty( $arguments['topic'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_topic',
				__( 'Topic is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$topic                    = sanitize_text_field( $arguments['topic'] );
		$depth                    = isset( $arguments['depth'] ) ? sanitize_text_field( $arguments['depth'] ) : 'standard';
		$focus_areas              = isset( $arguments['focus_areas'] ) && is_array( $arguments['focus_areas'] )
			? array_map( 'sanitize_text_field', $arguments['focus_areas'] )
			: array();
		$word_count               = isset( $arguments['word_count'] ) ? absint( $arguments['word_count'] ) : 1000;
		$template                 = isset( $arguments['template'] ) ? sanitize_key( $arguments['template'] ) : 'block-editor';
		$custom_format_description = isset( $arguments['custom_format_description'] ) ? sanitize_text_field( $arguments['custom_format_description'] ) : '';
		$template_data            = isset( $arguments['template_data'] ) ? $arguments['template_data'] : '';
		$output_format            = isset( $arguments['output_format'] ) ? sanitize_key( $arguments['output_format'] ) : 'json';
		$include_seo              = isset( $arguments['include_seo'] ) ? (bool) $arguments['include_seo'] : true;
		$tone                     = isset( $arguments['tone'] ) ? sanitize_key( $arguments['tone'] ) : 'professional';

		// Validate depth parameter.
		if ( ! in_array( $depth, array( 'basic', 'standard', 'comprehensive' ), true ) ) {
			$depth = 'standard';
		}

		// Validate word count.
		if ( $word_count < 100 || $word_count > 5000 ) {
			$word_count = 1000;
		}

		// Validate template.
		if ( ! in_array( $template, array( 'block-editor', 'classic-editor', 'elementor', 'custom' ), true ) ) {
			$template = 'block-editor';
		}

		// Validate and sanitize template_data (JSON string, max 10 000 chars).
		$template_analysis = array();
		if ( ! empty( $template_data ) ) {
			if ( ! is_string( $template_data ) ) {
				$template_data = wp_json_encode( $template_data );
			}
			// Enforce maximum length to stay within token budgets.
			$template_data = substr( $template_data, 0, self::MAX_TEMPLATE_DATA_LENGTH );
			// Validate it is parseable JSON.
			$decoded = json_decode( $template_data, true );
			if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
				$template_data = '';
			} else {
				// Auto-detect template type and extract structural summary.
				$template_analysis = $this->analyze_template_data( $decoded );
			}
		}

		// Validate output format.
		if ( ! in_array( $output_format, array( 'json', 'pdf', 'docx' ), true ) ) {
			$output_format = 'json';
		}

		// Validate tone.
		if ( ! in_array( $tone, array( 'professional', 'casual', 'friendly', 'authoritative', 'conversational' ), true ) ) {
			$tone = 'professional';
		}

		// Check cache first.
		$cache_key = 'post_research_' . md5( $topic . '_' . $depth . '_' . implode( '_', $focus_areas ) . '_' . $word_count . '_' . $template . '_' . $custom_format_description . '_' . md5( $template_data ) . '_' . $tone );
		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai_post_research' );

		if ( false !== $cached && is_array( $cached ) ) {
			$cached['_from_cache'] = true;
			return $cached;
		}

		// Log research start.
		WP_MCP_AI_Logger::log_event(
			'post_research_started',
			'Starting post research',
			array(
				'topic'                    => $topic,
				'depth'                    => $depth,
				'focus_areas'              => $focus_areas,
				'word_count'               => $word_count,
				'template'                 => $template,
				'custom_format_description' => $custom_format_description,
				'has_template_data'        => ! empty( $template_data ),
				'template_type_detected'   => ! empty( $template_analysis['detected_type'] ) ? $template_analysis['detected_type'] : '',
				'output_format'            => $output_format,
				'user_id'                  => $user_id,
			)
		);

		// Step 1: Gather information through web searches.
		$search_results = $this->gather_post_information( $topic, $depth, $focus_areas, $context );

		if ( is_wp_error( $search_results ) ) {
			WP_MCP_AI_Logger::log_error(
				'Post research web search failed: ' . $search_results->get_error_message(),
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
		$prompt = $this->build_research_prompt( $topic, $depth, $focus_areas, $search_results, $word_count, $template, $custom_format_description, $template_data, $template_analysis, $include_seo, $tone );

		// Step 3: Use AI to research the topic and generate content.
		$research_result = $this->perform_ai_research( $prompt, $context );

		if ( is_wp_error( $research_result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Post research failed: ' . $research_result->get_error_message(),
				array(
					'topic' => $topic,
					'error' => $research_result->get_error_code(),
				)
			);
			return $research_result;
		}

		// Parse and validate the research results.
		$post_data = $this->parse_research_results( $research_result, $topic, $template, $custom_format_description );

		// Flag whether a reference template was used and include analysis metadata.
		if ( ! is_wp_error( $post_data ) && ! empty( $template_data ) ) {
			$post_data['has_template_data'] = true;
			if ( ! empty( $template_analysis['detected_type'] ) ) {
				$post_data['template_type_detected'] = $template_analysis['detected_type'];
			}
		}

		if ( is_wp_error( $post_data ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to parse post research results: ' . $post_data->get_error_message(),
				array(
					'topic' => $topic,
				)
			);
			return $post_data;
		}

		// Build user-friendly research report for chat display.
		$post_data['report'] = $this->build_post_report_message( $post_data, $search_results, $word_count );

		// Cache the results for 24 hours.
		wp_cache_set( $cache_key, $post_data, 'wp_mcp_ai_post_research', DAY_IN_SECONDS );

		// Log success.
		WP_MCP_AI_Logger::log_event(
			'post_research_completed',
			'Post research completed successfully',
			array(
				'topic'         => $topic,
				'depth'         => $depth,
				'focus_areas'   => $focus_areas,
				'sources_count' => count( $search_results['sources'] ?? array() ),
				'title'         => isset( $post_data['title'] ) ? $post_data['title'] : '',
			)
		);

		// Export to document format if requested.
		if ( 'json' !== $output_format && ! empty( $post_data['content'] ) ) {
			$export_result = $this->export_research_document( $post_data, $output_format );
			if ( ! is_wp_error( $export_result ) ) {
				$post_data['document'] = $export_result;
			}
		}

		return $post_data;
	}

	/**
	 * Gather post information through web searches.
	 *
	 * @param string $topic       Post topic.
	 * @param string $depth       Research depth.
	 * @param array  $focus_areas Focus areas.
	 * @param array  $context     Execution context.
	 * @return array|WP_Error Search results or error.
	 */
	protected function gather_post_information( $topic, $depth, $focus_areas, $context ) {
		// Check if web search tool is available.
		$registry        = WP_MCP_AI_Tool_Registry::get_instance();
		$web_search_tool = $registry->get_tool( 'web_search' );

		if ( ! $web_search_tool ) {
			// Return empty results if web search is not available.
			WP_MCP_AI_Logger::log_event(
				'post_research_no_web_search',
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
		$search_queries = $this->generate_post_search_queries( $topic, $depth, $focus_areas );

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
					'Post research web search failed: ' . $search_result->get_error_message(),
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
			'post_research_web_search_complete',
			'Web search completed for post research',
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
	 * Generate search queries for post research.
	 *
	 * @param string $topic       Post topic.
	 * @param string $depth       Research depth.
	 * @param array  $focus_areas Focus areas.
	 * @return array Search queries.
	 */
	protected function generate_post_search_queries( $topic, $depth, $focus_areas ) {
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

		// Add depth-specific queries for blog posts.
		if ( count( $queries ) < $num_queries ) {
			if ( 'comprehensive' === $depth ) {
				$queries[] = $topic . ' examples case studies';
				if ( count( $queries ) < $num_queries ) {
					$queries[] = $topic . ' statistics data trends';
				}
			} elseif ( 'standard' === $depth ) {
				$queries[] = $topic . ' tips best practices';
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
	 * @param string $topic                    Topic to research.
	 * @param string $depth                    Research depth.
	 * @param array  $focus_areas              Focus areas.
	 * @param array  $search_results           Search results from web search.
	 * @param int    $word_count               Target word count.
	 * @param string $template                 Template format.
	 * @param string $custom_format_description Description for custom template format.
	 * @param string $template_data            Reference template JSON structure.
	 * @param array  $template_analysis        Analyzed template data (detected type, summary).
	 * @param bool   $include_seo              Whether to include SEO.
	 * @param string $tone                     Tone of voice.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $topic, $depth, $focus_areas, $search_results, $word_count, $template, $custom_format_description, $template_data, $template_analysis, $include_seo, $tone ) {
		$template_label = $template;
		if ( 'custom' === $template && ! empty( $custom_format_description ) ) {
			$template_label = 'custom (' . $custom_format_description . ')';
		}

		$prompt = sprintf(
			"Research and write a comprehensive blog post about the following topic:\n\n**Topic:** %s\n**Word Count:** %d words\n**Template:** %s\n**Tone:** %s\n\n",
			$topic,
			$word_count,
			$template_label,
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
			$prompt .= "**Research Depth: COMPREHENSIVE** - Include extensive details, multiple examples, case studies, and thorough analysis.\n\n";
		} elseif ( 'basic' === $depth ) {
			$prompt .= "**Research Depth: BASIC** - Focus on essential information and key points only.\n\n";
		} else {
			$prompt .= "**Research Depth: STANDARD** - Provide comprehensive coverage with good detail and examples.\n\n";
		}

		// Add focus areas if specified.
		if ( ! empty( $focus_areas ) ) {
			$prompt .= "**Focus Areas:** " . implode( ', ', $focus_areas ) . "\n\n";
		}

		$prompt .= "Use the provided sources and web search to find current, factually correct information.\n\n";
		$prompt .= "Generate a complete blog post including:\n\n";
		$prompt .= "1. **Title**: Engaging, SEO-friendly title (60-70 characters)\n";
		$prompt .= "2. **Content**: Well-structured, informative content covering the topic comprehensively\n";
		$prompt .= "3. **Excerpt**: Brief summary (150-200 characters) for post listings\n";

		if ( $include_seo ) {
			$prompt .= "4. **Meta Description**: SEO meta description (150-160 characters)\n";
			$prompt .= "5. **Focus Keywords**: 3-5 relevant keywords for SEO\n";
		}

		$prompt .= "\n**Content Structure Guidelines:**\n\n";

		switch ( $template ) {
			case 'block-editor':
				$prompt .= "- Format content for Gutenberg/Block Editor\n";
				$prompt .= "- Use semantic HTML5 elements: <h2>, <h3>, <p>, <ul>, <ol>, <blockquote>\n";
				$prompt .= "- Include <!-- wp:paragraph --> and <!-- wp:heading --> block comments where appropriate\n";
				$prompt .= "- Structure with clear headings and subheadings\n";
				$prompt .= "- Use bullet points and numbered lists where relevant\n";
				$prompt .= "- Use reusable block patterns where content repeats\n";
				$prompt .= "- Ensure accessibility: proper heading hierarchy (h2 → h3 → h4), alt text placeholders for images\n";
				break;

			case 'classic-editor':
				$prompt .= "- Format content for Classic Editor (TinyMCE)\n";
				$prompt .= "- Use simple, clean HTML: <h2>, <h3>, <p>, <ul>, <ol>, <strong>, <em>\n";
				$prompt .= "- Avoid inline styles; rely on theme CSS for visual styling\n";
				$prompt .= "- Structure with clear headings and logical reading order\n";
				$prompt .= "- Keep markup portable and compatible with any WordPress theme\n";
				break;

			case 'elementor':
				$prompt .= "- Format content for Elementor page builder\n";
				$prompt .= "- Use minimal HTML - primarily plain text with clear line breaks\n";
				$prompt .= "- Separate sections with clear headings (marked with **) for easy mapping to Elementor sections/widgets\n";
				$prompt .= "- Note: Content will be added to Elementor sections/widgets\n";
				$prompt .= "- Keep formatting simple for easy widget insertion\n";
				$prompt .= "- Consider global widget reuse for repeated elements (headers, CTAs)\n";
				break;

			case 'custom':
				$prompt .= "- Format content for a custom rendering context\n";
				$prompt .= "- Use clean, semantic HTML5 structure with <section>, <article>, <header>, <main> elements\n";
				$prompt .= "- Ensure mobile-first, responsive-ready content structure\n";
				$prompt .= "- Keep markup minimal and framework-agnostic for maximum portability\n";
				$prompt .= "- Use structured data attributes where helpful (data-section, data-component)\n";
				$prompt .= "- Content should work well when consumed via REST API or rendered in non-WordPress contexts\n";
				if ( ! empty( $custom_format_description ) ) {
					$prompt .= sprintf( "- **Target platform:** %s\n", $custom_format_description );
					$custom_lower = strtolower( $custom_format_description );
					if ( false !== strpos( $custom_lower, 'telegram' ) ) {
						$prompt .= "- Optimize for Telegram Mini App: mobile-viewport-friendly, concise sections, touch-friendly navigation\n";
						$prompt .= "- Use Telegram theme-compatible styling (avoid fixed colors; use CSS custom properties)\n";
						$prompt .= "- Structure content in card-like sections suitable for vertical scrolling\n";
					} elseif ( false !== strpos( $custom_lower, 'headless' ) || false !== strpos( $custom_lower, 'api' ) || false !== strpos( $custom_lower, 'json' ) ) {
						$prompt .= "- Structure content as clearly delineated sections with identifiable headings\n";
						$prompt .= "- Use predictable HTML structure that can be easily parsed into structured data\n";
					} elseif ( false !== strpos( $custom_lower, 'react' ) || false !== strpos( $custom_lower, 'vue' ) || false !== strpos( $custom_lower, 'angular' ) ) {
						$prompt .= "- Use component-friendly markup with clear section boundaries\n";
						$prompt .= "- Avoid inline event handlers; keep content purely declarative\n";
					}
				}
				break;
		}

		// Add reference template data if provided.
		if ( ! empty( $template_data ) ) {
			$prompt .= "\n**Reference Template Structure:**\n";

			// Include auto-detected template type info if analysis is available.
			if ( ! empty( $template_analysis['detected_type'] ) ) {
				$prompt .= sprintf( "Detected template type: **%s**\n", $template_analysis['detected_type'] );
			}

			// Include structured summary if available (more efficient than raw JSON).
			if ( ! empty( $template_analysis['summary'] ) ) {
				$prompt .= "Template structure summary:\n" . $template_analysis['summary'] . "\n\n";
			}

			// Include template-type-specific guidance.
			if ( ! empty( $template_analysis['detected_type'] ) ) {
				switch ( $template_analysis['detected_type'] ) {
					case 'elementor':
						$prompt .= "This is an Elementor template. Match content to the Elementor section/widget layout:\n";
						$prompt .= "- Map headings to Elementor Heading widgets\n";
						$prompt .= "- Map body text to Elementor Text Editor widgets\n";
						$prompt .= "- Map images to Elementor Image widgets with placeholder alt text\n";
						$prompt .= "- Preserve the section nesting structure (container → column → widget)\n";
						break;

					case 'block-editor':
						$prompt .= "This is a Block Editor (Gutenberg) template. Match content to the block pattern:\n";
						$prompt .= "- Preserve <!-- wp:* --> block comment structure\n";
						$prompt .= "- Map content to the block types in the pattern (paragraph, heading, image, columns, etc.)\n";
						$prompt .= "- Maintain block attributes (alignment, className, etc.) from the pattern\n";
						break;

					default:
						$prompt .= "Use this as a structural guide for organizing the content — match the section layout and content hierarchy:\n";
						break;
				}
			} else {
				$prompt .= "Use this as a structural guide for organizing the content — match the section layout, widget types, and content hierarchy:\n";
			}

			$prompt .= "\n```json\n" . $template_data . "\n```\n\n";
			$prompt .= "Adapt the generated content to fit the sections and structure defined in this template.\n";
		}

		$prompt .= "\n**Tone Guidelines:**\n";
		switch ( $tone ) {
			case 'professional':
				$prompt .= "- Maintain a professional, authoritative tone\n";
				$prompt .= "- Use industry-standard terminology\n";
				$prompt .= "- Focus on facts and expertise\n";
				break;

			case 'casual':
				$prompt .= "- Use a relaxed, friendly tone\n";
				$prompt .= "- Write as if speaking to a friend\n";
				$prompt .= "- Use everyday language\n";
				break;

			case 'friendly':
				$prompt .= "- Be warm and approachable\n";
				$prompt .= "- Use conversational language\n";
				$prompt .= "- Include relatable examples\n";
				break;

			case 'authoritative':
				$prompt .= "- Establish expertise and credibility\n";
				$prompt .= "- Use data and research to support points\n";
				$prompt .= "- Speak with confidence\n";
				break;

			case 'conversational':
				$prompt .= "- Write as if having a conversation\n";
				$prompt .= "- Use questions and direct address\n";
				$prompt .= "- Be engaging and personable\n";
				break;
		}

		// Industry best practices (2025/2026 standards).
		$prompt .= "\n**Quality & Standards Guidelines:**\n";
		$prompt .= "- **Readability:** Target Flesch Reading Ease score of 60-70 (grade 8-10 level). Use short sentences, active voice, and clear language\n";
		$prompt .= "- **E-E-A-T:** Demonstrate Experience, Expertise, Authoritativeness, and Trust — cite credible sources, include specific data points, avoid vague claims\n";
		$prompt .= "- **Accessibility (WCAG 2.1 AA):** Use proper heading hierarchy (h2→h3→h4, never skip levels), include alt text placeholders for images ([alt: description]), use semantic HTML elements\n";
		$prompt .= "- **Performance:** Avoid inline styles; no unnecessary wrapper elements; keep DOM depth shallow\n";

		// Schema markup guidance for posts (always Article schema).
		if ( $include_seo ) {
			$prompt .= "\n**Schema Markup (JSON-LD):**\n";
			$prompt .= "- Generate Article schema markup (JSON-LD) with headline, author, datePublished, and description\n";
			$prompt .= "- Include the schema markup as a valid JSON-LD script in the 'schema_markup' field of the response\n";
		}

		$prompt .= "\n**IMPORTANT**: Return the information in the following JSON format:\n\n";
		$prompt .= "```json\n";
		$prompt .= "{\n";
		$prompt .= '  "title": "Engaging Post Title Here",';
		$prompt .= "\n";
		$prompt .= '  "content": "Full post content with proper HTML formatting based on template...",';
		$prompt .= "\n";
		$prompt .= '  "excerpt": "Brief summary of the post...",';
		$prompt .= "\n";
		if ( $include_seo ) {
			$prompt .= '  "meta_description": "SEO meta description...",';
			$prompt .= "\n";
			$prompt .= '  "keywords": ["keyword1", "keyword2", "keyword3"],';
			$prompt .= "\n";
			$prompt .= '  "schema_markup": "<script type=application/ld+json>...</script>",';
			$prompt .= "\n";
		}
		$prompt .= '  "categories": ["Category1", "Category2"],';
		$prompt .= "\n";
		$prompt .= '  "tags": ["tag1", "tag2", "tag3"],';
		$prompt .= "\n";
		$prompt .= '  "template": "' . $template . '",';
		$prompt .= "\n";
		$prompt .= '  "sources": ["URL1", "URL2"]';
		$prompt .= "\n";
		$prompt .= "}\n";
		$prompt .= "```\n\n";

		$prompt .= 'Use web search to find accurate, up-to-date information. ';
		$prompt .= "Include source URLs in the 'sources' array. ";
		$prompt .= 'Ensure content is original, informative, and well-researched. ';
		$prompt .= "Make the content engaging and valuable to readers.\n";

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
				'content' => 'You are an expert web content writer specializing in WordPress blog posts. You follow 2025/2026 industry standards: E-E-A-T principles (Experience, Expertise, Authoritativeness, Trust), WCAG 2.1 AA accessibility, semantic HTML5, and SEO best practices including JSON-LD schema markup. You create high-quality, well-researched blog posts with proper structure, engaging writing, and SEO optimization. Always respond with valid JSON matching the requested format.',
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
				'temperature' => 0.7, // Balanced temperature for creative yet factual content.
				'max_tokens'  => 4000,
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
	 * Parse the AI research results into post data format.
	 *
	 * @param array  $research_result          AI research results.
	 * @param string $topic                    Original topic.
	 * @param string $template                 Template format.
	 * @param string $custom_format_description User-provided custom format description.
	 * @return array|WP_Error Parsed post data or error.
	 */
	protected function parse_research_results( $research_result, $topic, $template, $custom_format_description = '' ) {
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

		// Build post data structure compatible with create_post tool.
		$post_data = array(
			'success'                   => true,
			'topic'                     => $topic,
			'title'                     => sanitize_text_field( $data['title'] ),
			'content'                   => wp_kses_post( $data['content'] ),
			'excerpt'                   => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : '',
			'post_type'                 => 'post',
			'status'                    => 'draft',
			'template'                  => $template,
			'custom_format_description' => $custom_format_description,
			'sources'                   => isset( $data['sources'] ) && is_array( $data['sources'] ) ? array_map( 'esc_url_raw', $data['sources'] ) : array(),
			'researched_at'             => current_time( 'mysql' ),
			'research_model'            => $research_result['model'],
			'research_provider'         => $research_result['provider'],
		);

		// Add SEO metadata if present.
		if ( isset( $data['meta_description'] ) ) {
			$post_data['meta_description'] = sanitize_text_field( $data['meta_description'] );
		}

		if ( isset( $data['keywords'] ) && is_array( $data['keywords'] ) ) {
			$post_data['keywords'] = array_map( 'sanitize_text_field', $data['keywords'] );
		}

		// Add schema markup if present.
		if ( isset( $data['schema_markup'] ) && is_string( $data['schema_markup'] ) ) {
			$post_data['schema_markup'] = $data['schema_markup'];
		}

		// Add categories and tags if present.
		if ( isset( $data['categories'] ) && is_array( $data['categories'] ) ) {
			$post_data['categories'] = array_map( 'sanitize_text_field', $data['categories'] );
		}

		if ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) {
			$post_data['tags'] = array_map( 'sanitize_text_field', $data['tags'] );
		}

		return $post_data;
	}

	/**
	 * Build a user-friendly post research report message.
	 *
	 * This creates a comprehensive summary of the research findings
	 * that can be displayed in the chat client.
	 *
	 * @param array $post_data      Parsed post data.
	 * @param array $search_results Search results with sources.
	 * @param int   $word_count     Target word count.
	 * @return string Formatted research report message.
	 */
	protected function build_post_report_message( $post_data, $search_results, $word_count ) {
		$report = "## Blog Post Research Complete\n\n";

		// Post title.
		if ( ! empty( $post_data['title'] ) ) {
			$report .= "**Title:** " . esc_html( $post_data['title'] ) . "\n\n";
		}

		// Target word count.
		if ( ! empty( $word_count ) ) {
			$report .= "**Target Word Count:** " . absint( $word_count ) . " words\n";
		}

		// Template format.
		if ( ! empty( $post_data['template'] ) ) {
			$template_name = ucwords( str_replace( '-', ' ', $post_data['template'] ) );
			if ( 'custom' === $post_data['template'] && ! empty( $post_data['custom_format_description'] ) ) {
				$template_name .= ' (' . $post_data['custom_format_description'] . ')';
			}
			$report       .= "**Template:** " . esc_html( $template_name ) . "\n";
		}

		// Reference template indicator.
		if ( ! empty( $post_data['has_template_data'] ) ) {
			$detected = ! empty( $post_data['template_type_detected'] ) ? $post_data['template_type_detected'] : '';
			$label    = '✓ Provided';
			if ( ! empty( $detected ) ) {
				$label .= ' (auto-detected: ' . ucwords( str_replace( '-', ' ', $detected ) ) . ')';
			}
			$report .= "**Reference Template:** " . esc_html( $label ) . "\n";
		}

		$report .= "\n";

		// Schema markup indicator.
		if ( ! empty( $post_data['schema_markup'] ) ) {
			$report .= "**Schema Markup:** ✓ JSON-LD structured data included\n";
		}

		// Content outline/structure.
		if ( ! empty( $post_data['content'] ) ) {
			$report .= "### Content Outline\n";
			// Extract headings from content for outline.
			$content = $post_data['content'];
			preg_match_all( '/<h[2-3][^>]*>(.*?)<\/h[2-3]>/i', $content, $matches );
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

		// SEO keywords.
		if ( ! empty( $post_data['keywords'] ) && is_array( $post_data['keywords'] ) ) {
			$report .= "### SEO Keywords\n";
			$report .= implode( ', ', array_map( 'esc_html', $post_data['keywords'] ) ) . "\n\n";
		}

		// Meta description.
		if ( ! empty( $post_data['meta_description'] ) ) {
			$report .= "**Meta Description:** " . esc_html( $post_data['meta_description'] ) . "\n\n";
		}

		// Target audience (inferred from categories/tags).
		if ( ! empty( $post_data['categories'] ) && is_array( $post_data['categories'] ) ) {
			$report .= "**Categories:** " . implode( ', ', array_map( 'esc_html', $post_data['categories'] ) ) . "\n";
		}

		if ( ! empty( $post_data['tags'] ) && is_array( $post_data['tags'] ) ) {
			$report .= "**Tags:** " . implode( ', ', array_map( 'esc_html', $post_data['tags'] ) ) . "\n";
		}

		$report .= "\n";

		// Word count estimate (actual content length).
		if ( ! empty( $post_data['content'] ) ) {
			$plain_content = wp_strip_all_tags( $post_data['content'] );
			$actual_count  = str_word_count( $plain_content );
			$report       .= "**Actual Word Count:** " . absint( $actual_count ) . " words\n";
		}

		// Sources count.
		if ( ! empty( $search_results['sources'] ) && is_array( $search_results['sources'] ) ) {
			$source_count = count( $search_results['sources'] );
			$report      .= "**Research Sources:** " . absint( $source_count ) . " source(s)\n";
		}

		// Research metadata.
		if ( ! empty( $post_data['research_provider'] ) && ! empty( $post_data['research_model'] ) ) {
			$report .= "**AI Model:** " . esc_html( $post_data['research_provider'] . ' / ' . $post_data['research_model'] ) . "\n";
		}

		$report .= "\n---\n\n";
		$report .= "*Research completed successfully. Use the `create_post` tool to publish this content to your WordPress site.*";

		return $report;
	}

}
