<?php
/**
 * Fluent FFmpeg Service
 *
 * Provides Node.js fluent-ffmpeg integration for advanced video processing.
 * This service acts as a bridge between WordPress/PHP and the fluent-ffmpeg NPM package.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fluent FFmpeg Service class
 *
 * Provides advanced video processing capabilities using the fluent-ffmpeg NPM package:
 * - Video metadata extraction
 * - Frame extraction with precise timing
 * - Video transcoding and format conversion
 * - Thumbnail generation
 * - Video clipping and trimming
 * - Audio extraction
 *
 * This service uses a Node.js microservice pattern where PHP communicates with
 * a Node.js script that uses fluent-ffmpeg for processing.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Fluent_FFmpeg_Service {

	/**
	 * Check if fluent-ffmpeg package is available
	 *
	 * @return bool True if available, false otherwise.
	 */
	public function is_available() {
		// Check if fluent-ffmpeg package exists in vendor directory (production) or node_modules (development).
		$vendor_path       = WP_MCP_AI_PRO_PATH . 'assets/vendor/fluent-ffmpeg/index.js';
		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/fluent-ffmpeg/index.js';

		if ( ! file_exists( $vendor_path ) && ! file_exists( $node_modules_path ) ) {
			return false;
		}

		// Use Process Service to check for Node.js and FFmpeg availability.
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();

		if ( ! $process_service->is_command_available( 'node' ) ) {
			return false;
		}

		if ( ! $process_service->is_command_available( 'ffmpeg' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Extract video metadata using fluent-ffmpeg
	 *
	 * @param string $video_path Path to video file.
	 * @return array|WP_Error Video metadata or error.
	 */
	public function get_metadata( $video_path ) {
		if ( ! file_exists( $video_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_video_not_found',
				__( 'Video file not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$params = array(
			'action'     => 'get_metadata',
			'video_path' => $video_path,
		);

		/**
		 * Filter to allow custom fluent-ffmpeg metadata extraction.
		 *
		 * @param array|false $result Metadata result or false.
		 * @param array       $params Processing parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_fluent_ffmpeg_get_metadata', false, $params );

		if ( false === $result ) {
			// Fallback to basic FFprobe if filter not implemented.
			return $this->fallback_get_metadata( $video_path );
		}

		return $result;
	}

	/**
	 * Extract frames from video using fluent-ffmpeg
	 *
	 * @param string $video_path Path to video file.
	 * @param array  $options    Frame extraction options.
	 * @return array|WP_Error Array of frame paths or error.
	 */
	public function extract_frames( $video_path, $options = array() ) {
		if ( ! file_exists( $video_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_video_not_found',
				__( 'Video file not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$defaults = array(
			'timestamps' => array(), // Specific timestamps in seconds.
			'count'      => 10,      // Number of frames to extract.
			'size'       => '640x?', // Output size.
			'folder'     => null,    // Output folder (null = temp).
			'filename'   => 'frame-%i.jpg', // Output filename pattern.
		);

		$options = wp_parse_args( $options, $defaults );

		$params = array(
			'action'     => 'extract_frames',
			'video_path' => $video_path,
			'options'    => $options,
		);

		/**
		 * Filter to allow custom fluent-ffmpeg frame extraction.
		 *
		 * @param array|false $result Frame extraction result or false.
		 * @param array       $params Processing parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_fluent_ffmpeg_extract_frames', false, $params );

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_fluent_ffmpeg_not_configured',
				__( 'Fluent-ffmpeg frame extraction requires Node.js integration. Please implement the wp_mcp_ai_fluent_ffmpeg_extract_frames filter. See docs/INTEGRATION_BEST_PRACTICES.md for setup guide.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'  => 501,
					'package' => 'fluent-ffmpeg',
				)
			);
		}

		return $result;
	}

	/**
	 * Generate video thumbnail using fluent-ffmpeg
	 *
	 * @param string $video_path Path to video file.
	 * @param array  $options    Thumbnail generation options.
	 * @return string|WP_Error Thumbnail path or error.
	 */
	public function generate_thumbnail( $video_path, $options = array() ) {
		if ( ! file_exists( $video_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_video_not_found',
				__( 'Video file not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$defaults = array(
			'timestamp' => '10%',    // Timestamp or percentage.
			'size'      => '320x240', // Thumbnail size.
			'folder'    => null,      // Output folder (null = temp).
			'filename'  => 'thumbnail.jpg', // Output filename.
		);

		$options = wp_parse_args( $options, $defaults );

		$params = array(
			'action'     => 'generate_thumbnail',
			'video_path' => $video_path,
			'options'    => $options,
		);

		/**
		 * Filter to allow custom fluent-ffmpeg thumbnail generation.
		 *
		 * @param string|false $result Thumbnail path or false.
		 * @param array        $params Processing parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_fluent_ffmpeg_generate_thumbnail', false, $params );

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_fluent_ffmpeg_not_configured',
				__( 'Fluent-ffmpeg thumbnail generation requires Node.js integration. Please implement the wp_mcp_ai_fluent_ffmpeg_generate_thumbnail filter. See docs/INTEGRATION_BEST_PRACTICES.md for setup guide.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'  => 501,
					'package' => 'fluent-ffmpeg',
				)
			);
		}

		return $result;
	}

	/**
	 * Transcode video to different format using fluent-ffmpeg
	 *
	 * @param string $video_path   Path to video file.
	 * @param string $output_path  Output file path.
	 * @param array  $options      Transcoding options.
	 * @return string|WP_Error Output path or error.
	 */
	public function transcode_video( $video_path, $output_path, $options = array() ) {
		if ( ! file_exists( $video_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_video_not_found',
				__( 'Video file not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$defaults = array(
			'format'      => 'mp4',        // Output format.
			'codec'       => 'libx264',    // Video codec.
			'audio_codec' => 'aac',        // Audio codec.
			'bitrate'     => '1000k',      // Video bitrate.
			'size'        => null,         // Output size (e.g., '1280x720').
			'fps'         => null,         // Frame rate.
		);

		$options = wp_parse_args( $options, $defaults );

		$params = array(
			'action'      => 'transcode_video',
			'video_path'  => $video_path,
			'output_path' => $output_path,
			'options'     => $options,
		);

		/**
		 * Filter to allow custom fluent-ffmpeg video transcoding.
		 *
		 * @param string|false $result Output path or false.
		 * @param array        $params Processing parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_fluent_ffmpeg_transcode_video', false, $params );

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_fluent_ffmpeg_not_configured',
				__( 'Fluent-ffmpeg video transcoding requires Node.js integration. Please implement the wp_mcp_ai_fluent_ffmpeg_transcode_video filter. See docs/INTEGRATION_BEST_PRACTICES.md for setup guide.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'  => 501,
					'package' => 'fluent-ffmpeg',
				)
			);
		}

		return $result;
	}

	/**
	 * Extract audio from video using fluent-ffmpeg
	 *
	 * @param string $video_path  Path to video file.
	 * @param string $output_path Output audio file path.
	 * @param array  $options     Audio extraction options.
	 * @return string|WP_Error Output path or error.
	 */
	public function extract_audio( $video_path, $output_path, $options = array() ) {
		if ( ! file_exists( $video_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_video_not_found',
				__( 'Video file not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$defaults = array(
			'format'   => 'mp3',    // Output audio format.
			'codec'    => 'libmp3lame', // Audio codec.
			'bitrate'  => '128k',   // Audio bitrate.
			'channels' => 2,        // Number of audio channels.
		);

		$options = wp_parse_args( $options, $defaults );

		$params = array(
			'action'      => 'extract_audio',
			'video_path'  => $video_path,
			'output_path' => $output_path,
			'options'     => $options,
		);

		/**
		 * Filter to allow custom fluent-ffmpeg audio extraction.
		 *
		 * @param string|false $result Output path or false.
		 * @param array        $params Processing parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_fluent_ffmpeg_extract_audio', false, $params );

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_fluent_ffmpeg_not_configured',
				__( 'Fluent-ffmpeg audio extraction requires Node.js integration. Please implement the wp_mcp_ai_fluent_ffmpeg_extract_audio filter. See docs/INTEGRATION_BEST_PRACTICES.md for setup guide.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'  => 501,
					'package' => 'fluent-ffmpeg',
				)
			);
		}

		return $result;
	}

	/**
	 * Fallback metadata extraction using FFprobe directly
	 *
	 * @param string $video_path Path to video file.
	 * @return array|WP_Error Video metadata or error.
	 */
	private function fallback_get_metadata( $video_path ) {
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();

		// Use FFprobe to get JSON metadata.
		$result = $process_service->run(
			array(
				'ffprobe',
				'-v',
				'quiet',
				'-print_format',
				'json',
				'-show_format',
				'-show_streams',
				$video_path,
			),
			array( 'timeout' => 30 )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$metadata = json_decode( $result['output'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'wp_mcp_ai_json_decode_failed',
				__( 'Failed to parse video metadata.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		return $metadata;
	}
}
