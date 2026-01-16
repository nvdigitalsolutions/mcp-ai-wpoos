<?php
/**
 * Trait for featured image generation in research pages.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for generating featured images in research pages.
 */
trait WP_MCP_AI_Research_Page_Featured_Image {
	/**
	 * Generate featured image using AI.
	 *
	 * @param string $prompt      Custom prompt or empty to use title.
	 * @param string $title       Title for fallback prompt.
	 * @param string $context     Context description (e.g., 'blog post', 'product', 'page').
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	protected static function generate_featured_image( $prompt, $title, $context = 'content' ) {
		// Use provided prompt or generate from title.
		if ( empty( $prompt ) ) {
			$prompt = sprintf(
				/* translators: 1: Context description, 2: Title */
				__( 'A professional featured image for %1$s about: %2$s', 'mcp-ai-wpoos-pro' ),
				$context,
				$title
			);
		}

		// Try OpenAI image generation first.
		if ( class_exists( 'WP_MCP_AI_Tool_Generate_OpenAI_Image' ) ) {
			$tool = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
			$result = $tool->execute(
				array(
					'prompt' => $prompt,
					'size'   => '1792x1024', // 16:9 aspect ratio.
				),
				array( 'user_id' => get_current_user_id() )
			);

			if ( ! is_wp_error( $result ) && isset( $result['attachment_id'] ) ) {
				return $result['attachment_id'];
			}
		}

		// Fallback to Gemini image generation.
		if ( class_exists( 'WP_MCP_AI_Tool_Generate_Gemini_Image' ) ) {
			$tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
			$result = $tool->execute(
				array(
					'prompt'       => $prompt,
					'aspect_ratio' => '16:9',
				),
				array( 'user_id' => get_current_user_id() )
			);

			if ( ! is_wp_error( $result ) && isset( $result['attachment_id'] ) ) {
				return $result['attachment_id'];
			}
		}

		// Fallback to Cloudflare AI image generation.
		if ( class_exists( 'WP_MCP_AI_Tool_Generate_CloudflareAI_Image' ) ) {
			$tool = new WP_MCP_AI_Tool_Generate_CloudflareAI_Image();
			$result = $tool->execute(
				array(
					'prompt' => $prompt,
				),
				array( 'user_id' => get_current_user_id() )
			);

			if ( ! is_wp_error( $result ) && isset( $result['attachment_id'] ) ) {
				return $result['attachment_id'];
			}
		}

		return new WP_Error( 'image_generation_failed', __( 'Failed to generate featured image. Please ensure at least one image generation provider (OpenAI, Gemini, or Cloudflare AI) is configured.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Process featured image generation request from POST data.
	 *
	 * @param array  $research_data Research data array to modify.
	 * @param string $title         Title for fallback prompt.
	 * @param string $context       Context description.
	 * @return array Modified research data with featured_image_id if generated.
	 */
	protected static function process_featured_image_request( $research_data, $title, $context = 'content' ) {
		// Check if featured image generation is requested.
		$generate_image = isset( $_POST['generate_featured_image'] ) && 'true' === $_POST['generate_featured_image'];
		$image_prompt   = isset( $_POST['image_prompt'] ) ? sanitize_text_field( wp_unslash( $_POST['image_prompt'] ) ) : '';

		// Generate featured image if requested.
		if ( $generate_image ) {
			$image_attachment_id = self::generate_featured_image( $image_prompt, $title, $context );
			if ( $image_attachment_id && ! is_wp_error( $image_attachment_id ) ) {
				$research_data['featured_image_id'] = $image_attachment_id;
			}
		}

		return $research_data;
	}
}
