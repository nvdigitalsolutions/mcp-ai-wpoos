<?php
/**
 * Tool for converting images between formats.
 *
 * Converts images between JPG, PNG, WebP, AVIF, GIF, and other formats.
 * Supports batch conversion and optimization during conversion.
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
 * Convert images between different formats.
 */
class WP_MCP_AI_Tool_Convert_Image_Format extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'convert_image_format';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Convert Image Format', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Convert images between formats (JPG, PNG, WebP, AVIF, GIF). Supports optimization during conversion.', 'mcp-ai-wpoos-pro' );
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
					'format'   => array(
						'type'        => 'string',
						'description' => __( 'Target format: "jpg", "png", "webp", "avif", "gif".', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif' ),
					),
					'quality'  => array(
						'type'        => 'integer',
						'description' => __( 'Output quality (1-100). Higher values preserve more quality.', 'mcp-ai-wpoos-pro' ),
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 90,
					),
					'optimize' => array(
						'type'        => 'boolean',
						'description' => __( 'Optimize the output file for web delivery.', 'mcp-ai-wpoos-pro' ),
						'default'     => true,
					),
				)
			),
			'required'             => array( 'format' ),
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to convert images.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate target format.
		$format = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : '';
		if ( empty( $format ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_format',
				__( 'Target format is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Normalize format.
		$format = strtolower( $format );
		if ( 'jpg' === $format ) {
			$format = 'jpeg';
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Set quality.
		$quality = isset( $arguments['quality'] ) ? absint( $arguments['quality'] ) : 90;
		$source_image->set_quality( $quality );

		// Convert format by saving with new extension.
		$pathinfo  = pathinfo( $source_image->generate_filename() );
		$base_name = isset( $pathinfo['filename'] ) ? $pathinfo['filename'] : 'image';
		$new_path  = $source_image->generate_filename( $base_name, null, $format );

		$saved = $source_image->save( $new_path, 'image/' . $format );
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
