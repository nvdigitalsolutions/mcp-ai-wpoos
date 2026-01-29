<?php
/**
 * Tool for generating compelling post excerpts using AI.
 *
 * Creates SEO-optimized excerpts from post content that capture
 * the essence and encourage readers to continue.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../traits/trait-wp-mcp-ai-tool-wordpress-native.php';

/**
 * Generate Post Excerpt Tool
 *
 * Automatically creates compelling post excerpts optimized for SEO and engagement.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Generate_Post_Excerpt implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_WordPress_Native;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_post_excerpt';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Post Excerpt', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates compelling post excerpts using AI. Creates SEO-optimized summaries that capture the essence of content and encourage engagement.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Post ID to generate excerpt for.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'content'     => array(
					'type'        => 'string',
					'description' => __( 'Content to generate excerpt from (if post_id not provided).', 'mcp-ai-wpoos' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Post title to help with context.', 'mcp-ai-wpoos' ),
				),
				'length'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum length in words.', 'mcp-ai-wpoos' ),
					'minimum'     => 10,
					'maximum'     => 100,
					'default'     => 55,
				),
				'tone'        => array(
					'type'        => 'string',
					'description' => __( 'Writing tone for the excerpt.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'professional', 'casual', 'engaging', 'informative', 'compelling' ),
					'default'     => 'engaging',
				),
				'auto_save'   => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically save excerpt to post (requires post_id).', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'include_cta' => array(
					'type'        => 'boolean',
					'description' => __( 'Include a subtle call-to-action in the excerpt.', 'mcp-ai-wpoos' ),
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

		// Generate excerpt.
		$excerpt = $this->generate_excerpt( $content_data, $arguments, $context );
		if ( is_wp_error( $excerpt ) ) {
			return $excerpt;
		}

		// Apply filter hook.
		$excerpt = apply_filters(
			'wp_mcp_ai_generated_excerpt',
			$excerpt,
			$content_data['post_id'] ?? 0,
			$arguments
		);

		// Auto-save if requested.
		$saved = false;
		if ( ! empty( $content_data['post_id'] ) && ( $arguments['auto_save'] ?? false ) ) {
			$saved = $this->save_excerpt( $content_data['post_id'], $excerpt );

			if ( ! is_wp_error( $saved ) && $saved ) {
				/**
				 * Action hook after excerpt is saved.
				 *
				 * @param int    $post_id Post ID.
				 * @param string $excerpt Generated excerpt.
				 */
				do_action(
					'wp_mcp_ai_excerpt_saved',
					$content_data['post_id'],
					$excerpt
				);
			}
		}

		// Build result.
		$result = array(
			'excerpt'    => $excerpt,
			'word_count' => str_word_count( $excerpt ),
			'char_count' => strlen( $excerpt ),
			'post_id'    => $content_data['post_id'] ?? null,
			'saved'      => $saved,
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
	 * Get content data for excerpt generation.
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
			);
		}

		return array(
			'title'   => $arguments['title'] ?? '',
			'content' => wp_strip_all_tags( $arguments['content'] ?? '' ),
		);
	}

	/**
	 * Generate excerpt using AI.
	 *
	 * @param array $content_data Content to analyze.
	 * @param array $arguments    Tool arguments.
	 * @param array $context      Execution context.
	 * @return string|WP_Error Generated excerpt.
	 */
	private function generate_excerpt( $content_data, $arguments, $context = array() ) {
		// Build AI prompt.
		$prompt = $this->build_excerpt_prompt( $content_data, $arguments );

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
					'temperature' => 0.7, // Slightly higher for creative excerpt writing.
					'max_tokens'  => 200,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$excerpt = trim( $response['content'] ?? '' );

			// Remove quotes if present.
			$excerpt = trim( $excerpt, '"\'""' );

			return $excerpt;
		} catch ( Exception $e ) {
			return new WP_Error(
				'excerpt_generation_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Excerpt generation failed: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Build AI prompt for excerpt generation.
	 *
	 * @param array $content_data Content data.
	 * @param array $arguments    Tool arguments.
	 * @return string AI prompt.
	 */
	private function build_excerpt_prompt( $content_data, $arguments ) {
		$length      = $arguments['length'] ?? 55;
		$tone        = $arguments['tone'] ?? 'engaging';
		$include_cta = $arguments['include_cta'] ?? false;

		$content_preview = wp_trim_words( $content_data['content'], 300 );

		$prompt = "Generate a compelling excerpt for the following content.\n\n";
		$prompt .= "Title: {$content_data['title']}\n\n";
		$prompt .= "Content:\n{$content_preview}\n\n";
		$prompt .= "Requirements:\n";
		$prompt .= "- Maximum {$length} words\n";
		$prompt .= "- Tone: {$tone}\n";
		$prompt .= "- Capture the main idea and value proposition\n";
		$prompt .= "- Make it engaging and encourage readers to read more\n";
		$prompt .= "- Use active voice\n";
		$prompt .= "- SEO-friendly\n";

		if ( $include_cta ) {
			$prompt .= "- Include a subtle call-to-action (e.g., 'Learn more...', 'Discover how...', 'Find out...') \n";
		}

		$prompt .= "\nWrite only the excerpt, without quotes or additional commentary.";

		return $prompt;
	}

	/**
	 * Save excerpt to post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $excerpt Generated excerpt.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function save_excerpt( $post_id, $excerpt ) {
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_excerpt' => $excerpt,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Get AI client for excerpt generation.
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
			__( 'No AI client available for excerpt generation.', 'mcp-ai-wpoos' )
		);
	}
}
