<?php
/**
 * Pro Featured Image Service.
 *
 * Unified AI-powered featured image generation with multi-provider fallback.
 * Extracted from trait-wp-mcp-ai-research-page-featured-image.php into a
 * standalone reusable service so that schedule presets, workflow presets,
 * result delivery, research pages, and any other subsystem can generate
 * featured images through a single code path.
 *
 * Provider fallback order: OpenAI DALL-E → Google Gemini → Cloudflare AI
 * Configured via class_exists() guards — no provider is required.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Featured_Image_Service' ) ) {
	/**
	 * Featured Image Service — static methods, no constructor state.
	 *
	 * Provides a single entry point for AI-powered featured image generation
	 * with automatic multi-provider fallback. Any caller that needs a featured
	 * image for a post, page, product, or content item can use this service
	 * without worrying about which image provider is configured.
	 */
	class WP_MCP_AI_Featured_Image_Service {

		/**
		 * Available image generation providers in fallback order.
		 *
		 * @var string[]
		 */
		const PROVIDERS = array( 'openai', 'gemini', 'cloudflare' );

		/**
		 * Available image styles.
		 *
		 * @var string[]
		 */
		const STYLES = array(
			'photographic',
			'illustration',
			'abstract',
			'infographic',
			'minimal',
		);

		// ---------------------------------------------------------------------
		// Public API
		// ---------------------------------------------------------------------

		/**
		 * Generate a featured image using AI with multi-provider fallback.
		 *
		 * Tries providers in order (OpenAI → Gemini → Cloudflare). Returns the
		 * first successful result. If all providers fail or none are available,
		 * returns a WP_Error.
		 *
		 * @since 1.0.0
		 *
		 * @param string $title   Post title used to construct the generation prompt.
		 * @param string $context Context description (e.g., 'blog post', 'product', 'page').
		 * @param array  $options {
		 *     Optional. Generation options.
		 *
		 *     @type string $style   Image style. One of 'photographic', 'illustration',
		 *                           'abstract', 'infographic', 'minimal'. Default 'photographic'.
		 *     @type int    $user_id WordPress user ID for capability checks.
		 *                           Default current user.
		 * }
		 * @return array{
		 *     attachment_id: int,
		 *     url: string,
		 *     alt_text: string,
		 * }|WP_Error Attachment data on success, WP_Error on failure.
		 */
		public static function generate( $title, $context = 'content', $options = array() ) {
			$style   = isset( $options['style'] ) && in_array( $options['style'], self::STYLES, true )
				? $options['style']
				: 'photographic';
			$user_id = isset( $options['user_id'] ) ? absint( $options['user_id'] ) : get_current_user_id();

			if ( empty( $title ) ) {
				return new WP_Error(
					'missing_title',
					__( 'A title is required to generate a featured image.', 'mcp-ai-wpoos-pro' )
				);
			}

			$prompt   = self::build_prompt( $title, $context, $style );
			$alt_text = self::build_alt_text( $title, $context );

			// Try each provider in order.
			foreach ( self::PROVIDERS as $provider ) {
				$result = self::try_provider( $provider, $prompt, $user_id );
				if ( ! is_wp_error( $result ) && ! empty( $result['attachment_id'] ) ) {
					return array(
						'attachment_id' => $result['attachment_id'],
						'url'           => isset( $result['url'] ) ? $result['url'] : wp_get_attachment_url( $result['attachment_id'] ),
						'alt_text'      => $alt_text,
					);
				}
			}

			return new WP_Error(
				'image_generation_failed',
				__( 'Failed to generate featured image. Please ensure at least one image generation provider (OpenAI, Gemini, or Cloudflare AI) is configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		/**
		 * Generate a featured image and immediately attach it to a post.
		 *
		 * Convenience wrapper around generate() + set_post_thumbnail().
		 *
		 * @since 1.0.0
		 *
		 * @param int    $post_id Post ID to attach the featured image to.
		 * @param string $title   Post title for prompt construction.
		 * @param string $context Context description.
		 * @param array  $options Generation options (see generate()).
		 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
		 */
		public static function generate_and_attach( $post_id, $title, $context = 'content', $options = array() ) {
			$result = self::generate( $title, $context, $options );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$attachment_id = $result['attachment_id'];

			// Set alt text on the attachment.
			if ( ! empty( $result['alt_text'] ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $result['alt_text'] ) );
			}

			// Set as featured image.
			set_post_thumbnail( $post_id, $attachment_id );

			return $attachment_id;
		}

		// ---------------------------------------------------------------------
		// Provider execution
		// ---------------------------------------------------------------------

		/**
		 * Try a specific image generation provider.
		 *
		 * @since 1.0.0
		 *
		 * @param string $provider Provider slug ('openai', 'gemini', 'cloudflare').
		 * @param string $prompt   Image generation prompt.
		 * @param int    $user_id  WordPress user ID.
		 * @return array{attachment_id: int, url: string}|WP_Error
		 */
		protected static function try_provider( $provider, $prompt, $user_id ) {
			switch ( $provider ) {
				case 'openai':
					return self::try_openai( $prompt, $user_id );

				case 'gemini':
					return self::try_gemini( $prompt, $user_id );

				case 'cloudflare':
					return self::try_cloudflare( $prompt, $user_id );

				default:
					return new WP_Error(
						'unknown_provider',
						sprintf(
							/* translators: %s: provider slug */
							__( 'Unknown image generation provider: %s', 'mcp-ai-wpoos-pro' ),
							$provider
						)
					);
			}
		}

		/**
		 * Try OpenAI DALL-E image generation.
		 *
		 * @param string $prompt  Generation prompt.
		 * @param int    $user_id WordPress user ID.
		 * @return array|WP_Error
		 */
		protected static function try_openai( $prompt, $user_id ) {
			if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_OpenAI_Image' ) ) {
				return new WP_Error( 'openai_unavailable', __( 'OpenAI image generation is not available.', 'mcp-ai-wpoos-pro' ) );
			}

			$tool = new WP_MCP_AI_Tool_Generate_OpenAI_Image();

			// Let the tool use the model and quality from admin Provider Settings.
			// Only override size for a landscape blog-header aspect ratio.
			return $tool->execute(
				array(
					'prompt' => $prompt,
					'size'   => '1536x1024',
				),
				array( 'user_id' => $user_id )
			);
		}

		/**
		 * Try Google Gemini image generation.
		 *
		 * @param string $prompt  Generation prompt.
		 * @param int    $user_id WordPress user ID.
		 * @return array|WP_Error
		 */
		protected static function try_gemini( $prompt, $user_id ) {
			if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_Gemini_Image' ) ) {
				return new WP_Error( 'gemini_unavailable', __( 'Gemini image generation is not available.', 'mcp-ai-wpoos-pro' ) );
			}

			$tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();

			// Let the tool use the model and aspect ratio from admin Provider Settings.
			return $tool->execute(
				array(
					'prompt' => $prompt,
				),
				array( 'user_id' => $user_id )
			);
		}

		/**
		 * Try Cloudflare AI image generation.
		 *
		 * @param string $prompt  Generation prompt.
		 * @param int    $user_id WordPress user ID.
		 * @return array|WP_Error
		 */
		protected static function try_cloudflare( $prompt, $user_id ) {
			if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_CloudflareAI_Image' ) ) {
				return new WP_Error( 'cloudflare_unavailable', __( 'Cloudflare AI image generation is not available.', 'mcp-ai-wpoos-pro' ) );
			}

			$tool = new WP_MCP_AI_Tool_Generate_CloudflareAI_Image();

			return $tool->execute(
				array( 'prompt' => $prompt ),
				array( 'user_id' => $user_id )
			);
		}

		// ---------------------------------------------------------------------
		// Prompt construction
		// ---------------------------------------------------------------------

		/**
		 * Build an AI image generation prompt from title, context, and style.
		 *
		 * @since 1.0.0
		 *
		 * @param string $title   Post title.
		 * @param string $context Context description.
		 * @param string $style   Image style.
		 * @return string Generation prompt.
		 */
		protected static function build_prompt( $title, $context, $style ) {
			$style_prompts = array(
				'photographic' => __( 'professional photograph, realistic lighting, clean composition', 'mcp-ai-wpoos-pro' ),
				'illustration' => __( 'digital illustration, vibrant colours, modern flat design', 'mcp-ai-wpoos-pro' ),
				'abstract'     => __( 'abstract art, geometric shapes, bold colour palette', 'mcp-ai-wpoos-pro' ),
				'infographic'  => __( 'clean infographic style, data visualisation, minimal text', 'mcp-ai-wpoos-pro' ),
				'minimal'      => __( 'minimalist design, ample whitespace, subtle gradients', 'mcp-ai-wpoos-pro' ),
			);

			$style_desc = isset( $style_prompts[ $style ] ) ? $style_prompts[ $style ] : $style_prompts['photographic'];

			return sprintf(
				/* translators: 1: context description, 2: post title, 3: style description */
				__( 'A professional featured image for %1$s about: %2$s. Style: %3$s. No text or words in the image.', 'mcp-ai-wpoos-pro' ),
				$context,
				$title,
				$style_desc
			);
		}

		/**
		 * Build descriptive alt text for the generated image.
		 *
		 * @since 1.0.0
		 *
		 * @param string $title   Post title.
		 * @param string $context Context description.
		 * @return string Alt text.
		 */
		protected static function build_alt_text( $title, $context ) {
			return sprintf(
				/* translators: 1: context, 2: title */
				__( 'Featured image for %1$s: %2$s', 'mcp-ai-wpoos-pro' ),
				$context,
				$title
			);
		}
	}
}
