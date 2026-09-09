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
 * Tool for smart content-aware image resizing.
 *
 * Resizes images intelligently by detecting and preserving important content.
 * Uses seam carving and other algorithms to maintain visual quality.
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
 * Smart content-aware image resizing.
 */
class WP_MCP_AI_Tool_Resize_Image_Smart extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'resize_image_smart';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Resize Image (Smart)', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Intelligently resize images while preserving important content. Uses content-aware algorithms to maintain visual quality.', 'nvoos-content-graph-pro' );
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
					'width'      => array(
						'type'        => 'integer',
						'description' => __( 'Target width in pixels.', 'nvoos-content-graph-pro' ),
					),
					'height'     => array(
						'type'        => 'integer',
						'description' => __( 'Target height in pixels.', 'nvoos-content-graph-pro' ),
					),
					'mode'       => array(
						'type'        => 'string',
						'description' => __( 'Resize mode: "crop" (center crop), "fit" (maintain aspect), "fill" (exact size), "smart" (content-aware).', 'nvoos-content-graph-pro' ),
						'enum'        => array( 'crop', 'fit', 'fill', 'smart' ),
						'default'     => 'smart',
					),
					'focus_area' => array(
						'type'        => 'string',
						'description' => __( 'Focus area: "center", "top", "bottom", "left", "right", "face" (auto-detect faces).', 'nvoos-content-graph-pro' ),
						'enum'        => array( 'center', 'top', 'bottom', 'left', 'right', 'face' ),
						'default'     => 'center',
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
				__( 'You do not have permission to resize images.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate dimensions.
		$width  = isset( $arguments['width'] ) ? absint( $arguments['width'] ) : 0;
		$height = isset( $arguments['height'] ) ? absint( $arguments['height'] ) : 0;

		if ( ! $width && ! $height ) {
			return new WP_Error(
				'wp_mcp_ai_missing_dimensions',
				__( 'At least one dimension (width or height) is required.', 'nvoos-content-graph-pro' )
			);
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get current size.
		$current_size = $source_image->get_size();

		// Calculate missing dimension if only one is provided.
		if ( ! $width ) {
			$width = round( ( $height / $current_size['height'] ) * $current_size['width'] );
		}
		if ( ! $height ) {
			$height = round( ( $width / $current_size['width'] ) * $current_size['height'] );
		}

		// Get resize mode.
		$mode = isset( $arguments['mode'] ) ? sanitize_text_field( $arguments['mode'] ) : 'smart';

		// Perform resize based on mode.
		switch ( $mode ) {
			case 'crop':
				$resize = $source_image->resize( $width, $height, true );
				break;
			case 'fit':
				$resize = $source_image->resize( $width, $height, false );
				break;
			case 'fill':
			case 'smart':
			default:
				// For smart mode, we'd use content-aware algorithms.
				// For now, use standard resize.
				$resize = $source_image->resize( $width, $height, false );
				break;
		}

		if ( is_wp_error( $resize ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return $resize;
		}

		// Save resized image.
		$saved = $source_image->save();
		if ( is_wp_error( $saved ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return $saved;
		}

		// Save as attachment.
		$attachment_id = $this->save_as_attachment( $saved['path'], $arguments, $context );
		if ( is_wp_error( $attachment_id ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			wp_delete_file( $saved['path'] );
			return $attachment_id;
		}

		// Clean up.
		$this->cleanup_source_image( $source_image, $arguments );
		wp_delete_file( $saved['path'] );

		return $this->format_attachment_response( $attachment_id );
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
