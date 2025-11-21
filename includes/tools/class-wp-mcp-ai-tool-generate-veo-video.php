<?php
/**
 * Tool for generating videos using Gemini Veo 3.1.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';

/**
 * Generates videos from text prompts using Google's Veo 3.1 model.
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
		return __( 'Generates realistic videos from text descriptions using Google\'s Veo 3.1 model. Supports text-to-video and image-to-video generation with cinematic quality output.', 'wp-mcp-ai' );
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
					'description' => __( 'Video duration in seconds (1-8). Default is 5 seconds.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 8,
					'default'     => 5,
				),
				'aspect_ratio'       => array(
					'type'        => 'string',
					'description' => __( 'Video aspect ratio. Use "16:9" for landscape (default) or "9:16" for vertical/portrait videos.', 'wp-mcp-ai' ),
					'enum'        => array( '16:9', '9:16' ),
					'default'     => '16:9',
				),
				'resolution'         => array(
					'type'        => 'string',
					'description' => __( 'Video resolution. "720p" (default) or "1080p". Note: 1080p only available for 16:9 aspect ratio.', 'wp-mcp-ai' ),
					'enum'        => array( '720p', '1080p' ),
					'default'     => '720p',
				),
				'style'              => array(
					'type'        => 'string',
					'description' => __( 'Visual style preset: "cinematic", "realistic", "anime", "documentary", "artistic". This enhances the prompt with style-specific language.', 'wp-mcp-ai' ),
					'enum'        => array( 'cinematic', 'realistic', 'anime', 'documentary', 'artistic', 'none' ),
					'default'     => 'none',
				),
				'generate_audio'     => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to generate audio/sound effects for the video. Default is false (silent video).', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'enhance_prompt'     => array(
					'type'        => 'boolean',
					'description' => __( 'Enable automatic prompt enhancement by Gemini to improve video quality. Default is true.', 'wp-mcp-ai' ),
					'default'     => true,
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

		// Enhance prompt with style if specified.
		$prompt = $this->enhance_prompt_with_style( $arguments );

		// Prepare generation arguments.
		$generation_args = array(
			'prompt'         => $prompt,
			'duration'       => isset( $arguments['duration'] ) ? absint( $arguments['duration'] ) : 5,
			'aspect_ratio'   => isset( $arguments['aspect_ratio'] ) ? $arguments['aspect_ratio'] : '16:9',
			'resolution'     => isset( $arguments['resolution'] ) ? $arguments['resolution'] : '720p',
			'generate_audio' => isset( $arguments['generate_audio'] ) ? (bool) $arguments['generate_audio'] : false,
			'enhance_prompt' => isset( $arguments['enhance_prompt'] ) ? (bool) $arguments['enhance_prompt'] : true,
		);

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

		// Save to Media Library if requested.
		$save_to_media = isset( $arguments['save_to_media'] ) ? (bool) $arguments['save_to_media'] : true;

		if ( $save_to_media ) {
			$attachment_id = $this->save_video_to_media( $result, $user_id );

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			return array(
				'success'       => true,
				'attachment_id' => $attachment_id,
				'url'           => wp_get_attachment_url( $attachment_id ),
				'prompt'        => $result['prompt'],
				'duration'      => $result['duration'],
				'aspect_ratio'  => $result['aspect_ratio'],
				'resolution'    => $result['resolution'],
				'model'         => $result['model'],
				'provider'      => $result['provider'],
				'message'       => sprintf(
					/* translators: %d: attachment ID */
					__( 'Video generated successfully and saved as attachment ID %d.', 'wp-mcp-ai' ),
					$attachment_id
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
			'cinematic'    => 'Cinematic shot with professional lighting and composition: ',
			'realistic'    => 'Photorealistic footage with natural lighting and authentic details: ',
			'anime'        => 'Anime-style animation with vibrant colors and expressive characters: ',
			'documentary'  => 'Documentary-style footage with natural, observational cinematography: ',
			'artistic'     => 'Artistic interpretation with creative visual style and unique perspective: ',
		);

		if ( isset( $style_prefixes[ $style ] ) ) {
			$prompt = $style_prefixes[ $style ] . $prompt;
		}

		return $prompt;
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

		return $attachment_id;
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
			'rate-limited',         // Subject to API rate limits (15 RPM, 100 RPH).
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
