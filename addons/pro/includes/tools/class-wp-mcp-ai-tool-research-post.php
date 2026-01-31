<?php
/**
 * Tool for researching post topics using AI and web search.
 *
 * Provides comprehensive research about a blog post topic including
 * title, content, SEO metadata, and format ready for creation.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Research Post Tool
 *
 * Uses AI and web search to research comprehensive information about
 * blog post topics and generate ready-to-publish content.
 */
class WP_MCP_AI_Tool_Research_Post implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'Research comprehensive information about a blog post topic and generate content ready for publication. Returns title, content, excerpt, SEO metadata, and formatting instructions based on the selected template (Classic Editor, Block Editor, or Elementor).', 'mcp-ai-wpoos-pro' );
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
				'word_count'  => array(
					'type'        => 'integer',
					'description' => __( 'Target word count for the content', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 100,
					'maximum'     => 5000,
					'default'     => 1000,
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

		$topic       = sanitize_text_field( $arguments['topic'] );
		$word_count  = isset( $arguments['word_count'] ) ? absint( $arguments['word_count'] ) : 1000;
		$template    = isset( $arguments['template'] ) ? sanitize_key( $arguments['template'] ) : 'block-editor';
		$include_seo = isset( $arguments['include_seo'] ) ? (bool) $arguments['include_seo'] : true;
		$tone        = isset( $arguments['tone'] ) ? sanitize_key( $arguments['tone'] ) : 'professional';

		// Validate word count.
		if ( $word_count < 100 || $word_count > 5000 ) {
			$word_count = 1000;
		}

		// Validate template.
		if ( ! in_array( $template, array( 'block-editor', 'classic-editor', 'elementor' ), true ) ) {
			$template = 'block-editor';
		}

		// Validate tone.
		if ( ! in_array( $tone, array( 'professional', 'casual', 'friendly', 'authoritative', 'conversational' ), true ) ) {
			$tone = 'professional';
		}

		// Check cache first.
		$cache_key = 'post_research_' . md5( $topic . '_' . $word_count . '_' . $template . '_' . $tone );
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
				'topic'      => $topic,
				'word_count' => $word_count,
				'template'   => $template,
				'user_id'    => $user_id,
			)
		);

		// Build research prompt.
		$prompt = $this->build_research_prompt( $topic, $word_count, $template, $include_seo, $tone );

		// Use AI to research the topic and generate content.
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
		$post_data = $this->parse_research_results( $research_result, $topic, $template );

		if ( is_wp_error( $post_data ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to parse post research results: ' . $post_data->get_error_message(),
				array(
					'topic' => $topic,
				)
			);
			return $post_data;
		}

		// Cache the results for 24 hours.
		wp_cache_set( $cache_key, $post_data, 'wp_mcp_ai_post_research', DAY_IN_SECONDS );

		// Log success.
		WP_MCP_AI_Logger::log_event(
			'post_research_completed',
			'Post research completed successfully',
			array(
				'topic' => $topic,
				'title' => isset( $post_data['title'] ) ? $post_data['title'] : '',
			)
		);

		return $post_data;
	}

	/**
	 * Build the research prompt for AI.
	 *
	 * @param string $topic        Topic to research.
	 * @param int    $word_count   Target word count.
	 * @param string $template     Template format.
	 * @param bool   $include_seo  Whether to include SEO.
	 * @param string $tone         Tone of voice.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $topic, $word_count, $template, $include_seo, $tone ) {
		$prompt = sprintf(
			"Research and write a comprehensive blog post about the following topic:\n\n**Topic:** %s\n**Word Count:** %d words\n**Template:** %s\n**Tone:** %s\n\n",
			$topic,
			$word_count,
			$template,
			$tone
		);

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
				$prompt .= "- Use HTML5 elements: <h2>, <h3>, <p>, <ul>, <ol>, <blockquote>\n";
				$prompt .= "- Include <!-- wp:paragraph --> and <!-- wp:heading --> block comments where appropriate\n";
				$prompt .= "- Structure with clear headings and subheadings\n";
				$prompt .= "- Use bullet points and numbered lists where relevant\n";
				break;

			case 'classic-editor':
				$prompt .= "- Format content for Classic Editor (TinyMCE)\n";
				$prompt .= "- Use simple HTML: <h2>, <h3>, <p>, <ul>, <ol>, <strong>, <em>\n";
				$prompt .= "- Keep formatting straightforward and clean\n";
				$prompt .= "- Structure with clear headings\n";
				break;

			case 'elementor':
				$prompt .= "- Format content for Elementor page builder\n";
				$prompt .= "- Use minimal HTML - primarily plain text with clear line breaks\n";
				$prompt .= "- Separate sections with clear headings (marked with **)\n";
				$prompt .= "- Note: Content will be added to Elementor sections/widgets\n";
				$prompt .= "- Keep formatting simple for easy widget insertion\n";
				break;
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
				'content' => 'You are an expert content writer and researcher. You create high-quality, well-researched blog posts that are engaging, informative, and SEO-friendly. Always respond with valid JSON matching the requested format. Use web search when available to ensure accuracy and up-to-date information.',
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
	 * @param array  $research_result AI research results.
	 * @param string $topic           Original topic.
	 * @param string $template        Template format.
	 * @return array|WP_Error Parsed post data or error.
	 */
	protected function parse_research_results( $research_result, $topic, $template ) {
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
			'success'           => true,
			'topic'             => $topic,
			'title'             => sanitize_text_field( $data['title'] ),
			'content'           => wp_kses_post( $data['content'] ),
			'excerpt'           => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : '',
			'post_type'         => 'post',
			'status'            => 'draft',
			'template'          => $template,
			'sources'           => isset( $data['sources'] ) && is_array( $data['sources'] ) ? array_map( 'esc_url_raw', $data['sources'] ) : array(),
			'researched_at'     => current_time( 'mysql' ),
			'research_model'    => $research_result['model'],
			'research_provider' => $research_result['provider'],
		);

		// Add SEO metadata if present.
		if ( isset( $data['meta_description'] ) ) {
			$post_data['meta_description'] = sanitize_text_field( $data['meta_description'] );
		}

		if ( isset( $data['keywords'] ) && is_array( $data['keywords'] ) ) {
			$post_data['keywords'] = array_map( 'sanitize_text_field', $data['keywords'] );
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
}
