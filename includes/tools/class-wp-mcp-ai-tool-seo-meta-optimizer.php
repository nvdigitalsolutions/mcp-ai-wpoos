<?php
/**
 * Tool for optimizing SEO meta tags using AI and industry best practices.
 *
 * Generates optimized title tags, meta descriptions, and schema markup
 * following 2026 SEO best practices.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../traits/trait-wp-mcp-ai-tool-wordpress-native.php';

/**
 * SEO Meta Optimizer Tool
 *
 * Generates SEO-optimized meta tags following industry standards:
 * - Title tags: 50-60 characters
 * - Meta descriptions: 140-160 characters (120 for mobile)
 * - Schema markup recommendations
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_SEO_Meta_Optimizer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_WordPress_Native;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'seo_meta_optimizer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'SEO Meta Optimizer', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates SEO-optimized meta tags following 2026 industry standards. Creates compelling title tags (50-60 chars), meta descriptions (140-160 chars), and schema markup recommendations.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'            => array(
					'type'        => 'integer',
					'description' => __( 'Post ID to optimize SEO meta tags for.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'content'            => array(
					'type'        => 'string',
					'description' => __( 'Content to analyze (if post_id not provided).', 'mcp-ai-wpoos' ),
				),
				'title'              => array(
					'type'        => 'string',
					'description' => __( 'Current title (if post_id not provided).', 'mcp-ai-wpoos' ),
				),
				'focus_keyword'      => array(
					'type'        => 'string',
					'description' => __( 'Primary keyword to optimize for.', 'mcp-ai-wpoos' ),
				),
				'target_audience'    => array(
					'type'        => 'string',
					'description' => __( 'Target audience for the content.', 'mcp-ai-wpoos' ),
				),
				'include_schema'     => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include schema markup recommendations.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'mobile_optimized'   => array(
					'type'        => 'boolean',
					'description' => __( 'Optimize meta description for mobile (120 chars).', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'include_variations' => array(
					'type'        => 'boolean',
					'description' => __( 'Generate multiple variations for A/B testing.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'auto_save'          => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically save to post meta (requires Rank Math or Yoast).', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'anyOf'      => array(
				array( 'required' => array( 'post_id' ) ),
				array( 'required' => array( 'content', 'title' ) ),
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

			'risk_level'            => 'standard',

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
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool execution result.
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

		// Check cache if not auto-saving.
		if ( ! ( $arguments['auto_save'] ?? false ) && $this->should_cache() ) {
			$cached = $this->get_cached_result( $arguments );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Get content data.
		$content_data = $this->get_content_data( $arguments );
		if ( is_wp_error( $content_data ) ) {
			return $content_data;
		}

		// Generate SEO meta tags.
		$seo_meta = $this->generate_seo_meta( $content_data, $arguments, $context );
		if ( is_wp_error( $seo_meta ) ) {
			return $seo_meta;
		}

		// Validate generated meta against best practices.
		$validation_results = $this->validate_seo_meta( $seo_meta, $arguments );

		// Apply filter hook.
		$seo_meta = apply_filters(
			'wp_mcp_ai_seo_meta_generated',
			$seo_meta,
			$content_data['post_id'] ?? 0,
			$arguments
		);

		// Auto-save if requested.
		$saved = false;
		if ( ! empty( $content_data['post_id'] ) && ( $arguments['auto_save'] ?? false ) ) {
			$saved = $this->save_seo_meta( $content_data['post_id'], $seo_meta );

			if ( ! is_wp_error( $saved ) && $saved ) {
				do_action(
					'wp_mcp_ai_seo_meta_saved',
					$content_data['post_id'],
					$seo_meta
				);
			}
		}

		// Build result.
		$result = array(
			'seo_meta'           => $seo_meta,
			'validation'         => $validation_results,
			'post_id'            => $content_data['post_id'] ?? null,
			'saved'              => $saved,
			'best_practices_met' => $validation_results['all_passed'] ?? false,
		);

		// Cache result if not auto-saving.
		if ( ! ( $arguments['auto_save'] ?? false ) && $this->should_cache() ) {
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
		if ( empty( $arguments['post_id'] ) && ( empty( $arguments['content'] ) || empty( $arguments['title'] ) ) ) {
			return new WP_Error(
				'missing_content',
				__( 'Either post_id or both content and title must be provided.', 'mcp-ai-wpoos' )
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
	 * Get content data for SEO optimization.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Content data.
	 */
	private function get_content_data( $arguments ) {
		if ( ! empty( $arguments['post_id'] ) ) {
			$post = get_post( $arguments['post_id'] );
			return array(
				'post_id'   => $post->ID,
				'title'     => $post->post_title,
				'content'   => wp_strip_all_tags( $post->post_content ),
				'excerpt'   => $post->post_excerpt,
				'post_type' => $post->post_type,
			);
		}

		return array(
			'title'     => $arguments['title'] ?? '',
			'content'   => wp_strip_all_tags( $arguments['content'] ?? '' ),
			'excerpt'   => '',
			'post_type' => 'post',
		);
	}

	/**
	 * Generate SEO meta tags using AI.
	 *
	 * @param array $content_data Content to analyze.
	 * @param array $arguments    Tool arguments.
	 * @param array $context      Execution context.
	 * @return array|WP_Error Generated SEO meta tags.
	 */
	private function generate_seo_meta( $content_data, $arguments, $context = array() ) {
		// Build AI prompt based on 2026 best practices.
		$prompt = $this->build_seo_prompt( $content_data, $arguments );

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
					'temperature' => 0.7,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Parse AI response.
			return $this->parse_seo_response( $response['content'] ?? '', $arguments );
		} catch ( Exception $e ) {
			return new WP_Error(
				'seo_generation_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'SEO meta generation failed: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Build AI prompt for SEO meta generation.
	 *
	 * @param array $content_data Content data.
	 * @param array $arguments    Tool arguments.
	 * @return string AI prompt.
	 */
	private function build_seo_prompt( $content_data, $arguments ) {
		$focus_keyword      = $arguments['focus_keyword'] ?? '';
		$target_audience    = $arguments['target_audience'] ?? '';
		$mobile_optimized   = $arguments['mobile_optimized'] ?? false;
		$include_variations = $arguments['include_variations'] ?? false;
		$include_schema     = $arguments['include_schema'] ?? true;

		$meta_desc_length = $mobile_optimized ? 120 : 160;

		$content_preview = wp_trim_words( $content_data['content'], 200 );

		$prompt  = "Generate SEO-optimized meta tags following 2026 industry best practices.\n\n";
		$prompt .= "Content Title: {$content_data['title']}\n";
		$prompt .= "Content Preview:\n{$content_preview}\n\n";

		if ( $focus_keyword ) {
			$prompt .= "Primary Keyword: {$focus_keyword}\n";
		}

		if ( $target_audience ) {
			$prompt .= "Target Audience: {$target_audience}\n";
		}

		$prompt .= "\nRequirements:\n";
		$prompt .= "1. SEO Title Tag:\n";
		$prompt .= "   - 50-60 characters maximum\n";
		$prompt .= "   - Place primary keyword near the beginning\n";
		$prompt .= "   - Make it compelling and click-worthy\n";
		$prompt .= "   - Use action verbs, numbers, or questions to boost CTR\n\n";

		$prompt .= "2. Meta Description:\n";
		$prompt .= "   - {$meta_desc_length} characters maximum\n";
		$prompt .= "   - Accurately summarize content\n";
		$prompt .= "   - Include primary keyword naturally\n";
		$prompt .= "   - Add a call-to-action\n";
		$prompt .= "   - Make it unique and engaging\n\n";

		if ( $include_schema ) {
			$prompt .= "3. Schema Markup Recommendation:\n";
			$prompt .= "   - Suggest appropriate schema type (Article, BlogPosting, Product, etc.)\n";
			$prompt .= "   - Provide key schema properties to implement\n\n";
		}

		if ( $include_variations ) {
			$prompt .= "Generate 3 variations of title and description for A/B testing.\n\n";
		}

		$prompt .= "Respond in JSON format:\n";
		$prompt .= "{\n";
		$prompt .= "  \"title\": \"SEO title tag\",\n";
		$prompt .= "  \"meta_description\": \"Meta description text\",\n";

		if ( $include_schema ) {
			$prompt .= "  \"schema_type\": \"Article\",\n";
			$prompt .= "  \"schema_properties\": [\"headline\", \"author\", \"datePublished\"],\n";
		}

		if ( $include_variations ) {
			$prompt .= "  \"variations\": [{\"title\": \"...\", \"meta_description\": \"...\"}]\n";
		}

		$prompt .= '}';

		return $prompt;
	}

	/**
	 * Parse AI response into SEO meta structure.
	 *
	 * @param string $response  AI response.
	 * @param array  $arguments Tool arguments.
	 * @return array SEO meta tags.
	 */
	private function parse_seo_response( $response, $arguments ) {
		// Extract JSON from response.
		if ( preg_match( '/\{[\s\S]*\}/', $response, $matches ) ) {
			$json = $matches[0];
			$data = json_decode( $json, true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
				return $data;
			}
		}

		// Fallback: return empty structure.
		return array(
			'title'            => '',
			'meta_description' => '',
			'schema_type'      => 'Article',
		);
	}

	/**
	 * Validate SEO meta against 2026 best practices.
	 *
	 * @param array $seo_meta  Generated SEO meta.
	 * @param array $arguments Tool arguments.
	 * @return array Validation results.
	 */
	private function validate_seo_meta( $seo_meta, $arguments ) {
		$mobile_optimized = $arguments['mobile_optimized'] ?? false;
		$focus_keyword    = $arguments['focus_keyword'] ?? '';

		$validation = array(
			'title_length_ok'        => false,
			'description_length_ok'  => false,
			'keyword_in_title'       => false,
			'keyword_in_description' => false,
			'has_call_to_action'     => false,
			'all_passed'             => false,
			'issues'                 => array(),
		);

		// Validate title length (50-60 characters).
		$title_length = strlen( $seo_meta['title'] ?? '' );
		if ( $title_length >= 50 && $title_length <= 60 ) {
			$validation['title_length_ok'] = true;
		} else {
			$validation['issues'][] = sprintf(
				/* translators: %d: title length */
				__( 'Title length (%d chars) should be between 50-60 characters.', 'mcp-ai-wpoos' ),
				$title_length
			);
		}

		// Validate meta description length.
		$desc_length = strlen( $seo_meta['meta_description'] ?? '' );
		$ideal_min   = $mobile_optimized ? 100 : 140;
		$ideal_max   = $mobile_optimized ? 120 : 160;

		if ( $desc_length >= $ideal_min && $desc_length <= $ideal_max ) {
			$validation['description_length_ok'] = true;
		} else {
			$validation['issues'][] = sprintf(
				/* translators: %1$d: description length, %2$d: min length, %3$d: max length */
				__( 'Meta description length (%1$d chars) should be between %2$d-%3$d characters.', 'mcp-ai-wpoos' ),
				$desc_length,
				$ideal_min,
				$ideal_max
			);
		}

		// Check for keyword presence.
		if ( $focus_keyword ) {
			$title_lower   = strtolower( $seo_meta['title'] ?? '' );
			$desc_lower    = strtolower( $seo_meta['meta_description'] ?? '' );
			$keyword_lower = strtolower( $focus_keyword );

			if ( strpos( $title_lower, $keyword_lower ) !== false ) {
				$validation['keyword_in_title'] = true;
			} else {
				$validation['issues'][] = __( 'Primary keyword not found in title.', 'mcp-ai-wpoos' );
			}

			if ( strpos( $desc_lower, $keyword_lower ) !== false ) {
				$validation['keyword_in_description'] = true;
			} else {
				$validation['issues'][] = __( 'Primary keyword not found in meta description.', 'mcp-ai-wpoos' );
			}
		}

		// Check for CTA in description.
		$cta_words  = array( 'learn', 'discover', 'find out', 'read', 'explore', 'get', 'download', 'shop', 'buy', 'subscribe' );
		$desc_lower = strtolower( $seo_meta['meta_description'] ?? '' );

		foreach ( $cta_words as $cta ) {
			if ( strpos( $desc_lower, $cta ) !== false ) {
				$validation['has_call_to_action'] = true;
				break;
			}
		}

		// Determine if all validations passed.
		$validation['all_passed'] = $validation['title_length_ok'] &&
			$validation['description_length_ok'] &&
			( empty( $focus_keyword ) || ( $validation['keyword_in_title'] && $validation['keyword_in_description'] ) );

		return $validation;
	}

	/**
	 * Save SEO meta to post.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $seo_meta SEO meta tags.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function save_seo_meta( $post_id, $seo_meta ) {
		// Check for Rank Math.
		if ( class_exists( 'RankMath' ) ) {
			update_post_meta( $post_id, 'rank_math_title', $seo_meta['title'] ?? '' );
			update_post_meta( $post_id, 'rank_math_description', $seo_meta['meta_description'] ?? '' );

			if ( ! empty( $seo_meta['schema_type'] ) ) {
				update_post_meta( $post_id, 'rank_math_rich_snippet', strtolower( $seo_meta['schema_type'] ) );
			}

			return true;
		}

		// Check for Yoast SEO.
		if ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', $seo_meta['title'] ?? '' );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $seo_meta['meta_description'] ?? '' );
			return true;
		}

		// Fallback: save to custom meta.
		update_post_meta( $post_id, '_wp_mcp_ai_seo_title', $seo_meta['title'] ?? '' );
		update_post_meta( $post_id, '_wp_mcp_ai_meta_description', $seo_meta['meta_description'] ?? '' );

		return true;
	}

	/**
	 * Get AI client for SEO generation.
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
			__( 'No AI client available for SEO generation.', 'mcp-ai-wpoos' )
		);
	}
}
