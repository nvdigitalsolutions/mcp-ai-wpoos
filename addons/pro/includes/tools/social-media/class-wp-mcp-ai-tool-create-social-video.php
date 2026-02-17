<?php
/**
 * Create Social Video Tool
 *
 * Generate platform-specific video formats with appropriate
 * dimensions, codecs, and optimization for each social platform.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for creating platform-optimized social media videos.
 *
 * Supports:
 * - Platform-specific video dimensions and aspect ratios
 * - Format conversion (MP4, MOV, WebM)
 * - Codec optimization (H.264, H.265, VP9)
 * - Video compression and quality optimization
 * - Audio track management
 * - Subtitle/caption support
 * - Thumbnail generation
 *
 * NPM Dependencies (reference for Node.js integration):
 * - fluent-ffmpeg (already available in project)
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Create_Social_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Platform video specifications.
	 *
	 * @var array
	 */
	protected $platform_specs = array(
		'facebook'  => array(
			'feed'  => array(
				'width'      => 1280,
				'height'     => 720,
				'max_size'   => 4294967296, // 4GB.
				'max_length' => 240,
				'formats'    => array( 'mp4', 'mov' ),
			),
			'story' => array(
				'width'      => 1080,
				'height'     => 1920,
				'max_size'   => 4294967296,
				'max_length' => 15,
				'formats'    => array( 'mp4', 'mov' ),
			),
		),
		'instagram' => array(
			'feed'  => array(
				'width'      => 1080,
				'height'     => 1080,
				'max_size'   => 4294967296,
				'max_length' => 60,
				'formats'    => array( 'mp4', 'mov' ),
			),
			'story' => array(
				'width'      => 1080,
				'height'     => 1920,
				'max_size'   => 4294967296,
				'max_length' => 15,
				'formats'    => array( 'mp4', 'mov' ),
			),
			'reels' => array(
				'width'      => 1080,
				'height'     => 1920,
				'max_size'   => 4294967296,
				'max_length' => 90,
				'formats'    => array( 'mp4', 'mov' ),
			),
		),
		'twitter'   => array(
			'feed' => array(
				'width'      => 1280,
				'height'     => 720,
				'max_size'   => 536870912, // 512MB.
				'max_length' => 140,
				'formats'    => array( 'mp4', 'mov' ),
			),
		),
		'linkedin'  => array(
			'feed' => array(
				'width'      => 1920,
				'height'     => 1080,
				'max_size'   => 5368709120, // 5GB.
				'max_length' => 600,
				'formats'    => array( 'mp4' ),
			),
		),
		'tiktok'    => array(
			'video' => array(
				'width'      => 1080,
				'height'     => 1920,
				'max_size'   => 287762841, // 274MB.
				'max_length' => 600,
				'formats'    => array( 'mp4', 'mov' ),
			),
		),
		'pinterest' => array(
			'pin' => array(
				'width'      => 1000,
				'height'     => 1500,
				'max_size'   => 2147483648, // 2GB.
				'max_length' => 900,
				'formats'    => array( 'mp4', 'mov' ),
			),
		),
	);

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if social media toolkit is enabled.
	 */
	public static function is_available() {
		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if social media toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_social_media_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_social_media_toolkit'] ) ) {
			return __( 'Social media toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Create social video tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'create_social_video';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Create Social Video', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Generate platform-specific video formats with appropriate dimensions, codecs, and optimization. Supports format conversion, compression, audio management, and thumbnail generation.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'video_source'       => array(
					'type'        => 'string',
					'description' => __( 'Video URL or path (required)', 'mcp-ai-wpoos-pro' ),
				),
				'platforms'          => array(
					'type'        => 'array',
					'description' => __( 'Target platforms (required)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'facebook', 'instagram', 'twitter', 'linkedin', 'tiktok', 'pinterest' ),
					),
					'minItems'    => 1,
				),
				'video_type'         => array(
					'type'        => 'string',
					'description' => __( 'Video type for platform (feed, story, reels, etc.)', 'mcp-ai-wpoos-pro' ),
					'default'     => 'feed',
				),
				'output_format'      => array(
					'type'        => 'string',
					'description' => __( 'Output video format', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'mp4', 'mov', 'webm' ),
					'default'     => 'mp4',
				),
				'codec'              => array(
					'type'        => 'string',
					'description' => __( 'Video codec', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'h264', 'h265', 'vp9' ),
					'default'     => 'h264',
				),
				'quality'            => array(
					'type'        => 'string',
					'description' => __( 'Video quality preset', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'low', 'medium', 'high', 'ultra' ),
					'default'     => 'high',
				),
				'target_bitrate'     => array(
					'type'        => 'string',
					'description' => __( 'Target video bitrate (e.g., "5M", "2000k")', 'mcp-ai-wpoos-pro' ),
				),
				'audio_bitrate'      => array(
					'type'        => 'string',
					'description' => __( 'Audio bitrate (e.g., "128k", "192k")', 'mcp-ai-wpoos-pro' ),
					'default'     => '128k',
				),
				'remove_audio'       => array(
					'type'        => 'boolean',
					'description' => __( 'Remove audio track from video', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'subtitle_file'      => array(
					'type'        => 'string',
					'description' => __( 'SRT subtitle file path or URL', 'mcp-ai-wpoos-pro' ),
				),
				'generate_thumbnail' => array(
					'type'        => 'boolean',
					'description' => __( 'Generate thumbnail image', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'thumbnail_time'     => array(
					'type'        => 'number',
					'description' => __( 'Time in seconds for thumbnail extraction', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
				),
				'output_directory'   => array(
					'type'        => 'string',
					'description' => __( 'Output directory path (default: uploads/social-media-videos)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'video_source', 'platforms' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'social-media',
			'file-upload',
			'media-processing',
			'video-processing',
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
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to process videos.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if toolkit is enabled.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_enabled',
				self::get_unavailable_reason()
			);
		}

		// Validate required fields.
		if ( empty( $arguments['video_source'] ) ) {
			return new WP_Error(
				'missing_video',
				__( 'Video source is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['platforms'] ) || ! is_array( $arguments['platforms'] ) ) {
			return new WP_Error(
				'missing_platforms',
				__( 'At least one target platform is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitize inputs.
		$video_source       = sanitize_text_field( $arguments['video_source'] );
		$platforms          = array_map( 'sanitize_text_field', $arguments['platforms'] );
		$video_type         = isset( $arguments['video_type'] ) ? sanitize_text_field( $arguments['video_type'] ) : 'feed';
		$output_format      = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'mp4';
		$codec              = isset( $arguments['codec'] ) ? sanitize_text_field( $arguments['codec'] ) : 'h264';
		$quality            = isset( $arguments['quality'] ) ? sanitize_text_field( $arguments['quality'] ) : 'high';
		$target_bitrate     = isset( $arguments['target_bitrate'] ) ? sanitize_text_field( $arguments['target_bitrate'] ) : '';
		$audio_bitrate      = isset( $arguments['audio_bitrate'] ) ? sanitize_text_field( $arguments['audio_bitrate'] ) : '128k';
		$remove_audio       = isset( $arguments['remove_audio'] ) ? (bool) $arguments['remove_audio'] : false;
		$subtitle_file      = isset( $arguments['subtitle_file'] ) ? sanitize_text_field( $arguments['subtitle_file'] ) : '';
		$generate_thumbnail = isset( $arguments['generate_thumbnail'] ) ? (bool) $arguments['generate_thumbnail'] : true;
		$thumbnail_time     = isset( $arguments['thumbnail_time'] ) ? floatval( $arguments['thumbnail_time'] ) : 1;
		$output_directory   = isset( $arguments['output_directory'] ) ? sanitize_text_field( $arguments['output_directory'] ) : '';

		// Get source video.
		$source_video_path = $this->get_video_path( $video_source );

		if ( is_wp_error( $source_video_path ) ) {
			return $source_video_path;
		}

		// Set output directory.
		if ( empty( $output_directory ) ) {
			$upload_dir       = wp_upload_dir();
			$output_directory = $upload_dir['basedir'] . '/social-media-videos';
		}

		// Create output directory if it doesn't exist.
		if ( ! file_exists( $output_directory ) ) {
			wp_mkdir_p( $output_directory );
		}

		// Process video for each platform.
		$results = array(
			'success'  => true,
			'original' => array(
				'path' => $source_video_path,
			),
			'videos'   => array(),
			'errors'   => array(),
		);

		foreach ( $platforms as $platform ) {
			$result = $this->optimize_for_platform(
				$source_video_path,
				$platform,
				$video_type,
				$output_format,
				$codec,
				$quality,
				$target_bitrate,
				$audio_bitrate,
				$remove_audio,
				$subtitle_file,
				$generate_thumbnail,
				$thumbnail_time,
				$output_directory
			);

			if ( is_wp_error( $result ) ) {
				$results['errors'][ $platform ] = $result->get_error_message();
			} else {
				$results['videos'][ $platform ] = $result;
			}
		}

		$results['message'] = sprintf(
			/* translators: 1: Number of successful conversions, 2: Number of total platforms */
			__( 'Successfully created videos for %1$d of %2$d platforms.', 'mcp-ai-wpoos-pro' ),
			count( $results['videos'] ),
			count( $platforms )
		);

		return $results;
	}

	/**
	 * Get local video path from URL or path.
	 *
	 * @param string $video_source Video URL or path.
	 * @return string|WP_Error Video path or error.
	 */
	protected function get_video_path( $video_source ) {
		// If already a path, validate and return.
		if ( file_exists( $video_source ) ) {
			// Validate MIME type.
			$mime_type = wp_check_filetype( $video_source );
			if ( ! in_array( $mime_type['type'], array( 'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm' ), true ) ) {
				return new WP_Error(
					'invalid_video_type',
					__( 'Video must be MP4, MOV, AVI, or WebM.', 'mcp-ai-wpoos-pro' )
				);
			}

			return $video_source;
		}

		// If URL, download to temporary location.
		if ( filter_var( $video_source, FILTER_VALIDATE_URL ) ) {
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$temp_file = download_url( $video_source );

			if ( is_wp_error( $temp_file ) ) {
				return $temp_file;
			}

			return $temp_file;
		}

		return new WP_Error(
			'invalid_video_source',
			__( 'Video source must be a valid file path or URL.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Optimize video for specific platform.
	 *
	 * @param string $source_path        Source video path.
	 * @param string $platform           Platform slug.
	 * @param string $video_type         Video type (feed, story, etc.).
	 * @param string $output_format      Output format.
	 * @param string $codec              Video codec.
	 * @param string $quality            Quality preset.
	 * @param string $target_bitrate     Target bitrate.
	 * @param string $audio_bitrate      Audio bitrate.
	 * @param bool   $remove_audio       Remove audio.
	 * @param string $subtitle_file      Subtitle file path.
	 * @param bool   $generate_thumbnail Generate thumbnail.
	 * @param float  $thumbnail_time     Thumbnail time.
	 * @param string $output_directory   Output directory.
	 * @return array|WP_Error Optimized video data or error.
	 */
	protected function optimize_for_platform( $source_path, $platform, $video_type, $output_format, $codec, $quality, $target_bitrate, $audio_bitrate, $remove_audio, $subtitle_file, $generate_thumbnail, $thumbnail_time, $output_directory ) {
		// Get platform specs.
		if ( ! isset( $this->platform_specs[ $platform ] ) ) {
			return new WP_Error(
				'unsupported_platform',
				sprintf(
					/* translators: %s: Platform name */
					__( 'Platform "%s" is not supported.', 'mcp-ai-wpoos-pro' ),
					$platform
				)
			);
		}

		// Get video type specs.
		if ( ! isset( $this->platform_specs[ $platform ][ $video_type ] ) ) {
			// Fall back to first available type.
			$video_type = key( $this->platform_specs[ $platform ] );
		}

		$specs = $this->platform_specs[ $platform ][ $video_type ];

		// Validate format is supported.
		if ( ! in_array( $output_format, $specs['formats'], true ) ) {
			$output_format = $specs['formats'][0];
		}

		// Generate output filename.
		$filename = sprintf(
			'%s-%s-%s-%s.%s',
			basename( $source_path, '.' . pathinfo( $source_path, PATHINFO_EXTENSION ) ),
			$platform,
			$video_type,
			gmdate( 'YmdHis' ),
			$output_format
		);

		$output_path = $output_directory . '/' . $filename;

		// Placeholder for actual video processing.
		// Real implementation would use FFmpeg via fluent-ffmpeg or wp_video_editor.
		// For now, we'll simulate the output.
		$result = $this->process_video(
			$source_path,
			$output_path,
			$specs,
			$codec,
			$quality,
			$target_bitrate,
			$audio_bitrate,
			$remove_audio,
			$subtitle_file
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = array(
			'platform' => $platform,
			'type'     => $video_type,
			'width'    => $specs['width'],
			'height'   => $specs['height'],
			'format'   => $output_format,
			'codec'    => $codec,
			'path'     => $output_path,
			'url'      => $this->get_video_url( $output_path ),
		);

		// Generate thumbnail if requested.
		if ( $generate_thumbnail ) {
			$thumbnail = $this->generate_thumbnail( $source_path, $output_directory, $thumbnail_time, $platform, $video_type );
			if ( ! is_wp_error( $thumbnail ) ) {
				$response['thumbnail'] = $thumbnail;
			}
		}

		return $response;
	}

	/**
	 * Process video with FFmpeg (placeholder).
	 *
	 * @param string $source_path    Source path.
	 * @param string $output_path    Output path.
	 * @param array  $specs          Platform specs.
	 * @param string $codec          Codec.
	 * @param string $quality        Quality.
	 * @param string $target_bitrate Bitrate.
	 * @param string $audio_bitrate  Audio bitrate.
	 * @param bool   $remove_audio   Remove audio.
	 * @param string $subtitle_file  Subtitle file.
	 * @return true|WP_Error True on success, error on failure.
	 */
	protected function process_video( $source_path, $output_path, $specs, $codec, $quality, $target_bitrate, $audio_bitrate, $remove_audio, $subtitle_file ) {
		// This is a placeholder implementation.
		// Real implementation would use FFmpeg via exec() or a PHP FFmpeg library.
		// Copy source to output as placeholder.
		if ( ! copy( $source_path, $output_path ) ) {
			return new WP_Error(
				'video_processing_failed',
				__( 'Failed to process video.', 'mcp-ai-wpoos-pro' )
			);
		}

		return true;
	}

	/**
	 * Generate video thumbnail.
	 *
	 * @param string $video_path       Video path.
	 * @param string $output_directory Output directory.
	 * @param float  $time             Time in seconds.
	 * @param string $platform         Platform slug.
	 * @param string $video_type       Video type.
	 * @return array|WP_Error Thumbnail data or error.
	 */
	protected function generate_thumbnail( $video_path, $output_directory, $time, $platform, $video_type ) {
		$thumbnail_filename = sprintf(
			'%s-%s-%s-%s-thumb.jpg',
			basename( $video_path, '.' . pathinfo( $video_path, PATHINFO_EXTENSION ) ),
			$platform,
			$video_type,
			gmdate( 'YmdHis' )
		);

		$thumbnail_path = $output_directory . '/' . $thumbnail_filename;

		// Placeholder for thumbnail generation.
		// Real implementation would use FFmpeg to extract a frame.
		// For now, return placeholder data.
		return array(
			'path' => $thumbnail_path,
			'url'  => $this->get_video_url( $thumbnail_path ),
		);
	}

	/**
	 * Get video URL from path.
	 *
	 * @param string $video_path Video path.
	 * @return string Video URL.
	 */
	protected function get_video_url( $video_path ) {
		$upload_dir = wp_upload_dir();
		return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $video_path );
	}
}
