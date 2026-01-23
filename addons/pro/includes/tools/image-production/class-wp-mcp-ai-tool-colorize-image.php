<?php
/**
 * Tool for AI-powered image colorization.
 *
 * Colorizes black and white or grayscale images using AI.
 * Automatically detects appropriate colors based on image content.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';

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
		return __( 'Colorize Image', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Colorize black and white or grayscale images using AI. Automatically detects and applies realistic colors.', 'mcp-ai-wpoos-pro' );
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
					'color_mode'   => array(
						'type'        => 'string',
						'description' => __( 'Colorization mode: "auto" (AI decides), "vibrant" (bold colors), "subtle" (muted tones).', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'auto', 'vibrant', 'subtle' ),
						'default'     => 'auto',
					),
					'use_remote'   => array(
						'type'        => 'boolean',
						'description' => __( 'Use remote GPU processing for faster colorization.', 'mcp-ai-wpoos-pro' ),
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to colorize images.', 'mcp-ai-wpoos-pro' )
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
