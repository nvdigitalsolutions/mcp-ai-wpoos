<?php
/**
 * Tool for applying artistic styles to images using AI.
 *
 * Applies various artistic styles including:
 * - Famous artist styles (Van Gogh, Picasso, etc.)
 * - Art movements (Impressionism, Cubism, etc.)
 * - Custom style transfer from reference images
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
 * Apply artistic styles to images using AI style transfer.
 */
class WP_MCP_AI_Tool_Apply_Artistic_Style extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'apply_artistic_style';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Apply Artistic Style', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Apply artistic styles to images using AI style transfer. Transform photos into artwork in various artistic styles.', 'mcp-ai-wpoos-pro' );
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
					'style'        => array(
						'type'        => 'string',
						'description' => __( 'Style preset: "van_gogh", "picasso", "monet", "kandinsky", "ukiyo-e", "pop_art", "watercolor".', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'van_gogh', 'picasso', 'monet', 'kandinsky', 'ukiyo-e', 'pop_art', 'watercolor', 'oil_painting', 'sketch' ),
					),
					'strength'     => array(
						'type'        => 'number',
						'description' => __( 'Style strength (0-1). Higher values apply style more strongly.', 'mcp-ai-wpoos-pro' ),
						'minimum'     => 0,
						'maximum'     => 1,
						'default'     => 0.8,
					),
					'style_image'  => array(
						'type'        => 'object',
						'description' => __( 'Custom style reference image (optional).', 'mcp-ai-wpoos-pro' ),
						'properties'  => array(
							'attachment_id' => array( 'type' => 'integer' ),
							'url'           => array( 'type' => 'string' ),
						),
					),
					'use_remote'   => array(
						'type'        => 'boolean',
						'description' => __( 'Use remote GPU processing for faster style transfer.', 'mcp-ai-wpoos-pro' ),
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
				__( 'You do not have permission to apply styles to images.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get style parameters.
		$style    = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : 'van_gogh';
		$strength = isset( $arguments['strength'] ) ? floatval( $arguments['strength'] ) : 0.8;

		// Apply style transfer (placeholder - would use actual AI model).
		$result = $this->apply_style_transfer( $source_image, $style, $strength, $arguments, $context );

		// Clean up source image if it was a temp file.
		$this->cleanup_source_image( $source_image, $arguments );

		return $result;
	}

	/**
	 * Apply style transfer to image.
	 *
	 * @param WP_Image_Editor $source_image Source image.
	 * @param string          $style        Style to apply.
	 * @param float           $strength     Style strength.
	 * @param array           $arguments    Tool arguments.
	 * @param array           $context      Execution context.
	 * @return array|WP_Error Style transfer results or error.
	 */
	protected function apply_style_transfer( $source_image, $style, $strength, $arguments, $context ) {
		// This would use an AI style transfer model.
		// For now, save the image as-is (placeholder).
		$saved_file = $source_image->save();
		if ( is_wp_error( $saved_file ) ) {
			return $saved_file;
		}

		$attachment_id = $this->save_as_attachment( $saved_file['path'], $arguments, $context );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Save style metadata.
		update_post_meta( $attachment_id, '_wp_mcp_ai_artistic_style', $style );
		update_post_meta( $attachment_id, '_wp_mcp_ai_style_strength', $strength );

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
