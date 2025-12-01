<?php
/**
 * Tool for analyzing video content using AI vision capabilities.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Analyzes video content using AI vision models that support video understanding.
 */
class WP_MCP_AI_Tool_Analyze_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_video';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Video', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes video content to extract information, describe scenes, identify objects, and provide insights using AI vision models with video understanding capabilities.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'video_url'          => array(
					'type'        => 'string',
					'description' => __( 'URL of the video to analyze. Supports MP4 and QuickTime formats.', 'wp-mcp-ai' ),
				),
				'attachment_id'      => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the video to analyze.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'prompt'             => array(
					'type'        => 'string',
					'description' => __( 'Specific question or analysis prompt for the video content. If not provided, a general description will be generated.', 'wp-mcp-ai' ),
				),
				'context'            => array(
					'type'        => 'string',
					'description' => __( 'Optional context about the video to help generate more relevant analysis.', 'wp-mcp-ai' ),
				),
				'analysis_type'      => array(
					'type'        => 'string',
					'description' => __( 'Type of analysis to perform: "general" (default), "scene_breakdown", "timeline", "detailed".', 'wp-mcp-ai' ),
					'enum'        => array( 'general', 'scene_breakdown', 'timeline', 'detailed' ),
					'default'     => 'general',
				),
				'include_timestamps' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include timestamps for key events and scenes in the analysis.', 'wp-mcp-ai' ),
					'default'     => false,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
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

		// Check user capabilities.
		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to analyze videos.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		// Get video source.
		$video_url = '';

		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$video_url     = wp_get_attachment_url( $attachment_id );

			if ( ! $video_url ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_attachment',
					__( 'Invalid attachment ID provided.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			// Verify it's a video attachment.
			$mime_type = get_post_mime_type( $attachment_id );
			if ( ! $mime_type || false === strpos( $mime_type, 'video/' ) ) {
				return new WP_Error(
					'wp_mcp_ai_not_video',
					__( 'The provided attachment is not a video file.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}
		} elseif ( ! empty( $arguments['video_url'] ) ) {
			$video_url = esc_url_raw( $arguments['video_url'] );
		} else {
			return new WP_Error(
				'wp_mcp_ai_missing_video',
				__( 'Either video_url or attachment_id must be provided.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Get settings.
		$settings         = get_option( 'wp_mcp_ai_settings', array() );
		$default_provider = isset( $settings['default_provider'] ) ? $settings['default_provider'] : 'gemini';

		// Get analysis preferences.
		$user_prompt        = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$user_context       = isset( $arguments['context'] ) ? sanitize_text_field( $arguments['context'] ) : '';
		$analysis_type      = isset( $arguments['analysis_type'] ) ? sanitize_text_field( $arguments['analysis_type'] ) : 'general';
		$include_timestamps = isset( $arguments['include_timestamps'] ) && $arguments['include_timestamps'];

		// Build prompt based on analysis type.
		$prompt = $this->build_prompt( $user_prompt, $user_context, $analysis_type, $include_timestamps );

		// Call video-capable vision model and capture metadata.
		$attachment_id_for_video = ! empty( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : null;
		$api_response            = $this->call_video_model( $video_url, $prompt, $default_provider, $attachment_id_for_video );

		if ( is_wp_error( $api_response ) ) {
			return $api_response;
		}

		// Extract analysis and metadata.
		$analysis = is_array( $api_response ) && isset( $api_response['text'] ) ? $api_response['text'] : $api_response;
		$usage    = is_array( $api_response ) && isset( $api_response['usage'] ) ? $api_response['usage'] : null;
		$model    = is_array( $api_response ) && isset( $api_response['model'] ) ? $api_response['model'] : '';
		$provider = is_array( $api_response ) && isset( $api_response['provider'] ) ? $api_response['provider'] : $default_provider;

		$result = array(
			'analysis' => $analysis,
			'success'  => true,
		);

		// Include provider/model/usage metadata for accurate cost tracking.
		if ( $provider ) {
			$result['provider'] = $provider;
		}

		if ( $model ) {
			$result['model'] = $model;
		}

		if ( $usage ) {
			$result['usage'] = $usage;
		}

		return $result;
	}

	/**
	 * Build the analysis prompt.
	 *
	 * @param string $user_prompt        User's specific question or prompt.
	 * @param string $user_context       Additional context about the video.
	 * @param string $analysis_type      Type of analysis (general, scene_breakdown, timeline, detailed).
	 * @param bool   $include_timestamps Whether to include timestamps in analysis.
	 * @return string
	 */
	protected function build_prompt( $user_prompt, $user_context, $analysis_type = 'general', $include_timestamps = false ) {
		if ( ! empty( $user_prompt ) ) {
			$prompt = $user_prompt;
			if ( ! empty( $user_context ) ) {
				$prompt = sprintf(
					/* translators: 1: context, 2: prompt */
					__( 'Context: %1$s\n\n%2$s', 'wp-mcp-ai' ),
					$user_context,
					$prompt
				);
			}
			return $prompt;
		}

		// Build default prompt based on analysis type.
		$prompt = $this->get_default_prompt_for_type( $analysis_type, $include_timestamps );

		if ( ! empty( $user_context ) ) {
			$prompt = sprintf(
				/* translators: 1: context, 2: default prompt */
				__( 'Context: %1$s\n\n%2$s', 'wp-mcp-ai' ),
				$user_context,
				$prompt
			);
		}

		return $prompt;
	}

	/**
	 * Get default prompt based on analysis type.
	 *
	 * Provides specialized prompts for different video analysis needs,
	 * following Gemini video understanding best practices.
	 *
	 * @param string $analysis_type      Analysis type.
	 * @param bool   $include_timestamps Whether to include timestamps.
	 * @return string
	 */
	protected function get_default_prompt_for_type( $analysis_type, $include_timestamps ) {
		$timestamp_instruction = $include_timestamps
			? __( ' Include approximate timestamps or time ranges for key events.', 'wp-mcp-ai' )
			: '';

		switch ( $analysis_type ) {
			case 'scene_breakdown':
				return sprintf(
					/* translators: %s: optional timestamp instruction */
					__(
						'Analyze this video and provide a scene-by-scene breakdown. For each scene, describe:
1. Scene setting and environment
2. Main subjects and objects present
3. Actions and activities occurring
4. Notable visual elements or changes
5. Transitions to the next scene%s

Organize your analysis by scene number or natural segments.',
						'wp-mcp-ai'
					),
					$timestamp_instruction
				);

			case 'timeline':
				return __(
					'Create a timeline analysis of this video. Identify and describe key events in chronological order, including:
1. Opening/introduction
2. Major events or moments
3. Transitions and changes
4. Conclusion or ending
For each event, provide approximate timestamps and describe what happens.',
					'wp-mcp-ai'
				);

			case 'detailed':
				return sprintf(
					/* translators: %s: optional timestamp instruction */
					__(
						'Provide a comprehensive detailed analysis of this video including:
1. Main subjects and objects throughout the video
2. All significant actions and activities
3. Setting, environment, and any location changes
4. Visual elements, graphics, and text visible
5. Audio cues if apparent from visuals (speech, music indicators)
6. Overall narrative or flow
7. Tone, mood, and stylistic elements
8. Technical aspects (camera movements, effects, transitions)%s

Be thorough and include specific observations.',
						'wp-mcp-ai'
					),
					$timestamp_instruction
				);

			case 'general':
			default:
				return sprintf(
					/* translators: %s: optional timestamp instruction */
					__(
						'Please analyze this video and provide a detailed description including:
1. Main subjects and objects in the video
2. Actions and activities taking place
3. Setting and environment
4. Notable visual elements or transitions
5. Overall tone and mood
6. Any text or graphics visible in the video%s',
						'wp-mcp-ai'
					),
					$timestamp_instruction
				);
		}
	}

	/**
	 * Call a video-capable vision model.
	 *
	 * Delegates to the Video Analysis Service for proper SoC.
	 *
	 * @param string   $video_url     URL of the video to analyze.
	 * @param string   $prompt        Analysis prompt.
	 * @param string   $provider      AI provider to use.
	 * @param int|null $attachment_id WordPress attachment ID if available.
	 * @return array|WP_Error Response with text, usage, model, and provider.
	 */
	protected function call_video_model( $video_url, $prompt, $provider, $attachment_id = null ) {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-video-analysis-service.php';

		$service = new WP_MCP_AI_Video_Analysis_Service();

		$result = $service->analyze_video(
			array(
				'video_url'     => $video_url,
				'attachment_id' => $attachment_id,
				'prompt'        => $prompt,
				'provider'      => $provider,
			)
		);

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials',  // Requires AI provider API key.
			'requires-video-model',  // Requires video-capable AI model.
			'read-only',             // Only reads/analyzes data.
			'external-api',          // Makes external API requests.
			'network-dependent',     // Requires internet connection.
			'consumes-tokens',       // Uses AI tokens/credits.
			'model-dependent',       // Behavior varies by model.
			'async',                 // May take significant time.
			'rate-limited',          // Subject to API rate limits.
		);
	}
}
