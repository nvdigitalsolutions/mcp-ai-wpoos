<?php
/**
 * Tool that generates videos using the Higgsfield API.
 *
 * Higgsfield (https://higgsfield.ai) exposes a curated catalog of generative
 * video models behind one authenticated, asynchronous API. This tool wraps
 * the catalog behind a single `model` parameter (industry-standard unified
 * routing, as used by fal.ai / Replicate): the canonical parameter set
 * (prompt, duration, resolution, aspect ratio, audio, references) is mapped
 * to each model's native endpoint and schema, with per-model clamping.
 *
 * Verified catalog (https://docs.higgsfield.ai):
 *  - cinema-studio-4.0 — flagship cinematic workflow (camera/genre/era controls)
 *  - seedance-2.5      — ByteDance; 4-30s, mp4/mov output
 *  - seedance-2.0      — ByteDance; 4-15s, up to 4k
 *  - wan-3.0           — Alibaba; 2-30s, 1080p, seed + deep thinking
 *  - kling-3.0         — Kling standard; 3-15s, multi-shot prompts
 *
 * Reference media (image/video/audio URLs) routes the request to each model's
 * reference-to-video endpoint when available. Generation is asynchronous on
 * the provider side; the shared client polls with backoff until a terminal
 * state, then downloads the output (provider retention is ~7 days).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-higgsfield-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-async-metadata.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-video-response.php';

/**
 * Provides a tool for generating videos via the Higgsfield API and storing them as attachments.
 */
