<?php
/**
 * Tool for analyzing video content using AI vision capabilities.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';

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
				'video_url'     => array(
					'type'        => 'string',
					'description' => __( 'URL of the video to analyze. Supports MP4 and QuickTime formats.', 'wp-mcp-ai' ),
				),
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the video to analyze.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'prompt'        => array(
					'type'        => 'string',
					'description' => __( 'Specific question or analysis prompt for the video content. If not provided, a general description will be generated.', 'wp-mcp-ai' ),
				),
				'context'       => array(
					'type'        => 'string',
					'description' => __( 'Optional context about the video to help generate more relevant analysis.', 'wp-mcp-ai' ),
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

		// Build prompt.
		$user_prompt  = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$user_context = isset( $arguments['context'] ) ? sanitize_text_field( $arguments['context'] ) : '';
		$prompt       = $this->build_prompt( $user_prompt, $user_context );

		// Call video-capable vision model and capture metadata.
		$api_response = $this->call_video_model( $video_url, $prompt, $default_provider );

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
	 * @param string $user_prompt  User's specific question or prompt.
	 * @param string $user_context Additional context about the video.
	 * @return string
	 */
	protected function build_prompt( $user_prompt, $user_context ) {
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

		// Default comprehensive analysis prompt.
		$prompt = __(
			'Please analyze this video and provide a detailed description including:
1. Main subjects and objects in the video
2. Actions and activities taking place
3. Setting and environment
4. Notable visual elements or transitions
5. Overall tone and mood
6. Any text or graphics visible in the video',
			'wp-mcp-ai'
		);

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
	 * Call a video-capable vision model.
	 *
	 * @param string $video_url URL of the video to analyze.
	 * @param string $prompt    Analysis prompt.
	 * @param string $provider  AI provider to use.
	 * @return array|WP_Error Response with text, usage, model, and provider.
	 */
	protected function call_video_model( $video_url, $prompt, $provider ) {
		// Gemini is the primary provider with native video support.
		if ( 'gemini' === $provider || 'google' === $provider ) {
			return $this->call_gemini_video( $video_url, $prompt );
		}

		// GPT-4o supports video frames (extract frames and analyze).
		if ( 'openai' === $provider ) {
			return $this->call_openai_video_frames( $video_url, $prompt );
		}

		return new WP_Error(
			'wp_mcp_ai_unsupported_provider',
			sprintf(
				/* translators: %s: provider name */
				__( 'Video analysis is not supported for provider: %s. Please use Gemini or OpenAI.', 'wp-mcp-ai' ),
				$provider
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Call Gemini API with video support.
	 *
	 * Note: This is a placeholder implementation. Full video support requires
	 * uploading video files to Gemini File API first, then referencing them.
	 * For now, this returns a helpful error message.
	 *
	 * @param string $video_url URL of the video.
	 * @param string $prompt    Analysis prompt.
	 * @return array|WP_Error
	 */
	protected function call_gemini_video( $video_url, $prompt ) {
		// TODO: Implement full Gemini video support via File API.
		// This requires:
		// 1. Upload video to Gemini File API
		// 2. Get file reference
		// 3. Include fileData in message content
		// 4. Poll for completion if needed
		//
		// For now, return error with instructions.
		
		return new WP_Error(
			'wp_mcp_ai_video_not_fully_implemented',
			sprintf(
				/* translators: %s: video URL */
				__( 'Video analysis for Gemini requires uploading the video file to the Gemini File API first. Direct URL analysis is not yet supported. Video URL: %s', 'wp-mcp-ai' ),
				$video_url
			),
			array(
				'status' => 501,
				'next_steps' => array(
					__( 'Download the video file', 'wp-mcp-ai' ),
					__( 'Upload to WordPress media library', 'wp-mcp-ai' ),
					__( 'Use attachment_id parameter instead of video_url', 'wp-mcp-ai' ),
					__( 'Alternatively, wait for File API integration to be completed', 'wp-mcp-ai' ),
				),
			)
		);
	}

	/**
	 * Call OpenAI with extracted video frames.
	 *
	 * This is a fallback for providers that don't support native video.
	 * Note: This requires frame extraction which is not yet implemented.
	 *
	 * @param string $video_url URL of the video.
	 * @param string $prompt    Analysis prompt.
	 * @return array|WP_Error
	 */
	protected function call_openai_video_frames( $video_url, $prompt ) {
		return new WP_Error(
			'wp_mcp_ai_not_implemented',
			__( 'Video frame extraction for OpenAI is not yet implemented. Please use Gemini for video analysis.', 'wp-mcp-ai' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Check if a Gemini model supports video.
	 *
	 * @param string $model Model identifier.
	 * @return bool
	 */
	protected function is_video_capable_gemini_model( $model ) {
		// Gemini 2.0 and later support video.
		$video_capable_models = array(
			'gemini-2.0-flash-exp',
			'gemini-2.5-flash',
			'gemini-exp-1206',
		);

		return in_array( $model, $video_capable_models, true );
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
