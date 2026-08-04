<?php
/**
 * Gemini Omni Video Generation & Editing Service
 *
 * Handles video generation and editing using Google's Gemini Omni Flash model.
 * Omni replaces Veo as the primary video generation model — it is an any-to-any
 * multimodal model that accepts text, images, audio, and video as input and
 * produces video with native audio output.
 *
 * Key differences from Veo:
 * - Any-to-any multimodal: text/images/audio/video → video
 * - 10-second videos (vs. 8s for Veo)
 * - Native audio generation (no separate TTS step)
 * - Multi-turn conversational editing (preserve edit history)
 * - Up to 5 photo references as input
 * - AI avatars (with onboarding verification)
 * - SynthID watermarking on all outputs
 *
 * Google I/O 2026 Announcement (May 19, 2026):
 * - Omni Flash available in Gemini app, YouTube Shorts, Flow
 * - API access coming in the weeks following I/O
 * - Omni Pro expected later
 *
 * SoC Architecture:
 * - Tools call this service for video generation/editing
 * - Service uses Gemini Client for API communication
 * - Service handles async operation polling (similar to Veo pattern)
 * - Returns video data for WordPress integration
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

require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-inline-async-tick.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Gemini Omni Video Service class.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Gemini_Omni_Service {
	use WP_MCP_AI_Inline_Async_Tick_Trait;

	/**
	 * Omni Flash model identifier.
	 *
	 * @var string
	 */
	const OMNI_MODEL = 'gemini-omni-flash';

	/**
	 * Veo 3.1 model (fallback when Omni is unavailable).
	 *
	 * @var string
	 */
	const VEO_FALLBACK_MODEL = 'veo-3.1-generate-preview';

	/**
	 * Minimum video duration in seconds.
	 *
	 * @var int
	 */
	const MIN_DURATION = 4;

	/**
	 * Maximum video duration for Omni in seconds.
	 *
	 * @var int
	 */
	const MAX_DURATION = 10;

	/**
	 * Default video duration in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_DURATION = 5;

	/**
	 * Maximum number of reference images.
	 *
	 * @var int
	 */
	const MAX_REFERENCE_IMAGES = 5;

	/**
	 * Maximum polling attempts.
	 *
	 * @var int
	 */
	const MAX_POLLING_ATTEMPTS = 60;

	/**
	 * Polling interval in seconds.
	 *
	 * @var int
	 */
	const POLLING_INTERVAL = 5;

	/**
	 * Cron hook for async video polling.
	 *
	 * @var string
	 */
	const CRON_POLL_HOOK = 'wp_mcp_ai_poll_omni_video';

	/**
	 * Transient prefix for async operations.
	 *
	 * @var string
	 */
	const ASYNC_OP_PREFIX = 'wp_mcp_ai_omni_async_';

	/**
	 * Prefix for per-job cooperative tick lock.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const TICK_LOCK_PREFIX = 'wp_mcp_ai_omni_poll_lock_';

	/**
	 * Object-cache group used by the tick-lock entries.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const TICK_LOCK_CACHE_GROUP = 'wp_mcp_ai_omni_poll';

	/**
	 * Tick-lock TTL in seconds.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const TICK_LOCK_TTL = 30;

	/**
	 * Estimated number of polls for typical video completion.
	 *
	 * @var int
	 */
	const ESTIMATED_COMPLETION_POLLS = 40;

	/**
	 * Maximum progress percentage before completion.
	 *
	 * @var int
	 */
	const MAX_PROGRESS_BEFORE_COMPLETE = 95;

	/**
	 * Supported aspect ratios.
	 *
	 * @var array
	 */
	const ASPECT_RATIOS = array( '1:1', '2:3', '3:2', '16:9', '9:16' );

	/**
	 * Supported resolutions.
	 *
	 * @var array
	 */
	const RESOLUTIONS = array( '720p', '1080p' );

	/**
	 * Initialize the service and register hooks.
	 */
	public static function init() {
		add_action( self::CRON_POLL_HOOK, array( __CLASS__, 'poll_video_async_static' ), 10, 1 );
	}

	/**
	 * Check if Omni API is available.
	 *
	 * Omni was announced at Google I/O 2026 with API access coming
	 * in the following weeks. This gate prevents 404s until the
	 * endpoint is live.
	 *
	 * @return bool True if Omni API is available.
	 */
	public static function is_omni_api_available() {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings_Base' )
			? WP_MCP_AI_Admin_Settings_Base::get_settings()
			: get_option( 'wp_mcp_ai_settings', array() );

		// Explicit feature flag for pre-release access.
		if ( ! empty( $settings['enable_omni_api'] ) ) {
			return true;
		}

		/**
		 * Filter: wp_mcp_ai_omni_api_available
		 *
		 * Allows early access or forced disable of Omni API.
		 *
		 * @since 1.2.0
		 * @param bool $available Whether Omni API is available.
		 */
		return apply_filters( 'wp_mcp_ai_omni_api_available', false );
	}

	/**
	 * Generate a unique ID with underscores for cleaner filenames.
	 *
	 * @return string Unique ID.
	 */
	public static function generate_clean_unique_id() {
		return str_replace( '.', '_', uniqid( '', true ) );
	}

	/**
	 * Static wrapper for cron callback.
	 *
	 * @param string $job_id Job identifier.
	 */
	public static function poll_video_async_static( $job_id ) {
		$service = new self();
		$service->poll_video_async( $job_id );
	}

	/**
	 * Generate a video using Gemini Omni Flash with automatic Veo fallback.
	 *
	 * @param array $args {
	 *     Video generation arguments.
	 *
	 *     @type string $prompt              Video description/prompt (required).
	 *     @type int    $duration            Duration in seconds (4-10, default 5).
	 *     @type string $aspect_ratio        Aspect ratio: 1:1, 2:3, 3:2, 16:9, 9:16 (default 16:9).
	 *     @type string $resolution          Resolution: 720p or 1080p (default 720p).
	 *     @type array  $reference_images    Array of attachment IDs (up to 5).
	 *     @type int    $reference_video     Attachment ID for video-to-video input.
	 *     @type int    $reference_audio     Attachment ID for audio input.
	 *     @type string $negative_prompt     What to avoid in generation.
	 *     @type bool   $use_avatar          Whether to use stored AI avatar.
	 *     @type string $style               Visual style preset.
	 *     @type bool   $async               Whether to use async mode.
	 *     @type int    $user_id             User ID for async operations.
	 * }
	 * @return array|WP_Error Video data or async job info on success, error on failure.
	 */
	public function generate_video( array $args ) {
		// Validate required prompt.
		if ( empty( $args['prompt'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'Video generation requires a prompt.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Check if Omni API is available; fall back to Veo if not.
		if ( ! self::is_omni_api_available() ) {
			return $this->fallback_to_veo( $args );
		}

		// Omnibug: try Omni first, with Veo fallback on failure.
		$result = $this->generate_video_with_omni( $args );

		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		// Omni failed — attempt Veo fallback for retryable errors.
		if ( $this->should_fallback_to_veo( $result ) ) {
			WP_MCP_AI_Logger::log_event(
				'omni_fallback_to_veo',
				'Omni generation failed, attempting Veo fallback',
				array(
					'omni_error' => $result->get_error_message(),
					'error_code' => $result->get_error_code(),
				)
			);

			return $this->fallback_to_veo( $args, $result );
		}

		return $result;
	}

	/**
	 * Generate video using Omni Flash.
	 *
	 * @param array $args Generation arguments.
	 * @return array|WP_Error Video data or error.
	 */
	protected function generate_video_with_omni( array $args ) {
		$use_async = isset( $args['async'] ) && $args['async'];

		$payload = $this->build_omni_payload( $args );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$operation = $this->submit_omni_request( $payload );

		if ( is_wp_error( $operation ) ) {
			return $operation;
		}

		$operation['model_used'] = self::OMNI_MODEL;

		if ( $use_async ) {
			return $this->queue_async_polling( $operation, $args );
		}

		$result = $this->poll_for_completion( $operation, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $result['async'] ) && $result['async'] ) {
			return $result;
		}

		return $this->process_completed_video( $result, $args, self::OMNI_MODEL );
	}

	/**
	 * Edit an existing video using Omni conversational editing.
	 *
	 * Supports multi-turn editing — pass a previous_video_id to continue
	 * editing the same video with preserved context.
	 *
	 * @param array $args {
	 *     Video editing arguments.
	 *
	 *     @type string $edit_prompt        Natural language description of desired edits.
	 *     @type int    $source_video_id     Attachment ID of video to edit.
	 *     @type string $previous_video_id   Omni operation ID for multi-turn editing.
	 *     @type bool   $async              Whether to use async mode.
	 *     @type int    $user_id            User ID for async operations.
	 * }
	 * @return array|WP_Error Edited video data or error.
	 */
	public function edit_video( array $args ) {
		if ( empty( $args['edit_prompt'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_edit_prompt',
				__( 'Video editing requires an edit instruction.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $args['source_video_id'] ) && empty( $args['previous_video_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_source',
				__( 'Video editing requires a source video or previous operation ID.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( ! self::is_omni_api_available() ) {
			return new WP_Error(
				'wp_mcp_ai_omni_unavailable',
				__( 'Video editing via Omni is not yet available. The Omni API will be accessible in the coming weeks.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		$use_async = isset( $args['async'] ) && $args['async'];

		$payload = $this->build_omni_edit_payload( $args );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$operation = $this->submit_omni_request( $payload, 'edit' );

		if ( is_wp_error( $operation ) ) {
			return $operation;
		}

		$operation['model_used'] = self::OMNI_MODEL;
		$operation['is_edit']    = true;

		if ( $use_async ) {
			return $this->queue_async_polling( $operation, $args );
		}

		$result = $this->poll_for_completion( $operation, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $result['async'] ) && $result['async'] ) {
			return $result;
		}

		return $this->process_completed_video( $result, $args, self::OMNI_MODEL );
	}

	/**
	 * Build the Omni generation payload.
	 *
	 * Maps plugin arguments to the Gemini Omni API format.
	 *
	 * @param array $args Generation arguments.
	 * @return array|WP_Error Payload or error.
	 */
	protected function build_omni_payload( array $args ) {
		$prompt = sanitize_textarea_field( $args['prompt'] );

		// Duration validation.
		$duration = isset( $args['duration'] ) ? absint( $args['duration'] ) : self::DEFAULT_DURATION;

		if ( $duration < self::MIN_DURATION ) {
			$duration = self::MIN_DURATION;
		}

		if ( $duration > self::MAX_DURATION ) {
			$duration = self::MAX_DURATION;
		}

		// Aspect ratio validation.
		$aspect_ratio = isset( $args['aspect_ratio'] ) ? $args['aspect_ratio'] : '16:9';

		if ( ! in_array( $aspect_ratio, self::ASPECT_RATIOS, true ) ) {
			$aspect_ratio = '16:9';
		}

		// Resolution validation.
		$resolution = isset( $args['resolution'] ) ? $args['resolution'] : '720p';

		if ( ! in_array( $resolution, self::RESOLUTIONS, true ) ) {
			$resolution = '720p';
		}

		// Build contents (multimodal input).
		$parts = array(
			array(
				'text' => $prompt,
			),
		);

		// Add reference images (up to 5).
		if ( ! empty( $args['reference_images'] ) && is_array( $args['reference_images'] ) ) {
			$image_count = 0;
			foreach ( $args['reference_images'] as $attachment_id ) {
				if ( $image_count >= self::MAX_REFERENCE_IMAGES ) {
					break;
				}
				$image_part = $this->build_image_part( absint( $attachment_id ) );
				if ( null !== $image_part ) {
					$parts[] = $image_part;
					++$image_count;
				}
			}
		}

		// Add reference video for video-to-video generation.
		if ( ! empty( $args['reference_video'] ) ) {
			$video_part = $this->build_video_part( absint( $args['reference_video'] ) );
			if ( null !== $video_part ) {
				$parts[] = $video_part;
			}
		}

		// Add reference audio.
		if ( ! empty( $args['reference_audio'] ) ) {
			$audio_part = $this->build_audio_part( absint( $args['reference_audio'] ) );
			if ( null !== $audio_part ) {
				$parts[] = $audio_part;
			}
		}

		$contents = array(
			array(
				'role'  => 'user',
				'parts' => $parts,
			),
		);

		// Build generation config.
		$generation_config = array(
			'responseModalities' => array( 'VIDEO' ),
			'videoConfig'        => array(
				'durationSeconds' => $duration,
				'aspectRatio'     => $aspect_ratio,
				'generateAudio'   => true,
			),
		);

		if ( '720p' !== $resolution ) {
			$generation_config['videoConfig']['resolution'] = $resolution;
		}

		// Add style preset.
		if ( ! empty( $args['style'] ) && 'none' !== $args['style'] ) {
			$generation_config['videoConfig']['style'] = sanitize_text_field( $args['style'] );
		}

		// Add negative prompt.
		if ( ! empty( $args['negative_prompt'] ) ) {
			$generation_config['videoConfig']['negativePrompt'] = sanitize_textarea_field( $args['negative_prompt'] );
		}

		// Avatar mode.
		if ( ! empty( $args['use_avatar'] ) ) {
			$generation_config['videoConfig']['useAvatar'] = true;
		}

		$payload = array(
			'contents'         => $contents,
			'generationConfig' => $generation_config,
		);

		/**
		 * Filter: wp_mcp_ai_omni_video_payload
		 *
		 * @since 1.2.0
		 * @param array $payload Prepared request payload.
		 * @param array $args    Original method arguments.
		 */
		return apply_filters( 'wp_mcp_ai_omni_video_payload', $payload, $args );
	}

	/**
	 * Build the Omni editing payload.
	 *
	 * @param array $args Editing arguments.
	 * @return array|WP_Error Payload or error.
	 */
	protected function build_omni_edit_payload( array $args ) {
		$edit_prompt = sanitize_textarea_field( $args['edit_prompt'] );

		$parts = array(
			array(
				'text' => $edit_prompt,
			),
		);

		// Add source video for editing.
		if ( ! empty( $args['source_video_id'] ) ) {
			$video_part = $this->build_video_part( absint( $args['source_video_id'] ) );
			if ( null !== $video_part ) {
				$parts[] = $video_part;
			}
		}

		$contents = array(
			array(
				'role'  => 'user',
				'parts' => $parts,
			),
		);

		$generation_config = array(
			'responseModalities' => array( 'VIDEO' ),
			'videoConfig'        => array(
				'operation' => 'edit',
			),
		);

		// Multi-turn editing — link to previous operation.
		if ( ! empty( $args['previous_video_id'] ) ) {
			$generation_config['videoConfig']['previousOperationId'] = sanitize_text_field( $args['previous_video_id'] );
		}

		// Add aspect ratio override for edit output.
		if ( ! empty( $args['aspect_ratio'] ) && in_array( $args['aspect_ratio'], self::ASPECT_RATIOS, true ) ) {
			$generation_config['videoConfig']['aspectRatio'] = $args['aspect_ratio'];
		}

		$payload = array(
			'contents'         => $contents,
			'generationConfig' => $generation_config,
		);

		/**
		 * Filter: wp_mcp_ai_omni_edit_payload
		 *
		 * @since 1.2.0
		 * @param array $payload Prepared request payload.
		 * @param array $args    Original method arguments.
		 */
		return apply_filters( 'wp_mcp_ai_omni_edit_payload', $payload, $args );
	}

	/**
	 * Build an image part from a WordPress attachment.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null Image part or null on failure.
	 */
	protected function build_image_part( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return null;
		}

		$mime_type = get_post_mime_type( $attachment_id );

		if ( ! $mime_type || 0 !== strpos( $mime_type, 'image/' ) ) {
			return null;
		}

		$data = base64_encode( file_get_contents( $file_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return array(
			'inlineData' => array(
				'mimeType' => $mime_type,
				'data'     => $data,
			),
		);
	}

	/**
	 * Build a video part from a WordPress attachment.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null Video part or null on failure.
	 */
	protected function build_video_part( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return null;
		}

		$mime_type = get_post_mime_type( $attachment_id );

		if ( ! $mime_type || 0 !== strpos( $mime_type, 'video/' ) ) {
			return null;
		}

		$data = base64_encode( file_get_contents( $file_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return array(
			'inlineData' => array(
				'mimeType' => $mime_type,
				'data'     => $data,
			),
		);
	}

	/**
	 * Build an audio part from a WordPress attachment.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null Audio part or null on failure.
	 */
	protected function build_audio_part( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return null;
		}

		$mime_type = get_post_mime_type( $attachment_id );

		if ( ! $mime_type || 0 !== strpos( $mime_type, 'audio/' ) ) {
			return null;
		}

		$data = base64_encode( file_get_contents( $file_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return array(
			'inlineData' => array(
				'mimeType' => $mime_type,
				'data'     => $data,
			),
		);
	}

	/**
	 * Submit a video generation/edit request to the Omni API.
	 *
	 * @param array  $payload   Request payload.
	 * @param string $operation 'generate' or 'edit'.
	 * @return array|WP_Error Operation details or error.
	 */
	protected function submit_omni_request( $payload, $operation = 'generate' ) {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings_Base' )
			? WP_MCP_AI_Admin_Settings_Base::get_settings()
			: get_option( 'wp_mcp_ai_settings', array() );
		$api_key  = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'Gemini API key is not configured.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Select the appropriate endpoint based on operation type.
		// Omni uses :predict for generation (like Veo's :predictLongRunning)
		// and :edit for multi-turn conversational editing.
		// Matches the lib/core reference implementation (GenerateOmniVideoTool).
		$action = 'generate' === $operation
			? 'predict'
			: 'edit';

		$endpoint = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:%s',
			rawurlencode( self::OMNI_MODEL ),
			$action
		);

		$request_args = array(
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 60,
		);

		WP_MCP_AI_Logger::log_event(
			'omni_video_request',
			sprintf( 'Submitting Omni %s request', $operation ),
			array(
				'prompt'     => isset( $payload['contents'][0]['parts'][0]['text'] )
					? substr( $payload['contents'][0]['parts'][0]['text'], 0, 100 )
					: '',
				'duration'   => isset( $payload['generationConfig']['videoConfig']['durationSeconds'] )
					? $payload['generationConfig']['videoConfig']['durationSeconds']
					: null,
				'has_images' => $this->count_parts_by_type( $payload, 'image' ),
				'has_video'  => $this->count_parts_by_type( $payload, 'video' ),
				'has_audio'  => $this->count_parts_by_type( $payload, 'audio' ),
			)
		);

		$response = wp_remote_post( $endpoint, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				'Omni request failed',
				array( 'error' => $response->get_error_message() )
			);
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			WP_MCP_AI_Logger::log_error(
				'Omni request failed',
				array(
					'status' => $code,
					'body'   => $body,
				)
			);

			$error_message = __( 'Video request failed.', 'mcp-ai-wpoos' );
			$error_code    = 'wp_mcp_ai_omni_request_failed';

			if ( isset( $data['error']['message'] ) ) {
				$api_error     = $data['error']['message'];
				$error_message = $api_error;

				if ( false !== stripos( $api_error, 'not found' ) || false !== stripos( $api_error, 'does not exist' ) ) {
					$error_code    = 'wp_mcp_ai_omni_unavailable';
					$error_message = __( 'Gemini Omni API is not yet available. It will be accessible in the coming weeks. The Veo fallback will be used automatically.', 'mcp-ai-wpoos' );
				} elseif ( false !== stripos( $api_error, 'quota' ) || false !== stripos( $api_error, 'rate limit' ) ) {
					$error_code    = 'wp_mcp_ai_quota_exceeded';
					$error_message = sprintf(
						/* translators: %s: API error message */
						__( 'Video generation quota exceeded: %s', 'mcp-ai-wpoos' ),
						$api_error
					);
				} elseif ( false !== stripos( $api_error, 'content policy' ) || false !== stripos( $api_error, 'unsafe' ) ) {
					$error_code    = 'wp_mcp_ai_content_policy_violation';
					$error_message = sprintf(
						/* translators: %s: API error message */
						__( 'Prompt rejected by content policy. Try rephrasing: %s', 'mcp-ai-wpoos' ),
						$api_error
					);
				} else {
					$error_message = sprintf(
						/* translators: %s: API error message */
						__( 'Video request error: %s', 'mcp-ai-wpoos' ),
						$api_error
					);
				}
			}

			return new WP_Error( $error_code, $error_message, array( 'status' => $code ) );
		}

		// Extract operation name from response.
		if ( ! isset( $data['name'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from Omni API - no operation name.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'operation_name' => $data['name'],
			'metadata'       => isset( $data['metadata'] ) ? $data['metadata'] : array(),
		);
	}

	/**
	 * Count the number of parts of a specific inline data type.
	 *
	 * @param array  $payload Request payload.
	 * @param string $type    Media type (image, video, audio).
	 * @return int Count.
	 */
	protected function count_parts_by_type( $payload, $type ) {
		$count = 0;

		if ( ! isset( $payload['contents'][0]['parts'] ) ) {
			return 0;
		}

		foreach ( $payload['contents'][0]['parts'] as $part ) {
			if ( ! isset( $part['inlineData']['mimeType'] ) ) {
				continue;
			}
			if ( 0 === strpos( $part['inlineData']['mimeType'], $type . '/' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Poll for video generation/edit completion.
	 *
	 * @param array $operation Operation details.
	 * @param array $args      Original arguments for async fallback.
	 * @return array|WP_Error Completed operation data or error.
	 */
	protected function poll_for_completion( $operation, $args = array() ) {
		$operation_name = $operation['operation_name'];
		$settings       = class_exists( 'WP_MCP_AI_Admin_Settings_Base' )
			? WP_MCP_AI_Admin_Settings_Base::get_settings()
			: get_option( 'wp_mcp_ai_settings', array() );
		$api_key        = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		$endpoint = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/%s',
			$operation_name
		);

		$is_edit  = isset( $operation['is_edit'] ) && $operation['is_edit'];
		$attempts = 0;
		$in_async = isset( $args['in_async_executor'] ) && $args['in_async_executor'];

		if ( ! class_exists( 'WP_MCP_AI_Timeout_Detection_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-timeout-detection-service.php';
		}
		$timeout_detector = new WP_MCP_AI_Timeout_Detection_Service( 10 );

		while ( $attempts < self::MAX_POLLING_ATTEMPTS ) {
			++$attempts;

			if ( $attempts > 1 ) {
				sleep( self::POLLING_INTERVAL );
			}

			if ( ! $in_async && $timeout_detector->is_approaching_timeout() ) {
				WP_MCP_AI_Logger::log_event(
					'omni_timeout_async_fallback',
					sprintf( 'Approaching timeout after %.2fs, falling back to async', $timeout_detector->get_elapsed_time() )
				);

				return $this->queue_async_polling( $operation, $args );
			}

			$request_args = array(
				'headers' => array(
					'x-goog-api-key' => $api_key,
				),
				'timeout' => 30,
			);

			$response = wp_remote_get( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				continue;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( isset( $data['done'] ) && true === $data['done'] ) {
				if ( isset( $data['error'] ) ) {
					WP_MCP_AI_Logger::log_error(
						'Omni generation failed',
						array( 'error' => $data['error'] )
					);

					$error_message = isset( $data['error']['message'] )
						? $data['error']['message']
						: __( 'Video generation failed.', 'mcp-ai-wpoos' );

					return new WP_Error(
						'wp_mcp_ai_omni_generation_failed',
						$error_message,
						array( 'status' => 500 )
					);
				}

				WP_MCP_AI_Logger::log_event(
					'omni_generation_complete',
					'Omni video operation completed',
					array(
						'attempts' => $attempts,
						'is_edit'  => $is_edit,
					)
				);

				return $data;
			}

			if ( 1 === $attempts || 0 === $attempts % 10 ) {
				WP_MCP_AI_Logger::log_event(
					'omni_polling',
					sprintf( 'Polling for Omni completion (attempt %d/%d)', $attempts, self::MAX_POLLING_ATTEMPTS )
				);
			}
		}

		if ( $in_async ) {
			return new WP_Error(
				'wp_mcp_ai_omni_polling_timeout',
				sprintf(
					/* translators: %d: number of polling attempts */
					__( 'Video operation timed out after %d attempts.', 'mcp-ai-wpoos' ),
					$attempts
				),
				array( 'status' => 500 )
			);
		}

		return $this->queue_async_polling( $operation, $args );
	}

	/**
	 * Process completed video and download data.
	 *
	 * @param array  $result Completed operation result.
	 * @param array  $args   Original arguments.
	 * @param string $model  Model identifier.
	 * @return array|WP_Error Video data or error.
	 */
	protected function process_completed_video( $result, $args, $model = null ) {
		if ( null === $model ) {
			$model = self::OMNI_MODEL;
		}

		// Extract video URI from response.
		// The :predict endpoint returns predictions[0].videoUri (standard format).
		$video_uri = null;

		if ( isset( $result['response']['predictions'][0]['videoUri'] ) ) {
			$video_uri = $result['response']['predictions'][0]['videoUri'];
		} elseif ( isset( $result['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'] ) ) {
			$video_uri = $result['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'];
		} elseif ( isset( $result['response']['editVideoResponse']['editedVideo']['uri'] ) ) {
			$video_uri = $result['response']['editVideoResponse']['editedVideo']['uri'];
		}

		if ( empty( $video_uri ) ) {
			WP_MCP_AI_Logger::log_error(
				'omni_unknown_response_format',
				'Omni response did not match any known video URI path',
				array(
					'response_keys'     => is_array( $result ) ? array_keys( $result ) : gettype( $result ),
					'inner_keys'        => isset( $result['response'] ) && is_array( $result['response'] ) ? array_keys( $result['response'] ) : 'no response key',
					'has_predictions'   => isset( $result['response']['predictions'] ),
					'predictions_count' => isset( $result['response']['predictions'] ) ? count( $result['response']['predictions'] ) : 0,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_no_video_uri',
				__( 'No video URI in Omni response.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$video_data = $this->download_video( $video_uri );

		if ( is_wp_error( $video_data ) ) {
			return $video_data;
		}

		return array(
			'video_data'   => $video_data,
			'model'        => $model,
			'video_uri'    => $video_uri,
			'prompt'       => isset( $args['prompt'] ) ? $args['prompt'] : '',
			'aspect_ratio' => isset( $args['aspect_ratio'] ) ? $args['aspect_ratio'] : '16:9',
			'created'      => time(),
		);
	}

	/**
	 * Download generated video from URI.
	 *
	 * @param string $video_uri Video storage URI.
	 * @return array|WP_Error Video binary data or error.
	 */
	protected function download_video( $video_uri ) {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings_Base' )
			? WP_MCP_AI_Admin_Settings_Base::get_settings()
			: get_option( 'wp_mcp_ai_settings', array() );
		$api_key  = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		// Append API key as query parameter for GCS URLs.
		if ( false !== strpos( $video_uri, 'storage.googleapis.com' ) ) {
			$video_uri = add_query_arg( 'key', $api_key, $video_uri );
		}

		$request_args = array(
			'timeout' => 120,
		);

		if ( false === strpos( $video_uri, 'storage.googleapis.com' ) ) {
			$request_args['headers'] = array(
				'x-goog-api-key' => $api_key,
			);
		}

		WP_MCP_AI_Logger::log_event(
			'omni_video_download',
			'Downloading generated video',
			array( 'uri' => $video_uri )
		);

		$response = wp_remote_get( $video_uri, $request_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_video_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Failed to download video (HTTP %d).', 'mcp-ai-wpoos' ),
					$code
				),
				array( 'status' => $code )
			);
		}

		$video_data = wp_remote_retrieve_body( $response );

		if ( empty( $video_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_video',
				__( 'Downloaded video is empty.', 'mcp-ai-wpoos' )
			);
		}

		return $video_data;
	}

	/**
	 * Queue async polling via WP-Cron.
	 *
	 * @param array $operation Operation details.
	 * @param array $args      Original arguments.
	 * @return array Async job info.
	 */
	protected function queue_async_polling( $operation, $args = array() ) {
		$job_id = self::generate_clean_unique_id();
		$prefix = self::ASYNC_OP_PREFIX;

		// Store operation details for the cron callback.
		$job_data = array(
			'operation_name' => $operation['operation_name'],
			'model'          => isset( $operation['model_used'] ) ? $operation['model_used'] : self::OMNI_MODEL,
			'is_edit'        => isset( $operation['is_edit'] ) ? $operation['is_edit'] : false,
			'args'           => $args,
			'created_at'     => time(),
			'status'         => 'queued',
		);

		// Store parent_job_id if present (from async executor context).
		if ( isset( $args['parent_job_id'] ) && ! empty( $args['parent_job_id'] ) ) {
			$job_data['parent_job_id'] = sanitize_key( $args['parent_job_id'] );
		}

		// Store user_id and assistant_id for completion hook routing.
		if ( isset( $args['user_id'] ) ) {
			$job_data['user_id'] = absint( $args['user_id'] );
		}
		if ( isset( $args['assistant_id'] ) ) {
			$job_data['assistant_id'] = absint( $args['assistant_id'] );
		}

		set_transient( $prefix . $job_id, $job_data, HOUR_IN_SECONDS * 6 );

		// Schedule first poll with a 1-second delay to ensure transient is saved.
		// This prevents race condition where cron executes before database commit.
		$first_poll_time = time() + 1;
		wp_schedule_single_event( $first_poll_time, self::CRON_POLL_HOOK, array( $job_id ) );

		// Trigger WordPress cron immediately so polling starts right away.
		// WordPress cron is virtual and only runs on page loads by default.
		// Without this, the job sits idle until the next page load, which may
		// never arrive (especially on SSE connections).
		// This matches the pattern used by the VEO video generation service.
		spawn_cron();

		// Inline-async-tick: fire the first poll on the shutdown of the current
		// request so that jobs on hosts with DISABLE_WP_CRON (or a firewalled
		// wp-cron.php) are advanced without waiting for the next loopback.
		// The tick lock inside poll_video_async() prevents the shutdown kick and
		// the WP-Cron event from both executing concurrently for the same job_id.
		if ( self::inline_async_kick_enabled( $job_id, __CLASS__ ) ) {
			$self = $this;
			add_action(
				'shutdown',
				function () use ( $self, $job_id ) {
					self::inline_async_detach_worker_from_client();
					self::inline_async_run_kick(
						__CLASS__,
						$job_id,
						function () use ( $self, $job_id ) {
							$self->poll_video_async( $job_id );
						}
					);
				}
			);
		}

		WP_MCP_AI_Logger::log_event(
			'omni_async_queued',
			'Omni video operation queued for async polling',
			array(
				'job_id' => $job_id,
				'model'  => isset( $operation['model_used'] ) ? $operation['model_used'] : self::OMNI_MODEL,
			)
		);

		return array(
			'async'       => true,
			'job_id'      => $job_id,
			'status'      => 'queued',
			'eta_seconds' => self::ESTIMATED_COMPLETION_POLLS * self::POLLING_INTERVAL,
			'check_hook'  => self::CRON_POLL_HOOK,
			'model'       => isset( $operation['model_used'] ) ? $operation['model_used'] : self::OMNI_MODEL,
		);
	}

	/**
	 * Poll for async video completion (called by WP-Cron).
	 *
	 * @param string $job_id Job identifier.
	 */
	public function poll_video_async( $job_id ) {
		$prefix = self::ASYNC_OP_PREFIX;
		$data   = get_transient( $prefix . $job_id );

		if ( ! $data || ! is_array( $data ) ) {
			WP_MCP_AI_Logger::log_event(
				'omni_async_not_found',
				'Omni async job data not found',
				array( 'job_id' => $job_id )
			);
			return;
		}

		if ( 'completed' === $data['status'] || 'failed' === $data['status'] ) {
			return;
		}

		$operation = array(
			'operation_name' => $data['operation_name'],
			'model_used'     => $data['model'],
			'is_edit'        => $data['is_edit'],
		);

		$args                      = $data['args'];
		$args['in_async_executor'] = true;

		$result = $this->poll_for_completion( $operation, $args );

		if ( is_wp_error( $result ) ) {
			$data['status']       = 'failed';
			$data['error']        = $result->get_error_message();
			$data['completed_at'] = time();
			set_transient( $prefix . $job_id, $data, HOUR_IN_SECONDS * 6 );

			WP_MCP_AI_Logger::log_error(
				'Omni async generation failed',
				array(
					'job_id' => $job_id,
					'error'  => $result->get_error_message(),
				)
			);

			// Fire failure hook.
			do_action(
				'wp_mcp_ai_job_failed',
				$job_id,
				$result,
				array(
					'tool'   => 'generate_omni_video',
					'prompt' => isset( $data['args']['prompt'] ) ? $data['args']['prompt'] : '',
				)
			);

			if ( isset( $data['parent_job_id'] ) && ! empty( $data['parent_job_id'] ) ) {
				$this->complete_parent_job_error( $data['parent_job_id'], $result );
			}

			return;
		}

		if ( isset( $result['video_data'] ) ) {
			$data['status']       = 'completed';
			$data['video_data']   = $result['video_data'];
			$data['video_uri']    = isset( $result['video_uri'] ) ? $result['video_uri'] : '';
			$data['completed_at'] = time();
			set_transient( $prefix . $job_id, $data, HOUR_IN_SECONDS * 6 );

			WP_MCP_AI_Logger::log_event(
				'omni_async_completed',
				'Omni async video completed',
				array( 'job_id' => $job_id )
			);

			// Fire completion hooks (mirrors Veo service pattern).
			$hook_result = array(
				'success'   => true,
				'job_id'    => $job_id,
				'model'     => isset( $data['model'] ) ? $data['model'] : self::OMNI_MODEL,
				'prompt'    => isset( $data['args']['prompt'] ) ? $data['args']['prompt'] : '',
				'duration'  => isset( $result['duration'] ) ? $result['duration'] : null,
				'video_uri' => isset( $result['video_uri'] ) ? $result['video_uri'] : '',
			);

			$hook_meta = array(
				'tool'   => 'generate_omni_video',
				'prompt' => isset( $data['args']['prompt'] ) ? $data['args']['prompt'] : '',
			);

			if ( isset( $data['args']['user_id'] ) ) {
				$hook_meta['user_id'] = absint( $data['args']['user_id'] );
			}

			do_action( 'wp_mcp_ai_job_completed', $job_id, $hook_result, $hook_meta );

			// Complete parent job if present.
			if ( isset( $data['parent_job_id'] ) && ! empty( $data['parent_job_id'] ) ) {
				$this->complete_parent_job( $data['parent_job_id'], $hook_result );
			}
		} else {
			// Still running — re-schedule.
			$data['poll_attempts'] = isset( $data['poll_attempts'] ) ? $data['poll_attempts'] + 1 : 1;

			if ( $data['poll_attempts'] < self::MAX_POLLING_ATTEMPTS ) {
				set_transient( $prefix . $job_id, $data, HOUR_IN_SECONDS * 6 );

				if ( ! wp_next_scheduled( self::CRON_POLL_HOOK, array( $job_id ) ) ) {
					wp_schedule_single_event(
						time() + self::POLLING_INTERVAL,
						self::CRON_POLL_HOOK,
						array( $job_id )
					);
				}
			} else {
				$data['status']       = 'failed';
				$data['error']        = __( 'Maximum polling attempts reached.', 'mcp-ai-wpoos' );
				$data['completed_at'] = time();
				set_transient( $prefix . $job_id, $data, HOUR_IN_SECONDS * 6 );
			}
		}
	}

	/**
	 * Check if job is complete and return video data.
	 *
	 * @param string $job_id Job identifier.
	 * @return array|WP_Error Video data or error.
	 */
	public function check_job_status( $job_id ) {
		$prefix = self::ASYNC_OP_PREFIX;
		$data   = get_transient( $prefix . $job_id );

		if ( ! $data || ! is_array( $data ) ) {
			return new WP_Error(
				'wp_mcp_ai_job_not_found',
				__( 'Video generation job not found or expired.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		if ( 'completed' === $data['status'] ) {
			return array(
				'status'     => 'completed',
				'video_data' => $data['video_data'],
				'video_uri'  => $data['video_uri'],
				'job_id'     => $job_id,
			);
		}

		if ( 'failed' === $data['status'] ) {
			return new WP_Error(
				'wp_mcp_ai_job_failed',
				isset( $data['error'] ) ? $data['error'] : __( 'Video generation failed.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// Still queued or in progress.
		$progress = isset( $data['poll_attempts'] )
			? min( self::MAX_PROGRESS_BEFORE_COMPLETE, round( ( $data['poll_attempts'] / self::ESTIMATED_COMPLETION_POLLS ) * 100 ) )
			: 0;

		return array(
			'status'   => 'processing',
			'progress' => $progress,
			'job_id'   => $job_id,
			'model'    => isset( $data['model'] ) ? $data['model'] : self::OMNI_MODEL,
		);
	}

	/**
	 * Fall back to Veo when Omni is unavailable or fails.
	 *
	 * @param array         $args         Generation arguments.
	 * @param WP_Error|null $omni_error   Original Omni error (if any).
	 * @return array|WP_Error Video data or error.
	 */
	protected function fallback_to_veo( $args, $omni_error = null ) {
		WP_MCP_AI_Logger::log_event(
			'omni_veo_fallback',
			'Falling back to Veo for video generation',
			array(
				'omni_error' => $omni_error ? $omni_error->get_error_message() : 'Omni not available',
			)
		);

		if ( ! class_exists( 'WP_MCP_AI_Gemini_Video_Generation_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		}

		$veo_service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Translate Omni-style args to VEO-style args.
		// Cap duration at Veo max (8s) since Omni supports up to 10s.
		$veo_duration = isset( $args['duration'] ) ? absint( $args['duration'] ) : 5;
		if ( $veo_duration > 8 ) {
			$veo_duration = 8;
		}

		$veo_args = array(
			'prompt'          => $args['prompt'],
			'duration'        => $veo_duration,
			'aspect_ratio'    => isset( $args['aspect_ratio'] ) ? $args['aspect_ratio'] : '16:9',
			'resolution'      => isset( $args['resolution'] ) ? $args['resolution'] : '720p',
			'negative_prompt' => isset( $args['negative_prompt'] ) ? $args['negative_prompt'] : '',
			'async'           => isset( $args['async'] ) ? $args['async'] : false,
			'user_id'         => isset( $args['user_id'] ) ? $args['user_id'] : get_current_user_id(),
		);

		// Forward async executor context fields so the Veo service can:
		// 1. Prevent dual-async (in_async_executor flag).
		// 2. Complete the parent async job when video generation finishes (parent_job_id).
		// 3. Route completion hooks to the correct assistant (assistant_id).
		if ( isset( $args['in_async_executor'] ) ) {
			$veo_args['in_async_executor'] = $args['in_async_executor'];
		}
		if ( isset( $args['parent_job_id'] ) ) {
			$veo_args['parent_job_id'] = sanitize_key( $args['parent_job_id'] );
		}
		if ( isset( $args['assistant_id'] ) ) {
			$veo_args['assistant_id'] = absint( $args['assistant_id'] );
		}

		// Handle reference image.
		if ( ! empty( $args['reference_images'] ) && is_array( $args['reference_images'] ) ) {
			$first_image_id = absint( reset( $args['reference_images'] ) );
			$file_path      = get_attached_file( $first_image_id );
			if ( $file_path && file_exists( $file_path ) ) {
				$veo_args['image_base64']    = base64_encode( file_get_contents( $file_path ) ); // phpcs:ignore
				$veo_args['image_mime_type'] = get_post_mime_type( $first_image_id );
			}
		}

		$result = $veo_service->generate_video( $veo_args );

		if ( is_wp_error( $result ) && $omni_error ) {
			// Both Omni and Veo failed.
			return new WP_Error(
				$omni_error->get_error_code(),
				sprintf(
					/* translators: %1$s: Omni error message, %2$s: Veo error message */
					__( 'Video generation failed with both Omni and Veo. Omni: %1$s. Veo: %2$s', 'mcp-ai-wpoos' ),
					$omni_error->get_error_message(),
					$result->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		if ( ! is_wp_error( $result ) && is_array( $result ) ) {
			$result['fallback_used'] = true;
			$result['fallback_from'] = 'omni';
		}

		return $result;
	}

	/**
	 * Determine if error warrants fallback to Veo.
	 *
	 * @param WP_Error $error Error from Omni attempt.
	 * @return bool True if should fallback to Veo.
	 */
	protected function should_fallback_to_veo( $error ) {
		$error_message = $error->get_error_message();

		// Content policy violations should not trigger fallback.
		if ( 'wp_mcp_ai_content_policy_violation' === $error->get_error_code() ) {
			return false;
		}

		// Omni not yet available — always fall back.
		if ( 'wp_mcp_ai_omni_unavailable' === $error->get_error_code() ) {
			return true;
		}

		// Quota/rate limit errors.
		// Also treat argument validation errors (e.g., "durationSeconds out of bound")
		// as retryable because the Veo fallback has different (lower) max duration (8s vs 10s)
		// and will properly clamp the duration during build_generation_payload.
		$retryable = array(
			'quota',
			'rate limit',
			'too many requests',
			'resource exhausted',
			'unavailable',
			'not found',
			'out of bound',
			'invalid argument',
			'invalid parameter',
		);

		foreach ( $retryable as $indicator ) {
			if ( false !== stripos( $error_message, $indicator ) ) {
				return true;
			}
		}

		// HTTP status codes indicating availability issues.
		$error_data = $error->get_error_data();
		if ( isset( $error_data['status'] ) ) {
			$status = $error_data['status'];
			if ( in_array( $status, array( 403, 404, 429, 503 ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Complete parent async job with final video result.
	 *
	 * When Omni video generation is called through the async executor,
	 * there are two job IDs: async_xxx (from async executor) and an
	 * Omni job ID. This method updates the parent job with the result.
	 *
	 * @param string $parent_job_id Parent async job ID.
	 * @param array  $result        Final video generation result.
	 */
	protected function complete_parent_job( $parent_job_id, $result ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		}

		$parent_transient_key = WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $parent_job_id;
		$parent_metadata      = get_transient( $parent_transient_key );

		if ( ! $parent_metadata ) {
			WP_MCP_AI_Logger::log_event(
				'omni_parent_job_not_found',
				'Parent async job not found when completing Omni job',
				array( 'parent_job_id' => $parent_job_id )
			);
			return;
		}

		$serialized     = serialize( $result ); // phpcs:ignore
		$wrapped_result = array(
			'compressed'    => false,
			'data'          => $result,
			'original_size' => strlen( $serialized ),
		);

		$parent_metadata['status']       = 'completed';
		$parent_metadata['completed_at'] = time();
		$parent_metadata['result']       = $wrapped_result;
		set_transient( $parent_transient_key, $parent_metadata, DAY_IN_SECONDS );

		do_action(
			'wp_mcp_ai_job_completed',
			$parent_job_id,
			$result,
			array(
				'tool' => 'generate_omni_video',
				'note' => 'Parent async job completed by Omni service',
			)
		);
	}

	/**
	 * Mark parent async job as failed when Omni generation fails.
	 *
	 * @param string   $parent_job_id Parent async job ID.
	 * @param WP_Error $error         The error that occurred.
	 */
	protected function complete_parent_job_error( $parent_job_id, $error ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		}

		$parent_transient_key = WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $parent_job_id;
		$parent_metadata      = get_transient( $parent_transient_key );

		if ( ! $parent_metadata ) {
			return;
		}

		$parent_metadata['status']       = 'failed';
		$parent_metadata['completed_at'] = time();
		$parent_metadata['error']        = $error->get_error_message();
		set_transient( $parent_transient_key, $parent_metadata, DAY_IN_SECONDS );

		do_action(
			'wp_mcp_ai_job_failed',
			$parent_job_id,
			$error,
			array(
				'tool' => 'generate_omni_video',
				'note' => 'Parent async job failed by Omni service',
			)
		);
	}
}
