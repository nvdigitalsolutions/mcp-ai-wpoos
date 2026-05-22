<?php
/**
 * Tool for optimizing images using Sharp.
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

require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-nodejs-subprocess.php';

/**
 * Optimize images using Sharp for high-performance processing.
 *
 * This tool leverages Sharp (via Node.js) to provide:
 * - High-performance image resizing and optimization
 * - Modern format conversion (WebP, AVIF)
 * - Advanced image operations (blur, sharpen, rotate)
 * - Batch optimization capabilities
 * - Compression without quality loss
 *
 * Sharp is pre-packaged with Linux x64 binaries in addons/pro/assets/vendor/sharp/
 * for immediate use on most production servers. Other platforms require running
 * "npm install sharp --include=optional" in the addons/pro directory.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Optimize_Image_Sharp implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_NodeJS_Subprocess;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'optimize_image_sharp';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Optimize Image with Sharp', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'High-performance image optimization using Sharp. Supports resizing, format conversion (WebP, AVIF), compression, and advanced operations like blur, sharpen, and rotate. Significantly faster than ImageMagick/GraphicsMagick for batch processing.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_id'   => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the image to optimize', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'operation'       => array(
					'type'        => 'string',
					'enum'        => array( 'optimize', 'resize', 'convert', 'enhance' ),
					'description' => __( 'Operation: optimize (compress), resize (dimensions), convert (format), enhance (sharpen/blur)', 'mcp-ai-wpoos-pro' ),
					'default'     => 'optimize',
				),
				'width'           => array(
					'type'        => 'integer',
					'description' => __( 'Target width in pixels (for resize operation)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 10000,
				),
				'height'          => array(
					'type'        => 'integer',
					'description' => __( 'Target height in pixels (for resize operation)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 10000,
				),
				'format'          => array(
					'type'        => 'string',
					'enum'        => array( 'webp', 'avif', 'jpeg', 'png' ),
					'description' => __( 'Target format for conversion. WebP and AVIF offer superior compression.', 'mcp-ai-wpoos-pro' ),
				),
				'quality'         => array(
					'type'        => 'integer',
					'description' => __( 'Output quality (1-100). Default: 80 for lossy, 100 for lossless.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 80,
				),
				'sharpen'         => array(
					'type'        => 'boolean',
					'description' => __( 'Apply sharpening filter (for enhance operation)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'blur'            => array(
					'type'        => 'number',
					'description' => __( 'Blur sigma value (0.3-1000). Higher = more blur (for enhance operation)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.3,
					'maximum'     => 1000,
				),
				'rotate'          => array(
					'type'        => 'integer',
					'description' => __( 'Rotation angle in degrees (0, 90, 180, 270)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 0, 90, 180, 270 ),
				),
				'maintain_aspect' => array(
					'type'        => 'boolean',
					'description' => __( 'Maintain aspect ratio when resizing', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'upload_result'   => array(
					'type'        => 'boolean',
					'description' => __( 'Upload optimized image to media library as new attachment', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'attachment_id' ),
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
			'external-dependency',  // Requires Sharp (Node.js).
			'performance-impact',   // Large images may temporarily affect performance.
			'idempotent',           // Can be called multiple times safely.
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if media toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_media_toolkit'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Media Toolkit is not enabled. Please enable it in settings.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate attachment exists and is an image.
		$attachment_id = absint( $arguments['attachment_id'] );
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid attachment ID or not an image.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get source file path.
		$source_path = get_attached_file( $attachment_id );
		if ( ! $source_path || ! file_exists( $source_path ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Image file not found.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check if Sharp is available (Node.js package).
		$sharp_available = $this->check_sharp_availability();
		if ( ! $sharp_available ) {
			return array(
				'success' => false,
				'error'   => __( 'Sharp is not fully installed. Sharp requires Node.js, platform-specific binaries (libvips), and its dependencies (detect-libc, color, semver). To install: (1) Navigate to addons/pro directory, (2) Run "npm install --include=optional" to install Sharp with platform binaries, (3) Run "npm run build" to copy to vendor directory. See docs/BUILD_AND_DISTRIBUTION.md for details.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Build operation parameters.
		$operation = isset( $arguments['operation'] ) ? sanitize_text_field( $arguments['operation'] ) : 'optimize';
		$params    = array(
			'source'          => $source_path,
			'operation'       => $operation,
			'quality'         => isset( $arguments['quality'] ) ? absint( $arguments['quality'] ) : 80,
			'maintain_aspect' => isset( $arguments['maintain_aspect'] ) ? (bool) $arguments['maintain_aspect'] : true,
		);

		// Add operation-specific parameters.
		switch ( $operation ) {
			case 'resize':
				if ( isset( $arguments['width'] ) ) {
					$params['width'] = absint( $arguments['width'] );
				}
				if ( isset( $arguments['height'] ) ) {
					$params['height'] = absint( $arguments['height'] );
				}
				break;

			case 'convert':
				if ( isset( $arguments['format'] ) ) {
					$params['format'] = sanitize_text_field( $arguments['format'] );
				}
				break;

			case 'enhance':
				if ( isset( $arguments['sharpen'] ) && $arguments['sharpen'] ) {
					$params['sharpen'] = true;
				}
				if ( isset( $arguments['blur'] ) ) {
					$params['blur'] = floatval( $arguments['blur'] );
				}
				break;
		}

		if ( isset( $arguments['rotate'] ) ) {
			$params['rotate'] = absint( $arguments['rotate'] );
		}

		// Process image with Sharp.
		$result = $this->process_with_sharp( $params );

		if ( ! $result || isset( $result['error'] ) ) {
			return array(
				'success' => false,
				'error'   => isset( $result['error'] ) ? $result['error'] : __( 'Image processing failed.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Upload result to media library if requested.
		$upload_result = isset( $arguments['upload_result'] ) ? (bool) $arguments['upload_result'] : true;
		if ( $upload_result && isset( $result['output_path'] ) ) {
			$new_attachment_id = $this->upload_processed_image( $result['output_path'], $attachment_id );

			if ( $new_attachment_id ) {
				$result['attachment_id'] = $new_attachment_id;
				$result['url']           = wp_get_attachment_url( $new_attachment_id );
			}
		}

		return array(
			'success'           => true,
			'message'           => __( 'Image optimized successfully with Sharp.', 'mcp-ai-wpoos-pro' ),
			'attachment_id'     => isset( $result['attachment_id'] ) ? $result['attachment_id'] : null,
			'url'               => isset( $result['url'] ) ? $result['url'] : null,
			'original_size'     => isset( $result['original_size'] ) ? $result['original_size'] : null,
			'optimized_size'    => isset( $result['optimized_size'] ) ? $result['optimized_size'] : null,
			'reduction_percent' => isset( $result['reduction_percent'] ) ? $result['reduction_percent'] : null,
			'dimensions'        => isset( $result['dimensions'] ) ? $result['dimensions'] : null,
		);
	}

	/**
	 * Check if Sharp is available.
	 *
	 * @return bool True if Sharp is available.
	 */
	private function check_sharp_availability() {
		// Check if package exists in vendor directory (production) or node_modules (development).
		$vendor_path       = WP_MCP_AI_PRO_PATH . 'assets/vendor/sharp/lib/index.js';
		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/sharp/lib/index.js';

		$sharp_exists = file_exists( $vendor_path ) || file_exists( $node_modules_path );
		if ( ! $sharp_exists ) {
			return false;
		}

		// Check if required dependencies exist (detect-libc, color, semver).
		// These should be in Sharp's node_modules subdirectory.
		$base_dir = file_exists( $vendor_path ) ? WP_MCP_AI_PRO_PATH . 'assets/vendor/sharp/' : WP_MCP_AI_PRO_PATH . 'node_modules/sharp/';

		$required_deps = array( 'detect-libc', 'color', 'semver' );
		foreach ( $required_deps as $dep ) {
			$dep_path = $base_dir . 'node_modules/' . $dep;
			if ( ! is_dir( $dep_path ) ) {
				// Dependency missing - Sharp won't work.
				return false;
			}
		}

		// Check if platform-specific binaries exist.
		// At least one platform binary should exist for Sharp to function.
		$platform_binaries_path = $base_dir . 'node_modules/@img';
		if ( ! is_dir( $platform_binaries_path ) ) {
			return false;
		}

		// Use Process Service to check for Node.js availability.
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		return $process_service->is_command_available( 'node' );
	}

	/**
	 * Process image with Sharp via Node.js subprocess.
	 *
	 * The bundled script addons/pro/bin/sharp-process.js is invoked with the
	 * processing parameters serialised to a temporary JSON file.  An optional
	 * WordPress filter `wp_mcp_ai_sharp_process_image` lets site owners
	 * short-circuit the built-in implementation with a custom one.
	 *
	 * @param array $params Processing parameters.
	 * @return array|false Processing result array or false/error array on failure.
	 */
	private function process_with_sharp( $params ) {
		/**
		 * Filter to allow a custom Sharp processing implementation.
		 *
		 * Return a non-false value to bypass the built-in Node.js subprocess call.
		 *
		 * @param array|false $result Processing result or false to use built-in.
		 * @param array       $params Processing parameters.
		 */
		$custom_result = apply_filters( 'wp_mcp_ai_sharp_process_image', false, $params );
		if ( false !== $custom_result ) {
			return $custom_result;
		}

		// Determine the path to the processing script.
		$script_path = WP_MCP_AI_PRO_PATH . 'bin/sharp-process.js';
		if ( ! file_exists( $script_path ) ) {
			return array(
				'error' => __( 'Sharp processing script not found. Please reinstall the plugin.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Write params to a temporary JSON file.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$params_file = wp_tempnam( 'sharp-params-' );
		if ( ! $params_file ) {
			return array(
				'error' => sprintf(
					/* translators: %s: system temp directory path */
					__( 'Failed to create temporary file for Sharp processing. Check write permissions on %s.', 'mcp-ai-wpoos-pro' ),
					get_temp_dir()
				),
			);
		}

		// Determine the output file extension. Sanitize to alphanumeric only.
		$source_ext = isset( $params['format'] ) ? $params['format'] : pathinfo( $params['source'], PATHINFO_EXTENSION );
		$source_ext = preg_replace( '/[^a-zA-Z0-9]/', '', $source_ext );
		if ( '' === $source_ext ) {
			$source_ext = 'jpg';
		}

		// Create a uniquely-named output path with the correct extension.
		// wp_tempnam() creates a placeholder file to reserve the path; we delete it.
		// immediately so Sharp can write to the extension-appended path without conflict.
		$output_base = wp_tempnam( 'sharp-output-' );
		$output_file = $output_base . '.' . $source_ext;
		wp_delete_file( $output_base ); // Remove placeholder; Sharp creates the ext-versioned file.

		if ( false === file_put_contents( $params_file, wp_json_encode( $params ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct filesystem operation; WP_Filesystem unavailable here.
			wp_delete_file( $params_file );
			return array( 'error' => __( 'Failed to write Sharp processing parameters.', 'mcp-ai-wpoos-pro' ) );
		}

		// Execute the Node.js script.
		$result = $this->execute_nodejs_script(
			$script_path,
			array( $params_file, $output_file ),
			array(
				'timeout'    => 120,
				'parse_json' => true,
			)
		);

		// Clean up the params temp file.
		wp_delete_file( $params_file );

		if ( is_wp_error( $result ) ) {
			wp_delete_file( $output_file );
			return array( 'error' => $result->get_error_message() );
		}

		if ( empty( $result['success'] ) ) {
			wp_delete_file( $output_file );
			return array(
				'error' => isset( $result['error'] ) ? $result['error'] : __( 'Sharp processing failed with an unknown error.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return $result;
	}

	/**
	 * Upload processed image to media library.
	 *
	 * @param string $file_path Path to processed image file.
	 * @param int    $parent_id Parent attachment ID.
	 * @return int|false New attachment ID or false on failure.
	 */
	private function upload_processed_image( $file_path, $parent_id = 0 ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		// Get file info.
		$file_name = basename( $file_path );
		$file_type = wp_check_filetype( $file_name );

		// Prepare upload.
		$upload_dir  = wp_upload_dir();
		$target_path = $upload_dir['path'] . '/' . $file_name;

		// Copy file to uploads directory.
		if ( ! copy( $file_path, $target_path ) ) {
			return false;
		}

		// Prepare attachment data.
		$attachment_data = array(
			'post_mime_type' => $file_type['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $file_name ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $parent_id,
		);

		// Insert attachment.
		$attachment_id = wp_insert_attachment( $attachment_data, $target_path, $parent_id );

		if ( ! is_wp_error( $attachment_id ) ) {
			// Generate metadata.
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $target_path );
			wp_update_attachment_metadata( $attachment_id, $attachment_metadata );

			return $attachment_id;
		}

		return false;
	}
}
