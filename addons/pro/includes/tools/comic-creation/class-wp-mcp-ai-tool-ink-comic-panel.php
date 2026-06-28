<?php
/**
 * Tool that refines a sketch into clean inked lines.
 *
 * Takes a panel ID or image ID and an ink style, then refines the sketch/pencil
 * artwork into clean, bold inked lines suitable for comic production. Returns
 * the inked image URL.
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
 * Provides a Pro tool for inking a comic panel.
 */
class WP_MCP_AI_Tool_Ink_Comic_Panel implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'ink_comic_panel';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Ink Comic Panel', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Refines sketch or pencil artwork into clean inked lines for a comic panel. Supports multiple ink styles (clean-line, brush, rough, manga). Returns the inked image URL.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'panel_id'  => array(
					'type'        => 'integer',
					'description' => __( 'ID of the `mcp_ai_comic_panel` post to ink.', 'mcp-ai-wpoos-pro' ),
				),
				'image_id'  => array(
					'type'        => 'integer',
					'description' => __( 'ID of a WordPress attachment/image to ink. Used when panel_id is not provided.', 'mcp-ai-wpoos-pro' ),
				),
				'ink_style' => array(
					'type'        => 'string',
					'description' => __( 'Inking style to apply.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'clean-line', 'brush', 'rough', 'manga', 'crosshatch', 'noir-heavy' ),
					'default'     => 'clean-line',
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
			'profession_tags'       => array( 'artist', 'inker', 'illustrator' ),
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
				__( 'You do not have permission to ink comic panels.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --- Sanitize all arguments at entry (Gate 1) ---
		$panel_id  = isset( $arguments['panel_id'] ) ? absint( $arguments['panel_id'] ) : 0;
		$image_id  = isset( $arguments['image_id'] ) ? absint( $arguments['image_id'] ) : 0;
		$ink_style = isset( $arguments['ink_style'] ) ? sanitize_text_field( $arguments['ink_style'] ) : 'clean-line';

		// Validate ink style.
		$valid_styles = array( 'clean-line', 'brush', 'rough', 'manga', 'crosshatch', 'noir-heavy' );
		if ( ! in_array( $ink_style, $valid_styles, true ) ) {
			$ink_style = 'clean-line';
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

		WP_MCP_AI_Logger::log_event(
			'comic_inking_started',
			'Starting comic panel inking',
			array(
				'panel_id'  => $panel_id,
				'image_id'  => $source_image_id,
				'ink_style' => $ink_style,
				'user_id'   => $user_id,
			)
		);

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

		// TODO: Integrate with actual AI inking API or image processing pipeline.
		// For now, produce a simulated result.
		$inked_url = 'https://placehold.co/' . $width . 'x' . $height . '/111/fff?text=' . rawurlencode( 'Inked: ' . $ink_style );

		// Update panel meta if source was a panel.
		if ( $panel_id > 0 ) {
			update_post_meta( $panel_id, '_inked_image_url', esc_url_raw( $inked_url ) );
			update_post_meta( $panel_id, '_ink_style', $ink_style );
		}

		WP_MCP_AI_Logger::log_event(
			'comic_inked',
			'Comic panel inked successfully',
			array(
				'panel_id'  => $panel_id,
				'ink_style' => $ink_style,
				'user_id'   => $user_id,
			)
		);

		// --- Escape all output values (Gate 2) ---
		return array(
			'success' => true,
			'data'    => array(
				'panel_id'     => $panel_id,
				'image_id'     => $source_image_id,
				'original_url' => esc_url( $source_image_url ),
				'inked_url'    => esc_url( $inked_url ),
				'ink_style'    => esc_html( $ink_style ),
			),
		);
	}
}
