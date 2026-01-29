<?php
/**
 * Research Site Best Practices Tool
 *
 * Web search integration for discovering industry-standard site building best practices,
 * performance optimization techniques, accessibility guidelines, and modern design patterns.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Research Site Best Practices Tool
 *
 * Performs web searches to discover and compile best practices for:
 * - Page builder implementations
 * - Widget design patterns
 * - Performance optimization
 * - Accessibility standards (WCAG 2.2)
 * - SEO techniques
 * - Conversion optimization
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Research_Site_Best_Practices implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if tool is available.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'research_site_best_practices';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Research Site Best Practices', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Research industry-standard best practices for site building including page builders, widgets, performance optimization, accessibility, and modern design patterns. Uses web search to discover current standards and recommendations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'        => array(
					'type'        => 'string',
					'description' => __( 'Search query for best practices (e.g., "WordPress page builder best practices 2025")', 'mcp-ai-wpoos-pro' ),
				),
				'focus_areas'  => array(
					'type'        => 'array',
					'description' => __( 'Specific areas to focus on', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array(
							'performance',
							'accessibility',
							'seo',
							'conversion',
							'design',
							'security',
							'mobile',
							'user-experience',
						),
					),
				),
				'site_type'    => array(
					'type'        => 'string',
					'description' => __( 'Type of site to research (e.g., ecommerce, blog, portfolio, business)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ecommerce', 'blog', 'portfolio', 'business', 'landing-page', 'membership', 'directory', 'general' ),
				),
				'max_results'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of best practice items to return (default: 10)', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 50,
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Research results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_feature_disabled',
				__( 'The Site Creator Toolkit is disabled. Enable it in WP oOS → Tools & Features settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to research site best practices.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate and sanitize arguments.
		$query       = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';
		$focus_areas = isset( $arguments['focus_areas'] ) && is_array( $arguments['focus_areas'] ) ?
			array_map( 'sanitize_text_field', $arguments['focus_areas'] ) : array();
		$site_type   = isset( $arguments['site_type'] ) ? sanitize_text_field( $arguments['site_type'] ) : 'general';
		$max_results = isset( $arguments['max_results'] ) ? min( 50, max( 1, absint( $arguments['max_results'] ) ) ) : 10;

		if ( empty( $query ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_query',
				__( 'Search query is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build enhanced search query.
		$enhanced_query = $this->build_enhanced_query( $query, $focus_areas, $site_type );

		// Perform research using web search.
		$research_results = $this->perform_web_search( $enhanced_query, $max_results );

		if ( is_wp_error( $research_results ) ) {
			return $research_results;
		}

		// Process and structure the results.
		$structured_results = $this->structure_results( $research_results, $focus_areas );

		// Log the research activity.
		$this->log_research_activity( $user_id, $query, $focus_areas, count( $structured_results ) );

		return array(
			'success'         => true,
			'query'           => $query,
			'enhanced_query'  => $enhanced_query,
			'focus_areas'     => $focus_areas,
			'site_type'       => $site_type,
			'results_count'   => count( $structured_results ),
			'best_practices'  => $structured_results,
			'summary'         => $this->generate_summary( $structured_results ),
			'timestamp'       => current_time( 'mysql' ),
		);
	}

	/**
	 * Build enhanced search query.
	 *
	 * @since 1.2.0
	 *
	 * @param string $base_query  Base search query.
	 * @param array  $focus_areas Focus areas.
	 * @param string $site_type   Site type.
	 * @return string Enhanced query.
	 */
	private function build_enhanced_query( $base_query, $focus_areas, $site_type ) {
		$query_parts = array( $base_query );

		// Add site type if specified.
		if ( 'general' !== $site_type ) {
			$query_parts[] = $site_type . ' site';
		}

		// Add focus areas.
		if ( ! empty( $focus_areas ) ) {
			$query_parts[] = implode( ' ', $focus_areas );
		}

		// Add temporal relevance.
		$query_parts[] = '2025 standards';

		return implode( ' ', $query_parts );
	}

	/**
	 * Perform web search for best practices.
	 *
	 * @since 1.2.0
	 *
	 * @param string $query       Search query.
	 * @param int    $max_results Maximum results.
	 * @return array|WP_Error Search results or error.
	 */
	private function perform_web_search( $query, $max_results ) {
		// Check if web search tool is available.
		if ( ! function_exists( 'wp_mcp_ai_perform_web_search' ) ) {
			// Simulate research results with static best practices.
			return $this->get_static_best_practices( $max_results );
		}

		// Perform actual web search.
		$search_results = wp_mcp_ai_perform_web_search( $query, $max_results );

		if ( is_wp_error( $search_results ) ) {
			// Fallback to static best practices on error.
			return $this->get_static_best_practices( $max_results );
		}

		return $search_results;
	}

	/**
	 * Get static best practices as fallback.
	 *
	 * @since 1.2.0
	 *
	 * @param int $max_results Maximum results.
	 * @return array Best practices.
	 */
	private function get_static_best_practices( $max_results ) {
		$practices = array(
			array(
				'title'       => 'Optimize for Core Web Vitals',
				'category'    => 'performance',
				'description' => 'Ensure LCP < 2.5s, FID < 100ms, CLS < 0.1 for better SEO and user experience.',
				'priority'    => 'high',
				'source'      => 'Industry Standard (Google)',
			),
			array(
				'title'       => 'Implement WCAG 2.2 Accessibility',
				'category'    => 'accessibility',
				'description' => 'Ensure keyboard navigation, screen reader support, ARIA labels, and color contrast compliance.',
				'priority'    => 'high',
				'source'      => 'Industry Standard (W3C)',
			),
			array(
				'title'       => 'Mobile-First Responsive Design',
				'category'    => 'design',
				'description' => 'Design for mobile devices first, then enhance for larger screens. Over 60% of traffic is mobile.',
				'priority'    => 'high',
				'source'      => 'Industry Best Practice',
			),
			array(
				'title'       => 'Minimize JavaScript/CSS Bloat',
				'category'    => 'performance',
				'description' => 'Use lightweight builders, global styles, and reusable components to reduce code duplication.',
				'priority'    => 'high',
				'source'      => 'Performance Best Practice',
			),
			array(
				'title'       => 'Implement Lazy Loading',
				'category'    => 'performance',
				'description' => 'Lazy load images, videos, and offscreen content to improve initial page load time.',
				'priority'    => 'medium',
				'source'      => 'Performance Best Practice',
			),
			array(
				'title'       => 'Use Global Design Tokens',
				'category'    => 'design',
				'description' => 'Define site-wide colors, fonts, spacing, and breakpoints for brand consistency.',
				'priority'    => 'medium',
				'source'      => 'Design Best Practice',
			),
			array(
				'title'       => 'Block-Based Design (Gutenberg)',
				'category'    => 'design',
				'description' => 'Adopt or extend WordPress Gutenberg block editor for future-proofing and compatibility.',
				'priority'    => 'medium',
				'source'      => 'WordPress Best Practice',
			),
			array(
				'title'       => 'AI-Enhanced Workflows',
				'category'    => 'user-experience',
				'description' => 'Integrate AI for design suggestions, content drafting, image generation, and conversion optimization.',
				'priority'    => 'medium',
				'source'      => '2025 Trend',
			),
			array(
				'title'       => 'Dynamic Content Controls',
				'category'    => 'design',
				'description' => 'Enable custom fields, post loops, and dynamic widgets for advanced CMS functionality.',
				'priority'    => 'medium',
				'source'      => 'Advanced Feature',
			),
			array(
				'title'       => 'Security Best Practices',
				'category'    => 'security',
				'description' => 'Sanitize input, escape output, check capabilities, verify nonces, and keep plugins updated.',
				'priority'    => 'high',
				'source'      => 'WordPress Security Standard',
			),
		);

		return array_slice( $practices, 0, $max_results );
	}

	/**
	 * Structure research results.
	 *
	 * @since 1.2.0
	 *
	 * @param array $results     Raw search results.
	 * @param array $focus_areas Focus areas to emphasize.
	 * @return array Structured results.
	 */
	private function structure_results( $results, $focus_areas ) {
		$structured = array();

		foreach ( $results as $result ) {
			$practice = array(
				'title'       => isset( $result['title'] ) ? $result['title'] : '',
				'category'    => isset( $result['category'] ) ? $result['category'] : 'general',
				'description' => isset( $result['description'] ) ? $result['description'] : '',
				'priority'    => $this->determine_priority( $result, $focus_areas ),
				'source'      => isset( $result['source'] ) ? $result['source'] : 'Web Research',
				'url'         => isset( $result['url'] ) ? esc_url( $result['url'] ) : '',
			);

			$structured[] = $practice;
		}

		// Sort by priority.
		usort(
			$structured,
			function ( $a, $b ) {
				$priority_order = array( 'high' => 1, 'medium' => 2, 'low' => 3 );
				$a_val          = isset( $priority_order[ $a['priority'] ] ) ? $priority_order[ $a['priority'] ] : 4;
				$b_val          = isset( $priority_order[ $b['priority'] ] ) ? $priority_order[ $b['priority'] ] : 4;
				return $a_val - $b_val;
			}
		);

		return $structured;
	}

	/**
	 * Determine priority of a practice.
	 *
	 * @since 1.2.0
	 *
	 * @param array $result      Search result.
	 * @param array $focus_areas Focus areas.
	 * @return string Priority level (high, medium, low).
	 */
	private function determine_priority( $result, $focus_areas ) {
		if ( isset( $result['priority'] ) ) {
			return $result['priority'];
		}

		// If category matches focus areas, mark as high priority.
		if ( ! empty( $focus_areas ) && isset( $result['category'] ) ) {
			if ( in_array( $result['category'], $focus_areas, true ) ) {
				return 'high';
			}
		}

		return 'medium';
	}

	/**
	 * Generate summary of best practices.
	 *
	 * @since 1.2.0
	 *
	 * @param array $practices Best practices.
	 * @return string Summary text.
	 */
	private function generate_summary( $practices ) {
		$total = count( $practices );
		$high  = count(
			array_filter(
				$practices,
				function ( $p ) {
					return 'high' === $p['priority'];
				}
			)
		);

		$summary = sprintf(
			/* translators: 1: total count, 2: high priority count */
			_n(
				'Found %1$d best practice (%2$d high priority).',
				'Found %1$d best practices (%2$d high priority).',
				$total,
				'mcp-ai-wpoos-pro'
			),
			$total,
			$high
		);

		return $summary;
	}

	/**
	 * Log research activity.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $user_id      User ID.
	 * @param string $query        Search query.
	 * @param array  $focus_areas  Focus areas.
	 * @param int    $result_count Result count.
	 */
	private function log_research_activity( $user_id, $query, $focus_areas, $result_count ) {
		if ( ! function_exists( 'wp_mcp_ai_log_activity' ) ) {
			return;
		}

		$message = sprintf(
			'Site Creator: Research best practices - Query: "%s", Focus: %s, Results: %d (User: %d)',
			$query,
			empty( $focus_areas ) ? 'none' : implode( ', ', $focus_areas ),
			$result_count,
			$user_id
		);

		wp_mcp_ai_log_activity( $message, 'info' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read-only',            // Only reads/researches data.
			'external-api',         // May call external web search APIs.
			'network-dependent',    // Requires internet connection.
			'requires-capability',  // Requires manage_options capability.
			'non-deterministic',    // Results vary based on search results.
			'consumes-tokens',      // May use AI tokens for processing.
		);
	}
}
