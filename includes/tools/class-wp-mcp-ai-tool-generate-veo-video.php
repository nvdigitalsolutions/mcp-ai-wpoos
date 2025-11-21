<?php
/**
 * Tool for generating videos using Gemini Veo models with automatic fallback.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';

/**
 * Generates videos from text prompts using Google's Veo models.
 * 
 * Uses Veo 3.1 by default with automatic fallback to Veo 2.0 when:
 * - Veo 3.1 is unavailable
 * - Quota limits are reached
 * - Rate limits are exceeded
 */
class WP_MCP_AI_Tool_Generate_Veo_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_veo_video';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Video with Veo', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates realistic videos from text descriptions using Google\'s Veo models. Automatically uses Veo 3.1 (preferred) with fallback to Veo 2.0 if quota limits are reached or the model is unavailable. Supports text-to-video and image-to-video generation with cinematic quality output. Note: Veo 3.1 supports up to 1080p resolution and 4-8 second videos; Veo 2.0 supports up to 720p and 5-8 second videos. Audio generation is not currently supported. All generated videos include Google\'s SynthID watermark for AI provenance.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'             => array(
					'type'        => 'string',
					'description' => __( 'Detailed description of the video to generate. Use cinematic language (e.g., "wide shot", "golden hour", "slow zoom"). Be specific about subjects, actions, setting, lighting, and camera movements.', 'wp-mcp-ai' ),
				),
				'duration'           => array(
					'type'        => 'integer',
					'description' => __( 'Video duration in seconds (4-8 for Veo 3.1, 5-8 for Veo 2.0). Default is 4 seconds. Note: 1080p resolution requires exactly 8 seconds and is only available with Veo 3.1.', 'wp-mcp-ai' ),
					'minimum'     => 4,
					'maximum'     => 8,
					'default'     => 4,
				),
				'aspect_ratio'       => array(
					'type'        => 'string',
					'description' => __( 'Video aspect ratio. Use "16:9" for landscape (default) or "9:16" for vertical/portrait videos.', 'wp-mcp-ai' ),
					'enum'        => array( '16:9', '9:16' ),
					'default'     => '16:9',
				),
				'resolution'         => array(
					'type'        => 'string',
					'description' => __( 'Video resolution. "720p" (default, supported by all models) or "1080p" (Veo 3.1 only). Note: 1080p only available for 16:9 aspect ratio and requires 8 seconds duration. Veo 2.0 always outputs 720p regardless of this parameter.', 'wp-mcp-ai' ),
					'enum'        => array( '720p', '1080p' ),
					'default'     => '720p',
				),
				'style'              => array(
					'type'        => 'string',
					'description' => __( 'Visual style preset: "cinematic", "realistic", "anime", "documentary", "artistic". This enhances the prompt with style-specific language.', 'wp-mcp-ai' ),
					'enum'        => array( 'cinematic', 'realistic', 'anime', 'documentary', 'artistic', 'none' ),
					'default'     => 'none',
				),
				'negative_prompt'    => array(
					'type'        => 'string',
					'description' => __( 'What to avoid in the video (e.g., "blurry, low quality, distorted").', 'wp-mcp-ai' ),
				),
				'reference_image_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of a reference image to guide video generation (optional).', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'seed'               => array(
					'type'        => 'integer',
					'description' => __( 'Random seed for reproducible results. Use the same seed and prompt to generate similar videos.', 'wp-mcp-ai' ),
					'minimum'     => 0,
				),
				'save_to_media'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to save the generated video to WordPress Media Library. Default is true.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'model'              => array(
					'type'        => 'string',
					'description' => __( 'Force a specific Veo model: "veo-3.1" (default, supports 1080p) or "veo-2.0" (720p max). If not specified, automatically uses Veo 3.1 with fallback to Veo 2.0 on quota/availability issues.', 'wp-mcp-ai' ),
					'enum'        => array( 'veo-3.1', 'veo-2.0' ),
				),
			),
			'required'             => array( 'prompt' ),
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
				__( 'You do not have permission to generate videos.', 'wp-mcp-ai' ),
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

		// Validate prompt.
		if ( empty( $arguments['prompt'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'Video generation requires a prompt.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Determine if async mode should be used.
		$use_async = $this->should_use_async( $arguments, $context );

		// Enhance prompt with style if specified.
		$prompt = $this->enhance_prompt_with_style( $arguments );

		// Prepare generation arguments.
		$generation_args = array(
			'prompt'       => $prompt,
			'aspect_ratio' => isset( $arguments['aspect_ratio'] ) ? $arguments['aspect_ratio'] : '16:9',
			'resolution'   => isset( $arguments['resolution'] ) ? $arguments['resolution'] : '720p',
			'async'        => $use_async,
			'user_id'      => $user_id,
		);

		// Include assistant_id from context for multi-widget isolation in cron status tracking.
		if ( isset( $context['assistant_id'] ) ) {
			$generation_args['assistant_id'] = absint( $context['assistant_id'] );
		}

		// Add duration if provided (let service apply default if not provided).
		if ( isset( $arguments['duration'] ) ) {
			$generation_args['duration'] = absint( $arguments['duration'] );
		}

		// Add optional parameters.
		if ( ! empty( $arguments['negative_prompt'] ) ) {
			$generation_args['negative_prompt'] = sanitize_textarea_field( $arguments['negative_prompt'] );
		}

		if ( isset( $arguments['seed'] ) ) {
			$generation_args['seed'] = absint( $arguments['seed'] );
		}

		// Handle reference image if provided.
		if ( ! empty( $arguments['reference_image_id'] ) ) {
			$image_data = $this->get_reference_image_data( $arguments['reference_image_id'] );
			if ( is_wp_error( $image_data ) ) {
				return $image_data;
			}
			$generation_args['image_base64']    = $image_data['base64'];
			$generation_args['image_mime_type'] = $image_data['mime_type'];
		}

		// Load the video generation service.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Generate video.
		$result = $service->generate_video( $generation_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// If async mode, return job info immediately.
		if ( isset( $result['async'] ) && $result['async'] ) {
			return $result;
		}

		// Save to Media Library if requested.
		$save_to_media = isset( $arguments['save_to_media'] ) ? (bool) $arguments['save_to_media'] : true;

		if ( $save_to_media ) {
			$save_result = $this->save_video_to_media( $result, $user_id );

			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}

			return array(
				'success'       => true,
				'attachment_id' => $save_result['attachment_id'],
				'url'           => $save_result['url'],
				'prompt'        => $result['prompt'],
				'duration'      => $result['duration'],
				'aspect_ratio'  => $result['aspect_ratio'],
				'resolution'    => $result['resolution'],
				'model'         => $result['model'],
				'provider'      => $result['provider'],
				'message'       => sprintf(
					/* translators: %d: attachment ID */
					__( 'Video generated successfully and saved as attachment ID %d.', 'wp-mcp-ai' ),
					$save_result['attachment_id']
				),
			);
		}

		// Return video data URL.
		$video_base64 = base64_encode( $result['video_data'] );
		$data_url     = 'data:video/mp4;base64,' . $video_base64;

		return array(
			'success'      => true,
			'video_url'    => $data_url,
			'prompt'       => $result['prompt'],
			'duration'     => $result['duration'],
			'aspect_ratio' => $result['aspect_ratio'],
			'resolution'   => $result['resolution'],
			'model'        => $result['model'],
			'provider'     => $result['provider'],
			'message'      => __( 'Video generated successfully (temporary - not saved to Media Library).', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Enhance prompt with style-specific language
	 *
	 * @param array $arguments Tool arguments.
	 * @return string Enhanced prompt.
	 */
	protected function enhance_prompt_with_style( $arguments ) {
		$prompt = sanitize_textarea_field( $arguments['prompt'] );
		$style  = isset( $arguments['style'] ) ? $arguments['style'] : 'none';

		if ( 'none' === $style || empty( $style ) ) {
			return $prompt;
		}

		$style_prefixes = array(
			'cinematic'   => 'Cinematic shot with professional lighting and composition: ',
			'realistic'   => 'Photorealistic footage with natural lighting and authentic details: ',
			'anime'       => 'Anime-style animation with vibrant colors and expressive characters: ',
			'documentary' => 'Documentary-style footage with natural, observational cinematography: ',
			'artistic'    => 'Artistic interpretation with creative visual style and unique perspective: ',
		);

		if ( isset( $style_prefixes[ $style ] ) ) {
			$prompt = $style_prefixes[ $style ] . $prompt;
		}

		return $prompt;
	}

	/**
	 * Determine if async mode should be used
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return bool True if async mode should be used.
	 */
	protected function should_use_async( $arguments, $context = array() ) {
		// CRITICAL: If already running in async executor context, do NOT use tool-level async.
		// This prevents double-async execution where orchestrator queues the tool async,
		// then the tool itself queues another async job. This causes the client to get
		// a nested async response it doesn't know how to handle.
		if ( isset( $context['in_async_executor'] ) && $context['in_async_executor'] ) {
			return false;
		}

		// Check if explicitly set in arguments.
		if ( isset( $arguments['async'] ) ) {
			return (bool) $arguments['async'];
		}

		// Default to async for better reliability.
		// Video generation typically takes 60-120 seconds which often exceeds HTTP timeouts.
		// NOTE: We no longer check agentic_loop here because the REST API orchestrator
		// will handle async execution properly via the async executor when the tool
		// is marked as 'background-only', which is the correct pattern for long-running tools.
		return true;
	}

	/**
	 * Get reference image data for video generation
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error Array with base64 and mime_type or error.
	 */
	protected function get_reference_image_data( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		// Check if attachment exists.
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_image_not_found',
				__( 'Reference image not found.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		// Verify it's an image.
		$mime_type = get_post_mime_type( $attachment_id );
		if ( ! $mime_type || false === strpos( $mime_type, 'image/' ) ) {
			return new WP_Error(
				'wp_mcp_ai_not_image',
				__( 'The provided attachment is not an image.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Read and encode image.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$image_data = file_get_contents( $file_path );

		if ( false === $image_data ) {
			return new WP_Error(
				'wp_mcp_ai_read_failed',
				__( 'Failed to read reference image.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'base64'    => base64_encode( $image_data ),
			'mime_type' => $mime_type,
		);
	}

	/**
	 * Save generated video to Media Library
	 *
	 * @param array $result Video generation result.
	 * @param int   $user_id User ID for ownership.
	 * @return int|WP_Error Attachment ID or error.
	 */
	protected function save_video_to_media( $result, $user_id ) {
		// Generate unique filename.
		$filename = 'veo-video-' . uniqid( '', true ) . '.mp4';

		// Upload video.
		$upload = wp_upload_bits( $filename, null, $result['video_data'] );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				$upload['error'],
				array( 'status' => 500 )
			);
		}

		// Create attachment.
		$attachment = array(
			'post_mime_type' => 'video/mp4',
			'post_title'     => sprintf(
				/* translators: %s: truncated prompt */
				__( 'Veo Generated Video: %s', 'wp-mcp-ai' ),
				substr( $result['prompt'], 0, 50 )
			),
			'post_content'   => $result['prompt'],
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Add metadata.
		$metadata = array(
			'veo_prompt'       => $result['prompt'],
			'veo_duration'     => $result['duration'],
			'veo_aspect_ratio' => $result['aspect_ratio'],
			'veo_resolution'   => $result['resolution'],
			'veo_model'        => $result['model'],
			'veo_provider'     => $result['provider'],
		);

		foreach ( $metadata as $key => $value ) {
			update_post_meta( $attachment_id, '_' . $key, $value );
		}

		// Generate attachment metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		WP_MCP_AI_Logger::log_event(
			'veo_video_saved',
			'Veo generated video saved to Media Library',
			array(
				'attachment_id' => $attachment_id,
				'duration'      => $result['duration'],
			)
		);

		// Return attachment result with local WordPress URL.
		// Uses utility class for SoC compliance and code reusability.
		return WP_MCP_AI_Media_URL_Utils::build_attachment_result( $attachment_id, $upload );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials', // Requires Gemini API key.
			'requires-capability',  // Requires upload_files capability.
			'write',                // Creates video files.
			'external-api',         // Makes external API requests.
			'network-dependent',    // Requires internet connection.
			'consumes-tokens',      // Uses AI credits.
			'async',                // Takes significant time (60-120 seconds).
			'long-running',         // Video generation is async.
			'background-only',      // Must run in background even in agentic loops (prevents HTTP timeouts).
			'rate-limited',         // Subject to API rate limits (10 RPM for preview, higher for paid tiers).
			'may-timeout',          // May exceed typical HTTP timeouts.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array( 'video-generation' );
	}
}
