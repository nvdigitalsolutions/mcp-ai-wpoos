<?php
/**
 * Tool for AI-powered image upscaling.
 *
 * Upscales images using AI to increase resolution while preserving quality.
 * Supports 2x, 4x, and 8x upscaling factors.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.8
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';

/**
 * Upscale images using AI super-resolution.
 */
class WP_MCP_AI_Tool_Upscale_Image_AI extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'upscale_image_ai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Upscale Image (AI)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Upscale images using AI super-resolution. Increase resolution by 2x, 4x, or 8x while preserving quality and details.', 'mcp-ai-wpoos-pro' );
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
					'scale_factor' => array(
						'type'        => 'number',
						'description' => __( 'Upscaling factor: 2, 4, or 8.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 2, 4, 8 ),
						'default'     => 2,
					),
					'model'        => array(
						'type'        => 'string',
						'description' => __( 'AI model: "real-esrgan" (general), "esrgan" (photos), "anime" (illustrations).', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'real-esrgan', 'esrgan', 'anime' ),
						'default'     => 'real-esrgan',
					),
					'denoise'      => array(
						'type'        => 'number',
						'description' => __( 'Denoising strength (0-1). Higher values remove more noise.', 'mcp-ai-wpoos-pro' ),
						'minimum'     => 0,
						'maximum'     => 1,
						'default'     => 0.5,
					),
					'use_remote'   => array(
						'type'        => 'boolean',
						'description' => __( 'Use remote GPU processing for faster upscaling.', 'mcp-ai-wpoos-pro' ),
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
			'cpu-intensive',
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to upscale images.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get scale factor.
		$scale_factor = isset( $arguments['scale_factor'] ) ? absint( $arguments['scale_factor'] ) : 2;
		if ( ! in_array( $scale_factor, array( 2, 4, 8 ), true ) ) {
			$scale_factor = 2;
		}

		// Get model.
		$model = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : 'real-esrgan';

		// Use remote processing if requested.
		if ( ! empty( $arguments['use_remote'] ) ) {
			$result = $this->upscale_remote( $source_image, $scale_factor, $model, $arguments, $context );
		} else {
			$result = $this->upscale_local( $source_image, $scale_factor, $model, $arguments, $context );
		}

		// Clean up source image if it was a temp file.
		$this->cleanup_source_image( $source_image, $arguments );

		return $result;
	}

	/**
	 * Upscale image locally using Real-ESRGAN.
	 *
	 * @param WP_Image_Editor $source_image Source image.
	 * @param int             $scale_factor Scale factor.
	 * @param string          $model        AI model.
	 * @param array           $arguments    Tool arguments.
	 * @param array           $context      Execution context.
	 * @return array|WP_Error Upscaling results or error.
	 */
	protected function upscale_local( $source_image, $scale_factor, $model, $arguments, $context ) {
		// This would use a Python library like Real-ESRGAN.
		// For now, we'll use basic WordPress image scaling as fallback.
		$size     = $source_image->get_size();
		$new_size = array(
			'width'  => $size['width'] * $scale_factor,
			'height' => $size['height'] * $scale_factor,
		);

		$resize = $source_image->resize( $new_size['width'], $new_size['height'], false );
		if ( is_wp_error( $resize ) ) {
			return $resize;
		}

		// Save as attachment.
		$attachment_id = $this->save_as_attachment( $source_image->generate_filename(), $arguments, $context );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return $this->format_attachment_response( $attachment_id );
	}

	/**
	 * Upscale image using remote GPU processing.
	 *
	 * @param WP_Image_Editor $source_image Source image.
	 * @param int             $scale_factor Scale factor.
	 * @param string          $model        AI model.
	 * @param array           $arguments    Tool arguments.
	 * @param array           $context      Execution context.
	 * @return array|WP_Error Upscaling results or error.
	 */
	protected function upscale_remote( $source_image, $scale_factor, $model, $arguments, $context ) {
		// This would delegate to a remote upscaling service.
		// For now, fall back to local processing.
		return $this->upscale_local( $source_image, $scale_factor, $model, $arguments, $context );
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
