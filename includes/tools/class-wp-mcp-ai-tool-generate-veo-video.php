<?php
/**
 * Tool for generating videos using Gemini Veo models with automatic fallback.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-async-metadata.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';

/**
 * Generates videos from text prompts using Google's Veo models.
 *
 * Uses Veo 3.1 by default with automatic fallback to Veo 2.0 when:
 * - Veo 3.1 is unavailable
 * - Quota limits are reached
 * - Rate limits are exceeded
 */
class WP_MCP_AI_Tool_Generate_Veo_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Async_Metadata_Interface {
	use WP_MCP_AI_Attachment_File_Resolver;
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
		return __( 'Generates realistic videos from text descriptions using Google\'s Veo models. Automatically uses Veo 3.1 (preferred) with fallback to Veo 2.0 if quota limits are reached or the model is unavailable. Supports text-to-video and image-to-video generation with cinematic quality output. Duration: 4-8 seconds (Veo 3.1 supports 4-8 seconds, Veo 2.0 supports 5-8 seconds). Note: Veo 3.1 supports up to 1080p resolution; Veo 2.0 supports up to 720p. 1080p videos require exactly 8 seconds duration. Audio generation is not currently supported. All generated videos include Google\'s SynthID watermark for AI provenance.', 'wp-mcp-ai' );
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
					'description' => __( 'Video duration in seconds (4-8). Default is 5 seconds. Veo 3.1 supports 4-8 seconds, Veo 2.0 supports 5-8 seconds. Note: 1080p resolution requires exactly 8 seconds and is only available with Veo 3.1.', 'wp-mcp-ai' ),
					'minimum'     => 4,
					'maximum'     => 8,
					'default'     => 5,
				),
				'aspect_ratio'       => array(
					'type'        => 'string',
					'description' => __( 'Video aspect ratio. Supported values: "3:2" for landscape (default), "2:3" for portrait, "1:1" for square, or "auto" to let the model decide.', 'wp-mcp-ai' ),
					'enum'        => array( '1:1', '2:3', '3:2', 'auto' ),
					'default'     => '3:2',
				),
				'resolution'         => array(
					'type'        => 'string',
					'description' => __( 'Video resolution. "720p" (default, supported by all models) or "1080p" (Veo 3.1 only). Note: 1080p requires 8 seconds duration and is only available with Veo 3.1. Veo 2.0 always outputs 720p regardless of this parameter.', 'wp-mcp-ai' ),
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
				'reference_image_file_id' => $this->get_file_id_parameter_schema(),
				'reference_image_url'     => $this->get_url_parameter_schema( 'image', __( 'URL of a reference image to guide video generation (optional).', 'wp-mcp-ai' ) ),
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

		// Get default settings from admin configuration.
		$defaults = $this->get_default_video_settings();

		// Prepare generation arguments with settings fallback.
		$generation_args = array(
			'prompt'       => $prompt,
			'aspect_ratio' => isset( $arguments['aspect_ratio'] ) ? $arguments['aspect_ratio'] : $defaults['aspect_ratio'],
			'resolution'   => isset( $arguments['resolution'] ) ? $arguments['resolution'] : $defaults['resolution'],
			'async'        => $use_async,
			'user_id'      => $user_id,
		);

		// Pass assistant_id if available in context for proper completion hook routing.
		if ( isset( $context['assistant_id'] ) ) {
			$generation_args['assistant_id'] = absint( $context['assistant_id'] );
		}

		// Pass parent_job_id if available (when called from async executor).
		// This allows the veo service to complete the parent async job when video generation finishes.
		if ( isset( $context['parent_job_id'] ) ) {
			$generation_args['parent_job_id'] = sanitize_key( $context['parent_job_id'] );
		}

		// Pass in_async_executor flag to prevent dual async.
		// This tells the service to NOT fall back to async mode during polling.
		if ( isset( $context['in_async_executor'] ) && $context['in_async_executor'] ) {
			$generation_args['in_async_executor'] = true;
		}

		// Add duration if provided and valid.
		// Only pass to service if it's a positive integer, otherwise use settings default.
		// This prevents sending 0 or invalid values when OpenAI sends null/false/empty.
		if ( isset( $arguments['duration'] ) ) {
			$duration = absint( $arguments['duration'] );
			if ( $duration > 0 ) {
				$generation_args['duration'] = $duration;
			}
		} else {
			// Use settings default duration.
			$generation_args['duration'] = $defaults['duration'];
		}

		// Add model if provided, otherwise use settings default.
		if ( ! empty( $arguments['model'] ) ) {
			$generation_args['model'] = sanitize_text_field( $arguments['model'] );
		} elseif ( ! empty( $defaults['model'] ) ) {
			$generation_args['model'] = $defaults['model'];
		}

		// Add optional parameters.
		if ( ! empty( $arguments['negative_prompt'] ) ) {
			$generation_args['negative_prompt'] = sanitize_textarea_field( $arguments['negative_prompt'] );
		}

		if ( isset( $arguments['seed'] ) ) {
			$generation_args['seed'] = absint( $arguments['seed'] );
		}

		// Handle reference image if provided.
		$reference_image_id = 0;
		
		// Try to resolve from reference_image_id, reference_image_file_id, or reference_image_url.
		if ( ! empty( $arguments['reference_image_id'] ) || ! empty( $arguments['reference_image_file_id'] ) || ! empty( $arguments['reference_image_url'] ) ) {
			// Temporarily map to standard parameter names for the resolver.
			$temp_args = array();
			if ( ! empty( $arguments['reference_image_id'] ) ) {
				$temp_args['attachment_id'] = $arguments['reference_image_id'];
			}
			if ( ! empty( $arguments['reference_image_file_id'] ) ) {
				$temp_args['file_id'] = $arguments['reference_image_file_id'];
			}
			if ( ! empty( $arguments['reference_image_url'] ) ) {
				$temp_args['url'] = $arguments['reference_image_url'];
			}
			
			$resolved = $this->resolve_attachment_id( $temp_args );
			
			// Handle remote URL case.
			if ( is_array( $resolved ) && isset( $resolved['url'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_remote_url_not_supported',
					__( 'Remote URLs are not yet supported for reference images. Please upload to Media Library first.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}
			
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			
			if ( $resolved > 0 ) {
				$reference_image_id = $resolved;
			}
		}
		
		if ( $reference_image_id > 0 ) {
			$image_data = $this->get_reference_image_data( $reference_image_id );
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

		// Calculate cost for video generation.
		// Veo models are charged per second of generated video.
		$cost = $this->calculate_video_cost( $result );

		// Save to Media Library if requested.
		$save_to_media = isset( $arguments['save_to_media'] ) ? (bool) $arguments['save_to_media'] : true;

		if ( $save_to_media ) {
			// Pass parent_job_id if available to ensure filename matches job ID.
			$job_id      = isset( $context['parent_job_id'] ) ? sanitize_key( $context['parent_job_id'] ) : '';
			$save_result = $this->save_video_to_media( $result, $user_id, $job_id );

			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}

			// Generate media library edit link.
			$edit_url = admin_url( 'post.php?post=' . $save_result['attachment_id'] . '&action=edit' );

			// Build descriptive text message for the LLM and chat UI (mirrors generate_gemini_image pattern).
			$text_parts   = array();
			$text_parts[] = sprintf(
				/* translators: %d: attachment ID */
				__( 'Successfully generated video (ID: %d).', 'wp-mcp-ai' ),
				$save_result['attachment_id']
			);

			$text_parts[] = sprintf(
				/* translators: 1: duration in seconds, 2: resolution, 3: aspect ratio */
				__( 'Format: %1$ds, %2$s, %3$s', 'wp-mcp-ai' ),
				$result['duration'],
				$result['resolution'],
				$result['aspect_ratio']
			);

			return array(
				'success'       => true,
				'attachment_id' => $save_result['attachment_id'],
				'url'           => $save_result['url'],
				'file_name'     => isset( $save_result['file_name'] ) ? $save_result['file_name'] : '',
				'edit_url'      => $edit_url,
				'prompt'        => $result['prompt'],
				'duration'      => $result['duration'],
				'aspect_ratio'  => $result['aspect_ratio'],
				'resolution'    => $result['resolution'],
				'model'         => $result['model'],
				'provider'      => $result['provider'],
				'cost'          => $cost,
				'message'       => sprintf(
					/* translators: 1: attachment ID, 2: media library edit URL */
					__( 'Video generated successfully and saved as <a href="%2$s" target="_blank">attachment ID %1$d</a>.', 'wp-mcp-ai' ),
					$save_result['attachment_id'],
					esc_url( $edit_url )
				),
				'text'          => implode( ' ', $text_parts ), // Descriptive message for LLM and chat UI.
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
			'cost'         => $cost,
			'message'      => __( 'Video generated successfully (temporary - not saved to Media Library).', 'wp-mcp-ai' ),
			'text'         => sprintf(
				/* translators: 1: duration in seconds, 2: resolution, 3: aspect ratio */
				__( 'Successfully generated temporary video. Format: %1$ds, %2$s, %3$s', 'wp-mcp-ai' ),
				$result['duration'],
				$result['resolution'],
				$result['aspect_ratio']
			),
		);
	}

	/**
	 * Get default video settings from admin configuration.
	 *
	 * Retrieves settings for video model, resolution, aspect ratio, and duration
	 * that were configured in the Gemini provider settings panel.
	 *
	 * @return array Default settings with model, resolution, aspect_ratio, and duration.
	 */
	protected function get_default_video_settings() {
		$defaults = array(
			'model'        => 'veo-2.0-generate-001', // Conservative default.
			'resolution'   => '720p',
			'aspect_ratio' => '3:2',
			'duration'     => 5,
		);

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( ! empty( $settings['gemini_video_model'] ) ) {
				$defaults['model'] = sanitize_text_field( $settings['gemini_video_model'] );
			}

			if ( ! empty( $settings['gemini_video_resolution'] ) ) {
				$defaults['resolution'] = sanitize_text_field( $settings['gemini_video_resolution'] );
			}

			if ( ! empty( $settings['gemini_video_aspect_ratio'] ) ) {
				$defaults['aspect_ratio'] = sanitize_text_field( $settings['gemini_video_aspect_ratio'] );
			}

			if ( ! empty( $settings['gemini_video_duration'] ) ) {
				$duration = absint( $settings['gemini_video_duration'] );
				if ( $duration >= 4 && $duration <= 8 ) {
					$defaults['duration'] = $duration;
				}
			}
		}

		/**
		 * Allow third parties to filter the default Veo video settings.
		 *
		 * @param array $defaults Default settings array.
		 */
		return apply_filters( 'wp_mcp_ai_veo_default_settings', $defaults );
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
	 * @param array  $result  Video generation result.
	 * @param int    $user_id User ID for ownership.
	 * @param string $job_id  Optional. Job ID for tracking and filename consistency.
	 *                        When provided, the filename will be based on the job_id
	 *                        to enable proper correlation between job IDs and files.
	 * @return array|WP_Error Attachment result array or error.
	 */
	protected function save_video_to_media( $result, $user_id, $job_id = '' ) {
		// Generate filename based on job_id if provided, otherwise use unique ID.
		// This ensures filename matches the job ID for proper correlation.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		if ( ! empty( $job_id ) ) {
			$filename = 'veo-video-' . sanitize_file_name( $job_id ) . '.mp4';
		} else {
			$filename = 'veo-video-' . WP_MCP_AI_Gemini_Video_Generation_Service::generate_clean_unique_id() . '.mp4';
		}

		// Include WordPress file functions for wp_upload_bits() if not already loaded.
		// This is required in cron/async contexts where admin files aren't loaded.
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

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

		// Store job_id if provided - allows recovery of completion status when transient expires.
		if ( ! empty( $job_id ) ) {
			$metadata['veo_job_id'] = sanitize_key( $job_id );
		}

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
				'filename'      => $filename,
				'duration'      => $result['duration'],
				'job_id'        => $job_id,
			)
		);

		// Return attachment result with local WordPress URL.
		// Uses utility class for SoC compliance and code reusability.
		return WP_MCP_AI_Media_URL_Utils::build_attachment_result( $attachment_id, $upload );
	}

	/**
	 * Calculate cost for video generation
	 *
	 * Veo models are charged per second of generated video.
	 * Uses the cost calculator to get current pricing.
	 *
	 * @param array $result Video generation result with duration, model, and provider.
	 * @return array Cost data array with cost_usd, provider, model, and is_estimated.
	 */
	protected function calculate_video_cost( $result ) {
		// Get duration in seconds.
		$duration = isset( $result['duration'] ) ? absint( $result['duration'] ) : 0;

		// Get model identifier.
		$model = isset( $result['model'] ) ? $result['model'] : 'unknown';

		// Default cost structure.
		$cost = array(
			'cost_usd'     => 0.0,
			'provider'     => isset( $result['provider'] ) ? $result['provider'] : 'gemini',
			'model'        => $model,
			'is_estimated' => false,
		);

		// No cost if duration is invalid.
		if ( $duration <= 0 ) {
			return $cost;
		}

		// Load cost calculator.
		if ( ! class_exists( 'WP_MCP_AI_Cost_Calculator' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cost-calculator.php';
		}

		// Get pricing for the model.
		$pricing = WP_MCP_AI_Cost_Calculator::get_model_pricing( 'gemini', $model );

		// Check if model supports per_second pricing (Veo models).
		if ( isset( $pricing['per_second'] ) ) {
			$cost_per_second  = (float) $pricing['per_second'];
			$cost['cost_usd'] = round( $cost_per_second * $duration, 6 );
		} else {
			// Model doesn't have per_second pricing - mark as estimated with $0.
			// This shouldn't happen for Veo models, but provides fallback.
			$cost['is_estimated'] = true;
		}

		return $cost;
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

	/**
	 * Sanitize video generation results for LLM consumption.
	 *
	 * Video generation can return base64-encoded video data in data URLs that can be
	 * several megabytes in size. The LLM doesn't need this binary data - it only needs
	 * metadata to reference the generated video (attachment_id, url, etc.).
	 *
	 * For videos saved to the Media Library, we add a video_url structure similar to
	 * how generate_gemini_image adds image_url. This allows the chat client to display
	 * the video inline with a video player.
	 *
	 * @param mixed $result Tool execution result.
	 * @return mixed Sanitized result with only metadata.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Strip base64-encoded video data URL if present.
		// This is set when save_to_media=false and can be several MB.
		if ( isset( $result['video_url'] ) && is_string( $result['video_url'] ) ) {
			// Check if it's a base64 data URL.
			if ( strpos( $result['video_url'], 'data:video/' ) === 0 ) {
				// Strip the data URL but keep a reference that one existed.
				unset( $result['video_url'] );
				$result['video_data_stripped'] = true;
			}
		}

		// Keep only essential metadata.
		$keep_fields = array(
			'success',
			'attachment_id',
			'url',
			'file_name',           // Filename of the generated video.
			'edit_url',            // Media library edit link.
			'async',               // Async mode flag - CRITICAL for UI detection.
			'status',              // Job status (pending/completed/failed) - CRITICAL for UI.
			'job_id',              // Async job identifier (veo_*).
			'parent_job_id',       // Parent async job identifier (async_*).
			'expected_filename',   // Pre-generated filename for pending videos.
			'expected_url',        // Expected URL where video will be available.
			'prompt',
			'duration',
			'aspect_ratio',
			'resolution',
			'model',
			'provider',
			'message',
			'video_data_stripped', // Flag indicating data was stripped.
			'usage',               // Token usage data for UI display.
			'cost',                // Cost data for UI display.
			'text',                // Descriptive message for LLM and chat UI.
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		// Add video_url structure for the chat client to display the video inline.
		// This mirrors how generate_gemini_image adds image_url for the agentic loop.
		// The chat client uses isVideoAttachment() to detect video URLs and render a video player.
		// For pending async results, use expected_url if url is not yet available.
		// This allows the chat client to display a placeholder video element.
		$video_url = '';
		if ( isset( $result['url'] ) && '' !== $result['url'] ) {
			$video_url = $result['url'];
		} elseif ( isset( $result['expected_url'] ) && '' !== $result['expected_url'] ) {
			$video_url = $result['expected_url'];
		}

		if ( '' !== $video_url ) {
			$sanitized['video_url'] = array(
				'url' => $video_url,
			);
		}

		return ! empty( $sanitized ) ? $sanitized : $result;
	}

	/**
	 * Get pre-execution metadata for async pending response.
	 *
	 * When the orchestrator queues this tool for async execution, this method provides
	 * the expected_url and expected_filename so the chat UI can display a placeholder
	 * video element immediately, before the video is actually generated.
	 *
	 * @param string $job_id    The async job identifier (e.g., 'async_abc123').
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array Metadata including expected_url and expected_filename.
	 */
	public function get_async_pending_metadata( $job_id, array $arguments = array(), array $context = array() ) {
		// Generate the expected filename based on the job ID.
		// This matches the pattern used in save_video_to_media() and the video generation service.
		// Using sanitize_file_name() for consistency with save_video_to_media().
		$expected_filename = 'veo-video-' . sanitize_file_name( $job_id ) . '.mp4';

		// Generate expected URL based on WordPress upload directory.
		$expected_url = '';
		$upload_dir   = wp_upload_dir();
		if ( ! empty( $upload_dir['url'] ) && empty( $upload_dir['error'] ) ) {
			$expected_url = trailingslashit( $upload_dir['url'] ) . $expected_filename;
		} else {
			// Log when upload directory is not available.
			// This can happen when uploads are disabled or there's a permissions issue.
			WP_MCP_AI_Logger::log_warning(
				'veo_upload_dir_unavailable',
				'Cannot generate expected_url: upload directory not available',
				array(
					'job_id' => $job_id,
					'error'  => isset( $upload_dir['error'] ) ? $upload_dir['error'] : 'Unknown error',
				)
			);
		}

		// Build a descriptive message for the pending state.
		$message = sprintf(
			/* translators: 1: expected filename, 2: job ID */
			__( 'Video generation started. Your video (%1$s) is being created and will be available within approximately 5 minutes. Job ID: %2$s', 'wp-mcp-ai' ),
			$expected_filename,
			$job_id
		);

		return array(
			'expected_url'      => $expected_url,
			'expected_filename' => $expected_filename,
			'message'           => $message,
		);
	}
}
