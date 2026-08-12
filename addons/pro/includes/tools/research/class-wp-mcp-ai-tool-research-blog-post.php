<?php
/**
 * Blog Post Research & Creation Tool with Rich Media Support.
 *
 * Performs comprehensive deep research on a blog post topic, then generates
 * publish-ready content with embedded images, charts, infographics, and
 * structured blocks. Combines the depth of the deep_research tool with
 * the content-creation workflow of research_post, adding first-class
 * support for visual media elements.
 *
 * Supported editor formats:
 * - block-editor: Gutenberg blocks (wp:image, wp:heading, wp:html, etc.)
 * - classic-editor: Clean semantic HTML for TinyMCE
 * - elementor: Section-oriented content for Elementor widgets
 * - custom: Framework-agnostic HTML5 for headless / REST consumers
 *
 * @package WP_MCP_AI_Pro
 * @since   3.7.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-tool-research-template-analysis.php';

// Load the Content_Media trait from the base plugin.
if ( ! trait_exists( 'WP_MCP_AI_Tool_Content_Media' ) ) {
	$content_media_path = defined( 'WP_MCP_AI_PATH' )
		? WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-content-media.php'
		: '';
	if ( $content_media_path && file_exists( $content_media_path ) ) {
		require_once $content_media_path;
	}
}

/**
 * Research Blog Post Tool.
 *
 * Performs multi-step web research, synthesises findings with AI, and
 * produces a publish-ready blog post complete with:
 * - Inline images with alt text and captions
 * - Data-driven Chart.js charts / infographics
 * - Proper WordPress block markup (or Elementor-ready sections)
 * - SEO metadata, schema markup, and accessibility compliance
 *
 * @since 3.7.0
 */
