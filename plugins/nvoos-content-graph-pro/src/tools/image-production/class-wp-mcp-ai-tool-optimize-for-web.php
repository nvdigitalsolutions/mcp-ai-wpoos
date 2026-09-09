<?php
/**
 * Image-production tool batch (ecosystem port - Wave F2, image-production toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/image-production/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`; the
 * base-owned `WP_MCP_AI_PATH` interface/image-base/trait requires gain exists-check seams resolving from
 * the addon's D8-compat `src/` copies; the import tool's `NVOOS_CONTENT_GRAPH_PRO_PATH` refs swap to
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` with the `src/` root.
 *
 * Tool for optimizing images for web performance.
 *
 * Comprehensive web optimization including:
 * - Format conversion to modern formats (WebP, AVIF)
 * - Compression with quality preservation
 * - Metadata stripping
 * - Progressive/interlaced encoding
 * - Lazy loading preparation
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.8
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Tool_Image_Base' ) ) {
	$nvoos_content_graph_pro_image_base = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/class-wp-mcp-ai-tool-image-base.php';
	if ( file_exists( $nvoos_content_graph_pro_image_base ) ) {
		require_once $nvoos_content_graph_pro_image_base;
	}
}

/**
 * Optimize images for web performance.
 */
class WP_MCP_AI_Tool_Optimize_For_Web extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'optimize_for_web';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Optimize for Web', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Comprehensively optimize images for web performance. Includes format conversion, compression, metadata stripping, and more.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				$this->get_source_parameters_schema(),
				array(
					'target_format'  => array(
						'type'        => 'string',
						'description' => __( 'Target format: "auto" (best available), "webp", "avif", "jpg".', 'nvoos-content-graph-pro' ),
						'enum'        => array( 'auto', 'webp', 'avif', 'jpg' ),
						'default'     => 'auto',
					),
					'quality'        => array(
						'type'        => 'integer',
						'description' => __( 'Compression quality (1-100). Default 85 for web.', 'nvoos-content-graph-pro' ),
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 85,
					),
					'max_width'      => array(
						'type'        => 'integer',
						'description' => __( 'Maximum width in pixels. Images larger than this will be resized.', 'nvoos-content-graph-pro' ),
						'default'     => 2560,
					),
					'strip_metadata' => array(
						'type'        => 'boolean',
						'description' => __( 'Remove EXIF and other metadata to reduce file size.', 'nvoos-content-graph-pro' ),
						'default'     => true,
					),
					'progressive'    => array(
						'type'        => 'boolean',
						'description' => __( 'Use progressive/interlaced encoding for better perceived performance.', 'nvoos-content-graph-pro' ),
						'default'     => true,
					),
					'target_size_kb' => array(
						'type'        => 'integer',
						'description' => __( 'Target file size in KB. Will adjust quality to meet target.', 'nvoos-content-graph-pro' ),
					),
				)
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'write',
			'idempotent',
			'performance-impact',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to optimize images.', 'nvoos-content-graph-pro' )
			);
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get source size and file size.
		$source_size      = $source_image->get_size();
		$source_file_path = $source_image->generate_filename();
		$original_size    = file_exists( $source_file_path ) ? filesize( $source_file_path ) : 0;

		// Step 1: Resize if needed.
		$max_width = isset( $arguments['max_width'] ) ? absint( $arguments['max_width'] ) : 2560;
		if ( $source_size['width'] > $max_width ) {
			$new_height = round( ( $max_width / $source_size['width'] ) * $source_size['height'] );
			$resize     = $source_image->resize( $max_width, $new_height, false );
			if ( is_wp_error( $resize ) ) {
				$this->cleanup_source_image( $source_image, $arguments );
				return $resize;
			}
		}

		// Step 2: Determine best format.
		$target_format = isset( $arguments['target_format'] ) ? sanitize_text_field( $arguments['target_format'] ) : 'auto';
		if ( 'auto' === $target_format ) {
			$target_format = $this->select_best_format();
		}

		// Step 3: Set quality.
		$quality = isset( $arguments['quality'] ) ? absint( $arguments['quality'] ) : 85;
		$source_image->set_quality( $quality );

		// Step 4: Save with optimizations.
		$pathinfo  = pathinfo( $source_file_path );
		$base_name = isset( $pathinfo['filename'] ) ? $pathinfo['filename'] : 'image';
		$save_path = $source_image->generate_filename( $base_name, null, $target_format );

		$saved = $source_image->save( $save_path, 'image/' . $target_format );
		if ( is_wp_error( $saved ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return $saved;
		}

		// Step 5: If target size is specified, compress further.
		if ( ! empty( $arguments['target_size_kb'] ) ) {
			$target_bytes = absint( $arguments['target_size_kb'] ) * 1024;
			$compress     = $this->compress_to_target_size( $saved['path'], $target_bytes, $quality );
			if ( is_wp_error( $compress ) ) {
				$this->cleanup_source_image( $source_image, $arguments );
				wp_delete_file( $saved['path'] );
				return $compress;
			}
		}

		// Get final file size.
		$final_size = filesize( $saved['path'] );
		$savings    = $original_size > 0 ? round( ( ( $original_size - $final_size ) / $original_size ) * 100, 1 ) : 0;

		// Save as attachment.
		$attachment_id = $this->save_as_attachment( $saved['path'], $arguments, $context );
		if ( is_wp_error( $attachment_id ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			wp_delete_file( $saved['path'] );
			return $attachment_id;
		}

		// Save optimization metadata.
		update_post_meta( $attachment_id, '_wp_mcp_ai_optimized_for_web', true );
		update_post_meta( $attachment_id, '_wp_mcp_ai_optimization_savings', $savings );
		update_post_meta( $attachment_id, '_wp_mcp_ai_original_size', $original_size );
		update_post_meta( $attachment_id, '_wp_mcp_ai_optimized_size', $final_size );

		// Clean up.
		$this->cleanup_source_image( $source_image, $arguments );
		wp_delete_file( $saved['path'] );

		// Return result with optimization details.
		$result                 = $this->format_attachment_response( $attachment_id );
		$result['optimization'] = array(
			'original_size_kb' => round( $original_size / 1024, 1 ),
			'final_size_kb'    => round( $final_size / 1024, 1 ),
			'savings_percent'  => $savings,
			'format'           => $target_format,
		);

		return $result;
	}

	/**
	 * Select the best format for web.
	 *
	 * @return string Format name.
	 */
	protected function select_best_format() {
		// Check for AVIF support (WordPress 6.5+).
		if ( version_compare( get_bloginfo( 'version' ), '6.5', '>=' ) ) {
			return 'avif';
		}

		// Check for WebP support (WordPress 5.8+).
		if ( function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return 'webp';
		}

		// Fallback to JPEG.
		return 'jpeg';
	}

	/**
	 * Compress image to target file size.
	 *
	 * @param string $file_path  Path to image file.
	 * @param int    $max_bytes  Maximum file size in bytes.
	 * @param int    $quality    Starting quality.
	 * @return true|WP_Error True on success, error on failure.
	 */
	protected function compress_to_target_size( $file_path, $max_bytes, $quality ) {
		$current_size = filesize( $file_path );
		$attempts     = 0;
		$max_attempts = 10;

		while ( $current_size > $max_bytes && $attempts < $max_attempts && $quality > 10 ) {
			$quality -= 5;
			$editor   = wp_get_image_editor( $file_path );
			if ( is_wp_error( $editor ) ) {
				return $editor;
			}

			$editor->set_quality( $quality );
			$saved = $editor->save( $file_path );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$current_size = filesize( $file_path );
			++$attempts;
		}

		return true;
	}

	/**
	 * Sanitize the tool result for LLM consumption.
	 *
	 * @param array|WP_Error $result The result to sanitize.
	 * @return array Sanitized result.
	 */
	public function sanitize_for_llm( $result ) {
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
			);
		}

		return array(
			'success' => true,
			'result'  => $result,
		);
	}
}
