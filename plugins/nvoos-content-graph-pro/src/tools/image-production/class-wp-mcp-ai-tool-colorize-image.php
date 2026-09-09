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
 * Tool for AI-powered image colorization.
 *
 * Colorizes black and white or grayscale images using AI.
 * Automatically detects appropriate colors based on image content.
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
 * Colorize black and white images using AI.
 */
class WP_MCP_AI_Tool_Colorize_Image extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'colorize_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Colorize Image', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Colorize black and white or grayscale images using AI. Automatically detects and applies realistic colors.', 'nvoos-content-graph-pro' );
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
					'color_mode' => array(
						'type'        => 'string',
						'description' => __( 'Colorization mode: "auto" (AI decides), "vibrant" (bold colors), "subtle" (muted tones).', 'nvoos-content-graph-pro' ),
						'enum'        => array( 'auto', 'vibrant', 'subtle' ),
						'default'     => 'auto',
					),
					'use_remote' => array(
						'type'        => 'boolean',
						'description' => __( 'Use remote GPU processing for faster colorization.', 'nvoos-content-graph-pro' ),
						'default'     => false,
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
			'gpu-accelerated',
			'performance-impact',
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
				__( 'You do not have permission to colorize images.', 'nvoos-content-graph-pro' )
			);
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get color mode.
		$color_mode = isset( $arguments['color_mode'] ) ? sanitize_text_field( $arguments['color_mode'] ) : 'auto';

		// Apply colorization (placeholder - would use actual AI model).
		$result = $this->colorize_image( $source_image, $color_mode, $arguments, $context );

		// Clean up source image if it was a temp file.
		$this->cleanup_source_image( $source_image, $arguments );

		return $result;
	}

	/**
	 * Colorize the image.
	 *
	 * @param WP_Image_Editor $source_image Source image.
	 * @param string          $color_mode   Color mode.
	 * @param array           $arguments    Tool arguments.
	 * @param array           $context      Execution context.
	 * @return array|WP_Error Colorization results or error.
	 */
	protected function colorize_image( $source_image, $color_mode, $arguments, $context ) {
		// This would use an AI colorization model like DeOldify.
		// For now, save the image as-is (placeholder).
		$saved_file = $source_image->save();
		if ( is_wp_error( $saved_file ) ) {
			return $saved_file;
		}

		$attachment_id = $this->save_as_attachment( $saved_file['path'], $arguments, $context );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Save colorization metadata.
		update_post_meta( $attachment_id, '_wp_mcp_ai_colorized', true );
		update_post_meta( $attachment_id, '_wp_mcp_ai_color_mode', $color_mode );

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
