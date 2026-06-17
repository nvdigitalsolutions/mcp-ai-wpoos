<?php
/**
 * Tool for transcoding videos using fluent-ffmpeg.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';

/**
 * Transcode videos to different formats using fluent-ffmpeg.
 *
 * This tool leverages fluent-ffmpeg to provide:
 * - Video format conversion (MP4, WebM, AVI, MOV, etc.)
 * - Resolution adjustment for social media platforms
 * - Codec selection and optimization
 * - Bitrate control for file size management
 * - Audio track manipulation
 * - Perfect for preparing videos for different platforms
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Transcode_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Attachment_File_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'transcode_video';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Transcode Video', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Transcode videos to different formats using fluent-ffmpeg. Convert between formats (MP4, WebM, AVI, MOV), adjust resolution for social media platforms, optimize codecs and bitrates. Perfect for preparing videos for YouTube, Instagram, TikTok, Facebook, and more.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'URL of the video file to transcode.', 'mcp-ai-wpoos-pro' ),
				),
				'url'           => $this->get_url_parameter_schema( 'video' ),
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the video to transcode.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'file_id'       => $this->get_file_id_parameter_schema(),
				'output_format' => array(
					'type'        => 'string',
					'enum'        => array( 'mp4', 'webm', 'avi', 'mov', 'mkv', 'flv' ),
					'description' => __( 'Output video format', 'mcp-ai-wpoos-pro' ),
					'default'     => 'mp4',
				),
				'preset'        => array(
					'type'        => 'string',
					'enum'        => array( 'youtube', 'instagram', 'tiktok', 'facebook', 'twitter', 'linkedin', 'custom' ),
					'description' => __( 'Platform preset with optimized settings', 'mcp-ai-wpoos-pro' ),
				),
				'resolution'    => array(
					'type'        => 'string',
					'enum'        => array( '3840x2160', '2560x1440', '1920x1080', '1280x720', '854x480', '640x360' ),
					'description' => __( 'Output resolution (4K, 2K, 1080p, 720p, 480p, 360p)', 'mcp-ai-wpoos-pro' ),
				),
				'video_codec'   => array(
					'type'        => 'string',
					'enum'        => array( 'libx264', 'libx265', 'libvpx', 'libvpx-vp9' ),
					'description' => __( 'Video codec: H.264 (libx264), H.265 (libx265), VP8 (libvpx), VP9 (libvpx-vp9)', 'mcp-ai-wpoos-pro' ),
					'default'     => 'libx264',
				),
				'audio_codec'   => array(
					'type'        => 'string',
					'enum'        => array( 'aac', 'libmp3lame', 'libvorbis', 'libopus' ),
					'description' => __( 'Audio codec: AAC, MP3, Vorbis, Opus', 'mcp-ai-wpoos-pro' ),
					'default'     => 'aac',
				),
				'video_bitrate' => array(
					'type'        => 'string',
					'description' => __( 'Video bitrate (e.g., "2000k", "5M"). Higher = better quality but larger file.', 'mcp-ai-wpoos-pro' ),
					'default'     => '2000k',
				),
				'audio_bitrate' => array(
					'type'        => 'string',
					'description' => __( 'Audio bitrate (e.g., "128k", "192k")', 'mcp-ai-wpoos-pro' ),
					'default'     => '128k',
				),
				'fps'           => array(
					'type'        => 'integer',
					'description' => __( 'Frame rate (frames per second)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 120,
				),
				'remove_audio'  => array(
					'type'        => 'boolean',
					'description' => __( 'Remove audio track from output video', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'save_to_media' => array(
					'type'        => 'boolean',
					'description' => __( 'Save transcoded video to WordPress Media Library', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Creates new files.
			'requires-capability',  // Requires upload_files capability.
			'state-changing',       // Modifies media library.
			'external-dependency',  // Requires fluent-ffmpeg (Node.js) and FFmpeg binary.
			'performance-impact',   // Video transcoding is CPU-intensive.
			'long-running',         // May take minutes for large videos.
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check user capabilities.
		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to transcode videos.', 'mcp-ai-wpoos-pro' ),
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

		// Check if fluent-ffmpeg service is available.
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-fluent-ffmpeg-service.php';
		$ffmpeg_service = new WP_MCP_AI_Fluent_FFmpeg_Service();

		if ( ! $ffmpeg_service->is_available() ) {
			if ( $temp_file && file_exists( $video_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $video_path );
			}

			return new WP_Error(
				'wp_mcp_ai_ffmpeg_not_available',
				__( 'Fluent-ffmpeg is not available. Please ensure Node.js, fluent-ffmpeg package, and FFmpeg binary are installed. See documentation for setup instructions.', 'mcp-ai-wpoos-pro' ),
				array(
					'status' => 500,
					'docs'   => 'https://github.com/fluent-ffmpeg/node-fluent-ffmpeg',
				)
			);
		}

		// Build transcoding options.
		$transcode_options = $this->build_transcode_options( $arguments );

		// Generate output filename.
		$upload_dir  = wp_upload_dir();
		$output_name = 'transcoded-' . time() . '-' . uniqid() . '.' . $transcode_options['format'];
		$output_path = $upload_dir['path'] . '/' . $output_name;

		// Transcode video.
		$result = $ffmpeg_service->transcode_video( $video_path, $output_path, $transcode_options );

		// Clean up source temp file.
		if ( $temp_file && file_exists( $video_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $video_path );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Prepare response.
		$response = array(
			'success'       => true,
			'message'       => __( 'Video transcoded successfully.', 'mcp-ai-wpoos-pro' ),
			'output_path'   => $result,
			'output_format' => $transcode_options['format'],
			'file_size'     => file_exists( $result ) ? filesize( $result ) : 0,
		);

		// Save to media library if requested.
		$save_to_media = isset( $arguments['save_to_media'] ) ? (bool) $arguments['save_to_media'] : true;
		if ( $save_to_media && file_exists( $result ) ) {
			$attachment_id = $this->save_to_media_library( $result, $user_id );

			if ( ! is_wp_error( $attachment_id ) ) {
				$response['attachment_id'] = $attachment_id;
				$response['url']           = wp_get_attachment_url( $attachment_id );
			} else {
				$response['warning'] = sprintf(
					/* translators: %s: error message */
					__( 'Video transcoded but media upload failed: %s', 'mcp-ai-wpoos-pro' ),
					$attachment_id->get_error_message()
				);
			}
		}

		return $response;
	}

	/**
	 * Build transcoding options from arguments
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Transcoding options.
	 */
	private function build_transcode_options( $arguments ) {
		$format = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'mp4';

		// Check for preset.
		if ( isset( $arguments['preset'] ) ) {
			$preset_options = $this->get_preset_options( $arguments['preset'] );
			if ( $preset_options ) {
				return array_merge( $preset_options, array( 'format' => $format ) );
			}
		}

		// Build custom options.
		$options = array(
			'format'      => $format,
			'codec'       => isset( $arguments['video_codec'] ) ? sanitize_text_field( $arguments['video_codec'] ) : 'libx264',
			'audio_codec' => isset( $arguments['audio_codec'] ) ? sanitize_text_field( $arguments['audio_codec'] ) : 'aac',
			'bitrate'     => isset( $arguments['video_bitrate'] ) ? sanitize_text_field( $arguments['video_bitrate'] ) : '2000k',
		);

		if ( isset( $arguments['resolution'] ) ) {
			$options['size'] = sanitize_text_field( $arguments['resolution'] );
		}

		if ( isset( $arguments['fps'] ) ) {
			$options['fps'] = absint( $arguments['fps'] );
		}

		if ( isset( $arguments['remove_audio'] ) && $arguments['remove_audio'] ) {
			$options['audio_codec'] = null; // Remove audio.
		}

		return $options;
	}

	/**
	 * Get preset options for platforms
	 *
	 * @param string $preset Preset name.
	 * @return array|null Preset options or null.
	 */
	private function get_preset_options( $preset ) {
		$presets = array(
			'youtube'   => array(
				'codec'       => 'libx264',
				'audio_codec' => 'aac',
				'bitrate'     => '5000k',
				'size'        => '1920x1080',
				'fps'         => 30,
			),
			'instagram' => array(
				'codec'       => 'libx264',
				'audio_codec' => 'aac',
				'bitrate'     => '3500k',
				'size'        => '1080x1080', // Square.
				'fps'         => 30,
			),
			'tiktok'    => array(
				'codec'       => 'libx264',
				'audio_codec' => 'aac',
				'bitrate'     => '2000k',
				'size'        => '1080x1920', // Vertical 9:16.
				'fps'         => 30,
			),
			'facebook'  => array(
				'codec'       => 'libx264',
				'audio_codec' => 'aac',
				'bitrate'     => '4000k',
				'size'        => '1280x720',
				'fps'         => 30,
			),
			'twitter'   => array(
				'codec'       => 'libx264',
				'audio_codec' => 'aac',
				'bitrate'     => '2000k',
				'size'        => '1280x720',
				'fps'         => 30,
			),
			'linkedin'  => array(
				'codec'       => 'libx264',
				'audio_codec' => 'aac',
				'bitrate'     => '5000k',
				'size'        => '1920x1080',
				'fps'         => 30,
			),
		);

		return isset( $presets[ $preset ] ) ? $presets[ $preset ] : null;
	}

	/**
	 * Save transcoded video to media library
	 *
	 * @param string $file_path Path to transcoded file.
	 * @param int    $user_id   User ID.
	 * @return int|WP_Error Attachment ID or error.
	 */
	private function save_to_media_library( $file_path, $user_id ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Transcoded file not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$file_name = basename( $file_path );
		$file_type = wp_check_filetype( $file_name );

		// Prepare attachment data.
		$attachment_data = array(
			'post_mime_type' => $file_type['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $file_name ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		);

		// Insert attachment.
		$attachment_id = wp_insert_attachment( $attachment_data, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Generate metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $attachment_metadata );

		return $attachment_id;
	}
}