class WP_MCP_AI_Tool_Generate_Higgsfield_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_Async_Metadata_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Video_Response;

	const DEFAULT_MODEL          = 'cinema-studio-4.0';
	const DEFAULT_RESOLUTION     = '720p';
	const DEFAULT_ASPECT         = '16:9';
	const DEFAULT_DURATION       = 5;
	const DEFAULT_GENERATE_AUDIO = true;

	/**
	 * Verified video-model catalog. Maps a model ID to its endpoints and
	 * per-model constraints. Only documented, verified endpoints are listed —
	 * see the Higgsfield docs for the full catalog.
	 *
	 * Each definition:
	 *  - endpoint_t2v / endpoint_ref: API paths (ref used when media references exist).
	 *  - duration: array( min, max ) in seconds.
	 *  - resolutions / aspects: supported enum values; empty = param omitted.
	 *  - refs: array( image => max, video => max, audio => max ); empty = unsupported.
	 *  - audio_field / audio_values: how the audio toggle is serialised.
	 *  - creative: whether camera_movement/genre/era controls are supported.
	 */
	const VIDEO_MODELS = array(
		'cinema-studio-4.0' => array(
			'endpoint_t2v' => '/higgsfield/cinema-studio/4.0',
			'endpoint_ref' => '/higgsfield/cinema-studio/4.0',
			'duration'     => array( 4, 30 ),
			'resolutions'  => array( '480p', '720p' ),
			'aspects'      => array( '16:9', '4:3', '1:1', '3:4', '9:16', '21:9' ),
			'refs'         => array(
				'image' => 30,
				'video' => 10,
				'audio' => 10,
			),
			'audio_field'  => 'generate_audio',
			'audio_values' => array(),
			'creative'     => true,
		),
		'seedance-2.5'      => array(
			'endpoint_t2v' => '/bytedance/seedance-2.5/text-to-video',
			'endpoint_ref' => '/bytedance/seedance-2.5/reference-to-video',
			'duration'     => array( 4, 30 ),
			'resolutions'  => array( '480p', '720p' ),
			'aspects'      => array( '16:9', '4:3', '1:1', '3:4', '9:16', '21:9' ),
			'refs'         => array(
				'image' => 30,
				'video' => 10,
				'audio' => 10,
			),
			'audio_field'  => 'generate_audio',
			'audio_values' => array(),
			'creative'     => false,
		),
		'seedance-2.0'      => array(
			'endpoint_t2v' => '/bytedance/seedance-2.0/text-to-video',
			'endpoint_ref' => '/bytedance/seedance-2.0/reference-to-video',
			'duration'     => array( 4, 15 ),
			'resolutions'  => array( '480p', '720p', '1080p', '4k' ),
			'aspects'      => array( '16:9', '4:3', '1:1', '3:4', '9:16', '21:9' ),
			'refs'         => array(
				'image' => 9,
				'video' => 3,
				'audio' => 3,
			),
			'audio_field'  => 'generate_audio',
			'audio_values' => array(),
			'creative'     => false,
		),
		'wan-3.0'           => array(
			'endpoint_t2v' => '/alibaba/wan-3.0/text-to-video',
			'endpoint_ref' => '/alibaba/wan-3.0/reference-to-video',
			'duration'     => array( 2, 30 ),
			'resolutions'  => array( '480p', '720p', '1080p' ),
			'aspects'      => array( '16:9', '4:3', '1:1', '3:4', '9:16', 'adaptive' ),
			'refs'         => array(
				'image' => 10,
				'video' => 5,
				'audio' => 5,
			),
			'audio_field'  => 'generate_audio',
			'audio_values' => array(),
			'creative'     => false,
		),
		'kling-3.0'         => array(
			'endpoint_t2v' => '/kling-video/v3.0/std/text-to-video',
			'endpoint_ref' => '',
			'duration'     => array( 3, 15 ),
			'resolutions'  => array(),
			'aspects'      => array( '16:9', '9:16', '1:1' ),
			'refs'         => array(),
			'audio_field'  => 'sound',
			'audio_values' => array(
				true  => 'on',
				false => 'off',
			),
			'creative'     => false,
		),
	);

	/**
	 * Allowed creative-control enums (Cinema Studio 4.0 input schema).
	 */
	const ALLOWED_CAMERA_MOVEMENTS = array( 'snorricam', 'robot-arm', 'tilt-up', 'rack-focus', 'tilt-down', 'pov', 'pan-left', 'crane-up', 'pan-right', 'crane-down', 'side-tracking', 'pedestal-up', 'pedestal-down', 'handheld', 'tracking', 'drone-orbit', 'dolly-zoom', 'aerial-pullback', 'static-shot', 'bullet-time', 'whip-pan', 'slow-zoom-in', 'arc-left', 'slow-zoom-out', 'arc-right', 'truck-right', 'dolly-in', 'truck-left', 'dolly-out', 'slider-right', 'crush-zoom', 'slider-left', 'helicopter-shot' );
	const ALLOWED_GENRES           = array( 'epic', 'drama', 'noir', 'comedy', 'horror', 'action' );
	const ALLOWED_ERAS             = array( '1960s', '1980s', '1990s', '2000s', '2020s' );

	/**
	 * Union of aspect ratios across the verified catalog.
	 */
	const ALLOWED_ASPECT_RATIOS = array( '16:9', '4:3', '1:1', '3:4', '9:16', '21:9', 'adaptive' );

	/**
	 * Union of resolution tiers across the verified catalog.
	 */
	const ALLOWED_RESOLUTIONS = array( '480p', '720p', '1080p', '4k' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_higgsfield_video';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Higgsfield Video', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a video with the Higgsfield model catalog and stores it in the Media Library. One tool, five verified models: cinema-studio-4.0 (cinematic, camera/genre/era controls), seedance-2.5 and seedance-2.0 (ByteDance), wan-3.0 (Alibaba, up to 1080p, seed + deep thinking), and kling-3.0 (multi-shot prompts). Text-to-video by default; pass reference images/videos/audio for reference-to-video generation. Generation is asynchronous on the provider side; the tool polls with backoff until the clip is ready, then downloads it (provider retention is ~7 days). Billing is per second of output from a prepaid balance.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Creating cinematic or reference-driven short clips via the Higgsfield catalog, with optional generated audio.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Still images; use generate_higgsfield_image. Polling a submitted request or canceling queued work; use check_higgsfield_request / cancel_higgsfield_request. OpenAI or Gemini video models; use generate_sora_video, generate_veo_video, or generate_omni_video.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'generate_higgsfield_image', 'check_higgsfield_request', 'cancel_higgsfield_request', 'generate_sora_video', 'generate_veo_video' ),
			'notes'           => __( 'Needs a Higgsfield API key pair (Key ID + Secret). Reference media must be publicly reachable URLs. Parameter availability varies per model — values are clamped to each model\'s supported range.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$defaults = $this->get_configured_defaults();

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'              => array(
					'type'        => 'string',
					'description' => __( 'The text prompt describing the desired video. Be specific about subjects, actions, setting, lighting, and camera movement.', 'mcp-ai-wpoos' ),
				),
				'model'               => array(
					'type'        => 'string',
					'description' => __( 'Higgsfield video model to use. cinema-studio-4.0 (default) supports camera/genre/era controls; seedance-2.5 and seedance-2.0 are ByteDance models (2.0 supports up to 4k); wan-3.0 supports 1080p, adaptive aspect ratio, and seed; kling-3.0 supports multi-shot prompts but no reference media on this endpoint.', 'mcp-ai-wpoos' ),
					'enum'        => array_keys( self::VIDEO_MODELS ),
					'default'     => self::DEFAULT_MODEL,
				),
				'duration'            => array(
					'type'        => 'integer',
					'description' => __( 'Video duration in seconds. Supported range varies per model (2-30s; clamped per model). Default is 5. Billing is per second of output.', 'mcp-ai-wpoos' ),
					'minimum'     => 2,
					'maximum'     => 30,
					'default'     => $defaults['duration'],
				),
				'resolution'          => array(
					'type'        => 'string',
					'description' => __( 'Output resolution tier. Availability varies per model (e.g. 4k only on seedance-2.0, 1080p on seedance-2.0/wan-3.0). Unsupported values are omitted or clamped.', 'mcp-ai-wpoos' ),
					'enum'        => self::ALLOWED_RESOLUTIONS,
					'default'     => $defaults['resolution'],
				),
				'aspect_ratio'        => array(
					'type'        => 'string',
					'description' => __( 'Output width-to-height ratio. "adaptive" is supported by wan-3.0 only. Unsupported values fall back to the model default.', 'mcp-ai-wpoos' ),
					'enum'        => self::ALLOWED_ASPECT_RATIOS,
					'default'     => $defaults['aspect_ratio'],
				),
				'generate_audio'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to generate audio with the video. Default is true. Kling 3.0 maps this to sound on/off.', 'mcp-ai-wpoos' ),
					'default'     => $defaults['generate_audio'],
				),
				'camera_movement'     => array(
					'type'        => 'string',
					'description' => __( 'Camera movement preset (e.g., "dolly-in", "drone-orbit", "handheld", "static-shot"). Cinema Studio 4.0 only; ignored for other models.', 'mcp-ai-wpoos' ),
					'enum'        => self::ALLOWED_CAMERA_MOVEMENTS,
				),
				'genre'               => array(
					'type'        => 'string',
					'description' => __( 'Cinematic genre: epic, drama, noir, comedy, horror, action. Cinema Studio 4.0 only; ignored for other models.', 'mcp-ai-wpoos' ),
					'enum'        => self::ALLOWED_GENRES,
				),
				'era'                 => array(
					'type'        => 'string',
					'description' => __( 'Period look: 1960s, 1980s, 1990s, 2000s, 2020s. Cinema Studio 4.0 only; ignored for other models.', 'mcp-ai-wpoos' ),
					'enum'        => self::ALLOWED_ERAS,
				),
				'seed'                => array(
					'type'        => 'integer',
					'description' => __( 'Random seed for reproducible results (wan-3.0 supports 0-2147483647). Ignored by models without seed support.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'maximum'     => 2147483647,
				),
				'reference_image_id'  => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of a reference image to guide image-to-video generation (optional). The image must be publicly reachable.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'reference_image_url' => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'Publicly reachable URL of a reference image (optional).', 'mcp-ai-wpoos' ),
				),
				'reference_video_url' => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'Publicly reachable URL of a reference video clip (optional; 1-30s per clip recommended).', 'mcp-ai-wpoos' ),
				),
				'reference_audio_url' => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'Publicly reachable URL of a reference audio file (optional; WAV or MP3 for wan-3.0).', 'mcp-ai-wpoos' ),
				),
				'save_to_media'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to save the generated video to WordPress Media Library. Default is true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'async'               => array(
					'type'        => 'boolean',
					'description' => __( 'Run generation as a background job and return a job_id immediately. Default is true for reliability.', 'mcp-ai-wpoos' ),
				),
				'timeout'             => array(
					'type'        => 'integer',
					'description' => __( 'Maximum time in seconds to wait for generation before returning a timeout (60-600). On timeout the request_id is returned; poll it with check_higgsfield_request.', 'mcp-ai-wpoos' ),
					'minimum'     => 60,
					'maximum'     => 600,
					'default'     => 300,
				),
			),
			'required'             => array( 'prompt' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Retrieve the configured defaults for video generation.
	 *
	 * @return array
	 */
	protected function get_configured_defaults() {
		$defaults = array(
			'model'          => self::DEFAULT_MODEL,
			'resolution'     => self::DEFAULT_RESOLUTION,
			'aspect_ratio'   => self::DEFAULT_ASPECT,
			'duration'       => self::DEFAULT_DURATION,
			'generate_audio' => self::DEFAULT_GENERATE_AUDIO,
		);

		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return $defaults;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		if ( ! empty( $settings['higgsfield_video_resolution'] ) && in_array( $settings['higgsfield_video_resolution'], self::ALLOWED_RESOLUTIONS, true ) ) {
			$defaults['resolution'] = sanitize_text_field( $settings['higgsfield_video_resolution'] );
		}

		if ( ! empty( $settings['higgsfield_video_aspect_ratio'] ) && in_array( $settings['higgsfield_video_aspect_ratio'], self::ALLOWED_ASPECT_RATIOS, true ) ) {
			$defaults['aspect_ratio'] = sanitize_text_field( $settings['higgsfield_video_aspect_ratio'] );
		}

		if ( ! empty( $settings['higgsfield_video_duration'] ) ) {
			$duration = absint( $settings['higgsfield_video_duration'] );
			if ( $duration >= 2 && $duration <= 30 ) {
				$defaults['duration'] = $duration;
			}
		}

		if ( isset( $settings['higgsfield_video_generate_audio'] ) ) {
			$defaults['generate_audio'] = (bool) $settings['higgsfield_video_generate_audio'];
		}

		return $defaults;
	}

	/**
	 * Resolve the model definition for a model ID.
	 *
	 * @param string $model Model ID.
	 * @return array Model definition (falls back to the default model).
	 */
	protected function get_model_definition( $model ) {
		if ( isset( self::VIDEO_MODELS[ $model ] ) ) {
			return self::VIDEO_MODELS[ $model ];
		}

		return self::VIDEO_MODELS[ self::DEFAULT_MODEL ];
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
				__( 'You do not have permission to generate videos.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// Validate prompt.
		if ( empty( $arguments['prompt'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'Video generation requires a prompt.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Check if async mode should be used.
		$use_async = $this->should_use_async( $arguments, $context );

		// If async mode, queue the job and return immediately.
		if ( $use_async ) {
			$queue_result = $this->queue_async_job( $arguments, $context );

			// If queueing failed (e.g., executor unavailable), fall back to sync.
			if ( is_wp_error( $queue_result ) && 'wp_mcp_ai_executor_unavailable' === $queue_result->get_error_code() ) {
				return $this->generate_video_sync( $arguments, $context );
			}

			return $queue_result;
		}

		// Execute synchronously.
		return $this->generate_video_sync( $arguments, $context );
	}

	/**
	 * Determine if async mode should be used.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return bool True if async mode should be used.
	 */
	protected function should_use_async( $arguments, $context = array() ) {
		// CRITICAL: If already running in async executor context, do NOT use tool-level async.
		// This prevents double-async execution.
		if ( isset( $context['in_async_executor'] ) && $context['in_async_executor'] ) {
			return false;
		}

		// Check if explicitly set in arguments.
		if ( isset( $arguments['async'] ) ) {
			return (bool) $arguments['async'];
		}

		// Default to async for video generation (can take minutes).
		return true;
	}

	/**
	 * Queue an async job via the tool async executor.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Async job info with job_id and status, or error.
	 */
	protected function queue_async_job( $arguments, $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
			return new WP_Error(
				'wp_mcp_ai_executor_unavailable',
				__( 'The async tool executor is unavailable.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$job_id   = $executor->queue_tool( $this->get_slug(), $arguments, $context );

		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		WP_MCP_AI_Logger::log_event(
			'higgsfield_video_queued',
			'Higgsfield video generation queued',
			array(
				'job_id' => $job_id,
				'prompt' => substr( $arguments['prompt'], 0, 100 ),
			)
		);

		$expected_metadata = $this->get_async_pending_metadata( $job_id, $arguments, $context );

		return array(
			'success'           => true,
			'async'             => true,
			'status'            => 'pending',
			'job_id'            => $job_id,
			'message'           => $expected_metadata['message'],
			'expected_url'      => $expected_metadata['expected_url'],
			'expected_filename' => $expected_metadata['expected_filename'],
		);
	}

	/**
	 * Build the per-model request payload from canonical arguments.
	 *
	 * The Higgsfield endpoints reject unknown fields, so every payload key is
	 * gated by the selected model's definition.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Payload (endpoint + body) or error.
	 */
	protected function build_request_payload( $arguments ) {
		$defaults = $this->get_configured_defaults();

		// Gate 1 — sanitize all inputs at entry.
		$prompt = sanitize_textarea_field( $arguments['prompt'] );

		// Note: model IDs contain dots (e.g. wan-3.0) — sanitize_text_field, NOT
		// sanitize_key, which would strip them and break catalog lookups.
		$model      = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : $defaults['model'];
		$definition = $this->get_model_definition( $model );

		$duration = isset( $arguments['duration'] ) ? absint( $arguments['duration'] ) : $defaults['duration'];
		$duration = max( $definition['duration'][0], min( $definition['duration'][1], $duration ) );

		$resolution = isset( $arguments['resolution'] ) ? sanitize_text_field( $arguments['resolution'] ) : $defaults['resolution'];
		if ( ! in_array( $resolution, $definition['resolutions'], true ) ) {
			$resolution = ! empty( $definition['resolutions'] ) ? $definition['resolutions'][ count( $definition['resolutions'] ) - 1 ] : '';
		}

		$aspect_ratio = isset( $arguments['aspect_ratio'] ) ? sanitize_text_field( $arguments['aspect_ratio'] ) : $defaults['aspect_ratio'];
		if ( ! in_array( $aspect_ratio, $definition['aspects'], true ) ) {
			$aspect_ratio = ! empty( $definition['aspects'] ) ? $definition['aspects'][0] : '';
		}

		$generate_audio = isset( $arguments['generate_audio'] ) ? (bool) $arguments['generate_audio'] : $defaults['generate_audio'];

		// Reference media: resolve attachment ID and/or public URLs.
		$image_urls = array();
		$video_urls = array();
		$audio_urls = array();

		if ( ! empty( $arguments['reference_image_id'] ) ) {
			$attachment_id = absint( $arguments['reference_image_id'] );
			$mime_type     = get_post_mime_type( $attachment_id );
			if ( $mime_type && 0 === strpos( $mime_type, 'image/' ) ) {
				$attachment_url = wp_get_attachment_url( $attachment_id );
				if ( $attachment_url ) {
					$image_urls[] = $attachment_url;
				}
			} else {
				return new WP_Error(
					'wp_mcp_ai_not_image',
					__( 'The provided attachment is not an image.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( ! empty( $arguments['reference_image_url'] ) ) {
			$reference_url = $this->sanitize_reference_url( $arguments['reference_image_url'] );
			if ( is_wp_error( $reference_url ) ) {
				return $reference_url;
			}
			$image_urls[] = $reference_url;
		}

		if ( ! empty( $arguments['reference_video_url'] ) ) {
			$reference_url = $this->sanitize_reference_url( $arguments['reference_video_url'] );
			if ( is_wp_error( $reference_url ) ) {
				return $reference_url;
			}
			$video_urls[] = $reference_url;
		}

		if ( ! empty( $arguments['reference_audio_url'] ) ) {
			$reference_url = $this->sanitize_reference_url( $arguments['reference_audio_url'] );
			if ( is_wp_error( $reference_url ) ) {
				return $reference_url;
			}
			$audio_urls[] = $reference_url;
		}

		$has_references = ( ! empty( $image_urls ) || ! empty( $video_urls ) || ! empty( $audio_urls ) );

		// Select endpoint: reference endpoint when references exist, else text-to-video.
		if ( $has_references && empty( $definition['endpoint_ref'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_higgsfield_refs_unsupported',
				sprintf(
					/* translators: %s: model ID */
					__( 'The %s model does not accept reference media on this endpoint. Use text-only generation or choose a model with reference support (cinema-studio-4.0, seedance-2.5, seedance-2.0, or wan-3.0).', 'mcp-ai-wpoos' ),
					$model
				),
				array( 'status' => 400 )
			);
		}

		$endpoint = $has_references ? $definition['endpoint_ref'] : $definition['endpoint_t2v'];

		// Build the body with only model-supported fields.
		$payload = array(
			'prompt'   => $prompt,
			'duration' => $duration,
		);

		if ( '' !== $resolution ) {
			$payload['resolution'] = $resolution;
		}

		if ( '' !== $aspect_ratio ) {
			$payload['aspect_ratio'] = $aspect_ratio;
		}

		// Serialise the audio toggle in the model's native form.
		if ( '' !== $definition['audio_field'] ) {
			$audio_value = $generate_audio;
			if ( isset( $definition['audio_values'][ $generate_audio ] ) ) {
				$audio_value = $definition['audio_values'][ $generate_audio ];
			}
			$payload[ $definition['audio_field'] ] = $audio_value;
		}

		// Reference arrays, clamped to per-model maxima.
		if ( ! empty( $image_urls ) && isset( $definition['refs']['image'] ) ) {
			$payload['image_urls'] = array_values( array_unique( array_slice( $image_urls, 0, $definition['refs']['image'] ) ) );
		}
		if ( ! empty( $video_urls ) && isset( $definition['refs']['video'] ) ) {
			$payload['video_urls'] = array_values( array_unique( array_slice( $video_urls, 0, $definition['refs']['video'] ) ) );
		}
		if ( ! empty( $audio_urls ) && isset( $definition['refs']['audio'] ) ) {
			$payload['audio_urls'] = array_values( array_unique( array_slice( $audio_urls, 0, $definition['refs']['audio'] ) ) );
		}

		// wan-3.0 extras.
		if ( 'wan-3.0' === $model && isset( $arguments['seed'] ) ) {
			$seed = absint( $arguments['seed'] );
			if ( $seed > 0 ) {
				$payload['seed'] = min( 2147483647, $seed );
			}
		}

		// Cinema Studio creative controls (ignored for other models).
		if ( ! empty( $definition['creative'] ) ) {
			if ( ! empty( $arguments['camera_movement'] ) ) {
				$camera_movement = sanitize_key( $arguments['camera_movement'] );
				if ( in_array( $camera_movement, self::ALLOWED_CAMERA_MOVEMENTS, true ) ) {
					$payload['camera_movement'] = $camera_movement;
				}
			}

			if ( ! empty( $arguments['genre'] ) ) {
				$genre = sanitize_text_field( $arguments['genre'] );
				if ( in_array( $genre, self::ALLOWED_GENRES, true ) ) {
					$payload['genre'] = $genre;
				}
			}

			if ( ! empty( $arguments['era'] ) ) {
				$era = sanitize_text_field( $arguments['era'] );
				if ( in_array( $era, self::ALLOWED_ERAS, true ) ) {
					$payload['era'] = $era;
				}
			}
		}

		return array(
			'endpoint'   => $endpoint,
			'payload'    => $payload,
			'model'      => $model,
			'prompt'     => $prompt,
			'duration'   => $duration,
			'resolution' => $resolution,
			'aspect'     => $aspect_ratio,
		);
	}

	/**
	 * Validate and sanitise a reference media URL.
	 *
	 * @param string $url Candidate URL.
	 * @return string|WP_Error Sanitised URL or error.
	 */
	protected function sanitize_reference_url( $url ) {
		$url = esc_url_raw( $url, array( 'https', 'http' ) );

		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_reference_url',
				__( 'The reference media URL is invalid. It must be a publicly reachable http(s) URL.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		return $url;
	}

	/**
	 * Generate video synchronously.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Generation result or error.
	 */
	protected function generate_video_sync( $arguments, $context ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$request = $this->build_request_payload( $arguments );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$timeout = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 300;
		$timeout = max( 60, min( 600, $timeout ) );

		$client = new WP_MCP_AI_Higgsfield_Client();

		WP_MCP_AI_Logger::log_event(
			'higgsfield_video_request',
			'Sending Higgsfield video generation request',
			array(
				'model'      => $request['model'],
				'resolution' => $request['resolution'],
				'duration'   => $request['duration'],
			)
		);

		// Submit the generation request.
		$handle = $client->submit_request( $request['endpoint'], $request['payload'] );

		if ( is_wp_error( $handle ) ) {
			WP_MCP_AI_Logger::log_error(
				'Higgsfield video generation request failed',
				array(
					'model' => $request['model'],
					'error' => $handle->get_error_message(),
				)
			);
			return $handle;
		}

		$request_id = $handle['request_id'];

		WP_MCP_AI_Logger::log_event(
			'higgsfield_video_job_created',
			'Higgsfield video job created',
			array(
				'model'      => $request['model'],
				'request_id' => $request_id,
				'status'     => $handle['status'],
			)
		);

		// Poll until a terminal state (backoff + jitter inside the client).
		$outputs = $client->wait_for_completion( $request_id, $timeout );

		if ( is_wp_error( $outputs ) ) {
			WP_MCP_AI_Logger::log_error(
				'Higgsfield video generation did not complete',
				array(
					'model'      => $request['model'],
					'request_id' => $request_id,
					'error'      => $outputs->get_error_message(),
				)
			);
			return $outputs;
		}

		if ( empty( $outputs['video_url'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_higgsfield_missing_output',
				__( 'The request completed but no video output was returned.', 'mcp-ai-wpoos' ),
				array(
					'status'     => 500,
					'request_id' => $request_id,
				)
			);
		}

		// Download the completed video.
		$video_data = $client->download_file( $outputs['video_url'], $timeout );

		if ( is_wp_error( $video_data ) ) {
			WP_MCP_AI_Logger::log_error(
				'Higgsfield video download failed',
				array(
					'request_id' => $request_id,
					'error'      => $video_data->get_error_message(),
				)
			);
			return $video_data;
		}

		// Calculate cost (Higgsfield bills per second of output).
		$cost = $this->calculate_video_cost( $request['duration'], $request['model'] );

		// Save to media library if requested.
		$save_to_media = isset( $arguments['save_to_media'] ) ? (bool) $arguments['save_to_media'] : true;

		if ( $save_to_media ) {
			$job_id      = isset( $context['parent_job_id'] ) ? sanitize_key( $context['parent_job_id'] ) : '';
			$save_result = $this->save_video_to_media( $video_data, $request['prompt'], $request['model'], $user_id, $job_id, $request_id );

			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}

			$edit_url = admin_url( 'post.php?post=' . $save_result['attachment_id'] . '&action=edit' );

			$text_parts   = array();
			$text_parts[] = sprintf(
				/* translators: %d: attachment ID */
				__( 'Successfully generated video (ID: %d).', 'mcp-ai-wpoos' ),
				$save_result['attachment_id']
			);
			$text_parts[] = sprintf(
				/* translators: 1: model, 2: duration in seconds, 3: resolution, 4: aspect ratio */
				__( 'Model: %1$s. Format: %2$ds, %3$s, %4$s', 'mcp-ai-wpoos' ),
				$request['model'],
				$request['duration'],
				$request['resolution'],
				$request['aspect']
			);

			$final_result = array(
				'success'       => true,
				'attachment_id' => $save_result['attachment_id'],
				'url'           => $save_result['url'],
				'file_name'     => isset( $save_result['file_name'] ) ? $save_result['file_name'] : '',
				'edit_url'      => $edit_url,
				'prompt'        => $request['prompt'],
				'duration'      => $request['duration'],
				'resolution'    => $request['resolution'],
				'aspect_ratio'  => $request['aspect'],
				'model'         => $request['model'],
				'provider'      => 'higgsfield',
				'request_id'    => $request_id,
				'cost'          => $cost,
				'message'       => sprintf(
					/* translators: 1: attachment ID, 2: media library edit URL */
					__( 'Video generated successfully and saved as <a href="%2$s" target="_blank">attachment ID %1$d</a>.', 'mcp-ai-wpoos' ),
					$save_result['attachment_id'],
					esc_url( $edit_url )
				),
				'text'          => implode( ' ', $text_parts ),
			);

			// Fire completion action.
			if ( ! empty( $context['agentic_loop'] ) ) {
				/**
				 * Fires when a Higgsfield video generation completes successfully in chat context.
				 *
				 * @param array $final_result Video generation result with attachment info.
				 * @param array $arguments    Original generation arguments.
				 * @param array $context      Execution context.
				 */
				do_action( 'wp_mcp_ai_higgsfield_video_completed', $final_result, $arguments, $context );
			}

			// Add rendered video HTML to the response for display in chat UI.
			$final_result = $this->add_video_html_to_response( $final_result );

			return $final_result;
		}

		// Return video data URL.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Benign use: binary video encoded into a data URL for temporary (non-Media-Library) responses.
		$video_base64 = base64_encode( $video_data );
		$data_url     = 'data:video/mp4;base64,' . $video_base64;

		$final_result = array(
			'success'      => true,
			'video_url'    => $data_url,
			'prompt'       => $request['prompt'],
			'duration'     => $request['duration'],
			'resolution'   => $request['resolution'],
			'aspect_ratio' => $request['aspect'],
			'model'        => $request['model'],
			'provider'     => 'higgsfield',
			'request_id'   => $request_id,
			'cost'         => $cost,
			'message'      => __( 'Video generated successfully (temporary - not saved to Media Library).', 'mcp-ai-wpoos' ),
			'text'         => sprintf(
				/* translators: 1: duration in seconds, 2: resolution */
				__( 'Successfully generated temporary video. Format: %1$ds, %2$s', 'mcp-ai-wpoos' ),
				$request['duration'],
				$request['resolution']
			),
		);

		// Fire completion action.
		if ( ! empty( $context['agentic_loop'] ) ) {
			/**
			 * Fires when a Higgsfield video generation completes successfully in chat context.
			 *
			 * @param array $final_result Video generation result with data URL.
			 * @param array $arguments    Original generation arguments.
			 * @param array $context      Execution context.
			 */
			do_action( 'wp_mcp_ai_higgsfield_video_completed', $final_result, $arguments, $context );
		}

		return $final_result;
	}

	/**
	 * Save generated video to Media Library.
	 *
	 * @param string $video_data Video binary data.
	 * @param string $prompt     Generation prompt.
	 * @param string $model      Model used.
	 * @param int    $user_id    User ID for ownership.
	 * @param string $job_id     Optional job ID for tracking.
	 * @param string $request_id Optional Higgsfield request ID.
	 * @return array|WP_Error Attachment result array or error.
	 */
	protected function save_video_to_media( $video_data, $prompt, $model, $user_id, $job_id = '', $request_id = '' ) {
		// Generate filename.
		if ( ! empty( $job_id ) ) {
			$filename = 'higgsfield-video-' . sanitize_file_name( $job_id ) . '.mp4';
		} elseif ( ! empty( $request_id ) ) {
			$filename = 'higgsfield-video-' . sanitize_file_name( $request_id ) . '.mp4';
		} else {
			$filename = 'higgsfield-video-' . wp_generate_password( 12, false ) . '.mp4';
		}

		// Include WordPress file functions if not already loaded.
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Upload video.
		$upload = wp_upload_bits( $filename, null, $video_data );

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
				/* translators: 1: model, 2: truncated prompt */
				__( 'Higgsfield Video (%1$s): %2$s', 'mcp-ai-wpoos' ),
				$model,
				substr( $prompt, 0, 50 )
			),
			'post_content'   => $prompt,
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Add metadata.
		$metadata = array(
			'higgsfield_prompt' => $prompt,
			'higgsfield_model'  => $model,
			'provider'          => 'higgsfield',
		);

		if ( ! empty( $job_id ) ) {
			$metadata['higgsfield_job_id'] = sanitize_key( $job_id );
		}

		if ( ! empty( $request_id ) ) {
			$metadata['higgsfield_request_id'] = sanitize_text_field( $request_id );
		}

		foreach ( $metadata as $key => $value ) {
			update_post_meta( $attachment_id, '_' . $key, $value );
		}

		// Generate attachment metadata.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'wp_read_video_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		WP_MCP_AI_Logger::log_event(
			'higgsfield_video_saved',
			'Higgsfield generated video saved to Media Library',
			array(
				'attachment_id' => $attachment_id,
				'filename'      => $filename,
				'model'         => $model,
				'job_id'        => $job_id,
				'request_id'    => $request_id,
			)
		);

		// Return attachment result.
		return WP_MCP_AI_Media_URL_Utils::build_attachment_result( $attachment_id, $upload );
	}

	/**
	 * Calculate cost for video generation.
	 *
	 * Higgsfield bills per second of output from a prepaid USD balance.
	 * List rates live on the Higgsfield catalog; when no per-second pricing
	 * is registered locally, the cost is flagged as estimated.
	 *
	 * @param int    $duration Duration in seconds.
	 * @param string $model    Model ID.
	 * @return array Cost data array.
	 */
	protected function calculate_video_cost( $duration, $model ) {
		// Load cost calculator.
		if ( ! class_exists( 'WP_MCP_AI_Cost_Calculator' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cost-calculator.php';
		}

		$pricing = WP_MCP_AI_Cost_Calculator::get_model_pricing( 'higgsfield', $model );

		$cost = array(
			'cost_usd'     => 0.0,
			'provider'     => 'higgsfield',
			'model'        => $model,
			'is_estimated' => false,
		);

		if ( isset( $pricing['per_second'] ) ) {
			$cost_per_second  = (float) $pricing['per_second'];
			$cost['cost_usd'] = round( $cost_per_second * $duration, 6 );
		} else {
			// Mark as estimated if no pricing available.
			$cost['is_estimated'] = true;
		}

		return $cost;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'video_producer', 'content_creator' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials', // Requires Higgsfield API key pair.
			'requires-capability',  // Requires upload_files capability.
			'write',                // Creates video files.
			'external-api',         // Makes external API requests.
			'network-dependent',    // Requires internet connection.
			'consumes-tokens',      // Uses AI credits.
			'async',                // Takes significant time.
			'long-running',         // Video generation is async.
			'background-only',      // Must run in background.
			'rate-limited',         // Subject to API rate limits.
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
	 * @param mixed $result Tool execution result.
	 * @return mixed Sanitized result with only metadata.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Strip base64-encoded video data URL if present.
		if ( isset( $result['video_url'] ) && is_string( $result['video_url'] ) ) {
			if ( 0 === strpos( $result['video_url'], 'data:video/' ) ) {
				unset( $result['video_url'] );
				$result['video_data_stripped'] = true;
			}
		}

		// Keep only essential metadata.
		$keep_fields = array(
			'success',
			'attachment_id',
			'url',
			'file_name',
			'edit_url',
			'async',
			'status',
			'job_id',
			'parent_job_id',
			'expected_filename',
			'expected_url',
			'prompt',
			'duration',
			'resolution',
			'aspect_ratio',
			'model',
			'provider',
			'request_id',
			'message',
			'video_data_stripped',
			'usage',
			'cost',
			'text',
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		// Add video_url structure for the chat client.
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
	 * @param string $job_id    The async job identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array Metadata including expected_url and expected_filename.
	 */
	public function get_async_pending_metadata( $job_id, array $arguments = array(), array $context = array() ) {
		$expected_filename = 'higgsfield-video-' . sanitize_file_name( $job_id ) . '.mp4';

		$expected_url = '';
		$upload_dir   = wp_upload_dir();
		if ( ! empty( $upload_dir['url'] ) && empty( $upload_dir['error'] ) ) {
			$expected_url = trailingslashit( $upload_dir['url'] ) . $expected_filename;
		}

		$message = sprintf(
			/* translators: 1: expected filename, 2: job ID */
			__( 'Video generation started. Your video (%1$s) is being created and will be available within approximately 10 minutes. Job ID: %2$s', 'mcp-ai-wpoos' ),
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
