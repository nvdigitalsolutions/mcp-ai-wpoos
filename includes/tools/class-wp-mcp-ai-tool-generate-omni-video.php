<?php
/**
 * Tool for generating videos using Gemini Omni Flash with Veo fallback.
 *
 * Omni replaces Veo as the primary video generation model. This tool provides
 * a unified interface that attempts Omni first and automatically falls back
 * to Veo when Omni is unavailable or encounters retryable errors.
 *
 * Key capabilities over Veo:
 * - 10-second videos (vs. 8s)
 * - Native audio (no separate TTS step needed)
 * - Up to 5 reference images
 * - Video-to-video generation (use existing video as input)
 * - Audio-to-video generation (use audio as input)
 * - AI avatar support
 * - SynthID watermarking on all outputs
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-async-metadata.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-omni-service.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-video-response.php';

/**
 * Generate Omni Video Tool.
 */
class WP_MCP_AI_Tool_Generate_Omni_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Async_Metadata_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Attachment_File_Resolver;
	use WP_MCP_AI_Tool_Video_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_omni_video';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Omni Video', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates high-quality videos from text descriptions using Google Gemini Omni Flash. Supports text, images (up to 5), existing videos, and audio as input. Produces videos up to 10 seconds with native audio. Automatically falls back to Veo when Omni is unavailable. Omni supports multi-turn conversational editing (use edit_omni_video for subsequent edits). All videos include SynthID watermark for AI provenance.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'prompt'           => array(
					'type'        => 'string',
					'description' => __( 'Detailed video description. Use cinematic language (e.g., "wide shot", "golden hour"). Be specific about subjects, actions, setting, lighting, and camera movements.', 'mcp-ai-wpoos' ),
				),
				'duration'         => array(
					'type'        => 'integer',
					'description' => __( 'Video duration in seconds (4-10). Default is 5 seconds. Omni Flash supports up to 10 seconds. If Veo fallback is used, max is 8 seconds.', 'mcp-ai-wpoos' ),
					'minimum'     => 4,
					'maximum'     => 10,
					'default'     => 5,
				),
				'aspect_ratio'     => array(
					'type'        => 'string',
					'description' => __( 'Video aspect ratio. Supported: "16:9" for widescreen (default), "9:16" for vertical, "1:1" for square, "3:2" for landscape, "2:3" for portrait.', 'mcp-ai-wpoos' ),
					'enum'        => array( '1:1', '2:3', '3:2', '16:9', '9:16' ),
					'default'     => '16:9',
				),
				'resolution'       => array(
					'type'        => 'string',
					'description' => __( 'Video resolution. "720p" (default) or "1080p". Note: 1080p requires 16:9 aspect ratio and 8 seconds duration.', 'mcp-ai-wpoos' ),
					'enum'        => array( '720p', '1080p' ),
					'default'     => '720p',
				),
				'reference_images' => array(
					'type'        => 'array',
					'description' => __( 'WordPress attachment IDs of reference images (up to 5). The video will be guided by these images\' visual style and content.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'maxItems'    => 5,
				),
				'reference_video'  => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of a reference video for video-to-video generation. The output will be based on this video\'s content and style.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'reference_audio'  => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of a reference audio file. The generated video\'s audio will be influenced by this input.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'negative_prompt'  => array(
					'type'        => 'string',
					'description' => __( 'What to avoid in the video (e.g., "blurry, low quality, distorted, dark").', 'mcp-ai-wpoos' ),
				),
				'style'            => array(
					'type'        => 'string',
					'description' => __( 'Visual style preset: "cinematic", "realistic", "anime", "documentary", "artistic", or "none".', 'mcp-ai-wpoos' ),
					'enum'        => array( 'cinematic', 'realistic', 'anime', 'documentary', 'artistic', 'none' ),
					'default'     => 'none',
				),
				'use_avatar'       => array(
					'type'        => 'boolean',
					'description' => __( 'Use your stored AI avatar in the video. Requires avatar setup via Omni first.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'async'            => array(
					'type'        => 'boolean',
					'description' => __( 'Run generation asynchronously. Returns a job ID that can be checked with check_omni_video_status. Recommended for durations longer than 5 seconds.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'   => array( 'prompt' ),
		);
	}

	/**
	 * Execute the Omni Video generation tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Sanitize inputs (two-gate rule: sanitize at entry).
		$prompt          = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$duration        = isset( $arguments['duration'] ) ? absint( $arguments['duration'] ) : 5;
		$aspect_ratio    = isset( $arguments['aspect_ratio'] ) ? sanitize_text_field( $arguments['aspect_ratio'] ) : '16:9';
		$resolution      = isset( $arguments['resolution'] ) ? sanitize_text_field( $arguments['resolution'] ) : '720p';
		$negative_prompt = isset( $arguments['negative_prompt'] ) ? sanitize_textarea_field( $arguments['negative_prompt'] ) : '';
		$style           = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : 'none';
		$use_async       = ! empty( $arguments['async'] );
		$use_avatar      = ! empty( $arguments['use_avatar'] );
		$user_id         = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Reference images.
		$reference_images = array();
		if ( ! empty( $arguments['reference_images'] ) && is_array( $arguments['reference_images'] ) ) {
			$reference_images = array_slice( array_map( 'absint', $arguments['reference_images'] ), 0, 5 );
		}

		// Reference video.
		$reference_video = ! empty( $arguments['reference_video'] ) ? absint( $arguments['reference_video'] ) : 0;

		// Reference audio.
		$reference_audio = ! empty( $arguments['reference_audio'] ) ? absint( $arguments['reference_audio'] ) : 0;

		if ( empty( $prompt ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'A prompt is required for video generation.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Validate duration.
		if ( $duration < 4 ) {
			$duration = 4;
		}
		if ( $duration > 10 ) {
			$duration = 10;
		}

		// Validate aspect ratio.
		$allowed_ratios = array( '1:1', '2:3', '3:2', '16:9', '9:16' );
		if ( ! in_array( $aspect_ratio, $allowed_ratios, true ) ) {
			$aspect_ratio = '16:9';
		}

		// 1080p requires 16:9 and 8 seconds.
		if ( '1080p' === $resolution ) {
			if ( '16:9' !== $aspect_ratio ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_resolution',
					__( '1080p resolution requires 16:9 aspect ratio.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}
			if ( 8 !== $duration ) {
				$duration = 8;
			}
		}

		WP_MCP_AI_Logger::log_event(
			'omni_video_tool_execute',
			'Executing Omni video generation',
			array(
				'prompt_preview' => substr( $prompt, 0, 80 ),
				'duration'       => $duration,
				'aspect_ratio'   => $aspect_ratio,
				'resolution'     => $resolution,
				'image_count'    => count( $reference_images ),
				'has_video'      => $reference_video > 0,
				'has_audio'      => $reference_audio > 0,
				'async'          => $use_async,
			)
		);

		$service = new WP_MCP_AI_Gemini_Omni_Service();

		$result = $service->generate_video(
			array(
				'prompt'           => $prompt,
				'duration'         => $duration,
				'aspect_ratio'     => $aspect_ratio,
				'resolution'       => $resolution,
				'reference_images' => $reference_images,
				'reference_video'  => $reference_video,
				'reference_audio'  => $reference_audio,
				'negative_prompt'  => $negative_prompt,
				'style'            => $style,
				'use_avatar'       => $use_avatar,
				'async'            => $use_async,
				'user_id'          => $user_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Omni video generation failed',
				array(
					'error' => $result->get_error_message(),
					'code'  => $result->get_error_code(),
				)
			);
			return $result;
		}

		// Handle async response.
		if ( isset( $result['async'] ) && $result['async'] ) {
			return $this->build_chat_response(
				sprintf(
					/* translators: 1: job ID, 2: estimated seconds */
					__( 'Video generation queued. Job ID: %1$s. Estimated completion in ~%2$d seconds. Use check_omni_video_status to track progress.', 'mcp-ai-wpoos' ),
					esc_html( $result['job_id'] ),
					absint( $result['eta_seconds'] )
				),
				array(
					'job_id'      => esc_html( $result['job_id'] ),
					'status'      => 'queued',
					'eta_seconds' => absint( $result['eta_seconds'] ),
					'model'       => isset( $result['model'] ) ? esc_html( $result['model'] ) : 'gemini-omni-flash',
					'fallback'    => isset( $result['fallback_used'] ) ? true : false,
				)
			);
		}

		// Save video to Media Library.
		return $this->save_video_to_media_library( $result, $prompt, $aspect_ratio );
	}

	/**
	 * Save generated video to WordPress Media Library.
	 *
	 * @param array  $result       Video generation result.
	 * @param string $prompt       Original prompt.
	 * @param string $aspect_ratio Aspect ratio.
	 * @return array|WP_Error Media library attachment data or error.
	 */
	protected function save_video_to_media_library( $result, $prompt, $aspect_ratio ) {
		if ( ! isset( $result['video_data'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_video_data',
				__( 'No video data in generation result.', 'mcp-ai-wpoos' )
			);
		}

		$video_data  = $result['video_data'];
		$model       = isset( $result['model'] ) ? $result['model'] : 'gemini-omni-flash';
		$is_fallback = isset( $result['fallback_used'] ) && $result['fallback_used'];

		// Generate filename.
		$filename = sanitize_file_name(
			sprintf(
				'omni-video-%s-%s.mp4',
				substr( sanitize_title( $prompt ), 0, 40 ),
				substr( md5( $prompt . time() ), 0, 8 )
			)
		);

		$upload = wp_upload_bits( $filename, null, $video_data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				sprintf(
					/* translators: %s: upload error message */
					__( 'Failed to save video: %s', 'mcp-ai-wpoos' ),
					$upload['error']
				)
			);
		}

		// Create attachment post.
		$attachment = array(
			'post_mime_type' => 'video/mp4',
			'post_title'     => sprintf(
				/* translators: %s: video prompt */
				__( 'Omni Video: %s', 'mcp-ai-wpoos' ),
				substr( $prompt, 0, 80 )
			),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}

		// Generate attachment metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		// Store generation metadata.
		update_post_meta( $attach_id, '_wp_mcp_ai_generated_by', 'gemini-omni' );
		update_post_meta( $attach_id, '_wp_mcp_ai_generation_prompt', $prompt );
		update_post_meta( $attach_id, '_wp_mcp_ai_generation_model', $model );
		update_post_meta( $attach_id, '_wp_mcp_ai_aspect_ratio', $aspect_ratio );
		if ( $is_fallback ) {
			update_post_meta( $attach_id, '_wp_mcp_ai_fallback_used', true );
		}

		$attachment_url = wp_get_attachment_url( $attach_id );

		return array(
			'success'        => true,
			'attachment_id'  => $attach_id,
			'attachment_url' => $attachment_url,
			'model'          => $model,
			'prompt'         => $prompt,
			'aspect_ratio'   => $aspect_ratio,
			'fallback_used'  => $is_fallback,
			'filename'       => $filename,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'background-only'  => true,
			'token_multiplier' => 5.0,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array(
			'providers'    => array( 'gemini' ),
			'capabilities' => array( 'video-generation' ),
			'required'     => true,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_async_metadata() {
		return array(
			'background-only' => true,
			'timeout'         => 360,
		);
	}

	/**
	 * Get pre-execution metadata for async pending response.
	 *
	 * @param string $job_id    The async job identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array Metadata including expected_url and expected_filename.
	 */
	public function get_async_pending_metadata( $job_id, array $arguments = array(), array $context = array() ) {
		unset( $arguments, $context );

		return array(
			'expected_url'      => '',
			'expected_filename' => sanitize_file_name( 'omni-video-' . $job_id ) . '.mp4',
			'message'           => sprintf(
				/* translators: %s: job ID */
				__( 'Video generation started and is being processed. Job ID: %s', 'mcp-ai-wpoos' ),
				$job_id
			),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Sanitize omni-video generation results for LLM context consumption.
	 *
	 * @param mixed $result Raw tool execution result.
	 * @return mixed Sanitized result safe for LLM context.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$keep_fields = array(
			'success',
			'message',
			'url',
			'expected_url',
			'video_url',
			'job_id',
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		return ! empty( $sanitized ) ? $sanitized : $result;
	}
}
