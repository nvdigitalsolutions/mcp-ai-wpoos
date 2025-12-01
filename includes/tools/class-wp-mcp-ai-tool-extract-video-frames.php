<?php
/**
 * Tool for extracting specific frames from video files.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';

/**
 * Extracts frames from videos at specific timestamps or intervals for analysis.
 */
class WP_MCP_AI_Tool_Extract_Video_Frames implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'extract_video_frames';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Extract Video Frames', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Extracts specific frames from a video file at given timestamps or intervals. Useful for detailed analysis of specific moments or creating thumbnails.', 'wp-mcp-ai' );
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
					'description' => __( 'URL of the video file to extract frames from.', 'wp-mcp-ai' ),
				),
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the video to extract frames from.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'timestamps'    => array(
					'type'        => 'array',
					'description' => __( 'Specific timestamps in seconds to extract frames at. Example: [5.5, 10, 15.25]', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'number',
					),
				),
				'interval'      => array(
					'type'        => 'number',
					'description' => __( 'Extract frames at regular intervals (in seconds). Example: 5 for every 5 seconds.', 'wp-mcp-ai' ),
					'minimum'     => 0.1,
				),
				'frame_count'   => array(
					'type'        => 'integer',
					'description' => __( 'Number of frames to extract evenly distributed across the video. Default is 10, maximum is 20.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 20,
					'default'     => 10,
				),
				'save_to_media' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to save extracted frames to Media Library. Default is false (temporary files only).', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'quality'       => array(
					'type'        => 'integer',
					'description' => __( 'JPEG quality for extracted frames (1-31, lower is better quality). Default is 2.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 31,
					'default'     => 2,
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
				__( 'You do not have permission to extract video frames.', 'wp-mcp-ai' ),
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
		$file_info = $this->get_video_file_info( $arguments );

		if ( is_wp_error( $file_info ) ) {
			return $file_info;
		}

		$video_path = $file_info['file_path'];
		$temp_file  = $file_info['temp_file'];

		// Initialize frame extractor service.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-video-frame-extractor-service.php';
		$frame_extractor = new WP_MCP_AI_Video_Frame_Extractor_Service();

		// Check FFmpeg availability.
		if ( ! $frame_extractor->is_ffmpeg_available() ) {
			if ( $temp_file && file_exists( $video_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink.
				unlink( $video_path );
			}

			return new WP_Error(
				'wp_mcp_ai_ffmpeg_not_available',
				__( 'FFmpeg is not installed on this server. Frame extraction requires FFmpeg.', 'wp-mcp-ai' ),
				array(
					'status'  => 500,
					'actions' => array(
						'install_ffmpeg' => __( 'Install FFmpeg: https://ffmpeg.org/download.html', 'wp-mcp-ai' ),
					),
				)
			);
		}

		// Determine extraction mode and extract frames.
		$frame_paths = $this->extract_frames_by_mode( $frame_extractor, $video_path, $arguments );

		// Clean up temporary video file if downloaded.
		if ( $temp_file && file_exists( $video_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink.
			unlink( $video_path );
		}

		if ( is_wp_error( $frame_paths ) ) {
			return $frame_paths;
		}

		// Prepare result.
		$save_to_media = isset( $arguments['save_to_media'] ) && $arguments['save_to_media'];
		$result        = array(
			'success'     => true,
			'frame_count' => count( $frame_paths ),
			'frames'      => array(),
		);

		if ( $save_to_media ) {
			// Save frames to Media Library.
			$attachment_ids    = $this->save_frames_to_media( $frame_paths, $user_id );
			$result['frames']  = $attachment_ids;
			$result['message'] = sprintf(
				/* translators: %d: number of frames */
				__( 'Successfully extracted and saved %d frames to Media Library.', 'wp-mcp-ai' ),
				count( $attachment_ids )
			);
		} else {
			// Return base64-encoded frames (temporary).
			$base64_frames     = $frame_extractor->frames_to_base64( $frame_paths );
			$result['frames']  = $base64_frames;
			$result['message'] = sprintf(
				/* translators: %d: number of frames */
				__( 'Successfully extracted %d frames (temporary - not saved to Media Library).', 'wp-mcp-ai' ),
				count( $base64_frames )
			);
		}

		// Cleanup extracted frames.
		$frame_extractor->cleanup_frames( $frame_paths );

		return $result;
	}

	/**
	 * Get video file information from attachment or URL.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Array with file_path and temp_file flag.
	 */
	protected function get_video_file_info( $arguments ) {
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$file_path     = get_attached_file( $attachment_id );
			$mime_type     = get_post_mime_type( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_file_not_found',
					__( 'Video file not found on server.', 'wp-mcp-ai' ),
					array( 'status' => 404 )
				);
			}

			// Verify it's a video attachment.
			if ( ! $mime_type || false === strpos( $mime_type, 'video/' ) ) {
				return new WP_Error(
					'wp_mcp_ai_not_video',
					__( 'The provided attachment is not a video file.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			return array(
				'file_path' => $file_path,
				'temp_file' => false,
			);
		}

		if ( ! empty( $arguments['video_url'] ) ) {
			$video_url = esc_url_raw( $arguments['video_url'] );
			return $this->download_video_to_temp( $video_url );
		}

		return new WP_Error(
			'wp_mcp_ai_missing_video',
			__( 'Either video_url or attachment_id must be provided.', 'wp-mcp-ai' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Download video from URL to temporary file.
	 *
	 * @param string $video_url Video URL.
	 * @return array|WP_Error Array with file_path and temp_file flag.
	 */
	protected function download_video_to_temp( $video_url ) {
		$response = wp_remote_get(
			$video_url,
			array( 'timeout' => 300 )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Failed to download video. HTTP status: %d', 'wp-mcp-ai' ),
					$code
				),
				array( 'status' => $code )
			);
		}

		$body      = wp_remote_retrieve_body( $response );
		$mime_type = wp_remote_retrieve_header( $response, 'content-type' );

		if ( ! $mime_type || false === strpos( $mime_type, 'video/' ) ) {
			return new WP_Error(
				'wp_mcp_ai_not_video',
				__( 'Downloaded file is not a video.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$temp_file = wp_tempnam( 'video' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents.
		$written = file_put_contents( $temp_file, $body );

		if ( false === $written ) {
			return new WP_Error(
				'wp_mcp_ai_temp_file_failed',
				__( 'Failed to write video to temporary file.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'file_path' => $temp_file,
			'temp_file' => true,
		);
	}

	/**
	 * Extract frames based on the specified mode.
	 *
	 * @param WP_MCP_AI_Video_Frame_Extractor_Service $frame_extractor Frame extractor service.
	 * @param string                                  $video_path      Video file path.
	 * @param array                                   $arguments       Tool arguments.
	 * @return array|WP_Error Array of frame file paths or error.
	 */
	protected function extract_frames_by_mode( $frame_extractor, $video_path, $arguments ) {
		// Priority: timestamps > interval > frame_count.
		if ( ! empty( $arguments['timestamps'] ) && is_array( $arguments['timestamps'] ) ) {
			return $this->extract_frames_at_timestamps( $frame_extractor, $video_path, $arguments['timestamps'] );
		}

		if ( isset( $arguments['interval'] ) && $arguments['interval'] > 0 ) {
			return $this->extract_frames_at_interval( $frame_extractor, $video_path, floatval( $arguments['interval'] ) );
		}

		// Default: extract evenly distributed frames.
		$frame_count = isset( $arguments['frame_count'] ) ? absint( $arguments['frame_count'] ) : 10;
		return $frame_extractor->extract_frames( $video_path, $frame_count );
	}

	/**
	 * Extract frames at specific timestamps.
	 *
	 * @param WP_MCP_AI_Video_Frame_Extractor_Service $frame_extractor Frame extractor service.
	 * @param string                                  $video_path      Video file path.
	 * @param array                                   $timestamps      Array of timestamps in seconds.
	 * @return array|WP_Error Array of frame file paths or error.
	 */
	protected function extract_frames_at_timestamps( $frame_extractor, $video_path, $timestamps ) {
		// Get video duration to validate timestamps.
		$duration = $frame_extractor->get_video_duration( $video_path );
		if ( is_wp_error( $duration ) ) {
			return $duration;
		}

		// Create temporary directory for frames.
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/wp-mcp-ai-temp/frames_' . uniqid( '', true );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir.
		if ( ! mkdir( $temp_dir, 0755, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_temp_dir_failed',
				__( 'Failed to create temporary directory for frames.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		$frame_paths = array();
		$errors      = array();

		foreach ( $timestamps as $index => $timestamp ) {
			$timestamp = floatval( $timestamp );

			// Validate timestamp.
			if ( $timestamp < 0 || $timestamp > $duration ) {
				$errors[] = sprintf(
					/* translators: 1: timestamp, 2: duration */
					__( 'Timestamp %1$s is out of range (video duration: %2$s seconds)', 'wp-mcp-ai' ),
					$timestamp,
					$duration
				);
				continue;
			}

			$frame_path = $temp_dir . '/frame_' . str_pad( $index + 1, 3, '0', STR_PAD_LEFT ) . '.jpg';

			// Extract frame (service class should provide public API for this).
			$result = $this->extract_single_frame_at_timestamp( $frame_extractor, $video_path, $timestamp, $frame_path );

			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}

			if ( file_exists( $frame_path ) && filesize( $frame_path ) > 0 ) {
				$frame_paths[] = $frame_path;
			}
		}

		if ( empty( $frame_paths ) ) {
			// Clean up temp directory.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir.
			rmdir( $temp_dir );

			return new WP_Error(
				'wp_mcp_ai_no_frames',
				__( 'Failed to extract frames at specified timestamps.', 'wp-mcp-ai' ),
				array(
					'status' => 500,
					'errors' => $errors,
				)
			);
		}

		return $frame_paths;
	}

	/**
	 * Extract a single frame at a specific timestamp.
	 *
	 * Wraps the service's protected method to avoid using reflection.
	 *
	 * @param WP_MCP_AI_Video_Frame_Extractor_Service $frame_extractor Frame extractor service.
	 * @param string                                  $video_path      Video file path.
	 * @param float                                   $timestamp       Timestamp in seconds.
	 * @param string                                  $output_path     Output path for frame.
	 * @return true|WP_Error True on success, error on failure.
	 */
	protected function extract_single_frame_at_timestamp( $frame_extractor, $video_path, $timestamp, $output_path ) {
		$escaped_video       = escapeshellarg( $video_path );
		$escaped_output      = escapeshellarg( $output_path );
		$timestamp_formatted = number_format( $timestamp, 3, '.', '' );

		// FFmpeg command to extract frame at specific timestamp.
		$command = sprintf(
			'ffmpeg -ss %s -i %s -vframes 1 -q:v 2 -y %s 2>&1',
			$timestamp_formatted,
			$escaped_video,
			$escaped_output
		);

		$output      = array();
		$return_code = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec.
		exec( $command, $output, $return_code );

		if ( 0 !== $return_code ) {
			return new WP_Error(
				'wp_mcp_ai_ffmpeg_extraction_failed',
				sprintf(
					/* translators: %s: timestamp in seconds */
					__( 'FFmpeg failed to extract frame at timestamp %s', 'wp-mcp-ai' ),
					$timestamp_formatted
				),
				array(
					'status' => 500,
					'output' => implode( "\n", $output ),
				)
			);
		}

		return true;
	}

	/**
	 * Extract frames at regular intervals.
	 *
	 * @param WP_MCP_AI_Video_Frame_Extractor_Service $frame_extractor Frame extractor service.
	 * @param string                                  $video_path      Video file path.
	 * @param float                                   $interval        Interval in seconds.
	 * @return array|WP_Error Array of frame file paths or error.
	 */
	protected function extract_frames_at_interval( $frame_extractor, $video_path, $interval ) {
		// Get video duration.
		$duration = $frame_extractor->get_video_duration( $video_path );
		if ( is_wp_error( $duration ) ) {
			return $duration;
		}

		// Calculate timestamps.
		$timestamps = array();
		for ( $timestamp = 0; $timestamp < $duration; $timestamp += $interval ) {
			$timestamps[] = $timestamp;
		}

		if ( empty( $timestamps ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_interval',
				__( 'Interval is too large for the video duration.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Extract frames at calculated timestamps.
		return $this->extract_frames_at_timestamps( $frame_extractor, $video_path, $timestamps );
	}

	/**
	 * Save extracted frames to Media Library.
	 *
	 * @param array $frame_paths Array of frame file paths.
	 * @param int   $user_id     User ID for attachment ownership.
	 * @return array Array of attachment IDs and metadata.
	 */
	protected function save_frames_to_media( $frame_paths, $user_id ) {
		$attachments = array();

		foreach ( $frame_paths as $index => $frame_path ) {
			if ( ! file_exists( $frame_path ) ) {
				continue;
			}

			// Prepare file for upload using unique filename.
			$filename = 'video-frame-' . ( $index + 1 ) . '-' . uniqid( '', true ) . '.jpg';

			// Read file contents.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents.
			$file_content = file_get_contents( $frame_path );

			if ( false === $file_content ) {
				continue;
			}

			// Include WordPress file functions for wp_upload_bits() if not already loaded.
			// This is required in cron/async contexts where admin files aren't loaded.
			if ( ! function_exists( 'wp_upload_bits' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			// Use WordPress file handling.
			$upload = wp_upload_bits( $filename, null, $file_content );

			if ( ! empty( $upload['error'] ) ) {
				continue;
			}

			// Create attachment.
			$attachment = array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => sprintf(
					/* translators: %d: frame number */
					__( 'Video Frame %d', 'wp-mcp-ai' ),
					$index + 1
				),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_author'    => $user_id,
			);

			$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

			if ( ! is_wp_error( $attachment_id ) ) {
				// Generate attachment metadata.
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
				wp_update_attachment_metadata( $attachment_id, $attach_data );

				$attachments[] = array(
					'id'  => $attachment_id,
					'url' => wp_get_attachment_url( $attachment_id ),
				);
			}
		}

		return $attachments;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability',  // Requires upload_files capability.
			'write',                // Creates files/attachments.
			'external-api',         // May download remote videos.
			'network-dependent',    // May require internet for remote videos.
			'async',                // May take significant time.
			'rate-limited',         // Subject to FFmpeg processing limits.
		);
	}

	/**
	 * Sanitize video frame extraction results for LLM consumption.
	 *
	 * Frame extraction can return base64-encoded image data for each frame when
	 * save_to_media=false. Multiple frames can result in several MB of base64 data.
	 * The LLM doesn't need this binary data - it only needs metadata about the frames.
	 *
	 * @param mixed $result Tool execution result.
	 * @return mixed Sanitized result with only metadata.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Strip base64-encoded frame data if present.
		// When save_to_media=false, frames are returned as base64 data URLs.
		if ( isset( $result['frames'] ) && is_array( $result['frames'] ) ) {
			$has_base64 = false;

			// Check if frames contain base64 data (look at first frame)
			if ( ! empty( $result['frames'] ) ) {
				$first_frame = reset( $result['frames'] );
				if ( is_string( $first_frame ) && strpos( $first_frame, 'data:image/' ) === 0 ) {
					$has_base64 = true;
				}
			}

			if ( $has_base64 ) {
				// Strip base64 data but keep frame count.
				$frame_count                    = count( $result['frames'] );
				$result['frame_count']          = $frame_count;
				$result['frames_data_stripped'] = true;
				unset( $result['frames'] );
			}
		}

		// Keep only essential metadata.
		$keep_fields = array(
			'video_url',
			'attachment_id',
			'frames',  // Keep if it's attachment IDs, not base64
			'frame_count',
			'message',
			'frames_data_stripped', // Flag indicating data was stripped
			'usage',                // Token usage data for UI display.
			'cost',                 // Cost data for UI display.
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
