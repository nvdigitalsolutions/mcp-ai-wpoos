<?php
/**
 * Extended Cognition Tool — Analyze Video Feed
 *
 * Continuous video feed analysis that detects and tracks products/brands
 * across multiple frames.  Supports three video sources:
 *
 *  - camera_stream: polls N frames via the SSE sensor bridge
 *  - attachment_id: extracts frames from a WP media library video via FFmpeg
 *  - url: downloads and analyzes an external video
 *
 * Long-running analyses (thorough depth, large videos) are dispatched to
 * Action Scheduler as background jobs.  The tool returns immediately with
 * an action_id that can be polled via the Action Scheduler admin UI or
 * WP-CLI.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Video feed analysis tool for the Extended Cognition toolkit.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_Tool_Ext_Cog_Analyze_Video_Feed {

	/**
	 * Action Scheduler hook for background video analysis.
	 *
	 * @var string
	 */
	const AS_HOOK = 'wp_mcp_ai_ext_cog_analyze_video_feed';

	/**
	 * Action Scheduler group.
	 *
	 * @var string
	 */
	const AS_GROUP = 'ext_cog_video';

	/**
	 * Maximum frames to process in a single request.
	 * Longer videos are dispatched to Action Scheduler.
	 *
	 * @var int
	 */
	const SYNC_FRAME_LIMIT = 30;

	/**
	 * Get tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ext_cog_analyze_video_feed';
	}

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Analyze Video Feed (Extended Cognition)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Analyze a video feed (camera stream, uploaded video, or external URL) frame-by-frame to identify products, brands, and objects. Tracks items across frames, counts changes over time, and returns a time-series summary of product appearances. Long videos are dispatched to background processing via Action Scheduler.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'ext_cog_analyze_video_feed',
			'description'         => $this->get_description(),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'video_source'      => array(
						'type'        => 'string',
						'enum'        => array( 'camera_stream', 'attachment_id', 'url' ),
						'description' => 'Video source. "camera_stream" polls the browser camera via SSE. "attachment_id" uses a WP media library video. "url" downloads an external video. Default: camera_stream.',
						'default'     => 'camera_stream',
					),
					'session_id'        => array(
						'type'        => 'string',
						'description' => 'Active chat session ID (required for camera_stream mode).',
					),
					'attachment_id'     => array(
						'type'        => 'integer',
						'description' => 'WordPress attachment post ID (required for attachment_id mode).',
						'minimum'     => 1,
					),
					'url'               => array(
						'type'        => 'string',
						'format'      => 'uri',
						'description' => 'External video URL (required for url mode).',
					),
					'sample_rate'       => array(
						'type'        => 'integer',
						'description' => 'Frames per second to analyze. Higher values give more detail but take longer. Default: 1 (one frame per second).',
						'minimum'     => 1,
						'maximum'     => 30,
						'default'     => 1,
					),
					'analysis_depth'    => array(
						'type'        => 'string',
						'enum'        => array( 'quick', 'thorough', 'scene_change_only' ),
						'description' => 'Analysis depth. "quick" samples every Nth frame. "thorough" samples every frame at the requested rate. "scene_change_only" only samples when visual scene changes significantly. Default: quick.',
						'default'     => 'quick',
					),
					'labels_of_interest' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string', 'maxLength' => 100 ),
						'description' => 'Products/brands to look for. Falls back to Product Brand taxonomy.',
						'maxItems'    => 100,
					),
					'track_products'    => array(
						'type'        => 'boolean',
						'description' => 'Track products across frames to avoid double-counting. Default: true.',
						'default'     => true,
					),
					'max_frames'        => array(
						'type'        => 'integer',
						'description' => 'Maximum frames to process (1–600). Longer videos beyond the sync limit are dispatched to background processing. Default: 60.',
						'minimum'     => 1,
						'maximum'     => 600,
						'default'     => 60,
					),
					'timeout_ms'        => array(
						'type'        => 'integer',
						'description' => 'Max ms for synchronous processing. Default: 60000.',
						'minimum'     => 10000,
						'maximum'     => 120000,
						'default'     => 60000,
					),
				),
				'required'   => array( 'video_source' ),
			),
			'required_capability' => $this->get_required_capability(),
			'category'            => array( 'extended-cognition', 'vision', 'video-analysis' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! $this->current_user_can_use_sensors( $context ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to use sensory tools.', 'mcp-ai-wpoos-pro' ) );
		}

		// --- Sanitize ---
		$video_source   = isset( $arguments['video_source'] ) ? sanitize_text_field( $arguments['video_source'] ) : 'camera_stream';
		$session_id     = isset( $arguments['session_id'] ) ? sanitize_text_field( $arguments['session_id'] ) : '';
		$attachment_id  = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
		$url            = isset( $arguments['url'] ) ? esc_url_raw( $arguments['url'] ) : '';
		$sample_rate    = isset( $arguments['sample_rate'] ) ? absint( $arguments['sample_rate'] ) : 1;
		$analysis_depth = isset( $arguments['analysis_depth'] ) ? sanitize_text_field( $arguments['analysis_depth'] ) : 'quick';
		$track_products = ! isset( $arguments['track_products'] ) || ! empty( $arguments['track_products'] );
		$max_frames     = isset( $arguments['max_frames'] ) ? absint( $arguments['max_frames'] ) : 60;
		$timeout_ms     = isset( $arguments['timeout_ms'] ) ? absint( $arguments['timeout_ms'] ) : 60000;

		$max_frames = min( $max_frames, 600 );

		// Sanitize labels.
		$labels = array();
		if ( isset( $arguments['labels_of_interest'] ) && is_array( $arguments['labels_of_interest'] ) ) {
			$labels = array_map( 'sanitize_text_field', array_slice( $arguments['labels_of_interest'], 0, 100 ) );
		}
		if ( empty( $labels ) && class_exists( 'WP_MCP_AI_Product_Brand_Taxonomy' ) ) {
			$labels = WP_MCP_AI_Product_Brand_Taxonomy::get_brand_labels( 50 );
		}

		// Validate source-specific requirements.
		if ( 'camera_stream' === $video_source && empty( $session_id ) ) {
			return new WP_Error( 'missing_session', __( 'session_id is required for camera_stream mode.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( 'attachment_id' === $video_source && $attachment_id < 1 ) {
			return new WP_Error( 'missing_attachment', __( 'attachment_id is required for attachment_id mode.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( 'url' === $video_source && empty( $url ) ) {
			return new WP_Error( 'missing_url', __( 'url is required for url mode.', 'mcp-ai-wpoos-pro' ) );
		}

		// --- Dispatch to Action Scheduler for long jobs ---
		if ( $max_frames > self::SYNC_FRAME_LIMIT || 'thorough' === $analysis_depth ) {
			return $this->dispatch_background_job( $video_source, $session_id, $attachment_id, $url, $sample_rate, $analysis_depth, $track_products, $max_frames, $labels, $context );
		}

		// --- Synchronous path (≤ 30 frames, quick/scene_change_only) ---
		return $this->run_sync_analysis( $video_source, $session_id, $attachment_id, $url, $sample_rate, $analysis_depth, $track_products, $max_frames, $labels, $timeout_ms, $context );
	}

	/**
	 * Dispatch a background job via Action Scheduler for long video analysis.
	 *
	 * @param string $video_source   Source type.
	 * @param string $session_id     Session ID.
	 * @param int    $attachment_id  Attachment ID.
	 * @param string $url            External URL.
	 * @param int    $sample_rate    Frames per second.
	 * @param string $analysis_depth Depth mode.
	 * @param bool   $track_products Track across frames.
	 * @param int    $max_frames     Max frames.
	 * @param array  $labels         Labels of interest.
	 * @param array  $context        Execution context.
	 * @return array
	 */
	private function dispatch_background_job( $video_source, $session_id, $attachment_id, $url, $sample_rate, $analysis_depth, $track_products, $max_frames, array $labels, array $context ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new WP_Error(
				'as_unavailable',
				__( 'Action Scheduler is required for long video analysis but is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$job_args = array(
			'video_source'   => $video_source,
			'session_id'     => $session_id,
			'attachment_id'  => $attachment_id,
			'url'            => $url,
			'sample_rate'    => $sample_rate,
			'analysis_depth' => $analysis_depth,
			'track_products' => $track_products,
			'max_frames'     => $max_frames,
			'labels'         => $labels,
			'user_id'        => get_current_user_id(),
		);

		$action_id = as_enqueue_async_action( self::AS_HOOK, $job_args, self::AS_GROUP );

		if ( empty( $action_id ) ) {
			return new WP_Error(
				'as_enqueue_failed',
				__( 'Failed to enqueue video analysis background job.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		// Store action_id + user context so results can be retrieved later.
		$user_id = get_current_user_id();
		$jobs    = get_option( 'wp_mcp_ai_ext_cog_video_jobs', array() );
		$jobs    = is_array( $jobs ) ? $jobs : array();

		$jobs[ $action_id ] = array(
			'action_id'     => $action_id,
			'user_id'       => $user_id,
			'video_source'  => $video_source,
			'max_frames'    => $max_frames,
			'status'        => 'pending',
			'created_at'    => time(),
		);

		// Keep only last 100 jobs.
		if ( count( $jobs ) > 100 ) {
			$jobs = array_slice( $jobs, -100, 100, true );
		}

		update_option( 'wp_mcp_ai_ext_cog_video_jobs', $jobs, false );

		return array(
			'success'       => true,
			'processing_mode' => 'background',
			'action_id'     => $action_id,
			'max_frames'    => $max_frames,
			'message'       => sprintf(
				/* translators: %d: action scheduler ID */
				__( 'Video analysis dispatched to background queue (job #%d). Results will be stored on the session when complete. Check Action Scheduler admin for progress.', 'mcp-ai-wpoos-pro' ),
				$action_id
			),
		);
	}

	/**
	 * Run synchronous frame-by-frame analysis.
	 *
	 * @param string $video_source   Source type.
	 * @param string $session_id     Session ID.
	 * @param int    $attachment_id  Attachment ID.
	 * @param string $url            External URL.
	 * @param int    $sample_rate    Frames per second.
	 * @param string $analysis_depth Depth mode.
	 * @param bool   $track_products Track across frames.
	 * @param int    $max_frames     Max frames.
	 * @param array  $labels         Labels of interest.
	 * @param int    $timeout_ms     Timeout.
	 * @param array  $context        Execution context.
	 * @return array|WP_Error
	 */
	private function run_sync_analysis( $video_source, $session_id, $attachment_id, $url, $sample_rate, $analysis_depth, $track_products, $max_frames, array $labels, $timeout_ms, array $context ) {
		// --- Acquire frames ---
		if ( 'camera_stream' === $video_source ) {
			$frames = $this->capture_stream_frames( $session_id, $sample_rate, $max_frames, $timeout_ms, $context );
		} elseif ( 'attachment_id' === $video_source ) {
			$frames = $this->extract_attachment_frames( $attachment_id, $sample_rate, $max_frames, $analysis_depth );
		} else {
			$frames = $this->download_and_extract_frames( $url, $sample_rate, $max_frames, $analysis_depth );
		}

		if ( is_wp_error( $frames ) ) {
			return $frames;
		}

		if ( empty( $frames ) ) {
			return new WP_Error( 'no_frames', __( 'No frames could be extracted from the video source.', 'mcp-ai-wpoos-pro' ) );
		}

		// --- Analyze each frame ---
		$service      = new WP_MCP_AI_HF_Vision_Inference_Service();
		$frame_results = array();
		$all_brands    = array();
		$brand_timeline = array();

		foreach ( $frames as $index => $frame_base64 ) {
			$result = $service->run_brand_detection_pipeline( $frame_base64, $labels, '', '', 0.5 );

			if ( is_wp_error( $result ) ) {
				$frame_results[] = array(
					'frame_index' => $index,
					'error'       => $result->get_error_message(),
				);
				continue;
			}

			$frame_data = array(
				'frame_index'  => $index,
				'detections'   => $result['detections'],
				'brands_found' => $result['brands_found'],
			);

			$frame_results[] = $frame_data;

			// Aggregate brands.
			foreach ( $result['brands_found'] as $brand ) {
				$all_brands[] = $brand;
				$brand_timeline[ $index ] = isset( $brand_timeline[ $index ] )
					? array_merge( $brand_timeline[ $index ], array( $brand ) )
					: array( $brand );
			}
		}

		$unique_brands = array_values( array_unique( $all_brands ) );
		$brand_counts  = array_count_values( $all_brands );
		arsort( $brand_counts );

		return array(
			'success'         => true,
			'processing_mode' => 'sync',
			'frames_analyzed' => count( $frame_results ),
			'total_frames'    => count( $frames ),
			'unique_brands'   => $unique_brands,
			'brand_counts'    => $brand_counts,
			'brand_timeline'  => $track_products ? $brand_timeline : array(),
			'frame_results'   => $frame_results,
			'message'         => sprintf(
				/* translators: 1: frame count, 2: unique brand count */
				__( 'Analyzed %1$d frames and found %2$d unique brands.', 'mcp-ai-wpoos-pro' ),
				count( $frame_results ),
				count( $unique_brands )
			),
		);
	}

	/**
	 * Capture N frames from the browser camera via the SSE sensor bridge.
	 *
	 * @param string $session_id  Session ID.
	 * @param int    $sample_rate Frames per second (approximate).
	 * @param int    $max_frames  Max frames.
	 * @param int    $timeout_ms  Timeout.
	 * @param array  $context     Execution context.
	 * @return array|WP_Error Array of base64 image strings.
	 */
	private function capture_stream_frames( $session_id, $sample_rate, $max_frames, $timeout_ms, array $context ) {
		$settings = wp_mcp_ai_ext_cog_get_settings();

		if ( empty( $settings['sensor_camera'] ) ) {
			return new WP_Error( 'sensor_disabled', __( 'Camera sensor is disabled.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! is_ssl() && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return new WP_Error( 'https_required', __( 'HTTPS required for camera access.', 'mcp-ai-wpoos-pro' ) );
		}

		$user_id = get_current_user_id();
		$post_id = WP_MCP_AI_Ext_Cog_Sensor_Session::get_or_create( $session_id, $user_id );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$rate_limit = absint( $settings['rate_limit'] );
		$interval_us = $sample_rate > 0 ? (int) ( 1000000 / $sample_rate ) : 1000000;

		$timeout_s    = ceil( $timeout_ms / 1000 );
		$start_time   = time();
		$frames       = array();

		for ( $i = 0; $i < $max_frames; $i++ ) {
			if ( ( time() - $start_time ) >= $timeout_s ) {
				break;
			}

			if ( ! WP_MCP_AI_Ext_Cog_Sensor_Session::check_rate_limit( $post_id, 'camera', $rate_limit ) ) {
				break;
			}

			$request_id = wp_generate_uuid4();
			WP_MCP_AI_Ext_Cog_Sensor_Session::push_request(
				$post_id,
				array(
					'type'       => 'capture_visual',
					'request_id' => $request_id,
					'resolution' => array( 'width' => 640, 'height' => 480 ),
					'store'      => false,
				)
			);

			// Wait for response (max 5s per frame).
			$frame_start = time();
			$captured    = null;

			while ( ( time() - $frame_start ) < 5 ) {
				$data = WP_MCP_AI_Ext_Cog_Sensor_Session::consume_data( $post_id, $request_id );
				if ( null !== $data ) {
					$captured = $data;
					break;
				}
				usleep( 300000 );
			}

			if ( null !== $captured && ! empty( $captured['image_base64'] ) ) {
				$frames[] = $captured['image_base64'];
			}

			// Respect sample rate between captures.
			if ( $i < $max_frames - 1 ) {
				usleep( $interval_us );
			}
		}

		return $frames;
	}

	/**
	 * Extract frames from a WordPress media attachment video via FFmpeg.
	 *
	 * @param int    $attachment_id  Attachment post ID.
	 * @param int    $sample_rate    Frames per second.
	 * @param int    $max_frames     Max frames.
	 * @param string $analysis_depth Depth mode.
	 * @return array|WP_Error Array of base64 image strings.
	 */
	private function extract_attachment_frames( $attachment_id, $sample_rate, $max_frames, $analysis_depth ) {
		$video_path = get_attached_file( $attachment_id );
		if ( ! $video_path || ! file_exists( $video_path ) ) {
			return new WP_Error( 'video_not_found', __( 'Video file not found in media library.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Video_Frame_Extractor_Service' ) ) {
			return new WP_Error( 'ffmpeg_unavailable', __( 'Video frame extractor service is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$extractor = new WP_MCP_AI_Video_Frame_Extractor_Service();
		$frame_count = min( $max_frames, 'thorough' === $analysis_depth ? $max_frames : max( 5, (int) ( $max_frames / 5 ) ) );

		$frame_paths = $extractor->extract_frames( $video_path, $frame_count );
		if ( is_wp_error( $frame_paths ) ) {
			return $frame_paths;
		}

		$frames = array();
		foreach ( $frame_paths as $path ) {
			if ( file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$data = file_get_contents( $path );
				if ( false !== $data ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					$frames[] = base64_encode( $data );
				}
			}
		}

		return $frames;
	}

	/**
	 * Download an external video and extract frames.
	 *
	 * @param string $url            External video URL.
	 * @param int    $sample_rate    Frames per second.
	 * @param int    $max_frames     Max frames.
	 * @param string $analysis_depth Depth mode.
	 * @return array|WP_Error
	 */
	private function download_and_extract_frames( $url, $sample_rate, $max_frames, $analysis_depth ) {
		// Download to temp file.
		$temp_file = wp_tempnam( 'ext_cog_video_' );
		if ( ! $temp_file ) {
			return new WP_Error( 'temp_file_error', __( 'Could not create temporary file.', 'mcp-ai-wpoos-pro' ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $temp_file,
			)
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $temp_file );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			@unlink( $temp_file );
			return new WP_Error(
				'download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Failed to download video (HTTP %d).', 'mcp-ai-wpoos-pro' ),
					$code
				)
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Video_Frame_Extractor_Service' ) ) {
			@unlink( $temp_file );
			return new WP_Error( 'ffmpeg_unavailable', __( 'Video frame extractor service is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$extractor   = new WP_MCP_AI_Video_Frame_Extractor_Service();
		$frame_count = min( $max_frames, 'thorough' === $analysis_depth ? $max_frames : max( 5, (int) ( $max_frames / 5 ) ) );

		$frame_paths = $extractor->extract_frames( $temp_file, $frame_count );

		// Clean up temp file.
		@unlink( $temp_file );

		if ( is_wp_error( $frame_paths ) ) {
			return $frame_paths;
		}

		$frames = array();
		foreach ( $frame_paths as $path ) {
			if ( file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$data = file_get_contents( $path );
				if ( false !== $data ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					$frames[] = base64_encode( $data );
				}
			}
		}

		return $frames;
	}

	/**
	 * Check if the current user (or guest) is allowed to use sensors.
	 *
	 * @param array $context Execution context.
	 * @return bool
	 */
	private function current_user_can_use_sensors( array $context ) {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		$settings = wp_mcp_ai_ext_cog_get_settings();
		if ( ! empty( $settings['guest_access'] ) && ! empty( $context['guest_request'] ) ) {
			return true;
		}

		return false;
	}
}
