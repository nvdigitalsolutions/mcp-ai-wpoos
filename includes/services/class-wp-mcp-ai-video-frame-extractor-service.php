<?php
/**
 * Video Frame Extractor Service
 *
 * Extracts key frames from video files using FFmpeg for use with OpenAI Vision API.
 * Supports multiple video formats and provides intelligent frame selection.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Video Frame Extractor Service class
 *
 * Responsible for:
 * - Checking FFmpeg availability
 * - Extracting frames from video files
 * - Intelligent frame selection (key frames, intervals)
 * - Managing temporary frame files
 * - Cleanup of extracted frames
 *
 * SoC Architecture:
 * - Video Analysis Service uses this service for frame extraction
 * - Service handles all FFmpeg interaction and file management
 * - Returns frame file paths for use with OpenAI Vision API
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Video_Frame_Extractor_Service {

	/**
	 * Default number of frames to extract
	 *
	 * @var int
	 */
	private $default_frame_count = 10;

	/**
	 * Maximum number of frames to extract
	 *
	 * @var int
	 */
	private $max_frame_count = 20;

	/**
	 * Frame quality (1-31, lower is better)
	 *
	 * @var int
	 */
	private $frame_quality = 2;

	/**
	 * Constructor
	 *
	 * @param int $default_frame_count Default number of frames to extract.
	 * @param int $max_frame_count     Maximum number of frames to extract.
	 */
	public function __construct( $default_frame_count = 10, $max_frame_count = 20 ) {
		$this->default_frame_count = absint( $default_frame_count );
		$this->max_frame_count     = absint( $max_frame_count );
	}

	/**
	 * Check if FFmpeg is available on the system
	 *
	 * @return bool True if FFmpeg is available, false otherwise.
	 */
	public function is_ffmpeg_available() {
		// Try to execute FFmpeg version command.
		$output      = array();
		$return_code = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( 'ffmpeg -version 2>&1', $output, $return_code );

		// FFmpeg should return 0 and have output containing 'ffmpeg version'.
		if ( 0 === $return_code && ! empty( $output ) ) {
			$first_line = $output[0];
			if ( false !== stripos( $first_line, 'ffmpeg version' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get video duration in seconds using FFprobe
	 *
	 * @param string $video_path Path to video file.
	 * @return float|WP_Error Duration in seconds, or error.
	 */
	public function get_video_duration( $video_path ) {
		if ( ! file_exists( $video_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_video_not_found',
				__( 'Video file not found.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		// Use FFprobe to get duration.
		$escaped_path = escapeshellarg( $video_path );
		$command      = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 {$escaped_path} 2>&1";

		$output      = array();
		$return_code = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $return_code );

		if ( 0 !== $return_code || empty( $output ) ) {
			return new WP_Error(
				'wp_mcp_ai_ffprobe_failed',
				__( 'Failed to determine video duration. FFprobe returned an error.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		$duration = floatval( trim( $output[0] ) );

		if ( $duration <= 0 ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_duration',
				__( 'Invalid video duration detected.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		return $duration;
	}

	/**
	 * Extract frames from video at regular intervals
	 *
	 * @param string $video_path  Path to video file.
	 * @param int    $frame_count Number of frames to extract.
	 * @return array|WP_Error Array of frame file paths, or error.
	 */
	public function extract_frames( $video_path, $frame_count = null ) {
		// Check FFmpeg availability.
		if ( ! $this->is_ffmpeg_available() ) {
			return new WP_Error(
				'wp_mcp_ai_ffmpeg_not_found',
				__( 'FFmpeg is not installed or not available. Please install FFmpeg to enable video frame extraction for OpenAI.', 'wp-mcp-ai' ),
				array(
					'status'  => 500,
					'actions' => array(
						'install_ffmpeg' => __( 'Install FFmpeg on your server: https://ffmpeg.org/download.html', 'wp-mcp-ai' ),
					),
				)
			);
		}

		if ( ! file_exists( $video_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_video_not_found',
				__( 'Video file not found.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		// Validate and sanitize frame count.
		if ( null === $frame_count ) {
			$frame_count = $this->default_frame_count;
		}
		$frame_count = absint( $frame_count );
		if ( $frame_count > $this->max_frame_count ) {
			$frame_count = $this->max_frame_count;
		}
		if ( $frame_count < 1 ) {
			$frame_count = 1;
		}

		// Get video duration.
		$duration = $this->get_video_duration( $video_path );
		if ( is_wp_error( $duration ) ) {
			return $duration;
		}

		// Create temporary directory for frames.
		$temp_dir = $this->create_temp_directory();
		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		// Calculate frame interval (extract frames at regular intervals).
		$interval = $duration / ( $frame_count + 1 );

		// Extract frames using FFmpeg.
		$frame_paths = array();
		$errors      = array();

		for ( $i = 1; $i <= $frame_count; $i++ ) {
			$timestamp   = $i * $interval;
			$frame_path  = $temp_dir . '/frame_' . str_pad( $i, 3, '0', STR_PAD_LEFT ) . '.jpg';
			$result      = $this->extract_single_frame( $video_path, $timestamp, $frame_path );

			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}

			if ( file_exists( $frame_path ) && filesize( $frame_path ) > 0 ) {
				$frame_paths[] = $frame_path;
			}
		}

		// Check if we got any frames.
		if ( empty( $frame_paths ) ) {
			// Clean up temp directory.
			$this->cleanup_directory( $temp_dir );

			return new WP_Error(
				'wp_mcp_ai_frame_extraction_failed',
				__( 'Failed to extract any frames from video.', 'wp-mcp-ai' ),
				array(
					'status' => 500,
					'errors' => $errors,
				)
			);
		}

		WP_MCP_AI_Logger::log_event(
			'video_frames_extracted',
			sprintf( 'Extracted %d frames from video', count( $frame_paths ) ),
			array(
				'video_path'    => basename( $video_path ),
				'frame_count'   => count( $frame_paths ),
				'duration'      => $duration,
				'interval'      => $interval,
				'temp_dir'      => $temp_dir,
			)
		);

		return $frame_paths;
	}

	/**
	 * Extract a single frame from video at specific timestamp
	 *
	 * @param string $video_path  Path to video file.
	 * @param float  $timestamp   Timestamp in seconds.
	 * @param string $output_path Output path for frame image.
	 * @return true|WP_Error True on success, error on failure.
	 */
	protected function extract_single_frame( $video_path, $timestamp, $output_path ) {
		$escaped_video = escapeshellarg( $video_path );
		$escaped_output = escapeshellarg( $output_path );
		$timestamp_formatted = number_format( $timestamp, 3, '.', '' );

		// FFmpeg command to extract frame at specific timestamp.
		// -ss: seek to timestamp
		// -i: input file
		// -vframes 1: extract 1 frame
		// -q:v: quality (2 is high quality)
		// -y: overwrite output file.
		$command = sprintf(
			'ffmpeg -ss %s -i %s -vframes 1 -q:v %d -y %s 2>&1',
			$timestamp_formatted,
			$escaped_video,
			$this->frame_quality,
			$escaped_output
		);

		$output      = array();
		$return_code = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
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
	 * Create temporary directory for frames
	 *
	 * @return string|WP_Error Directory path or error.
	 */
	protected function create_temp_directory() {
		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'] . '/wp-mcp-ai-temp';

		// Create base temp directory if it doesn't exist.
		if ( ! file_exists( $base_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			if ( ! mkdir( $base_dir, 0755, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_temp_dir_failed',
					__( 'Failed to create temporary directory for video frames.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}
		}

		// Create unique subdirectory for this extraction.
		$unique_id = uniqid( 'frames_', true );
		$temp_dir  = $base_dir . '/' . $unique_id;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		if ( ! mkdir( $temp_dir, 0755, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_temp_subdir_failed',
				__( 'Failed to create unique temporary directory for video frames.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		return $temp_dir;
	}

	/**
	 * Cleanup extracted frames and temporary directory
	 *
	 * @param array|string $frame_paths Array of frame paths or directory path.
	 * @return bool True on success, false on failure.
	 */
	public function cleanup_frames( $frame_paths ) {
		if ( is_string( $frame_paths ) ) {
			// Single directory path.
			return $this->cleanup_directory( $frame_paths );
		}

		if ( ! is_array( $frame_paths ) || empty( $frame_paths ) ) {
			return false;
		}

		// Get the directory from first frame path.
		$first_frame = $frame_paths[0];
		$directory   = dirname( $first_frame );

		return $this->cleanup_directory( $directory );
	}

	/**
	 * Cleanup a directory and all its contents
	 *
	 * @param string $directory Directory path to cleanup.
	 * @return bool True on success, false on failure.
	 */
	protected function cleanup_directory( $directory ) {
		if ( ! file_exists( $directory ) || ! is_dir( $directory ) ) {
			return false;
		}

		// Delete all files in directory.
		$files = glob( $directory . '/*' );
		if ( ! empty( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					unlink( $file );
				}
			}
		}

		// Delete the directory itself.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		$result = rmdir( $directory );

		if ( $result ) {
			WP_MCP_AI_Logger::log_event(
				'video_frames_cleanup',
				'Cleaned up extracted video frames',
				array(
					'directory' => $directory,
				)
			);
		}

		return $result;
	}

	/**
	 * Convert frame images to base64-encoded data URLs
	 *
	 * @param array $frame_paths Array of frame file paths.
	 * @return array Array of base64-encoded data URLs.
	 */
	public function frames_to_base64( $frame_paths ) {
		$base64_frames = array();

		foreach ( $frame_paths as $frame_path ) {
			if ( ! file_exists( $frame_path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$image_data = file_get_contents( $frame_path );
			if ( false === $image_data ) {
				continue;
			}

			// Get MIME type.
			$finfo     = finfo_open( FILEINFO_MIME_TYPE );
			$mime_type = finfo_file( $finfo, $frame_path );
			finfo_close( $finfo );

			// Create base64 data URL.
			$base64 = base64_encode( $image_data );
			$data_url = 'data:' . $mime_type . ';base64,' . $base64;

			$base64_frames[] = $data_url;
		}

		return $base64_frames;
	}
}
