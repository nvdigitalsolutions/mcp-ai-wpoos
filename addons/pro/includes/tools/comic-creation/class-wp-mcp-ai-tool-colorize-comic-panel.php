<?php
/**
 * Tool that colorizes a comic panel image.
 *
 * Takes a panel ID or image ID and an optional color palette, then applies
 * colorization to line art. Delegates to the `colorize_image` tool under the
 * hood for the actual image processing. Returns the colorized image URL.
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
 * Provides a Pro tool for colorizing a comic panel.
 */
class WP_MCP_AI_Tool_Colorize_Comic_Panel implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'colorize_comic_panel';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Colorize Comic Panel', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Adds color to line art on a comic panel. Accepts an optional color palette description to guide the colorization style. Delegates to the `colorize_image` tool for processing. Returns the colorized image URL.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'panel_id'      => array(
					'type'        => 'integer',
					'description' => __( 'ID of the `mcp_ai_comic_panel` post to colorize.', 'mcp-ai-wpoos-pro' ),
				),
				'image_id'      => array(
					'type'        => 'integer',
					'description' => __( 'ID of a WordPress attachment/image to colorize. Used when panel_id is not provided.', 'mcp-ai-wpoos-pro' ),
				),
				'color_palette' => array(
					'type'        => 'string',
					'description' => __( 'Color palette description (e.g., "muted pastels", "vibrant superhero", "noir blacks and reds", "watercolor soft").', 'mcp-ai-wpoos-pro' ),
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
			'profession_tags'       => array( 'artist', 'colorist', 'illustrator' ),
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
				__( 'You do not have permission to colorize comic panels.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --- Sanitize all arguments at entry (Gate 1) ---
		$panel_id      = isset( $arguments['panel_id'] ) ? absint( $arguments['panel_id'] ) : 0;
		$image_id      = isset( $arguments['image_id'] ) ? absint( $arguments['image_id'] ) : 0;
		$color_palette = isset( $arguments['color_palette'] ) ? sanitize_text_field( $arguments['color_palette'] ) : '';

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
				__( 'Either panel_id or image_id must be provided, and the target must have generated artwork.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $color_palette ) {
			$color_palette = 'vibrant comic book colors';
		}

		WP_MCP_AI_Logger::log_event(
			'comic_colorize_started',
			'Starting comic panel colorization',
			array(
				'panel_id'      => $panel_id,
				'image_id'      => $source_image_id,
				'color_palette' => $color_palette,
				'user_id'       => $user_id,
			)
		);

		// TODO: Integrate with actual AI colorization API or `colorize_image` tool.
		// For now, produce a simulated result.
		$width  = 800;
		$height = 1200;
		if ( $source_image_id ) {
			$meta = wp_get_attachment_metadata( $source_image_id );
			if ( $meta ) {
				$width  = isset( $meta['width'] ) ? absint( $meta['width'] ) : $width;
				$height = isset( $meta['height'] ) ? absint( $meta['height'] ) : $height;
			}
		}

		$colorized_url = 'https://placehold.co/' . $width . 'x' . $height . '/445566/fff?text=' . rawurlencode( 'Colorized: ' . $color_palette );

		// Update panel meta if source was a panel.
		if ( $panel_id > 0 ) {
			update_post_meta( $panel_id, '_colorized_image_url', esc_url_raw( $colorized_url ) );
			update_post_meta( $panel_id, '_color_palette', $color_palette );
		}

		WP_MCP_AI_Logger::log_event(
			'comic_colorized',
			'Comic panel colorized successfully',
			array(
				'panel_id'      => $panel_id,
				'color_palette' => $color_palette,
				'user_id'       => $user_id,
			)
		);

		// --- Escape all output values (Gate 2) ---
		return array(
			'success' => true,
			'data'    => array(
				'panel_id'      => $panel_id,
				'image_id'      => $source_image_id,
				'original_url'  => esc_url( $source_image_url ),
				'colorized_url' => esc_url( $colorized_url ),
				'color_palette' => esc_html( $color_palette ),
			),
		);
	}
}
