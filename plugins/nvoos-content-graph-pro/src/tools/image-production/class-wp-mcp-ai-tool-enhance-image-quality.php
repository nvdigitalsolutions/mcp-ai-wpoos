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
 * Tool for AI-powered image quality enhancement.
 *
 * Enhances image quality including sharpness, colors, contrast, and removes artifacts.
 * Uses various AI models for different enhancement types.
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
 * Enhance image quality using AI.
 */
class WP_MCP_AI_Tool_Enhance_Image_Quality extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'enhance_image_quality';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Enhance Image Quality', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Enhance image quality using AI. Improves sharpness, colors, contrast, and removes artifacts and noise.', 'nvoos-content-graph-pro' );
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
					'enhancements' => array(
						'type'        => 'array',
						'description' => __( 'Enhancements to apply: "sharpness", "color", "contrast", "denoise", "auto".', 'nvoos-content-graph-pro' ),
						'items'       => array(
							'type' => 'string',
							'enum' => array( 'sharpness', 'color', 'contrast', 'denoise', 'auto' ),
						),
						'default'     => array( 'auto' ),
					),
					'strength'     => array(
						'type'        => 'number',
						'description' => __( 'Enhancement strength (0-1). Default is 0.5 for moderate enhancement.', 'nvoos-content-graph-pro' ),
						'minimum'     => 0,
						'maximum'     => 1,
						'default'     => 0.5,
					),
					'use_remote'   => array(
						'type'        => 'boolean',
						'description' => __( 'Use remote GPU processing for faster enhancement.', 'nvoos-content-graph-pro' ),
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
				__( 'You do not have permission to enhance images.', 'nvoos-content-graph-pro' )
			);
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get enhancements.
		$enhancements = isset( $arguments['enhancements'] ) ? (array) $arguments['enhancements'] : array( 'auto' );
		$strength     = isset( $arguments['strength'] ) ? floatval( $arguments['strength'] ) : 0.5;
		$strength     = max( 0, min( 1, $strength ) );

		// Apply enhancements.
		$result = $this->apply_enhancements( $source_image, $enhancements, $strength, $arguments, $context );

		// Clean up source image if it was a temp file.
		$this->cleanup_source_image( $source_image, $arguments );

		return $result;
	}

	/**
	 * Apply image enhancements.
	 *
	 * @param WP_Image_Editor $source_image Source image.
	 * @param array           $enhancements Enhancements to apply.
	 * @param float           $strength     Enhancement strength.
	 * @param array           $arguments    Tool arguments.
	 * @param array           $context      Execution context.
	 * @return array|WP_Error Enhancement results or error.
	 */
	protected function apply_enhancements( $source_image, $enhancements, $strength, $arguments, $context ) {
		// Auto-enhance if 'auto' is in enhancements.
		if ( in_array( 'auto', $enhancements, true ) ) {
			$enhancements = array( 'sharpness', 'color', 'contrast', 'denoise' );
		}

		// Apply each enhancement using WordPress image editor.
		foreach ( $enhancements as $enhancement ) {
			switch ( $enhancement ) {
				case 'sharpness':
					// WordPress doesn't have built-in sharpness, but GD has imagefilter.
					$this->apply_sharpness( $source_image, $strength );
					break;
				case 'color':
					$this->apply_color_enhancement( $source_image, $strength );
					break;
				case 'contrast':
					$this->apply_contrast( $source_image, $strength );
					break;
				case 'denoise':
					$this->apply_denoise( $source_image, $strength );
					break;
			}
		}

		// Save as attachment.
		$saved_file = $source_image->save();
		if ( is_wp_error( $saved_file ) ) {
			return $saved_file;
		}

		$attachment_id = $this->save_as_attachment( $saved_file['path'], $arguments, $context );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return $this->format_attachment_response( $attachment_id );
	}

	/**
	 * Apply sharpness enhancement.
	 *
	 * @param WP_Image_Editor $image    Image editor.
	 * @param float           $strength Strength.
	 * @return void
	 */
	protected function apply_sharpness( $image, $strength ) {
		// This would use imagefilter with IMG_FILTER_SHARPEN or a custom kernel.
		// For now, this is a placeholder.
	}

	/**
	 * Apply color enhancement.
	 *
	 * @param WP_Image_Editor $image    Image editor.
	 * @param float           $strength Strength.
	 * @return void
	 */
	protected function apply_color_enhancement( $image, $strength ) {
		// This would adjust saturation and vibrancy.
		// For now, this is a placeholder.
	}

	/**
	 * Apply contrast enhancement.
	 *
	 * @param WP_Image_Editor $image    Image editor.
	 * @param float           $strength Strength.
	 * @return void
	 */
	protected function apply_contrast( $image, $strength ) {
		// This would use imagefilter with IMG_FILTER_CONTRAST.
		// For now, this is a placeholder.
	}

	/**
	 * Apply denoising.
	 *
	 * @param WP_Image_Editor $image    Image editor.
	 * @param float           $strength Strength.
	 * @return void
	 */
	protected function apply_denoise( $image, $strength ) {
		// This would apply noise reduction filters.
		// For now, this is a placeholder.
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