class WP_MCP_AI_Tool_Research_Blog_Post implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Research_Template_Analysis;
	use WP_MCP_AI_Tool_Content_Media;

	/**
	 * Maximum number of search queries to perform.
	 *
	 * @var int
	 */
	const MAX_SEARCH_QUERIES = 5;

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
	const MAX_DISPLAYED_SOURCES = 8;

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
	const QUERIES_BASIC = 2;

	/**
	 * Number of queries for standard depth research.
	 *
	 * @var int
	 */
	const QUERIES_STANDARD = 3;

	/**
	 * Number of queries for comprehensive depth research.
	 *
	 * @var int
	 */
	const QUERIES_COMPREHENSIVE = 5;

	/**
	 * Maximum number of inline images the AI should suggest.
	 *
	 * @var int
	 */
	const MAX_SUGGESTED_IMAGES = 5;

	/**
	 * Maximum number of charts the AI should produce.
	 *
	 * @var int
	 */
	const MAX_SUGGESTED_CHARTS = 3;

	/**
	 * Check if this tool is available.
	 *
	 * @since 3.7.0
	 *
	 * @return bool True when the AI CPT Management feature is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_ai_cpt_management'] );
	}

	/**
	 * Get the reason this tool is unavailable.
	 *
	 * @since 3.7.0
	 *
	 * @return string Human-readable reason.
	 */
	public static function get_unavailable_reason() {
		return __( 'Research Blog Post tool requires the AI CPT Management feature to be enabled in Settings → NV oOS.', 'mcp-ai-wpoos-pro' );
	}

	// ------------------------------------------------------------------
	// Interface: WP_MCP_AI_Tool_Interface.
	// ------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'research_blog_post';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Research Blog Post', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Long description kept readable with concatenation.
		return __( 'Performs comprehensive deep research on a blog post topic, then generates a publish-ready post with rich media — inline images (with captions & alt text), data-driven Chart.js charts, and infographic blocks. Supports Block Editor (Gutenberg), Classic Editor, Elementor, and custom formats. Includes SEO metadata, JSON-LD schema, accessibility (WCAG 2.1 AA), and E-E-A-T compliance. Use the returned content_images and content_charts arrays with create_post to publish immediately.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'topic'                     => array(
					'type'        => 'string',
					'description' => __( 'The blog post topic or title to research and write about.', 'mcp-ai-wpoos-pro' ),
				),
				'depth'                     => array(
					'type'        => 'string',
					'description' => __( 'Research depth level. Higher depth performs more web searches and produces richer content.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'basic', 'standard', 'comprehensive' ),
					'default'     => 'standard',
				),
				'focus_areas'               => array(
					'type'        => 'array',
					'description' => __( 'Specific aspects to focus on (e.g., "statistics", "case studies", "how-to", "infographics").', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'word_count'                => array(
					'type'        => 'integer',
					'description' => __( 'Target word count for the content.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 300,
					'maximum'     => 8000,
					'default'     => 1500,
				),
				'template'                  => array(
					'type'        => 'string',
					'description' => __( 'Editor/template format for content output.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'block-editor', 'classic-editor', 'elementor', 'custom' ),
					'default'     => 'block-editor',
				),
				'custom_format_description' => array(
					'type'        => 'string',
					'description' => __( 'Description of the custom format when template is "custom".', 'mcp-ai-wpoos-pro' ),
				),
				'template_data'             => array(
					'type'        => 'string',
					'description' => __( 'Reference template structure as JSON. Accepts Elementor, Block Editor, or custom JSON layouts. Auto-detected. Max 10 000 chars.', 'mcp-ai-wpoos-pro' ),
				),
				'output_format'             => array(
					'type'        => 'string',
					'description' => __( 'Output format: json (default), pdf, or docx.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'json', 'pdf', 'docx' ),
					'default'     => 'json',
				),
				'include_seo'               => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include SEO metadata (meta description, keywords, schema markup).', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'tone'                      => array(
					'type'        => 'string',
					'description' => __( 'Tone of voice for the content.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'professional', 'casual', 'friendly', 'authoritative', 'conversational' ),
					'default'     => 'professional',
				),
				'media_strategy'            => array(
					'type'        => 'string',
					'description' => __( 'How to handle images and visual media in the generated post.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'full', 'charts-only', 'images-only', 'minimal', 'none' ),
					'default'     => 'full',
				),
				'image_style'               => array(
					'type'        => 'string',
					'description' => __( 'Preferred style for image suggestions (e.g., "photography", "illustration", "stock", "infographic").', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'photography', 'illustration', 'stock', 'infographic', 'mixed' ),
					'default'     => 'mixed',
				),
				'chart_types'               => array(
					'type'        => 'array',
					'description' => __( 'Preferred chart types to include when relevant data is found.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'bar', 'line', 'pie', 'doughnut', 'radar', 'polarArea' ),
					),
					'default'     => array( 'bar', 'line', 'pie' ),
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
		);
	}

	// ------------------------------------------------------------------
	// Execution.
	// ------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @since 3.7.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error  Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Permission check.
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to research blog posts.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate required arguments.
		if ( empty( $arguments['topic'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_topic',
				__( 'Topic is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --- Sanitise & default inputs -------------------------------------------
		$topic          = sanitize_text_field( $arguments['topic'] );
		$depth          = $this->validate_enum( $arguments, 'depth', array( 'basic', 'standard', 'comprehensive' ), 'standard' );
		$focus_areas    = isset( $arguments['focus_areas'] ) && is_array( $arguments['focus_areas'] )
			? array_map( 'sanitize_text_field', $arguments['focus_areas'] )
			: array();
		$word_count     = isset( $arguments['word_count'] ) ? absint( $arguments['word_count'] ) : 1500;
		$word_count     = max( 300, min( 8000, $word_count ) );
		$template       = $this->validate_enum( $arguments, 'template', array( 'block-editor', 'classic-editor', 'elementor', 'custom' ), 'block-editor' );
		$custom_fmt     = isset( $arguments['custom_format_description'] ) ? sanitize_text_field( $arguments['custom_format_description'] ) : '';
		$template_data  = isset( $arguments['template_data'] ) ? $arguments['template_data'] : '';
		$output_format  = $this->validate_enum( $arguments, 'output_format', array( 'json', 'pdf', 'docx' ), 'json' );
		$include_seo    = isset( $arguments['include_seo'] ) ? (bool) $arguments['include_seo'] : true;
		$tone           = $this->validate_enum( $arguments, 'tone', array( 'professional', 'casual', 'friendly', 'authoritative', 'conversational' ), 'professional' );
		$media_strategy = $this->validate_enum( $arguments, 'media_strategy', array( 'full', 'charts-only', 'images-only', 'minimal', 'none' ), 'full' );
		$image_style    = $this->validate_enum( $arguments, 'image_style', array( 'photography', 'illustration', 'stock', 'infographic', 'mixed' ), 'mixed' );
		$chart_types    = isset( $arguments['chart_types'] ) && is_array( $arguments['chart_types'] )
			? array_values( array_intersect( $arguments['chart_types'], array( 'bar', 'line', 'pie', 'doughnut', 'radar', 'polarArea' ) ) )
			: array( 'bar', 'line', 'pie' );

		// Template analysis.
		$template_analysis = array();
		if ( ! empty( $template_data ) ) {
			if ( ! is_string( $template_data ) ) {
				$template_data = wp_json_encode( $template_data );
			}
			$template_data = substr( $template_data, 0, self::MAX_TEMPLATE_DATA_LENGTH );
			$decoded       = json_decode( $template_data, true );
			if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
				$template_data = '';
			} else {
				$template_analysis = $this->analyze_template_data( $decoded );
			}
		}

		// Cache check.
		$cache_key = 'blog_research_' . md5(
			wp_json_encode(
				array(
					$topic,
					$depth,
					$focus_areas,
					$word_count,
					$template,
					$custom_fmt,
					md5( $template_data ),
					$tone,
					$media_strategy,
					$image_style,
					$chart_types,
				)
			)
		);
		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai_blog_research' );
		if ( false !== $cached && is_array( $cached ) ) {
			$cached['_from_cache'] = true;
			return $cached;
		}

		// Log start.
		WP_MCP_AI_Logger::log_event(
			'blog_post_research_started',
			'Starting blog post research with media',
			array(
				'topic'          => $topic,
				'depth'          => $depth,
				'word_count'     => $word_count,
				'template'       => $template,
				'media_strategy' => $media_strategy,
				'image_style'    => $image_style,
				'user_id'        => $user_id,
			)
		);

		// --- Step 1: Web search --------------------------------------------------
		$search_results = $this->gather_information( $topic, $depth, $focus_areas, $media_strategy, $context );

		if ( is_wp_error( $search_results ) ) {
			WP_MCP_AI_Logger::log_error(
				'Blog post research web search failed: ' . $search_results->get_error_message(),
				array( 'topic' => $topic )
			);
			$search_results = array(
				'results' => array(),
				'sources' => array(),
				'queries' => array( $topic ),
			);
		}

		// --- Step 2: Build prompt -----------------------------------------------
		$prompt = $this->build_research_prompt(
			$topic,
			$depth,
			$focus_areas,
			$search_results,
			$word_count,
			$template,
			$custom_fmt,
			$template_data,
			$template_analysis,
			$include_seo,
			$tone,
			$media_strategy,
			$image_style,
			$chart_types
		);

		// --- Step 3: AI synthesis ------------------------------------------------
		$research_result = $this->perform_ai_research( $prompt, $context );

		if ( is_wp_error( $research_result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Blog post research AI failed: ' . $research_result->get_error_message(),
				array( 'topic' => $topic )
			);
			return $research_result;
		}

		// --- Step 4: Parse results -----------------------------------------------
		$post_data = $this->parse_research_results( $research_result, $topic, $template, $custom_fmt, $media_strategy );

		if ( ! is_wp_error( $post_data ) && ! empty( $template_data ) ) {
			$post_data['has_template_data'] = true;
			if ( ! empty( $template_analysis['detected_type'] ) ) {
				$post_data['template_type_detected'] = $template_analysis['detected_type'];
			}
		}

		if ( is_wp_error( $post_data ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to parse blog post research results: ' . $post_data->get_error_message(),
				array( 'topic' => $topic )
			);
			return $post_data;
		}

		// --- Step 5: Embed media into content if provided -----------------------
		if ( 'none' !== $media_strategy ) {
			$embed_args = array();
			if ( ! empty( $post_data['content_images'] ) ) {
				$embed_args['content_images'] = $post_data['content_images'];
			}
			if ( ! empty( $post_data['content_charts'] ) ) {
				$embed_args['content_charts'] = $post_data['content_charts'];
			}
			if ( ! empty( $embed_args ) ) {
				$post_data['content'] = $this->embed_content_media(
					$post_data['content'],
					$embed_args
				);
			}
		}

		// Build chat-friendly report.
		$post_data['report'] = $this->build_blog_report_message( $post_data, $search_results, $word_count );

		// Cache for 24 hours.
		wp_cache_set( $cache_key, $post_data, 'wp_mcp_ai_blog_research', DAY_IN_SECONDS );

		// Log success.
		WP_MCP_AI_Logger::log_event(
			'blog_post_research_completed',
			'Blog post research with media completed',
			array(
				'topic'        => $topic,
				'depth'        => $depth,
				'title'        => isset( $post_data['title'] ) ? $post_data['title'] : '',
				'images_count' => isset( $post_data['content_images'] ) ? count( $post_data['content_images'] ) : 0,
				'charts_count' => isset( $post_data['content_charts'] ) ? count( $post_data['content_charts'] ) : 0,
			)
		);

		// Optional document export.
		if ( 'json' !== $output_format && ! empty( $post_data['content'] ) ) {
			$export = $this->export_research_document( $post_data, $output_format );
			if ( ! is_wp_error( $export ) ) {
				$post_data['document'] = $export;
			}
		}

		return $post_data;
	}

	// ------------------------------------------------------------------
	// Step 1: Gather information (web search).
	// ------------------------------------------------------------------

	/**
	 * Gather information through web searches.
	 *
	 * @param string $topic          Blog post topic.
	 * @param string $depth          Research depth.
	 * @param array  $focus_areas    Focus areas.
	 * @param string $media_strategy Media strategy.
	 * @param array  $context        Execution context.
	 * @return array|WP_Error Search results or error.
	 */
	protected function gather_information( $topic, $depth, $focus_areas, $media_strategy, $context ) {
		$registry        = WP_MCP_AI_Tool_Registry::get_instance();
		$web_search_tool = $registry->get_tool( 'web_search' );

		if ( ! $web_search_tool ) {
			WP_MCP_AI_Logger::log_event(
				'blog_research_no_web_search',
				'Web search tool not available, using AI-only mode',
				array( 'topic' => $topic )
			);
			return array(
				'results' => array(),
				'sources' => array(),
				'queries' => array( $topic ),
			);
		}

		$queries     = $this->generate_search_queries( $topic, $depth, $focus_areas, $media_strategy );
		$all_results = array();
		$all_sources = array();

		foreach ( $queries as $query ) {
			$result = $web_search_tool->execute(
				array(
					'query'       => $query,
					'max_results' => self::MAX_RESULTS_PER_QUERY,
				),
				$context
			);

			if ( is_wp_error( $result ) ) {
				WP_MCP_AI_Logger::log_error(
					'Blog research web search failed: ' . $result->get_error_message(),
					array(
						'query' => $query,
						'topic' => $topic,
					)
				);
				continue;
			}

			if ( ! empty( $result['results'] ) && is_array( $result['results'] ) ) {
				foreach ( $result['results'] as $r ) {
					$all_results[] = $r;
					if ( ! empty( $r['url'] ) ) {
						$all_sources[] = array(
							'url'     => $r['url'],
							'title'   => isset( $r['title'] ) ? $r['title'] : '',
							'snippet' => isset( $r['snippet'] ) ? $r['snippet'] : '',
						);
					}
				}
			}
		}

		$all_sources = $this->deduplicate_sources( $all_sources );

		WP_MCP_AI_Logger::log_event(
			'blog_research_search_complete',
			'Web search completed for blog post research',
			array(
				'topic'         => $topic,
				'queries_count' => count( $queries ),
				'results_count' => count( $all_results ),
				'sources_count' => count( $all_sources ),
			)
		);

		return array(
			'results' => $all_results,
			'sources' => $all_sources,
			'queries' => $queries,
		);
	}

	/**
	 * Generate search queries based on depth, focus areas, and media needs.
	 *
	 * @param string $topic          Blog post topic.
	 * @param string $depth          Research depth.
	 * @param array  $focus_areas    Focus areas.
	 * @param string $media_strategy Media strategy.
	 * @return array Search query strings.
	 */
	protected function generate_search_queries( $topic, $depth, $focus_areas, $media_strategy ) {
		$queries = array( $topic );

		switch ( $depth ) {
			case 'basic':
				$num_queries = self::QUERIES_BASIC;
				break;
			case 'comprehensive':
				$num_queries = self::QUERIES_COMPREHENSIVE;
				break;
			default:
				$num_queries = self::QUERIES_STANDARD;
				break;
		}

		// Focus area queries.
		foreach ( $focus_areas as $area ) {
			if ( count( $queries ) >= $num_queries ) {
				break;
			}
			$queries[] = $topic . ' ' . $area;
		}

		// Depth-specific content queries.
		if ( count( $queries ) < $num_queries && 'comprehensive' === $depth ) {
			$queries[] = $topic . ' examples case studies best practices';
		}
		if ( count( $queries ) < $num_queries ) {
			$queries[] = $topic . ' statistics data trends ' . gmdate( 'Y' );
		}

		// Media-specific query for data / infographic context.
		if ( count( $queries ) < $num_queries && in_array( $media_strategy, array( 'full', 'charts-only' ), true ) ) {
			$queries[] = $topic . ' statistics infographic data chart';
		}

		return array_slice( $queries, 0, min( $num_queries, self::MAX_SEARCH_QUERIES ) );
	}

	/**
	 * Deduplicate sources by URL.
	 *
	 * @param array $sources Sources array.
	 * @return array Deduplicated sources.
	 */
	protected function deduplicate_sources( $sources ) {
		$unique = array();
		$seen   = array();

		foreach ( $sources as $source ) {
			if ( empty( $source['url'] ) || in_array( $source['url'], $seen, true ) ) {
				continue;
			}
			$unique[] = $source;
			$seen[]   = $source['url'];
		}

		return $unique;
	}

	// ------------------------------------------------------------------
	// Step 2: Build AI prompt.
	// ------------------------------------------------------------------

	/**
	 * Build the comprehensive research prompt.
	 *
	 * @param string $topic             Topic to research.
	 * @param string $depth             Research depth.
	 * @param array  $focus_areas       Focus areas.
	 * @param array  $search_results    Search results.
	 * @param int    $word_count        Target word count.
	 * @param string $template          Template format.
	 * @param string $custom_fmt        Custom format description.
	 * @param string $template_data     Reference template JSON.
	 * @param array  $template_analysis Analyzed template data.
	 * @param bool   $include_seo       Include SEO metadata.
	 * @param string $tone              Tone of voice.
	 * @param string $media_strategy    Media strategy.
	 * @param string $image_style       Image style preference.
	 * @param array  $chart_types       Preferred chart types.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $topic, $depth, $focus_areas, $search_results, $word_count, $template, $custom_fmt, $template_data, $template_analysis, $include_seo, $tone, $media_strategy, $image_style, $chart_types ) {

		$template_label = $template;
		if ( 'custom' === $template && ! empty( $custom_fmt ) ) {
			$template_label = 'custom (' . $custom_fmt . ')';
		}

		// --- Header -------------------------------------------------------
		$prompt  = "You are an expert content strategist and blog-post author.\n\n";
		$prompt .= sprintf(
			"Research and write a **comprehensive, publish-ready blog post** about:\n\n**Topic:** %s\n**Target Word Count:** %d words\n**Template:** %s\n**Tone:** %s\n\n",
			$topic,
			$word_count,
			$template_label,
			$tone
		);

		// --- Source context ------------------------------------------------
		if ( ! empty( $search_results['sources'] ) ) {
			$prompt      .= "**Research Sources Found:**\n";
			$source_count = min( self::MAX_DISPLAYED_SOURCES, count( $search_results['sources'] ) );
			for ( $i = 0; $i < $source_count; $i++ ) {
				$s       = $search_results['sources'][ $i ];
				$prompt .= sprintf( "[%d] %s — %s\n", $i + 1, $s['title'], $s['snippet'] );
			}
			$prompt .= "\n";
		}

		// --- Depth --------------------------------------------------------
		switch ( $depth ) {
			case 'comprehensive':
				$prompt .= "**Research Depth: COMPREHENSIVE** — Include extensive details, multiple real-world examples, case studies, expert quotes, and thorough data analysis.\n\n";
				break;
			case 'basic':
				$prompt .= "**Research Depth: BASIC** — Focus on essential information and key points with concise explanations.\n\n";
				break;
			default:
				$prompt .= "**Research Depth: STANDARD** — Provide comprehensive coverage with good detail, examples, and supporting data.\n\n";
				break;
		}

		// --- Focus areas --------------------------------------------------
		if ( ! empty( $focus_areas ) ) {
			$prompt .= '**Focus Areas:** ' . implode( ', ', $focus_areas ) . "\n\n";
		}

		// --- Tone guidelines ----------------------------------------------
		$prompt .= $this->get_tone_guidelines( $tone );

		// --- Template-specific formatting ---------------------------------
		$prompt .= $this->get_template_instructions( $template, $custom_fmt );

		// --- Reference template data --------------------------------------
		if ( ! empty( $template_data ) ) {
			$prompt .= $this->get_template_data_instructions( $template_analysis, $template_data );
		}

		// --- Media / visual instructions ----------------------------------
		$prompt .= $this->get_media_instructions( $media_strategy, $image_style, $chart_types, $template );

		// --- Quality & standards ------------------------------------------
		$prompt .= "\n**Quality & Industry Standards (2025 / 2026):**\n";
		$prompt .= "- **Readability:** Target Flesch Reading Ease ≥ 60 (grade 8-10). Use short sentences, active voice, clear language.\n";
		$prompt .= "- **E-E-A-T:** Demonstrate Experience, Expertise, Authoritativeness, Trust — cite credible sources, include specific data.\n";
		$prompt .= "- **Accessibility (WCAG 2.1 AA):** Proper heading hierarchy (h2→h3→h4), descriptive alt text for every image, sufficient color contrast in charts.\n";
		$prompt .= "- **Performance:** Avoid inline styles; keep DOM depth shallow; use lazy-loading attributes where appropriate.\n";
		$prompt .= "- **Content Freshness:** Reference the most recent data available; mention the year of publication for time-sensitive statistics.\n";

		// --- SEO / schema -------------------------------------------------
		if ( $include_seo ) {
			$prompt .= "\n**SEO & Structured Data:**\n";
			$prompt .= "- Generate Article schema markup (JSON-LD) with headline, author placeholder, datePublished, and description.\n";
			$prompt .= "- Include 3-5 focus keywords. Ensure the primary keyword appears in the title, first paragraph, and at least one subheading.\n";
			$prompt .= "- Meta description: 150-160 characters, include primary keyword.\n";
		}

		// --- Output JSON format -------------------------------------------
		$prompt .= "\n**IMPORTANT — Return the result as valid JSON in this exact structure:**\n\n```json\n";
		$prompt .= "{\n";
		$prompt .= "  \"title\": \"Engaging, SEO-friendly title (60-70 chars)\",\n";
		$prompt .= "  \"content\": \"Full HTML content with proper blocks/formatting...\",\n";
		$prompt .= "  \"excerpt\": \"Brief summary (150-200 chars)\",\n";

		if ( $include_seo ) {
			$prompt .= "  \"meta_description\": \"SEO meta description (150-160 chars)\",\n";
			$prompt .= "  \"keywords\": [\"keyword1\", \"keyword2\", \"keyword3\"],\n";
			$prompt .= "  \"schema_markup\": \"<script type=\\\"application/ld+json\\\">...</script>\",\n";
		}

		$prompt .= "  \"categories\": [\"Category1\", \"Category2\"],\n";
		$prompt .= "  \"tags\": [\"tag1\", \"tag2\", \"tag3\"],\n";
		$prompt .= '  "template": "' . $template . "\",\n";
		$prompt .= "  \"sources\": [\"https://example.com/source1\", \"https://example.com/source2\"],\n";

		if ( 'none' !== $media_strategy ) {
			$prompt .= "  \"content_images\": [\n";
			$prompt .= "    {\n";
			$prompt .= "      \"source\": \"https://example.com/image.jpg\",\n";
			$prompt .= "      \"alt\": \"Descriptive alt text for accessibility\",\n";
			$prompt .= "      \"caption\": \"Figure 1: Descriptive caption\",\n";
			$prompt .= "      \"position\": \"start\"\n";
			$prompt .= "    }\n";
			$prompt .= "  ],\n";

			if ( in_array( $media_strategy, array( 'full', 'charts-only' ), true ) ) {
				$prompt .= "  \"content_charts\": [\n";
				$prompt .= "    {\n";
				$prompt .= "      \"type\": \"bar\",\n";
				$prompt .= "      \"title\": \"Chart Title\",\n";
				$prompt .= "      \"data\": { \"labels\": [\"A\",\"B\"], \"datasets\": [{ \"label\": \"Series 1\", \"data\": [10,20] }] },\n";
				$prompt .= "      \"position\": \"middle\"\n";
				$prompt .= "    }\n";
				$prompt .= "  ],\n";
			}

			$prompt .= "  \"featured_image_suggestion\": {\n";
			$prompt .= "    \"description\": \"Description of the ideal featured image\",\n";
			$prompt .= "    \"alt\": \"Featured image alt text\",\n";
			$prompt .= "    \"search_query\": \"query to find a suitable stock image\"\n";
			$prompt .= "  },\n";
		}

		$prompt .= "  \"table_of_contents\": [\"Section 1\", \"Section 2\"]\n";
		$prompt .= "}\n```\n\n";

		$prompt .= 'Use the research sources to include accurate, up-to-date information. ';
		$prompt .= 'Ensure content is original, well-structured, and provides genuine value to readers. ';
		$prompt .= "All images must have descriptive alt text. Charts must use real or realistic data from the research.\n";

		return $prompt;
	}

	/**
	 * Get tone guideline text.
	 *
	 * @param string $tone Tone name.
	 * @return string Prompt section.
	 */
	protected function get_tone_guidelines( $tone ) {
		$guidelines = "\n**Tone Guidelines:**\n";
		switch ( $tone ) {
			case 'professional':
				$guidelines .= "- Maintain a professional, authoritative tone. Use industry-standard terminology. Focus on facts and expertise.\n";
				break;
			case 'casual':
				$guidelines .= "- Use a relaxed, friendly tone. Write as if speaking to a friend. Use everyday language.\n";
				break;
			case 'friendly':
				$guidelines .= "- Be warm and approachable. Use conversational language. Include relatable examples.\n";
				break;
			case 'authoritative':
				$guidelines .= "- Establish expertise and credibility. Use data and research to support points. Speak with confidence.\n";
				break;
			case 'conversational':
				$guidelines .= "- Write as if having a conversation. Use questions and direct address. Be engaging and personable.\n";
				break;
		}
		return $guidelines;
	}

	/**
	 * Get template-specific formatting instructions.
	 *
	 * @param string $template   Template name.
	 * @param string $custom_fmt Custom format description.
	 * @return string Prompt section.
	 */
	protected function get_template_instructions( $template, $custom_fmt ) {
		$inst = "\n**Content Structure & Formatting:**\n";

		switch ( $template ) {
			case 'block-editor':
				$inst .= "- Format content for WordPress Gutenberg / Block Editor.\n";
				$inst .= "- Use semantic HTML5: <h2>, <h3>, <p>, <ul>, <ol>, <blockquote>, <figure>.\n";
				$inst .= "- Include <!-- wp:paragraph -->, <!-- wp:heading -->, <!-- wp:image -->, <!-- wp:html --> block comments.\n";
				$inst .= "- Use <!-- wp:columns --> for side-by-side layouts when comparing data.\n";
				$inst .= "- Use <!-- wp:table --> for structured data presentation.\n";
				$inst .= "- Ensure accessibility: proper heading hierarchy, alt text placeholders.\n";
				break;

			case 'classic-editor':
				$inst .= "- Format content for Classic Editor (TinyMCE).\n";
				$inst .= "- Use clean HTML: <h2>, <h3>, <p>, <ul>, <ol>, <strong>, <em>, <figure>, <img>.\n";
				$inst .= "- Avoid inline styles; rely on theme CSS.\n";
				$inst .= "- Use <table> for data with proper <thead>/<tbody>.\n";
				break;

			case 'elementor':
				$inst .= "- Format content for Elementor page builder.\n";
				$inst .= "- Use minimal HTML with clear section headings (marked with **).\n";
				$inst .= "- Separate content into discrete sections for easy mapping to Elementor widgets.\n";
				$inst .= "- Note image/chart placement for Image widgets and HTML widgets.\n";
				break;

			case 'custom':
				$inst .= "- Format content for a custom rendering context.\n";
				$inst .= "- Use semantic HTML5 with <section>, <article>, <header>, <main>.\n";
				$inst .= "- Mobile-first, responsive-ready, framework-agnostic.\n";
				if ( ! empty( $custom_fmt ) ) {
					$inst .= sprintf( "- **Target platform:** %s\n", $custom_fmt );
				}
				break;
		}

		return $inst;
	}

	/**
	 * Get instructions for reference template data.
	 *
	 * @param array  $analysis      Template analysis result.
	 * @param string $template_data Raw template JSON.
	 * @return string Prompt section.
	 */
	protected function get_template_data_instructions( $analysis, $template_data ) {
		$inst = "\n**Reference Template Structure:**\n";

		if ( ! empty( $analysis['detected_type'] ) ) {
			$inst .= sprintf( "Detected template type: **%s**\n", $analysis['detected_type'] );
		}
		if ( ! empty( $analysis['summary'] ) ) {
			$inst .= "Template structure summary:\n" . $analysis['summary'] . "\n\n";
		}

		if ( ! empty( $analysis['detected_type'] ) ) {
			switch ( $analysis['detected_type'] ) {
				case 'elementor':
					$inst .= "Match content to Elementor section/widget layout. Map headings to Heading widgets, body text to Text Editor widgets, images to Image widgets.\n";
					break;
				case 'block-editor':
					$inst .= "Preserve <!-- wp:* --> block comment structure. Map content to block types in the pattern.\n";
					break;
				default:
					$inst .= "Use as a structural guide — match section layout and content hierarchy.\n";
					break;
			}
		}

		$inst .= "\n```json\n" . $template_data . "\n```\n";
		$inst .= "Adapt generated content to fit sections and structure defined in this template.\n";

		return $inst;
	}

	/**
	 * Get media-specific prompt instructions.
	 *
	 * @param string $media_strategy Media strategy.
	 * @param string $image_style    Image style.
	 * @param array  $chart_types    Chart types.
	 * @param string $template       Template format.
	 * @return string Prompt section.
	 */
	protected function get_media_instructions( $media_strategy, $image_style, $chart_types, $template ) {
		if ( 'none' === $media_strategy ) {
			return "\n**Media:** Do not include images or charts.\n";
		}

		$inst = "\n**Visual Media & Rich Content:**\n";

		// Image instructions.
		if ( in_array( $media_strategy, array( 'full', 'images-only', 'minimal' ), true ) ) {
			$max_images = 'minimal' === $media_strategy ? 2 : self::MAX_SUGGESTED_IMAGES;
			$inst      .= sprintf(
				"- Include up to %d image suggestions in the `content_images` array. Each must have:\n",
				$max_images
			);
			$inst      .= "  - `source`: A descriptive search query URL (e.g., `https://unsplash.com/s/photos/topic`) or placeholder URL.\n";
			$inst      .= "  - `alt`: Descriptive alt text for accessibility (WCAG 2.1 AA).\n";
			$inst      .= "  - `caption`: Informative figure caption.\n";
			$inst      .= "  - `position`: \"start\", \"middle\", or \"end\".\n";

			switch ( $image_style ) {
				case 'photography':
					$inst .= "- Prefer photographic / real-world imagery suggestions.\n";
					break;
				case 'illustration':
					$inst .= "- Prefer illustrated / graphic style imagery.\n";
					break;
				case 'infographic':
					$inst .= "- Prefer infographic-style visual summaries.\n";
					break;
				case 'stock':
					$inst .= "- Prefer professional stock photography.\n";
					break;
				default:
					$inst .= "- Mix photography, illustrations, and infographic-style visuals as appropriate.\n";
					break;
			}

			$inst .= "- Include a `featured_image_suggestion` object with a description and search query for the ideal hero image.\n";
		}

		// Chart instructions.
		if ( in_array( $media_strategy, array( 'full', 'charts-only' ), true ) ) {
			$inst .= sprintf(
				"- Include up to %d data-driven charts in the `content_charts` array using **Chart.js** format.\n",
				self::MAX_SUGGESTED_CHARTS
			);
			$inst .= '- Preferred chart types: ' . implode( ', ', $chart_types ) . ".\n";
			$inst .= "- Each chart must have: `type`, `title`, `data` (with `labels` and `datasets`), `position`.\n";
			$inst .= "- Use real statistics from the research when available; otherwise use realistic representative data.\n";
			$inst .= "- Ensure chart colors have sufficient contrast for accessibility.\n";
		}

		// Block-specific media markup guidance.
		if ( 'block-editor' === $template ) {
			$inst .= "- In the HTML content, reference images using <!-- wp:image --> blocks with placeholder src attributes.\n";
			$inst .= "- Reference charts using <!-- wp:html --> blocks with a placeholder comment like [CHART: title].\n";
			$inst .= "- Use <!-- wp:table --> for tabular data that doesn't warrant a chart.\n";
		} elseif ( 'elementor' === $template ) {
			$inst .= "- Note image placements as [IMAGE: description] markers for Elementor Image widgets.\n";
			$inst .= "- Note chart placements as [CHART: title] markers for Elementor HTML widgets.\n";
		}

		return $inst;
	}

	// ------------------------------------------------------------------
	// Step 3: AI research.
	// ------------------------------------------------------------------

	/**
	 * Perform AI research using the configured provider.
	 *
	 * @param string $prompt  Research prompt.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Research results or error.
	 */
	protected function perform_ai_research( $prompt, $context ) {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ? WP_MCP_AI_Admin_Settings_Base::get_settings() : get_option( 'wp_mcp_ai_settings', array() );
		$provider = $this->get_research_provider( $settings );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$model = $this->get_research_model( $provider, $settings );
		if ( is_wp_error( $model ) ) {
			return $model;
		}

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are an expert content strategist and blog-post author specialising in rich-media WordPress content. '
					. 'You follow 2025/2026 industry standards: E-E-A-T principles, WCAG 2.1 AA accessibility, semantic HTML5, '
					. 'SEO best practices including JSON-LD schema markup, and visual storytelling with charts and images. '
					. 'Always respond with valid JSON matching the requested format. '
					. 'All chart data must use Chart.js compatible format. '
					. 'All images must include descriptive alt text.',
			),
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		$client = $this->get_ai_client( $provider, $settings );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$result = $client->create_chat_completion(
			$messages,
			array(
				'model'       => $model,
				'temperature' => 0.7,
				'max_tokens'  => 8000,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

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
		// $settings kept for backward compatibility with subclasses.
		unset( $settings );

		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'openai' ) ) {
			return 'openai';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'gemini' ) ) {
			return 'gemini';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'anthropic' ) ) {
			return 'anthropic';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'deepseek' ) ) {
			return 'deepseek';
		}

		// Providers requiring multi-field or non-standard credential checks.
		$settings_raw = class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ? WP_MCP_AI_Admin_Settings_Base::get_settings() : get_option( 'wp_mcp_ai_settings', array() );
		if ( ! empty( $settings_raw['cloudflare_api_token'] ) && ! empty( $settings_raw['cloudflare_account_id'] ) && class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
			return 'cloudflare';
		}
		if ( ! empty( $settings_raw['huggingface_api_key'] ) && ! empty( $settings_raw['huggingface_endpoint_url'] ) && class_exists( 'WP_MCP_AI_Huggingface_Client' ) ) {
			return 'huggingface';
		}
		if ( ! empty( $settings_raw['ollama_endpoint_url'] ) && class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
			return 'ollama';
		}
		if ( ! empty( $settings_raw['lm_studio_endpoint_url'] ) && class_exists( 'WP_MCP_AI_LM_Studio_Client' ) ) {
			return 'lm_studio';
		}

		// Standard API-key providers.
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'openrouter' ) ) {
			return 'openrouter';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'nvidia' ) ) {
			return 'nvidia';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'digitalocean' ) ) {
			return 'digitalocean';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'kimi' ) ) {
			return 'kimi';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'baseten' ) ) {
			return 'baseten';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'zai' ) ) {
			return 'zai';
		}

		return new WP_Error(
			'wp_mcp_ai_no_provider',
			__( 'No AI provider configured. Please configure an AI provider (OpenAI, Gemini, Anthropic, DeepSeek, Cloudflare, HuggingFace, Ollama, OpenRouter, NVIDIA, LM Studio, DigitalOcean, Kimi, Baseten, or Z.AI) in plugin settings.', 'mcp-ai-wpoos-pro' )
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
				return ! empty( $settings['openai_default_model'] ) ? $settings['openai_default_model'] : 'gpt-4.1';
			case 'gemini':
				return ! empty( $settings['gemini_default_model'] ) ? $settings['gemini_default_model'] : 'gemini-2.5-flash';
			case 'anthropic':
				return 'claude-sonnet-4-5-20250929';
			case 'deepseek':
				return ! empty( $settings['deepseek_model'] ) ? $settings['deepseek_model'] : 'deepseek-chat';
			case 'cloudflare':
				return ! empty( $settings['cloudflare_model'] ) ? $settings['cloudflare_model'] : '@cf/meta/llama-4-scout-17b-16e-instruct';
			case 'huggingface':
				return ! empty( $settings['huggingface_model'] ) ? $settings['huggingface_model'] : 'meta-llama/Llama-3.3-70B-Instruct';
			case 'ollama':
				return ! empty( $settings['ollama_model'] ) ? $settings['ollama_model'] : 'llama3.3';
			case 'openrouter':
				return ! empty( $settings['openrouter_model'] ) ? $settings['openrouter_model'] : 'openrouter/auto';
			case 'nvidia':
				return ! empty( $settings['nvidia_model'] ) ? $settings['nvidia_model'] : 'meta/llama-3.1-8b-instruct';
			case 'lm_studio':
				return ! empty( $settings['lm_studio_model'] ) ? $settings['lm_studio_model'] : '';
			case 'digitalocean':
				return ! empty( $settings['digitalocean_model'] ) ? $settings['digitalocean_model'] : 'llama3.3-70b-instruct';
			case 'kimi':
				return ! empty( $settings['kimi_model'] ) ? $settings['kimi_model'] : 'kimi-k2.7-code';
			case 'baseten':
				return ! empty( $settings['baseten_model'] ) ? $settings['baseten_model'] : 'deepseek-ai/DeepSeek-V3';
			case 'zai':
				return ! empty( $settings['zai_model'] ) ? $settings['zai_model'] : 'glm-4';
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
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'OpenAI client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_OpenAI_Client();

			case 'gemini':
				if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'Gemini client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_Gemini_Client();

			case 'anthropic':
				if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'Anthropic client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_Anthropic_Client();

			case 'deepseek':
				if ( ! class_exists( 'WP_MCP_AI_DeepSeek_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'DeepSeek client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_DeepSeek_Client();

			case 'cloudflare':
				if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'Cloudflare client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_Cloudflare_Client();

			case 'huggingface':
				if ( ! class_exists( 'WP_MCP_AI_Huggingface_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'HuggingFace client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_Huggingface_Client();

			case 'ollama':
				if ( ! class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'Ollama client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_Ollama_Client();

			case 'openrouter':
				if ( ! class_exists( 'WP_MCP_AI_OpenRouter_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'OpenRouter client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_OpenRouter_Client();

			case 'nvidia':
				if ( ! class_exists( 'WP_MCP_AI_Nvidia_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'NVIDIA client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_Nvidia_Client();

			case 'lm_studio':
				if ( ! class_exists( 'WP_MCP_AI_LM_Studio_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'LM Studio client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_LM_Studio_Client();

			case 'digitalocean':
				if ( ! class_exists( 'WP_MCP_AI_DigitalOcean_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'DigitalOcean client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_DigitalOcean_Client();

			case 'kimi':
				if ( ! class_exists( 'WP_MCP_AI_Kimi_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'Kimi client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_Kimi_Client();

			case 'baseten':
				if ( ! class_exists( 'WP_MCP_AI_Baseten_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'Baseten client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_Baseten_Client();

			case 'zai':
				if ( ! class_exists( 'WP_MCP_AI_ZAI_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'Z.AI client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_ZAI_Client();

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

	// ------------------------------------------------------------------
	// Step 4: Parse results.
	// ------------------------------------------------------------------

	/**
	 * Parse the AI research results into post data.
	 *
	 * @param array  $research_result AI response.
	 * @param string $topic           Original topic.
	 * @param string $template        Template format.
	 * @param string $custom_fmt      Custom format description.
	 * @param string $media_strategy  Media strategy.
	 * @return array|WP_Error Parsed post data or error.
	 */
	protected function parse_research_results( $research_result, $topic, $template, $custom_fmt, $media_strategy ) {
		$content = $research_result['content'];

		// Extract JSON from markdown code blocks.
		if ( preg_match( '/```json\s*(.*?)\s*```/s', $content, $matches ) ) {
			$json = $matches[1];
		} elseif ( preg_match( '/```\s*(.*?)\s*```/s', $content, $matches ) ) {
			$json = $matches[1];
		} else {
			$json = $content;
		}

		$data = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'wp_mcp_ai_parse_error',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Failed to parse AI response as JSON: %s', 'mcp-ai-wpoos-pro' ),
					json_last_error_msg()
				)
			);
		}

		if ( empty( $data['title'] ) ) {
			$data['title'] = $topic;
		}
		if ( empty( $data['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_content',
				__( 'No content was generated.', 'mcp-ai-wpoos-pro' )
			);
		}

		$post_data = array(
			'success'                   => true,
			'topic'                     => $topic,
			'title'                     => sanitize_text_field( $data['title'] ),
			'content'                   => wp_kses_post( $data['content'] ),
			'excerpt'                   => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : '',
			'post_type'                 => 'post',
			'status'                    => 'draft',
			'template'                  => $template,
			'custom_format_description' => $custom_fmt,
			'sources'                   => isset( $data['sources'] ) && is_array( $data['sources'] ) ? array_map( 'esc_url_raw', $data['sources'] ) : array(),
			'researched_at'             => current_time( 'mysql' ),
			'research_model'            => $research_result['model'],
			'research_provider'         => $research_result['provider'],
		);

		// SEO metadata.
		if ( isset( $data['meta_description'] ) ) {
			$post_data['meta_description'] = sanitize_text_field( $data['meta_description'] );
		}
		if ( isset( $data['keywords'] ) && is_array( $data['keywords'] ) ) {
			$post_data['keywords'] = array_map( 'sanitize_text_field', $data['keywords'] );
		}
		if ( isset( $data['schema_markup'] ) && is_string( $data['schema_markup'] ) ) {
			$post_data['schema_markup'] = $data['schema_markup'];
		}

		// Taxonomy.
		if ( isset( $data['categories'] ) && is_array( $data['categories'] ) ) {
			$post_data['categories'] = array_map( 'sanitize_text_field', $data['categories'] );
		}
		if ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) {
			$post_data['tags'] = array_map( 'sanitize_text_field', $data['tags'] );
		}

		// Table of contents.
		if ( isset( $data['table_of_contents'] ) && is_array( $data['table_of_contents'] ) ) {
			$post_data['table_of_contents'] = array_map( 'sanitize_text_field', $data['table_of_contents'] );
		}

		// --- Media: images ---
		if ( 'none' !== $media_strategy && isset( $data['content_images'] ) && is_array( $data['content_images'] ) ) {
			$post_data['content_images'] = $this->sanitize_content_images( $data['content_images'] );
		}

		// --- Media: charts ---
		if ( in_array( $media_strategy, array( 'full', 'charts-only' ), true )
			&& isset( $data['content_charts'] ) && is_array( $data['content_charts'] ) ) {
			$post_data['content_charts'] = $this->sanitize_content_charts( $data['content_charts'] );
		}

		// --- Featured image suggestion ---
		if ( 'none' !== $media_strategy && isset( $data['featured_image_suggestion'] ) && is_array( $data['featured_image_suggestion'] ) ) {
			$post_data['featured_image_suggestion'] = array(
				'description'  => isset( $data['featured_image_suggestion']['description'] ) ? sanitize_text_field( $data['featured_image_suggestion']['description'] ) : '',
				'alt'          => isset( $data['featured_image_suggestion']['alt'] ) ? sanitize_text_field( $data['featured_image_suggestion']['alt'] ) : '',
				'search_query' => isset( $data['featured_image_suggestion']['search_query'] ) ? sanitize_text_field( $data['featured_image_suggestion']['search_query'] ) : '',
			);
		}

		return $post_data;
	}

	/**
	 * Sanitize content images array from AI response.
	 *
	 * @param array $images Raw image data.
	 * @return array Sanitized images (max MAX_SUGGESTED_IMAGES).
	 */
	protected function sanitize_content_images( $images ) {
		$sanitized = array();
		$count     = 0;

		foreach ( $images as $img ) {
			if ( $count >= self::MAX_SUGGESTED_IMAGES ) {
				break;
			}
			if ( empty( $img['source'] ) && empty( $img['alt'] ) ) {
				continue;
			}

			$entry = array();
			if ( isset( $img['source'] ) ) {
				$entry['source'] = is_numeric( $img['source'] ) ? absint( $img['source'] ) : esc_url_raw( $img['source'] );
			}
			if ( isset( $img['alt'] ) ) {
				$entry['alt'] = sanitize_text_field( $img['alt'] );
			}
			if ( isset( $img['caption'] ) ) {
				$entry['caption'] = sanitize_text_field( $img['caption'] );
			}
			$entry['position'] = isset( $img['position'] ) && in_array( $img['position'], array( 'start', 'middle', 'end' ), true )
				? $img['position']
				: 'middle';

			$sanitized[] = $entry;
			++$count;
		}

		return $sanitized;
	}

	/**
	 * Sanitize content charts array from AI response.
	 *
	 * @param array $charts Raw chart data.
	 * @return array Sanitized charts (max MAX_SUGGESTED_CHARTS).
	 */
	protected function sanitize_content_charts( $charts ) {
		$valid_types = array( 'bar', 'line', 'pie', 'doughnut', 'radar', 'polarArea' );
		$sanitized   = array();
		$count       = 0;

		foreach ( $charts as $chart ) {
			if ( $count >= self::MAX_SUGGESTED_CHARTS ) {
				break;
			}
			if ( empty( $chart['type'] ) || empty( $chart['data'] ) ) {
				continue;
			}
			if ( ! in_array( $chart['type'], $valid_types, true ) ) {
				continue;
			}

			$entry = array(
				'type' => sanitize_key( $chart['type'] ),
				'data' => $chart['data'], // Chart.js data structure.
			);
			if ( isset( $chart['title'] ) ) {
				$entry['title'] = sanitize_text_field( $chart['title'] );
			}
			$entry['position'] = isset( $chart['position'] ) && in_array( $chart['position'], array( 'start', 'middle', 'end' ), true )
				? $chart['position']
				: 'middle';

			$sanitized[] = $entry;
			++$count;
		}

		return $sanitized;
	}

	// ------------------------------------------------------------------
	// Report builder.
	// ------------------------------------------------------------------

	/**
	 * Build a user-friendly research report for chat display.
	 *
	 * @param array $post_data      Parsed post data.
	 * @param array $search_results Search results with sources.
	 * @param int   $word_count     Target word count.
	 * @return string Formatted markdown report.
	 */
	protected function build_blog_report_message( $post_data, $search_results, $word_count ) {
		$report = "## 📝 Blog Post Research Complete\n\n";

		if ( ! empty( $post_data['title'] ) ) {
			$report .= '**Title:** ' . esc_html( $post_data['title'] ) . "\n\n";
		}

		$report .= '**Target Word Count:** ' . absint( $word_count ) . " words\n";

		if ( ! empty( $post_data['template'] ) ) {
			$tpl = ucwords( str_replace( '-', ' ', $post_data['template'] ) );
			if ( 'custom' === $post_data['template'] && ! empty( $post_data['custom_format_description'] ) ) {
				$tpl .= ' (' . $post_data['custom_format_description'] . ')';
			}
			$report .= '**Template:** ' . esc_html( $tpl ) . "\n";
		}

		if ( ! empty( $post_data['has_template_data'] ) ) {
			$detected = ! empty( $post_data['template_type_detected'] ) ? $post_data['template_type_detected'] : '';
			$label    = '✓ Provided';
			if ( $detected ) {
				$label .= ' (auto-detected: ' . ucwords( str_replace( '-', ' ', $detected ) ) . ')';
			}
			$report .= '**Reference Template:** ' . esc_html( $label ) . "\n";
		}

		$report .= "\n";

		// Media summary.
		$images_count = isset( $post_data['content_images'] ) ? count( $post_data['content_images'] ) : 0;
		$charts_count = isset( $post_data['content_charts'] ) ? count( $post_data['content_charts'] ) : 0;
		if ( $images_count || $charts_count ) {
			$report .= "### 🖼️ Visual Media\n";
			if ( $images_count ) {
				$report .= sprintf( "- **Images:** %d inline image(s) with alt text & captions\n", $images_count );
			}
			if ( $charts_count ) {
				$report .= sprintf( "- **Charts:** %d data-driven Chart.js chart(s)\n", $charts_count );
			}
			if ( ! empty( $post_data['featured_image_suggestion'] ) ) {
				$report .= '- **Featured Image:** Suggestion included — ' . esc_html( $post_data['featured_image_suggestion']['description'] ) . "\n";
			}
			$report .= "\n";
		}

		// Schema markup.
		if ( ! empty( $post_data['schema_markup'] ) ) {
			$report .= "**Schema Markup:** ✓ JSON-LD structured data included\n";
		}

		// Content outline.
		if ( ! empty( $post_data['table_of_contents'] ) && is_array( $post_data['table_of_contents'] ) ) {
			$report .= "\n### 📋 Table of Contents\n";
			foreach ( $post_data['table_of_contents'] as $section ) {
				$report .= '- ' . esc_html( $section ) . "\n";
			}
			$report .= "\n";
		} elseif ( ! empty( $post_data['content'] ) ) {
			$report .= "\n### 📋 Content Outline\n";
			preg_match_all( '/<h[2-3][^>]*>(.*?)<\/h[2-3]>/i', $post_data['content'], $matches );
			if ( ! empty( $matches[1] ) ) {
				foreach ( $matches[1] as $heading ) {
					$report .= '- ' . wp_strip_all_tags( $heading ) . "\n";
				}
			}
			$report .= "\n";
		}

		// SEO keywords.
		if ( ! empty( $post_data['keywords'] ) && is_array( $post_data['keywords'] ) ) {
			$report .= "### 🔑 SEO Keywords\n";
			$report .= implode( ', ', array_map( 'esc_html', $post_data['keywords'] ) ) . "\n\n";
		}

		if ( ! empty( $post_data['meta_description'] ) ) {
			$report .= '**Meta Description:** ' . esc_html( $post_data['meta_description'] ) . "\n\n";
		}

		// Taxonomy.
		if ( ! empty( $post_data['categories'] ) && is_array( $post_data['categories'] ) ) {
			$report .= '**Categories:** ' . implode( ', ', array_map( 'esc_html', $post_data['categories'] ) ) . "\n";
		}
		if ( ! empty( $post_data['tags'] ) && is_array( $post_data['tags'] ) ) {
			$report .= '**Tags:** ' . implode( ', ', array_map( 'esc_html', $post_data['tags'] ) ) . "\n";
		}
		$report .= "\n";

		// Actual word count.
		if ( ! empty( $post_data['content'] ) ) {
			$plain   = wp_strip_all_tags( $post_data['content'] );
			$report .= '**Actual Word Count:** ' . absint( str_word_count( $plain ) ) . " words\n";
		}

		// Sources.
		if ( ! empty( $search_results['sources'] ) && is_array( $search_results['sources'] ) ) {
			$report .= '**Research Sources:** ' . absint( count( $search_results['sources'] ) ) . " source(s)\n";
		}

		// Model info.
		if ( ! empty( $post_data['research_provider'] ) && ! empty( $post_data['research_model'] ) ) {
			$report .= '**AI Model:** ' . esc_html( $post_data['research_provider'] . ' / ' . $post_data['research_model'] ) . "\n";
		}

		$report .= "\n---\n\n";
		$report .= '*Research completed successfully. Use the `create_post` tool to publish this content to your WordPress site. ';
		$report .= 'The `content_images` and `content_charts` arrays can be passed directly to `create_post` for rich media embedding.*';

		return $report;
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Validate an enum parameter.
	 *
	 * @param array  $arguments    Arguments array.
	 * @param string $key          Argument key.
	 * @param array  $allowed      Allowed values.
	 * @param string $fallback_val Default / fallback value.
	 * @return string Validated value.
	 */
	protected function validate_enum( $arguments, $key, $allowed, $fallback_val ) {
		if ( ! isset( $arguments[ $key ] ) ) {
			return $fallback_val;
		}
		$value = sanitize_key( $arguments[ $key ] );
		return in_array( $value, $allowed, true ) ? $value : $fallback_val;
	}
}
