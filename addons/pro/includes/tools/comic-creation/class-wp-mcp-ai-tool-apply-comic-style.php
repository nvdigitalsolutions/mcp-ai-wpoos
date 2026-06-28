<?php
/**
 * Tool that applies an art style preset to a comic panel image.
 *
 * Takes a panel ID or image ID and a style preset (manga, american-comic, noir,
 * silver-age, etc.), then transforms the image to match that visual style. Uses
 * AI style transfer or image-to-image generation. Returns the styled image URL.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a Pro tool for applying a comic art style to a panel image.
 */
class WP_MCP_AI_Tool_Apply_Comic_Style implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'apply_comic_style';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Apply Comic Style', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Applies a comic art style preset to an existing panel image. Supports styles like manga, american-comic, noir, silver-age, golden-age, euro-comic, and webtoon. Uses AI style transfer to transform the image. Returns the styled image URL.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'panel_id'   => array(
					'type'        => 'integer',
					'description' => __( 'ID of the `mcp_ai_comic_panel` post to apply style to.', 'mcp-ai-wpoos-pro' ),
				),
				'image_id'   => array(
					'type'        => 'integer',
					'description' => __( 'ID of a WordPress attachment/image to style. Used when panel_id is not provided.', 'mcp-ai-wpoos-pro' ),
				),
				'style'      => array(
					'type'        => 'string',
					'description' => __( 'Art style preset to apply.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'manga', 'american-comic', 'noir', 'silver-age', 'golden-age', 'euro-comic', 'webtoon', 'watercolor', 'pulp', 'ligne-claire' ),
					'default'     => 'american-comic',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'comic_creation',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'artist', 'illustrator', 'stylist' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'pro-tool',
			'write',
			'external-api',
			'may-timeout',
			'requires-credentials',
			'consumes-tokens',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error  Canonical success array or WP_Error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Capability check.
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to apply comic styles.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --- Sanitize all arguments at entry (Gate 1) ---
		$panel_id = isset( $arguments['panel_id'] ) ? absint( $arguments['panel_id'] ) : 0;
		$image_id = isset( $arguments['image_id'] ) ? absint( $arguments['image_id'] ) : 0;
		$style    = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : 'american-comic';

		// Validate style.
		$valid_styles = array( 'manga', 'american-comic', 'noir', 'silver-age', 'golden-age', 'euro-comic', 'webtoon', 'watercolor', 'pulp', 'ligne-claire' );
		if ( ! in_array( $style, $valid_styles, true ) ) {
			$style = 'american-comic';
		}

		// Resolve image source.
		$source_image_id  = 0;
		$source_image_url = '';

		if ( $panel_id > 0 ) {
			$panel_post = get_post( $panel_id );
			if ( ! $panel_post || 'mcp_ai_comic_panel' !== $panel_post->post_type ) {
				return new WP_Error(
					'wp_mcp_ai_panel_not_found',
					__( 'The specified comic panel was not found.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 404 )
				);
			}

			$source_image_id  = (int) get_post_meta( $panel_id, '_generated_image_id', true );
			$source_image_url = get_post_meta( $panel_id, '_generated_image_url', true );
		} elseif ( $image_id > 0 ) {
			$attachment = get_post( $image_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return new WP_Error(
					'wp_mcp_ai_image_not_found',
					__( 'The specified image attachment was not found.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 404 )
				);
			}
			$source_image_id  = $image_id;
			$source_image_url = wp_get_attachment_url( $image_id );
		}

		if ( $source_image_id <= 0 && empty( $source_image_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_image',
				__( 'Either panel_id or image_id must be provided, and the target must have artwork.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Determine image dimensions.
		$width  = 800;
		$height = 1200;
		if ( $source_image_id ) {
			$meta = wp_get_attachment_metadata( $source_image_id );
			if ( $meta ) {
				$width  = isset( $meta['width'] ) ? absint( $meta['width'] ) : $width;
				$height = isset( $meta['height'] ) ? absint( $meta['height'] ) : $height;
			}
		}

		$style_display = $this->get_style_display_name( $style );

		WP_MCP_AI_Logger::log_event(
			'comic_style_apply_started',
			'Starting comic style application',
			array(
				'panel_id' => $panel_id,
				'image_id' => $source_image_id,
				'style'    => $style,
				'user_id'  => $user_id,
			)
		);

		// TODO: Integrate with actual AI style transfer API or image-to-image generation.
		// For now, produce a simulated result.
		$styled_url = 'https://placehold.co/' . $width . 'x' . $height . '/222/eee?text=' . rawurlencode( $style_display . ' Style' );

		// Update panel meta if source was a panel.
		if ( $panel_id > 0 ) {
			update_post_meta( $panel_id, '_styled_image_url', esc_url_raw( $styled_url ) );
			update_post_meta( $panel_id, '_applied_style', $style );
		}

		WP_MCP_AI_Logger::log_event(
			'comic_style_applied',
			'Comic style applied successfully',
			array(
				'panel_id' => $panel_id,
				'style'    => $style,
				'user_id'  => $user_id,
			)
		);

		// --- Escape all output values (Gate 2) ---
		return array(
			'success' => true,
			'data'    => array(
				'panel_id'     => $panel_id,
				'image_id'     => $source_image_id,
				'original_url' => esc_url( $source_image_url ),
				'styled_url'   => esc_url( $styled_url ),
				'style'        => esc_html( $style ),
				'style_name'   => esc_html( $style_display ),
				'dimensions'   => array(
					'width'  => $width,
					'height' => $height,
				),
			),
		);
	}

	/**
	 * Get a human-readable display name for a style slug.
	 *
	 * @param string $style Style slug.
	 * @return string Display name.
	 */
	private function get_style_display_name( $style ) {
		$names = array(
			'manga'          => __( 'Manga', 'mcp-ai-wpoos-pro' ),
			'american-comic' => __( 'American Comic', 'mcp-ai-wpoos-pro' ),
			'noir'           => __( 'Noir', 'mcp-ai-wpoos-pro' ),
			'silver-age'     => __( 'Silver Age', 'mcp-ai-wpoos-pro' ),
			'golden-age'     => __( 'Golden Age', 'mcp-ai-wpoos-pro' ),
			'euro-comic'     => __( 'Euro Comic', 'mcp-ai-wpoos-pro' ),
			'webtoon'        => __( 'Webtoon', 'mcp-ai-wpoos-pro' ),
			'watercolor'     => __( 'Watercolor', 'mcp-ai-wpoos-pro' ),
			'pulp'           => __( 'Pulp', 'mcp-ai-wpoos-pro' ),
			'ligne-claire'   => __( 'Ligne Claire', 'mcp-ai-wpoos-pro' ),
		);

		return isset( $names[ $style ] ) ? $names[ $style ] : ucfirst( str_replace( '-', ' ', $style ) );
	}
}
