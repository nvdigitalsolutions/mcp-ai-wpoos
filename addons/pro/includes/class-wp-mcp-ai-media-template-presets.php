<?php
/**
 * Media Template Presets - Pre-configured templates for common use cases.
 *
 * Provides 15+ ready-to-use template presets for social media, e-commerce,
 * branding, content, and marketing workflows.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages pre-configured media template presets.
 */
class WP_MCP_AI_Media_Template_Presets {

	/**
	 * Option key for tracking seeded presets.
	 *
	 * @var string
	 */
	const SEEDED_VERSION_KEY = 'wp_mcp_ai_media_presets_seeded';

	/**
	 * Current preset version.
	 *
	 * @var string
	 */
	const PRESET_VERSION = '1.0.0';

	/**
	 * Get all available preset templates.
	 *
	 * @return array Array of preset template definitions.
	 */
	public static function get_presets() {
		return array(
			// Social Media Presets.
			'instagram_square'      => array(
				'title'       => __( 'Instagram Square Post', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Perfect square format for Instagram feed posts (1080×1080)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'social-media',
				'operation'   => 'resize_graphic',
				'parameters'  => array(
					'target_width'   => 1080,
					'target_height'  => 1080,
					'output_format'  => 'jpg',
					'maintain_ratio' => false,
					'quality'        => 90,
				),
			),
			'facebook_cover'        => array(
				'title'       => __( 'Facebook Cover Photo', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Optimized dimensions for Facebook page cover images (820×312)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'social-media',
				'operation'   => 'resize_graphic',
				'parameters'  => array(
					'target_width'   => 820,
					'target_height'  => 312,
					'output_format'  => 'jpg',
					'maintain_ratio' => false,
					'quality'        => 85,
				),
			),
			'twitter_header'        => array(
				'title'       => __( 'Twitter Header Image', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Perfect size for Twitter/X profile header (1500×500)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'social-media',
				'operation'   => 'resize_graphic',
				'parameters'  => array(
					'target_width'   => 1500,
					'target_height'  => 500,
					'output_format'  => 'jpg',
					'maintain_ratio' => false,
					'quality'        => 90,
				),
			),
			'linkedin_banner'       => array(
				'title'       => __( 'LinkedIn Profile Banner', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Professional banner for LinkedIn profiles (1584×396)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'social-media',
				'operation'   => 'resize_graphic',
				'parameters'  => array(
					'target_width'   => 1584,
					'target_height'  => 396,
					'output_format'  => 'jpg',
					'maintain_ratio' => false,
					'quality'        => 90,
				),
			),

			// E-commerce Presets.
			'product_thumbnail'     => array(
				'title'       => __( 'Product Thumbnail', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Square product thumbnail for e-commerce sites (800×800)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'e-commerce',
				'operation'   => 'resize_graphic',
				'parameters'  => array(
					'target_width'   => 800,
					'target_height'  => 800,
					'output_format'  => 'webp',
					'maintain_ratio' => false,
					'quality'        => 85,
				),
			),
			'hero_banner'           => array(
				'title'       => __( 'Hero Banner', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Full-width hero banner for homepage (1920×600)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'e-commerce',
				'operation'   => 'resize_graphic',
				'parameters'  => array(
					'target_width'   => 1920,
					'target_height'  => 600,
					'output_format'  => 'webp',
					'maintain_ratio' => false,
					'quality'        => 85,
				),
			),
			'category_banner'       => array(
				'title'       => __( 'Category Banner', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Product category page banner (1200×400)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'e-commerce',
				'operation'   => 'resize_graphic',
				'parameters'  => array(
					'target_width'   => 1200,
					'target_height'  => 400,
					'output_format'  => 'webp',
					'maintain_ratio' => false,
					'quality'        => 85,
				),
			),

			// Branding Presets.
			'logo_watermark'        => array(
				'title'       => __( 'Logo Watermark (Bottom Right)', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Add logo watermark in bottom-right corner with 15% scale', 'mcp-ai-wpoos-pro' ),
				'category'    => 'branding',
				'operation'   => 'add_logo',
				'parameters'  => array(
					'logo_position' => 'bottom-right',
					'logo_scale'    => 0.15,
					'logo_margin'   => 20,
				),
				'note'        => __( 'Note: Requires logo_attachment_id parameter when applying', 'mcp-ai-wpoos-pro' ),
			),
			'logo_watermark_center' => array(
				'title'       => __( 'Logo Watermark (Center)', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Add centered logo watermark with 25% scale', 'mcp-ai-wpoos-pro' ),
				'category'    => 'branding',
				'operation'   => 'add_logo',
				'parameters'  => array(
					'logo_position' => 'center',
					'logo_scale'    => 0.25,
					'logo_margin'   => 0,
				),
				'note'        => __( 'Note: Requires logo_attachment_id parameter when applying', 'mcp-ai-wpoos-pro' ),
			),
			'logo_watermark_subtle' => array(
				'title'       => __( 'Logo Watermark (Subtle)', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Small, subtle logo in bottom-left corner (10% scale)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'branding',
				'operation'   => 'add_logo',
				'parameters'  => array(
					'logo_position' => 'bottom-left',
					'logo_scale'    => 0.10,
					'logo_margin'   => 15,
				),
				'note'        => __( 'Note: Requires logo_attachment_id parameter when applying', 'mcp-ai-wpoos-pro' ),
			),

			// Content Presets.
			'blog_featured_image'   => array(
				'title'       => __( 'Blog Featured Image', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Optimized size for blog post featured images (1200×630)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'content',
				'operation'   => 'resize_graphic',
				'parameters'  => array(
					'target_width'   => 1200,
					'target_height'  => 630,
					'output_format'  => 'jpg',
					'maintain_ratio' => false,
					'quality'        => 85,
				),
			),
			'newsletter_header'     => array(
				'title'       => __( 'Newsletter Header', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Email newsletter header image (600×200)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'content',
				'operation'   => 'resize_graphic',
				'parameters'  => array(
					'target_width'   => 600,
					'target_height'  => 200,
					'output_format'  => 'jpg',
					'maintain_ratio' => false,
					'quality'        => 85,
				),
			),
			'email_signature'       => array(
				'title'       => __( 'Email Signature Image', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Compact image for email signatures (600×150)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'content',
				'operation'   => 'resize_graphic',
				'parameters'  => array(
					'target_width'   => 600,
					'target_height'  => 150,
					'output_format'  => 'png',
					'maintain_ratio' => false,
					'quality'        => 90,
				),
			),

			// Marketing Presets.
			'promo_banner'          => array(
				'title'       => __( 'Promotional Banner', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Standard web banner size for promotions (728×90)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'marketing',
				'operation'   => 'resize_graphic',
				'parameters'  => array(
					'target_width'   => 728,
					'target_height'  => 90,
					'output_format'  => 'jpg',
					'maintain_ratio' => false,
					'quality'        => 85,
				),
			),
			'sale_badge'            => array(
				'title'       => __( 'Sale Badge', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Square badge for sale/promotion overlays (300×300)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'marketing',
				'operation'   => 'resize_graphic',
				'parameters'  => array(
					'target_width'   => 300,
					'target_height'  => 300,
					'output_format'  => 'png',
					'maintain_ratio' => false,
					'quality'        => 90,
				),
			),
			'event_poster'          => array(
				'title'       => __( 'Event Poster (Portrait)', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Portrait format for event posters and announcements (1080×1920)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'marketing',
				'operation'   => 'resize_graphic',
				'parameters'  => array(
					'target_width'   => 1080,
					'target_height'  => 1920,
					'output_format'  => 'jpg',
					'maintain_ratio' => false,
					'quality'        => 90,
				),
			),
		);
	}

	/**
	 * Get preset categories for taxonomy.
	 *
	 * @return array Array of category definitions.
	 */
	public static function get_preset_categories() {
		return array(
			'social-media' => array(
				'name'        => __( 'Social Media', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Templates optimized for social media platforms', 'mcp-ai-wpoos-pro' ),
			),
			'e-commerce'   => array(
				'name'        => __( 'E-commerce', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Product images and online store graphics', 'mcp-ai-wpoos-pro' ),
			),
			'branding'     => array(
				'name'        => __( 'Branding', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Logo overlays and brand watermarks', 'mcp-ai-wpoos-pro' ),
			),
			'content'      => array(
				'name'        => __( 'Content', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Blog posts, newsletters, and content marketing', 'mcp-ai-wpoos-pro' ),
			),
			'marketing'    => array(
				'name'        => __( 'Marketing', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Promotional banners, badges, and event graphics', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Seed preset templates on plugin activation.
	 *
	 * This method is called via activation hook and creates default template presets
	 * if they haven't been seeded yet or if the preset version has been updated.
	 *
	 * @return void
	 */
	public static function seed_presets() {
		// Check if already seeded with current version.
		$seeded_version = get_option( self::SEEDED_VERSION_KEY, '' );
		if ( self::PRESET_VERSION === $seeded_version ) {
			return;
		}

		// Check if media toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_media_toolkit'] ) ) {
			// Don't seed if feature is disabled.
			return;
		}

		// Ensure categories exist first.
		self::seed_categories();

		// Get all presets.
		$presets = self::get_presets();

		// Create each preset template.
		foreach ( $presets as $slug => $preset ) {
			self::create_preset_template( $slug, $preset );
		}

		// Update seeded version.
		update_option( self::SEEDED_VERSION_KEY, self::PRESET_VERSION );
	}

	/**
	 * Seed taxonomy categories.
	 *
	 * @return void
	 */
	protected static function seed_categories() {
		$categories = self::get_preset_categories();

		foreach ( $categories as $slug => $category ) {
			// Check if term already exists.
			$term = term_exists( $slug, WP_MCP_AI_Media_Template_CPT::TAXONOMY_CATEGORY );

			if ( ! $term ) {
				// Create the term.
				wp_insert_term(
					$category['name'],
					WP_MCP_AI_Media_Template_CPT::TAXONOMY_CATEGORY,
					array(
						'slug'        => $slug,
						'description' => $category['description'],
					)
				);
			}
		}
	}

	/**
	 * Create a preset template.
	 *
	 * @param string $slug   Preset slug.
	 * @param array  $preset Preset definition.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	protected static function create_preset_template( $slug, $preset ) {
		// Check if template with this slug already exists.
		$existing = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Media_Template_CPT::POST_TYPE,
				'name'        => 'preset-' . $slug,
				'post_status' => 'any',
				'numberposts' => 1,
			)
		);

		if ( ! empty( $existing ) ) {
			// Template already exists, update it.
			$post_id = $existing[0]->ID;
		} else {
			// Create new template.
			$post_data = array(
				'post_type'    => WP_MCP_AI_Media_Template_CPT::POST_TYPE,
				'post_title'   => $preset['title'],
				'post_content' => isset( $preset['description'] ) ? $preset['description'] : '',
				'post_status'  => 'publish',
				'post_name'    => 'preset-' . $slug,
				'post_author'  => 1, // System user.
				'meta_input'   => array(
					'_mcp_ai_template_is_preset' => true,
					'_mcp_ai_template_preset_id' => $slug,
				),
			);

			$post_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
		}

		// Update template meta.
		update_post_meta( $post_id, '_mcp_ai_template_operation', $preset['operation'] );
		update_post_meta( $post_id, '_mcp_ai_template_parameters', wp_json_encode( $preset['parameters'] ) );
		update_post_meta( $post_id, '_mcp_ai_template_is_preset', true );
		update_post_meta( $post_id, '_mcp_ai_template_preset_id', $slug );

		// Assign category.
		if ( isset( $preset['category'] ) ) {
			wp_set_object_terms( $post_id, $preset['category'], WP_MCP_AI_Media_Template_CPT::TAXONOMY_CATEGORY );
		}

		return $post_id;
	}

	/**
	 * Get preset template by slug.
	 *
	 * @param string $slug Preset slug.
	 * @return WP_Post|null Post object or null if not found.
	 */
	public static function get_preset_by_slug( $slug ) {
		$posts = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Media_Template_CPT::POST_TYPE,
				'name'        => 'preset-' . $slug,
				'post_status' => 'publish',
				'numberposts' => 1,
			)
		);

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Check if a template is a preset.
	 *
	 * @param int $post_id Template post ID.
	 * @return bool True if template is a preset.
	 */
	public static function is_preset( $post_id ) {
		return (bool) get_post_meta( $post_id, '_mcp_ai_template_is_preset', true );
	}
}
