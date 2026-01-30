<?php
/**
 * Tool for suggesting relevant internal links within WordPress content.
 *
 * Analyzes post content and suggests relevant internal links to other
 * posts/pages based on content similarity and keyword matching.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../traits/trait-wp-mcp-ai-tool-wordpress-native.php';

/**
 * Suggest Internal Links Tool
 *
 * Discovers and recommends relevant internal links for content optimization.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Suggest_Internal_Links implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_WordPress_Native;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'suggest_internal_links';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Suggest Internal Links', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes post content and suggests relevant internal links to improve SEO and user navigation. Uses AI to find contextually relevant connections between posts.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'           => array(
					'type'        => 'integer',
					'description' => __( 'Post ID to analyze for internal link suggestions.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'content'           => array(
					'type'        => 'string',
					'description' => __( 'Content to analyze (if post_id not provided).', 'mcp-ai-wpoos' ),
				),
				'max_suggestions'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of link suggestions to return.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 20,
					'default'     => 5,
				),
				'min_relevance'     => array(
					'type'        => 'number',
					'description' => __( 'Minimum relevance score (0-1) for suggestions.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'maximum'     => 1,
					'default'     => 0.5,
				),
				'post_types'        => array(
					'type'        => 'array',
					'description' => __( 'Post types to search for link targets.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
					'default'     => array( 'post', 'page' ),
				),
				'exclude_posts'     => array(
					'type'        => 'array',
					'description' => __( 'Post IDs to exclude from suggestions.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'include_anchors'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to generate suggested anchor text.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'anyOf'      => array(
				array( 'required' => array( 'post_id' ) ),
				array( 'required' => array( 'content' ) ),
			),
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.

	 *

	 * @since 1.1.0

	 *

	 * @return array Tool definition with metadata.

	 */

	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'content_publishing',

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'seo_specialist', 'content_strategist' ),

			'risk_level'            => 'info',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'cacheable',
			'consumes-tokens',
			'model-dependent',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Start performance tracking.
		$start_time = microtime( true );

		// Fire before execute hook.
		$this->do_before_execute( $arguments, $context );

		// Validate arguments.
		$validation = $this->validate_arguments( $arguments );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Check cache.
		if ( $this->should_cache() ) {
			$cached = $this->get_cached_result( $arguments );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Get content to analyze.
		$content_data = $this->get_content_data( $arguments );
		if ( is_wp_error( $content_data ) ) {
			return $content_data;
		}

		// Get candidate posts for linking.
		$candidates = $this->get_link_candidates( $arguments );
		if ( empty( $candidates ) ) {
			$result = array(
				'suggestions' => array(),
				'message'     => __( 'No suitable posts found for internal linking.', 'mcp-ai-wpoos' ),
			);
			return $this->apply_result_filter( $result, $arguments, $context );
		}

		// Analyze and suggest links.
		$suggestions = $this->analyze_and_suggest_links( $content_data, $candidates, $arguments, $context );
		if ( is_wp_error( $suggestions ) ) {
			return $suggestions;
		}

		// Filter by relevance.
		$min_relevance        = $arguments['min_relevance'] ?? 0.5;
		$max_suggestions      = $arguments['max_suggestions'] ?? 5;
		$filtered_suggestions = $this->filter_suggestions( $suggestions, $min_relevance, $max_suggestions );

		// Apply filter hook.
		$filtered_suggestions = apply_filters(
			'wp_mcp_ai_internal_links_suggestions',
			$filtered_suggestions,
			$content_data['post_id'] ?? 0,
			$arguments
		);

		// Build result.
		$result = array(
			'suggestions'      => $filtered_suggestions,
			'total_candidates' => count( $candidates ),
			'post_id'          => $content_data['post_id'] ?? null,
		);

		// Cache result.
		if ( $this->should_cache() ) {
			$this->set_cached_result( $arguments, $result );
		}

		// Track performance.
		$this->track_performance( $start_time, $arguments );

		// Fire after execute hook.
		$this->do_after_execute( $result, $arguments, $context );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Validate tool arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_arguments( $arguments ) {
		if ( empty( $arguments['post_id'] ) && empty( $arguments['content'] ) ) {
			return new WP_Error(
				'missing_content',
				__( 'Either post_id or content must be provided.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! empty( $arguments['post_id'] ) ) {
			$post = get_post( $arguments['post_id'] );
			if ( ! $post ) {
				return new WP_Error(
					'invalid_post',
					__( 'Post not found.', 'mcp-ai-wpoos' )
				);
			}
		}

		return true;
	}

	/**
	 * Get content data to analyze.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Content data.
	 */
	private function get_content_data( $arguments ) {
		if ( ! empty( $arguments['post_id'] ) ) {
			$post = get_post( $arguments['post_id'] );
			return array(
				'post_id' => $post->ID,
				'title'   => $post->post_title,
				'content' => wp_strip_all_tags( $post->post_content ),
				'excerpt' => $post->post_excerpt,
			);
		}

		return array(
			'title'   => '',
			'content' => wp_strip_all_tags( $arguments['content'] ?? '' ),
			'excerpt' => '',
		);
	}

	/**
	 * Get candidate posts for internal linking.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Array of post data.
	 */
	private function get_link_candidates( $arguments ) {
		$post_types     = $arguments['post_types'] ?? array( 'post', 'page' );
		$exclude_posts  = $arguments['exclude_posts'] ?? array();
		$current_post_id = $arguments['post_id'] ?? 0;

		// Exclude current post.
		if ( $current_post_id > 0 ) {
			$exclude_posts[] = $current_post_id;
		}

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'post__not_in'   => $exclude_posts,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$posts = get_posts( $query_args );

		$candidates = array();
		foreach ( $posts as $post ) {
			$candidates[] = array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'content' => wp_strip_all_tags( $post->post_content ),
				'excerpt' => $post->post_excerpt,
				'url'     => get_permalink( $post->ID ),
			);
		}

		return $candidates;
	}

	/**
	 * Analyze content and suggest internal links using AI.
	 *
	 * @param array $content_data Content to analyze.
	 * @param array $candidates   Candidate posts for linking.
	 * @param array $arguments    Tool arguments.
	 * @param array $context      Execution context.
	 * @return array|WP_Error Link suggestions with relevance scores.
	 */
	private function analyze_and_suggest_links( $content_data, $candidates, $arguments, $context = array() ) {
		// Build AI prompt.
		$prompt = $this->build_analysis_prompt( $content_data, $candidates, $arguments );

		// Get AI client.
		$client = $this->get_ai_client( $arguments, $context );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Call AI model.
		try {
			$response = $client->complete(
				array(
					'messages'    => array(
						array(
							'role'    => 'user',
							'content' => $prompt,
						),
					),
					'model'       => $arguments['model'] ?? 'gpt-4o-mini',
					'temperature' => 0.3,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Parse AI response.
			return $this->parse_ai_response( $response['content'] ?? '', $candidates );
		} catch ( Exception $e ) {
			return new WP_Error(
				'ai_analysis_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'AI analysis failed: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Build AI analysis prompt.
	 *
	 * @param array $content_data Content to analyze.
	 * @param array $candidates   Candidate posts.
	 * @param array $arguments    Tool arguments.
	 * @return string AI prompt.
	 */
	private function build_analysis_prompt( $content_data, $candidates, $arguments ) {
		$include_anchors = $arguments['include_anchors'] ?? true;

		$candidate_list = array();
		foreach ( $candidates as $candidate ) {
			$excerpt = ! empty( $candidate['excerpt'] ) ? $candidate['excerpt'] : wp_trim_words( $candidate['content'], 30 );
			$candidate_list[] = sprintf(
				'%d. "%s" - %s',
				$candidate['id'],
				$candidate['title'],
				$excerpt
			);
		}

		$prompt = "Analyze the following content and suggest relevant internal links from the available posts.\n\n";
		$prompt .= "Current Content:\n";
		$prompt .= "Title: {$content_data['title']}\n";
		$prompt .= "Content: " . wp_trim_words( $content_data['content'], 200 ) . "\n\n";
		$prompt .= "Available Posts for Linking:\n" . implode( "\n", array_slice( $candidate_list, 0, 50 ) ) . "\n\n";

		if ( $include_anchors ) {
			$prompt .= "For each suggested link, provide:\n";
			$prompt .= "1. Post ID\n";
			$prompt .= "2. Relevance score (0-1)\n";
			$prompt .= "3. Suggested anchor text (a phrase from the current content that would work well as a link)\n";
			$prompt .= "4. Brief reason for the suggestion\n\n";
			$prompt .= "Respond in JSON format:\n";
			$prompt .= '[{"id": 5, "relevance": 0.9, "anchor_text": "WordPress development", "reason": "Highly relevant guide"}]';
		} else {
			$prompt .= "For each suggested link, provide the post ID and relevance score (0-1).\n";
			$prompt .= "Respond in JSON format:\n";
			$prompt .= '[{"id": 5, "relevance": 0.9}]';
		}

		return $prompt;
	}

	/**
	 * Parse AI response into link suggestions.
	 *
	 * @param string $response   AI response.
	 * @param array  $candidates Available candidates for validation.
	 * @return array Link suggestions.
	 */
	private function parse_ai_response( $response, $candidates ) {
		// Extract JSON from response.
		if ( preg_match( '/\[[\s\S]*\]/', $response, $matches ) ) {
			$json        = $matches[0];
			$suggestions = json_decode( $json, true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $suggestions ) ) {
				// Validate suggestions against available candidates.
				$valid_ids = wp_list_pluck( $candidates, 'id' );
				$validated = array();

				foreach ( $suggestions as $suggestion ) {
					if ( isset( $suggestion['id'] ) && in_array( $suggestion['id'], $valid_ids, true ) ) {
						// Enrich with post data.
						$post_data = array_values(
							array_filter(
								$candidates,
								function ( $c ) use ( $suggestion ) {
									return $c['id'] === $suggestion['id'];
								}
							)
						);

						if ( ! empty( $post_data ) ) {
							$validated[] = array_merge(
								$suggestion,
								array(
									'title' => $post_data[0]['title'],
									'url'   => $post_data[0]['url'],
								)
							);
						}
					}
				}

				return $validated;
			}
		}

		// Fallback: return empty suggestions.
		return array();
	}

	/**
	 * Filter suggestions by relevance and limit.
	 *
	 * @param array $suggestions   Link suggestions.
	 * @param float $min_relevance Minimum relevance threshold.
	 * @param int   $max_suggestions Maximum suggestions to return.
	 * @return array Filtered suggestions.
	 */
	private function filter_suggestions( $suggestions, $min_relevance, $max_suggestions ) {
		// Filter by relevance.
		$filtered = array_filter(
			$suggestions,
			function ( $suggestion ) use ( $min_relevance ) {
				return ( $suggestion['relevance'] ?? 0 ) >= $min_relevance;
			}
		);

		// Sort by relevance (highest first).
		usort(
			$filtered,
			function ( $a, $b ) {
				return ( $b['relevance'] ?? 0 ) <=> ( $a['relevance'] ?? 0 );
			}
		);

		// Limit to max suggestions.
		return array_slice( $filtered, 0, $max_suggestions );
	}

	/**
	 * Get AI client for content analysis.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return object|WP_Error AI client instance.
	 */
	private function get_ai_client( $arguments, $context ) {
		// Get model router or client.
		if ( class_exists( 'WP_MCP_AI_Language_Model_Router' ) ) {
			$router = WP_MCP_AI_Language_Model_Router::get_instance();
			return $router->get_client( $arguments['model'] ?? 'gpt-4o-mini' );
		}

		// Fallback to OpenAI client.
		if ( class_exists( 'WP_MCP_AI_Enhanced_OpenAI_Client' ) ) {
			return new WP_MCP_AI_Enhanced_OpenAI_Client();
		}

		return new WP_Error(
			'no_ai_client',
			__( 'No AI client available for content analysis.', 'mcp-ai-wpoos' )
		);
	}
}
