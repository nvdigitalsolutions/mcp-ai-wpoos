<?php
/**
 * Tool for automatically categorizing WordPress content using AI.
 *
 * Analyzes post content and suggests relevant categories based on
 * content analysis, existing categories, and context.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../traits/trait-wp-mcp-ai-tool-wordpress-native.php';

/**
 * Auto-Categorize Content Tool
 *
 * Automatically assigns relevant categories to posts based on AI content analysis.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Auto_Categorize_Content implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_WordPress_Native;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'auto_categorize_content';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Auto-Categorize Content', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Automatically analyzes post content and suggests relevant categories. Can be used manually or triggered automatically on post save.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Post ID to categorize.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'content'          => array(
					'type'        => 'string',
					'description' => __( 'Post content to analyze (if post_id not provided).', 'mcp-ai-wpoos' ),
				),
				'title'            => array(
					'type'        => 'string',
					'description' => __( 'Post title to analyze (if post_id not provided).', 'mcp-ai-wpoos' ),
				),
				'auto_assign'      => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically assign suggested categories to the post.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'min_confidence'   => array(
					'type'        => 'number',
					'description' => __( 'Minimum confidence score (0-1) to suggest a category.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'maximum'     => 1,
					'default'     => 0.6,
				),
				'max_categories'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of categories to suggest.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 10,
					'default'     => 3,
				),
				'taxonomy'         => array(
					'type'        => 'string',
					'description' => __( 'Taxonomy to use for categorization (default: category).', 'mcp-ai-wpoos' ),
					'default'     => 'category',
				),
				'create_new'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to create new categories if none match.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'anyOf'      => array(
				array( 'required' => array( 'post_id' ) ),
				array( 'required' => array( 'content' ) ),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',
			'state-changing',
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

		// Check cache if not auto-assigning.
		if ( ! ( $arguments['auto_assign'] ?? false ) && $this->should_cache() ) {
			$cached = $this->get_cached_result( $arguments );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Get post content to analyze.
		$content_data = $this->get_content_data( $arguments );
		if ( is_wp_error( $content_data ) ) {
			return $content_data;
		}

		// Get available categories.
		$taxonomy             = $arguments['taxonomy'] ?? 'category';
		$available_categories = $this->get_available_categories( $taxonomy );

		// Analyze content and suggest categories.
		$suggestions = $this->analyze_and_suggest( $content_data, $available_categories, $arguments, $context );
		if ( is_wp_error( $suggestions ) ) {
			return $suggestions;
		}

		// Filter suggestions.
		$min_confidence       = $arguments['min_confidence'] ?? 0.6;
		$max_categories       = $arguments['max_categories'] ?? 3;
		$filtered_suggestions = $this->filter_suggestions( $suggestions, $min_confidence, $max_categories );

		// Apply filter hook.
		$filtered_suggestions = apply_filters(
			'wp_mcp_ai_auto_categorize_categories',
			$filtered_suggestions,
			$content_data['post_id'] ?? 0,
			$suggestions
		);

		// Auto-assign if requested.
		$assigned = false;
		if ( ! empty( $content_data['post_id'] ) && ( $arguments['auto_assign'] ?? false ) ) {
			$assigned = $this->assign_categories(
				$content_data['post_id'],
				$filtered_suggestions,
				$taxonomy
			);

			if ( ! is_wp_error( $assigned ) && $assigned ) {
				/**
				 * Action hook after content is categorized.
				 *
				 * @param int   $post_id     Post ID.
				 * @param array $categories  Assigned categories.
				 * @param array $analysis    Analysis results.
				 */
				do_action(
					'wp_mcp_ai_content_categorized',
					$content_data['post_id'],
					$filtered_suggestions,
					$suggestions
				);
			}
		}

		// Build result.
		$result = array(
			'suggestions'    => $filtered_suggestions,
			'all_scores'     => $suggestions,
			'auto_assigned'  => $assigned,
			'post_id'        => $content_data['post_id'] ?? null,
			'taxonomy'       => $taxonomy,
		);

		// Cache result if not auto-assigning.
		if ( ! ( $arguments['auto_assign'] ?? false ) && $this->should_cache() ) {
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

		$taxonomy = $arguments['taxonomy'] ?? 'category';
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'invalid_taxonomy',
				__( 'Taxonomy does not exist.', 'mcp-ai-wpoos' )
			);
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
				'content' => $post->post_content,
				'excerpt' => $post->post_excerpt,
			);
		}

		return array(
			'title'   => $arguments['title'] ?? '',
			'content' => $arguments['content'] ?? '',
			'excerpt' => '',
		);
	}

	/**
	 * Get available categories for the taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array Categories with ID, name, and description.
	 */
	private function get_available_categories( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$categories = array();
		foreach ( $terms as $term ) {
			$categories[] = array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
			);
		}

		return $categories;
	}

	/**
	 * Analyze content and suggest categories using AI.
	 *
	 * @param array $content_data Content to analyze.
	 * @param array $categories   Available categories.
	 * @param array $arguments    Tool arguments.
	 * @param array $context      Execution context.
	 * @return array|WP_Error Category suggestions with confidence scores.
	 */
	private function analyze_and_suggest( $content_data, $categories, $arguments, $context = array() ) {
		// Build AI prompt.
		$prompt = $this->build_analysis_prompt( $content_data, $categories );

		// Get AI client.
		$client = $this->get_ai_client( $arguments, $context );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Call AI model.
		try {
			$response = $client->complete(
				array(
					'messages' => array(
						array(
							'role'    => 'user',
							'content' => $prompt,
						),
					),
					'model'       => $arguments['model'] ?? 'gpt-4o-mini',
					'temperature' => 0.3, // Lower temperature for more consistent categorization.
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Parse AI response.
			return $this->parse_ai_response( $response['content'] ?? '', $categories );
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
	 * @param array $categories   Available categories.
	 * @return string AI prompt.
	 */
	private function build_analysis_prompt( $content_data, $categories ) {
		$category_list = array();
		foreach ( $categories as $cat ) {
			$desc = ! empty( $cat['description'] ) ? ' - ' . $cat['description'] : '';
			$category_list[] = "- {$cat['name']} (ID: {$cat['id']}){$desc}";
		}

		$prompt = "Analyze the following content and suggest the most relevant categories from the available list.\n\n";
		$prompt .= "Title: {$content_data['title']}\n\n";
		$prompt .= "Content:\n{$content_data['content']}\n\n";
		$prompt .= "Available Categories:\n" . implode( "\n", $category_list ) . "\n\n";
		$prompt .= "Respond in JSON format with an array of objects containing 'id', 'name', and 'confidence' (0-1).\n";
		$prompt .= "Only suggest categories that are truly relevant to the content.\n";
		$prompt .= "Example: [{\"id\": 5, \"name\": \"Technology\", \"confidence\": 0.95}]";

		return $prompt;
	}

	/**
	 * Parse AI response into category suggestions.
	 *
	 * @param string $response     AI response.
	 * @param array  $categories   Available categories for validation.
	 * @return array Category suggestions.
	 */
	private function parse_ai_response( $response, $categories ) {
		// Extract JSON from response.
		if ( preg_match( '/\[[\s\S]*\]/', $response, $matches ) ) {
			$json = $matches[0];
			$suggestions = json_decode( $json, true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $suggestions ) ) {
				// Validate suggestions against available categories.
				$valid_ids = wp_list_pluck( $categories, 'id' );
				return array_filter( $suggestions, function( $suggestion ) use ( $valid_ids ) {
					return isset( $suggestion['id'] ) && in_array( $suggestion['id'], $valid_ids, true );
				} );
			}
		}

		// Fallback: return empty suggestions.
		return array();
	}

	/**
	 * Filter suggestions by confidence and limit.
	 *
	 * @param array $suggestions    Category suggestions.
	 * @param float $min_confidence Minimum confidence threshold.
	 * @param int   $max_categories Maximum categories to return.
	 * @return array Filtered suggestions.
	 */
	private function filter_suggestions( $suggestions, $min_confidence, $max_categories ) {
		// Filter by confidence.
		$filtered = array_filter( $suggestions, function( $suggestion ) use ( $min_confidence ) {
			return ( $suggestion['confidence'] ?? 0 ) >= $min_confidence;
		} );

		// Sort by confidence (highest first).
		usort( $filtered, function( $a, $b ) {
			return ( $b['confidence'] ?? 0 ) <=> ( $a['confidence'] ?? 0 );
		} );

		// Limit to max categories.
		return array_slice( $filtered, 0, $max_categories );
	}

	/**
	 * Assign categories to post.
	 *
	 * @param int    $post_id    Post ID.
	 * @param array  $categories Category suggestions.
	 * @param string $taxonomy   Taxonomy name.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function assign_categories( $post_id, $categories, $taxonomy ) {
		if ( empty( $categories ) ) {
			return new WP_Error(
				'no_categories',
				__( 'No categories to assign.', 'mcp-ai-wpoos' )
			);
		}

		$term_ids = wp_list_pluck( $categories, 'id' );
		$result   = wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
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
