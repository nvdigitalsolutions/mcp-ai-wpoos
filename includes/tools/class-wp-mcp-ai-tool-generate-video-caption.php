<?php
/**
 * Tool for generating captions for videos using AI vision capabilities.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';

/**
 * Generates descriptive captions for videos using AI vision models with video understanding.
 */
class WP_MCP_AI_Tool_Generate_Video_Caption implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_video_caption';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Video Caption', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates concise, descriptive captions for videos to provide context and enhance accessibility using AI vision models with video understanding capabilities.', 'wp-mcp-ai' );
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
					'description' => __( 'URL of the video to caption. Supports MP4 and QuickTime formats.', 'wp-mcp-ai' ),
				),
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the video to caption.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'context'       => array(
					'type'        => 'string',
					'description' => __( 'Optional context about the video to help generate more relevant captions.', 'wp-mcp-ai' ),
				),
				'max_length'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum caption length in characters. Default is 200.', 'wp-mcp-ai' ),
					'minimum'     => 50,
					'maximum'     => 500,
					'default'     => 200,
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
				__( 'You do not have permission to generate video captions.', 'wp-mcp-ai' ),
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

		// Get parameters.
		$max_length   = isset( $arguments['max_length'] ) ? absint( $arguments['max_length'] ) : 200;
		$max_length   = max( 50, min( 500, $max_length ) ); // Clamp between 50-500.
		$user_context = isset( $arguments['context'] ) ? sanitize_text_field( $arguments['context'] ) : '';

		// Get settings.
		$settings         = get_option( 'wp_mcp_ai_settings', array() );
		$default_provider = isset( $settings['default_provider'] ) ? $settings['default_provider'] : 'gemini';

		// Build prompt.
		$prompt = $this->build_prompt( $max_length, $user_context );

		// Call video-capable vision model.
		$api_response = $this->call_video_model( $video_url, $prompt, $default_provider );

		if ( is_wp_error( $api_response ) ) {
			return $api_response;
		}

		// Extract caption and metadata.
		$caption  = is_array( $api_response ) && isset( $api_response['text'] ) ? $api_response['text'] : $api_response;
		$usage    = is_array( $api_response ) && isset( $api_response['usage'] ) ? $api_response['usage'] : null;
		$model    = is_array( $api_response ) && isset( $api_response['model'] ) ? $api_response['model'] : '';
		$provider = is_array( $api_response ) && isset( $api_response['provider'] ) ? $api_response['provider'] : $default_provider;

		// Clean and truncate caption if needed.
		$caption = trim( $caption );
		if ( strlen( $caption ) > $max_length ) {
			$caption = substr( $caption, 0, $max_length - 3 ) . '...';
		}

		$result = array(
			'caption' => $caption,
			'success' => true,
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
	 * Build the caption generation prompt.
	 *
	 * @param int    $max_length   Maximum caption length.
	 * @param string $user_context Additional context.
	 * @return string
	 */
	protected function build_prompt( $max_length, $user_context ) {
		$prompt = sprintf(
			/* translators: %d: maximum caption length */
			__(
				'Generate a concise, descriptive caption for this video in %d characters or less. The caption should briefly describe what happens in the video, including main subjects, actions, and setting. Make it engaging and informative.',
				'wp-mcp-ai'
			),
			$max_length
		);

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

	/**
	 * Call a video-capable vision model.
	 *
	 * @param string $video_url URL of the video to analyze.
	 * @param string $prompt    Caption prompt.
	 * @param string $provider  AI provider to use.
	 * @return array|WP_Error Response with text, usage, model, and provider.
	 */
	protected function call_video_model( $video_url, $prompt, $provider ) {
		// Gemini is the primary provider with native video support.
		if ( 'gemini' === $provider || 'google' === $provider ) {
			return $this->call_gemini_video( $video_url, $prompt );
		}

		return new WP_Error(
			'wp_mcp_ai_unsupported_provider',
			sprintf(
				/* translators: %s: provider name */
				__( 'Video caption generation is not supported for provider: %s. Please use Gemini.', 'wp-mcp-ai' ),
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
	 * @param string $prompt    Caption prompt.
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
				__( 'Video captioning for Gemini requires uploading the video file to the Gemini File API first. Direct URL analysis is not yet supported. Video URL: %s', 'wp-mcp-ai' ),
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
			'requires-credentials', // Requires AI provider API key.
			'requires-video-model', // Requires video-capable AI model.
			'read-only',            // Only reads/analyzes data.
			'external-api',         // Makes external API requests.
			'network-dependent',    // Requires internet connection.
			'consumes-tokens',      // Uses AI tokens/credits.
			'model-dependent',      // Behavior varies by model.
			'async',                // May take significant time.
			'rate-limited',         // Subject to API rate limits.
		);
	}
}
