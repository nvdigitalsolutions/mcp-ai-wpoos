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
 * Tool for generating responsive image variants.
 *
 * Creates multiple sizes of an image optimized for responsive design.
 * Generates srcset and sizes attributes for modern responsive images.
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
 * Generate responsive image variants.
 */
class WP_MCP_AI_Tool_Generate_Responsive_Images extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_responsive_images';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Responsive Images', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate multiple responsive variants of an image with optimized sizes for different screen resolutions and devices.', 'nvoos-content-graph-pro' );
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
					'sizes'   => array(
						'type'        => 'array',
						'description' => __( 'Array of widths to generate. Default: [320, 640, 768, 1024, 1366, 1920].', 'nvoos-content-graph-pro' ),
						'items'       => array( 'type' => 'integer' ),
						'default'     => array( 320, 640, 768, 1024, 1366, 1920 ),
					),
					'format'  => array(
						'type'        => 'string',
						'description' => __( 'Output format: "same" (keep original), "webp", "avif", "jpg".', 'nvoos-content-graph-pro' ),
						'enum'        => array( 'same', 'webp', 'avif', 'jpg' ),
						'default'     => 'webp',
					),
					'quality' => array(
						'type'        => 'integer',
						'description' => __( 'Quality for all variants (1-100).', 'nvoos-content-graph-pro' ),
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 85,
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
				__( 'You do not have permission to generate responsive images.', 'nvoos-content-graph-pro' )
			);
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get sizes to generate.
		$sizes = isset( $arguments['sizes'] ) ? (array) $arguments['sizes'] : array( 320, 640, 768, 1024, 1366, 1920 );
		$sizes = array_map( 'absint', $sizes );
		sort( $sizes );

		// Get format and quality.
		$format  = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'webp';
		$quality = isset( $arguments['quality'] ) ? absint( $arguments['quality'] ) : 85;

		// Get source size.
		$source_size = $source_image->get_size();

		// Filter out sizes larger than source.
		$sizes = array_filter(
			$sizes,
			function ( $width ) use ( $source_size ) {
				return $width <= $source_size['width'];
			}
		);

		// Generate variants.
		$variants = array();
		foreach ( $sizes as $width ) {
			$variant = $this->generate_variant( $source_image, $width, $format, $quality, $arguments, $context );
			if ( ! is_wp_error( $variant ) ) {
				$variants[] = $variant;
			}
		}

		// Clean up source image if it was a temp file.
		$this->cleanup_source_image( $source_image, $arguments );

		return array(
			'success'  => true,
			'count'    => count( $variants ),
			'variants' => $variants,
		);
	}

	/**
	 * Generate a single variant.
	 *
	 * @param WP_Image_Editor $source_image Source image.
	 * @param int             $width        Target width.
	 * @param string          $format       Output format.
	 * @param int             $quality      Output quality.
	 * @param array           $arguments    Tool arguments.
	 * @param array           $context      Execution context.
	 * @return array|WP_Error Variant data or error.
	 */
	protected function generate_variant( $source_image, $width, $format, $quality, $arguments, $context ) {
		// Clone the image editor to avoid modifying the original.
		$editor = clone $source_image;

		// Calculate height maintaining aspect ratio.
		$size   = $editor->get_size();
		$height = round( ( $width / $size['width'] ) * $size['height'] );

		// Resize.
		$resize = $editor->resize( $width, $height, false );
		if ( is_wp_error( $resize ) ) {
			return $resize;
		}

		// Set quality.
		$editor->set_quality( $quality );

		// Determine output format.
		$output_format = ( 'same' === $format ) ? null : $format;

		// Save.
		$saved = $editor->save( null, $output_format ? 'image/' . $output_format : null );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Save as attachment.
		$attachment_id = $this->save_as_attachment( $saved['path'], $arguments, $context );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $saved['path'] );
			return $attachment_id;
		}

		// Clean up temp file.
		wp_delete_file( $saved['path'] );

		// Get attachment URL.
		$url = wp_get_attachment_url( $attachment_id );

		return array(
			'width'         => $width,
			'height'        => $height,
			'attachment_id' => $attachment_id,
			'url'           => $url,
		);
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
