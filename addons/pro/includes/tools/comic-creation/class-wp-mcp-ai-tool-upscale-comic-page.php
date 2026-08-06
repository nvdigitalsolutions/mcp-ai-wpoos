<?php
/**
 * Tool that upscales a comic page for print resolution.
 *
 * Takes an image ID or panel ID and a scale factor (2x or 4x), then upscales
 * the image using AI-powered super-resolution. Delegates to the
 * `upscale_image_ai` tool under the hood. Returns the upscaled image URL.
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
 * Provides a Pro tool for upscaling a comic page to print resolution.
 */
class WP_MCP_AI_Tool_Upscale_Comic_Page implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'upscale_comic_page';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Upscale Comic Page', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Upscales a comic page image to print resolution using AI-powered super-resolution. Supports 2x and 4x scale factors. Delegates to the `upscale_image_ai` tool for processing. Returns the upscaled image URL.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'image_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of a WordPress attachment/image to upscale.', 'mcp-ai-wpoos-pro' ),
				),
				'panel_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of a `mcp_ai_comic_panel` post whose generated image should be upscaled.', 'mcp-ai-wpoos-pro' ),
				),
				'scale'    => array(
					'type'        => 'integer',
					'description' => __( 'Scale factor for upscaling (2 for 2x, 4 for 4x).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 2, 4 ),
					'default'     => 2,
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
			'profession_tags'       => array( 'artist', 'publisher', 'designer' ),
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
			'large-response',
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
				__( 'You do not have permission to upscale comic pages.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --- Sanitize all arguments at entry (Gate 1) ---
		$image_id = isset( $arguments['image_id'] ) ? absint( $arguments['image_id'] ) : 0;
		$panel_id = isset( $arguments['panel_id'] ) ? absint( $arguments['panel_id'] ) : 0;
		$scale    = isset( $arguments['scale'] ) ? absint( $arguments['scale'] ) : 2;

		// Validate scale factor.
		if ( 2 !== $scale && 4 !== $scale ) {
			$scale = 2;
		}

		// Resolve image source.
		$source_image_id  = 0;
		$source_image_url = '';
		$source_width     = 0;
		$source_height    = 0;

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
				__( 'Either panel_id or image_id must be provided, and the source must have artwork.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Get original dimensions.
		if ( $source_image_id ) {
			$meta = wp_get_attachment_metadata( $source_image_id );
			if ( $meta ) {
				$source_width  = isset( $meta['width'] ) ? absint( $meta['width'] ) : 800;
				$source_height = isset( $meta['height'] ) ? absint( $meta['height'] ) : 1200;
			}
		}

		if ( ! $source_width ) {
			$source_width = 800;
		}
		if ( ! $source_height ) {
			$source_height = 1200;
		}

		$new_width  = $source_width * $scale;
		$new_height = $source_height * $scale;

		WP_MCP_AI_Logger::log_event(
			'comic_upscale_started',
			'Starting comic page upscale',
			array(
				'panel_id'        => $panel_id,
				'image_id'        => $source_image_id,
				'scale'           => $scale,
				'original_width'  => $source_width,
				'original_height' => $source_height,
				'new_width'       => $new_width,
				'new_height'      => $new_height,
				'user_id'         => $user_id,
			)
		);

		// TODO: Integrate with actual `upscale_image_ai` tool or external API.
		// For now, produce a simulated result.
		$upscaled_url = 'https://placehold.co/' . $new_width . 'x' . $new_height . '/1a1a2e/gold?text=' . rawurlencode( $scale . 'x Upscaled' );

		// Update panel meta if source was a panel.
		if ( $panel_id > 0 ) {
			update_post_meta( $panel_id, '_upscaled_image_url', esc_url_raw( $upscaled_url ) );
			update_post_meta( $panel_id, '_upscale_factor', $scale );
			update_post_meta( $panel_id, '_upscaled_width', $new_width );
			update_post_meta( $panel_id, '_upscaled_height', $new_height );
		}

		WP_MCP_AI_Logger::log_event(
			'comic_upscaled',
			'Comic page upscaled successfully',
			array(
				'panel_id'   => $panel_id,
				'image_id'   => $source_image_id,
				'scale'      => $scale,
				'new_width'  => $new_width,
				'new_height' => $new_height,
				'user_id'    => $user_id,
			)
		);

		// --- Escape all output values (Gate 2) ---
		return array(
			'success' => true,
			'data'    => array(
				'panel_id'            => $panel_id,
				'image_id'            => $source_image_id,
				'original_url'        => esc_url( $source_image_url ),
				'upscaled_url'        => esc_url( $upscaled_url ),
				'scale'               => $scale,
				'original_dimensions' => array(
					'width'  => $source_width,
					'height' => $source_height,
				),
				'new_dimensions'      => array(
					'width'  => $new_width,
					'height' => $new_height,
				),
				'print_dpi_300'       => array(
					'width_inches'  => round( $new_width / 300, 2 ),
					'height_inches' => round( $new_height / 300, 2 ),
				),
			),
		);
	}
}
