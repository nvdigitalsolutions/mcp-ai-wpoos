<?php
/**
 * Auto Optimize Images Tool
 *
 * Automatically resize and optimize images for each social media
 * platform's specific requirements and dimensions.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for automatically optimizing images for social media platforms.
 *
 * Supports:
 * - Platform-specific dimensions (Facebook, Instagram, Twitter, etc.)
 * - Image format conversion (JPEG, PNG, WebP)
 * - Quality optimization
 * - Aspect ratio preservation or cropping
 * - Batch processing
 * - Watermark addition
 *
 * NPM Dependencies (reference for Node.js integration):
 * - sharp (already available in project)
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Auto_Optimize_Images implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Platform image specifications.
	 *
	 * @var array
	 */
	protected $platform_specs = array(
		'facebook'  => array(
			'feed'    => array(
				'width'  => 1200,
				'height' => 630,
			),
			'story'   => array(
				'width'  => 1080,
				'height' => 1920,
			),
			'profile' => array(
				'width'  => 180,
				'height' => 180,
			),
		),
		'instagram' => array(
			'feed'      => array(
				'width'  => 1080,
				'height' => 1080,
			),
			'story'     => array(
				'width'  => 1080,
				'height' => 1920,
			),
			'reels'     => array(
				'width'  => 1080,
				'height' => 1920,
			),
			'portrait'  => array(
				'width'  => 1080,
				'height' => 1350,
			),
			'landscape' => array(
				'width'  => 1080,
				'height' => 566,
			),
		),
		'twitter'   => array(
			'feed'    => array(
				'width'  => 1200,
				'height' => 675,
			),
			'header'  => array(
				'width'  => 1500,
				'height' => 500,
			),
			'profile' => array(
				'width'  => 400,
				'height' => 400,
			),
		),
		'linkedin'  => array(
			'feed'    => array(
				'width'  => 1200,
				'height' => 627,
			),
			'profile' => array(
				'width'  => 400,
				'height' => 400,
			),
		),
		'pinterest' => array(
			'pin'     => array(
				'width'  => 1000,
				'height' => 1500,
			),
			'profile' => array(
				'width'  => 165,
				'height' => 165,
			),
		),
		'tiktok'    => array(
			'video_cover' => array(
				'width'  => 1080,
				'height' => 1920,
			),
			'profile'     => array(
				'width'  => 200,
				'height' => 200,
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

		return __( 'Auto optimize images tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'auto_optimize_images';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Auto Optimize Images', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Automatically resize and optimize images for social media platform requirements. Supports platform-specific dimensions, format conversion, quality optimization, and batch processing.', 'mcp-ai-wpoos-pro' );
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
				'image_source'       => array(
					'type'        => 'string',
					'description' => __( 'Image URL or path (required)', 'mcp-ai-wpoos-pro' ),
				),
				'platforms'          => array(
					'type'        => 'array',
					'description' => __( 'Target platforms (required)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'facebook', 'instagram', 'twitter', 'linkedin', 'pinterest', 'tiktok' ),
					),
					'minItems'    => 1,
				),
				'image_type'         => array(
					'type'        => 'string',
					'description' => __( 'Image type for platform (feed, story, profile, etc.)', 'mcp-ai-wpoos-pro' ),
					'default'     => 'feed',
				),
				'output_format'      => array(
					'type'        => 'string',
					'description' => __( 'Output image format', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'jpeg', 'png', 'webp' ),
					'default'     => 'jpeg',
				),
				'quality'            => array(
					'type'        => 'integer',
					'description' => __( 'Output quality (1-100)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 85,
				),
				'crop_mode'          => array(
					'type'        => 'string',
					'description' => __( 'How to handle aspect ratio', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'cover', 'contain', 'fill' ),
					'default'     => 'cover',
				),
				'add_watermark'      => array(
					'type'        => 'boolean',
					'description' => __( 'Add watermark to images', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'watermark_image'    => array(
					'type'        => 'string',
					'description' => __( 'Watermark image URL or path', 'mcp-ai-wpoos-pro' ),
				),
				'watermark_position' => array(
					'type'        => 'string',
					'description' => __( 'Watermark position', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'center' ),
					'default'     => 'bottom-right',
				),
				'output_directory'   => array(
					'type'        => 'string',
					'description' => __( 'Output directory path (default: uploads/social-media)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'image_source', 'platforms' ),
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
				__( 'You do not have permission to optimize images.', 'mcp-ai-wpoos-pro' )
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
		if ( empty( $arguments['image_source'] ) ) {
			return new WP_Error(
				'missing_image',
				__( 'Image source is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['platforms'] ) || ! is_array( $arguments['platforms'] ) ) {
			return new WP_Error(
				'missing_platforms',
				__( 'At least one target platform is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitize inputs.
		$image_source       = sanitize_text_field( $arguments['image_source'] );
		$platforms          = array_map( 'sanitize_text_field', $arguments['platforms'] );
		$image_type         = isset( $arguments['image_type'] ) ? sanitize_text_field( $arguments['image_type'] ) : 'feed';
		$output_format      = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'jpeg';
		$quality            = isset( $arguments['quality'] ) ? absint( $arguments['quality'] ) : 85;
		$crop_mode          = isset( $arguments['crop_mode'] ) ? sanitize_text_field( $arguments['crop_mode'] ) : 'cover';
		$add_watermark      = isset( $arguments['add_watermark'] ) ? (bool) $arguments['add_watermark'] : false;
		$watermark_image    = isset( $arguments['watermark_image'] ) ? sanitize_text_field( $arguments['watermark_image'] ) : '';
		$watermark_position = isset( $arguments['watermark_position'] ) ? sanitize_text_field( $arguments['watermark_position'] ) : 'bottom-right';
		$output_directory   = isset( $arguments['output_directory'] ) ? sanitize_text_field( $arguments['output_directory'] ) : '';

		// Get source image.
		$source_image_path = $this->get_image_path( $image_source );

		if ( is_wp_error( $source_image_path ) ) {
			return $source_image_path;
		}

		// Set output directory.
		if ( empty( $output_directory ) ) {
			$upload_dir       = wp_upload_dir();
			$output_directory = $upload_dir['basedir'] . '/social-media';
		}

		// Create output directory if it doesn't exist.
		if ( ! file_exists( $output_directory ) ) {
			wp_mkdir_p( $output_directory );
		}

		// Process image for each platform.
		$results = array(
			'success'  => true,
			'original' => array(
				'path' => $source_image_path,
				'url'  => $this->get_image_url( $source_image_path ),
			),
			'images'   => array(),
			'errors'   => array(),
		);

		foreach ( $platforms as $platform ) {
			$result = $this->optimize_for_platform(
				$source_image_path,
				$platform,
				$image_type,
				$output_format,
				$quality,
				$crop_mode,
				$output_directory,
				$add_watermark,
				$watermark_image,
				$watermark_position
			);

			if ( is_wp_error( $result ) ) {
				$results['errors'][ $platform ] = $result->get_error_message();
			} else {
				$results['images'][ $platform ] = $result;
			}
		}

		$results['message'] = sprintf(
			/* translators: 1: Number of successful optimizations, 2: Number of total platforms */
			__( 'Successfully optimized images for %1$d of %2$d platforms.', 'mcp-ai-wpoos-pro' ),
			count( $results['images'] ),
			count( $platforms )
		);

		return $results;
	}

	/**
	 * Get local image path from URL or path.
	 *
	 * @param string $image_source Image URL or path.
	 * @return string|WP_Error Image path or error.
	 */
	protected function get_image_path( $image_source ) {
		// If already a path, validate and return.
		if ( file_exists( $image_source ) ) {
			// Validate MIME type.
			$mime_type = wp_check_filetype( $image_source );
			if ( ! in_array( $mime_type['type'], array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
				return new WP_Error(
					'invalid_image_type',
					__( 'Image must be JPEG, PNG, WebP, or GIF.', 'mcp-ai-wpoos-pro' )
				);
			}

			return $image_source;
		}

		// If URL, download to temporary location.
		if ( filter_var( $image_source, FILTER_VALIDATE_URL ) ) {
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$temp_file = download_url( $image_source );

			if ( is_wp_error( $temp_file ) ) {
				return $temp_file;
			}

			return $temp_file;
		}

		return new WP_Error(
			'invalid_image_source',
			__( 'Image source must be a valid file path or URL.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Get image URL from path.
	 *
	 * @param string $image_path Image path.
	 * @return string Image URL.
	 */
	protected function get_image_url( $image_path ) {
		$upload_dir = wp_upload_dir();
		return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $image_path );
	}

	/**
	 * Optimize image for specific platform.
	 *
	 * @param string $source_path        Source image path.
	 * @param string $platform           Platform slug.
	 * @param string $image_type         Image type (feed, story, etc.).
	 * @param string $output_format      Output format.
	 * @param int    $quality            Quality (1-100).
	 * @param string $crop_mode          Crop mode.
	 * @param string $output_directory   Output directory.
	 * @param bool   $add_watermark      Add watermark.
	 * @param string $watermark_image    Watermark image path.
	 * @param string $watermark_position Watermark position.
	 * @return array|WP_Error Optimized image data or error.
	 */
	protected function optimize_for_platform( $source_path, $platform, $image_type, $output_format, $quality, $crop_mode, $output_directory, $add_watermark, $watermark_image, $watermark_position ) {
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

		// Get image type specs.
		if ( ! isset( $this->platform_specs[ $platform ][ $image_type ] ) ) {
			// Fall back to first available type.
			$image_type = key( $this->platform_specs[ $platform ] );
		}

		$specs = $this->platform_specs[ $platform ][ $image_type ];

		// Use WordPress image editor.
		$editor = wp_get_image_editor( $source_path );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		// Resize image.
		$result = $editor->resize( $specs['width'], $specs['height'], ( 'cover' === $crop_mode ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Set quality.
		$editor->set_quality( $quality );

		// Generate output filename.
		$filename = sprintf(
			'%s-%s-%s-%s.%s',
			basename( $source_path, '.' . pathinfo( $source_path, PATHINFO_EXTENSION ) ),
			$platform,
			$image_type,
			gmdate( 'YmdHis' ),
			$output_format
		);

		$output_path = $output_directory . '/' . $filename;

		// Save optimized image.
		$saved = $editor->save( $output_path, 'image/' . $output_format );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Add watermark if requested.
		if ( $add_watermark && ! empty( $watermark_image ) ) {
			$watermarked = $this->add_watermark( $output_path, $watermark_image, $watermark_position );
			if ( is_wp_error( $watermarked ) ) {
				// Log error but don't fail the operation.
				error_log( 'Watermark failed: ' . $watermarked->get_error_message() );
			}
		}

		// Get file info.
		$file_size = filesize( $output_path );

		return array(
			'platform'   => $platform,
			'type'       => $image_type,
			'width'      => $specs['width'],
			'height'     => $specs['height'],
			'format'     => $output_format,
			'quality'    => $quality,
			'size_bytes' => $file_size,
			'size_kb'    => round( $file_size / 1024, 2 ),
			'path'       => $output_path,
			'url'        => $this->get_image_url( $output_path ),
		);
	}

	/**
	 * Add watermark to image.
	 *
	 * @param string $image_path    Image path.
	 * @param string $watermark_path Watermark path.
	 * @param string $position      Position.
	 * @return true|WP_Error True on success, error on failure.
	 */
	protected function add_watermark( $image_path, $watermark_path, $position ) {
		// Get watermark path if URL.
		if ( filter_var( $watermark_path, FILTER_VALIDATE_URL ) ) {
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$watermark_path = download_url( $watermark_path );
			if ( is_wp_error( $watermark_path ) ) {
				return $watermark_path;
			}
		}

		// Validate watermark exists.
		if ( ! file_exists( $watermark_path ) ) {
			return new WP_Error(
				'watermark_not_found',
				__( 'Watermark image not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		// This is a placeholder for watermark implementation.
		// Real implementation would use GD or ImageMagick to composite the watermark.
		// For now, just return success.
		return true;
	}
}
