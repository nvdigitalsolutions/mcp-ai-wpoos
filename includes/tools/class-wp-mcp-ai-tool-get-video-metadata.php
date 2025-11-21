<?php
/**
 * Tool for retrieving detailed video metadata.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';

/**
 * Retrieves comprehensive metadata about video files including duration, format, dimensions, and codec information.
 */
class WP_MCP_AI_Tool_Get_Video_Metadata implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_video_metadata';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Video Metadata', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed technical metadata about a video file including duration, dimensions, format, codecs, bitrate, and frame rate.', 'wp-mcp-ai' );
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
					'description' => __( 'URL of the video file to analyze.', 'wp-mcp-ai' ),
				),
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the video to analyze.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'include_streams' => array(
					'type'        => 'boolean',
					'description' => __( 'Include detailed stream information (audio/video tracks). Default is true.', 'wp-mcp-ai' ),
					'default'     => true,
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
				__( 'You do not have permission to access video metadata.', 'wp-mcp-ai' ),
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

		// Check FFprobe availability.
		$ffprobe_available = $this->is_ffprobe_available();

		if ( ! $ffprobe_available ) {
			if ( $temp_file && file_exists( $video_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $video_path );
			}

			return new WP_Error(
				'wp_mcp_ai_ffprobe_not_available',
				__( 'FFprobe is not installed on this server. Video metadata extraction requires FFprobe (part of FFmpeg).', 'wp-mcp-ai' ),
				array(
					'status'  => 500,
					'actions' => array(
						'install_ffmpeg' => __( 'Install FFmpeg (includes FFprobe): https://ffmpeg.org/download.html', 'wp-mcp-ai' ),
					),
				)
			);
		}

		// Extract metadata using FFprobe.
		$include_streams = isset( $arguments['include_streams'] ) ? (bool) $arguments['include_streams'] : true;
		$metadata        = $this->extract_metadata( $video_path, $include_streams );

		// Clean up temporary video file if downloaded.
		if ( $temp_file && file_exists( $video_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $video_path );
		}

		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		// Add WordPress-specific metadata if attachment.
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$metadata['wordpress'] = array(
				'id'          => $attachment_id,
				'url'         => wp_get_attachment_url( $attachment_id ),
				'title'       => get_the_title( $attachment_id ),
				'mime_type'   => get_post_mime_type( $attachment_id ),
				'upload_date' => get_the_date( 'c', $attachment_id ),
				'file_size'   => size_format( filesize( $video_path ), 2 ),
			);
		}

		$metadata['success'] = true;

		return $metadata;
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
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
	 * Check if FFprobe is available on the system.
	 *
	 * @return bool True if FFprobe is available, false otherwise.
	 */
	protected function is_ffprobe_available() {
		$output      = array();
		$return_code = 0;

		// Try to execute FFprobe version command.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( 'which ffprobe 2>&1', $ffprobe_path, $which_return );

		if ( 0 === $which_return && ! empty( $ffprobe_path[0] ) ) {
			// Use absolute path for security.
			$ffprobe_cmd = escapeshellcmd( $ffprobe_path[0] ) . ' -version 2>&1';
		} else {
			// Fallback to PATH-based ffprobe.
			$ffprobe_cmd = 'ffprobe -version 2>&1';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $ffprobe_cmd, $output, $return_code );

		// FFprobe should return 0 and have output containing 'ffprobe version'.
		if ( 0 === $return_code && ! empty( $output ) ) {
			$first_line = $output[0];
			if ( false !== stripos( $first_line, 'ffprobe version' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract metadata from video using FFprobe.
	 *
	 * @param string $video_path      Path to video file.
	 * @param bool   $include_streams Whether to include stream details.
	 * @return array|WP_Error Metadata array or error.
	 */
	protected function extract_metadata( $video_path, $include_streams = true ) {
		$escaped_path = escapeshellarg( $video_path );

		// Build FFprobe command to get JSON output.
		$command = sprintf(
			'ffprobe -v quiet -print_format json -show_format -show_streams %s 2>&1',
			$escaped_path
		);

		$output      = array();
		$return_code = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $return_code );

		if ( 0 !== $return_code || empty( $output ) ) {
			return new WP_Error(
				'wp_mcp_ai_ffprobe_failed',
				__( 'Failed to extract video metadata. FFprobe returned an error.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Parse JSON output.
		$json_output = implode( "\n", $output );
		$data        = json_decode( $json_output, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $data['format'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_metadata_parse_failed',
				__( 'Failed to parse video metadata.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Build metadata result.
		$format   = $data['format'];
		$streams  = isset( $data['streams'] ) ? $data['streams'] : array();

		$metadata = array(
			'format' => array(
				'filename'       => isset( $format['filename'] ) ? basename( $format['filename'] ) : '',
				'format_name'    => isset( $format['format_name'] ) ? $format['format_name'] : '',
				'format_long_name' => isset( $format['format_long_name'] ) ? $format['format_long_name'] : '',
				'duration'       => isset( $format['duration'] ) ? floatval( $format['duration'] ) : 0,
				'size'           => isset( $format['size'] ) ? intval( $format['size'] ) : 0,
				'bit_rate'       => isset( $format['bit_rate'] ) ? intval( $format['bit_rate'] ) : 0,
			),
		);

		// Add formatted duration (human-readable).
		if ( $metadata['format']['duration'] > 0 ) {
			$metadata['format']['duration_formatted'] = $this->format_duration( $metadata['format']['duration'] );
		}

		// Add formatted file size.
		if ( $metadata['format']['size'] > 0 ) {
			$metadata['format']['size_formatted'] = size_format( $metadata['format']['size'], 2 );
		}

		// Process video and audio streams.
		if ( $include_streams && ! empty( $streams ) ) {
			$video_streams = array();
			$audio_streams = array();

			foreach ( $streams as $stream ) {
				$codec_type = isset( $stream['codec_type'] ) ? $stream['codec_type'] : '';

				if ( 'video' === $codec_type ) {
					$video_streams[] = $this->parse_video_stream( $stream );
				} elseif ( 'audio' === $codec_type ) {
					$audio_streams[] = $this->parse_audio_stream( $stream );
				}
			}

			$metadata['video_streams'] = $video_streams;
			$metadata['audio_streams'] = $audio_streams;
		}

		return $metadata;
	}

	/**
	 * Parse video stream data.
	 *
	 * @param array $stream FFprobe stream data.
	 * @return array Parsed video stream info.
	 */
	protected function parse_video_stream( $stream ) {
		return array(
			'codec_name'      => isset( $stream['codec_name'] ) ? $stream['codec_name'] : '',
			'codec_long_name' => isset( $stream['codec_long_name'] ) ? $stream['codec_long_name'] : '',
			'width'           => isset( $stream['width'] ) ? intval( $stream['width'] ) : 0,
			'height'          => isset( $stream['height'] ) ? intval( $stream['height'] ) : 0,
			'aspect_ratio'    => isset( $stream['display_aspect_ratio'] ) ? $stream['display_aspect_ratio'] : '',
			'frame_rate'      => isset( $stream['r_frame_rate'] ) ? $this->parse_frame_rate( $stream['r_frame_rate'] ) : '',
			'bit_rate'        => isset( $stream['bit_rate'] ) ? intval( $stream['bit_rate'] ) : 0,
			'pixel_format'    => isset( $stream['pix_fmt'] ) ? $stream['pix_fmt'] : '',
		);
	}

	/**
	 * Parse audio stream data.
	 *
	 * @param array $stream FFprobe stream data.
	 * @return array Parsed audio stream info.
	 */
	protected function parse_audio_stream( $stream ) {
		return array(
			'codec_name'      => isset( $stream['codec_name'] ) ? $stream['codec_name'] : '',
			'codec_long_name' => isset( $stream['codec_long_name'] ) ? $stream['codec_long_name'] : '',
			'sample_rate'     => isset( $stream['sample_rate'] ) ? intval( $stream['sample_rate'] ) : 0,
			'channels'        => isset( $stream['channels'] ) ? intval( $stream['channels'] ) : 0,
			'channel_layout'  => isset( $stream['channel_layout'] ) ? $stream['channel_layout'] : '',
			'bit_rate'        => isset( $stream['bit_rate'] ) ? intval( $stream['bit_rate'] ) : 0,
		);
	}

	/**
	 * Format duration in seconds to human-readable format.
	 *
	 * @param float $seconds Duration in seconds.
	 * @return string Formatted duration (HH:MM:SS).
	 */
	protected function format_duration( $seconds ) {
		$hours   = floor( $seconds / 3600 );
		$minutes = floor( ( $seconds % 3600 ) / 60 );
		$secs    = floor( $seconds % 60 );

		return sprintf( '%02d:%02d:%02d', $hours, $minutes, $secs );
	}

	/**
	 * Parse frame rate from FFprobe format (e.g., "30000/1001").
	 *
	 * @param string $frame_rate Frame rate string.
	 * @return string Formatted frame rate (e.g., "29.97 fps").
	 */
	protected function parse_frame_rate( $frame_rate ) {
		if ( false !== strpos( $frame_rate, '/' ) ) {
			$parts = explode( '/', $frame_rate );
			if ( 2 === count( $parts ) && intval( $parts[1] ) > 0 ) {
				$fps = floatval( $parts[0] ) / floatval( $parts[1] );
				return number_format( $fps, 2 ) . ' fps';
			}
		}

		return $frame_rate;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability', // Requires upload_files capability.
			'read-only',           // Only reads video metadata.
			'external-api',        // May download remote videos.
			'network-dependent',   // May require internet for remote videos.
			'cacheable',           // Results can be cached.
		);
	}
}
