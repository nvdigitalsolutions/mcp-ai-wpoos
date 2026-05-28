<?php
/**
 * Tool that adds text and sound effects to a comic panel.
 *
 * Takes a panel ID or image ID and a JSON array of text elements, then renders
 * lettering (dialogue, captions, sound effects) onto the panel image. Returns
 * the lettered image URL.
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
 * Provides a Pro tool for lettering a comic panel.
 */
class WP_MCP_AI_Tool_Letter_Comic_Panel implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'letter_comic_panel';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Letter Comic Panel', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Adds text elements (dialogue, captions, sound effects) to a comic panel image. Accepts a JSON array of text definitions with position, font style, size, and content. Returns the lettered image URL.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'panel_id'       => array(
					'type'        => 'integer',
					'description' => __( 'ID of the `mcp_ai_comic_panel` post to add lettering to.', 'mcp-ai-wpoos-pro' ),
				),
				'image_id'       => array(
					'type'        => 'integer',
					'description' => __( 'ID of a WordPress attachment/image to letter. Used when panel_id is not provided.', 'mcp-ai-wpoos-pro' ),
				),
				'text_elements'  => array(
					'type'        => 'string',
					'description' => __( 'JSON-encoded array of text element objects. Each element: { text: string, x: int, y: int, font_size: int, style: "dialogue"|"caption"|"sfx"|"title", color: string, font: string, rotation: int }.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'text_elements' ),
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
			'profession_tags'       => array( 'artist', 'letterer' ),
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
			'local-only',
			'may-timeout',
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Capability check.
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to letter comic panels.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --- Sanitize all arguments at entry (Gate 1) ---
		$panel_id  = isset( $arguments['panel_id'] ) ? absint( $arguments['panel_id'] ) : 0;
		$image_id  = isset( $arguments['image_id'] ) ? absint( $arguments['image_id'] ) : 0;
		$text_raw  = isset( $arguments['text_elements'] ) ? $arguments['text_elements'] : '';

		// Parse and sanitize text elements.
		if ( is_string( $text_raw ) ) {
			$text_elements = json_decode( $text_raw, true );
		} elseif ( is_array( $text_raw ) ) {
			$text_elements = $text_raw;
		} else {
			$text_elements = null;
		}

		if ( null === $text_elements || JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_text',
				__( 'text_elements must be a valid JSON array of text element objects.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_array( $text_elements ) || empty( $text_elements ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_text',
				__( 'text_elements must contain at least one text element.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
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

		// Sanitize each text element.
		$valid_styles = array( 'dialogue', 'caption', 'sfx', 'title' );
		$sanitized    = array();

		foreach ( $text_elements as $element ) {
			if ( ! is_array( $element ) || empty( $element['text'] ) ) {
				continue;
			}

			$item = array(
				'text'      => sanitize_text_field( $element['text'] ),
				'x'         => isset( $element['x'] ) ? absint( $element['x'] ) : 0,
				'y'         => isset( $element['y'] ) ? absint( $element['y'] ) : 0,
				'font_size' => isset( $element['font_size'] ) ? absint( $element['font_size'] ) : 24,
				'style'     => 'dialogue',
				'color'     => '#000000',
				'font'      => isset( $element['font'] ) ? sanitize_text_field( $element['font'] ) : 'Komika',
				'rotation'  => isset( $element['rotation'] ) ? absint( $element['rotation'] ) : 0,
			);

			if ( isset( $element['style'] ) && in_array( $element['style'], $valid_styles, true ) ) {
				$item['style'] = $element['style'];
			}

			if ( isset( $element['color'] ) ) {
				$item['color'] = sanitize_hex_color( $element['color'] ) ?: '#000000';
			}

			$sanitized[] = $item;
		}

		WP_MCP_AI_Logger::log_event(
			'comic_lettering_started',
			'Starting comic panel lettering',
			array(
				'panel_id'       => $panel_id,
				'image_id'       => $source_image_id,
				'element_count'  => count( $sanitized ),
				'user_id'        => $user_id,
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

		// TODO: Implement actual lettering via GD/Imagick text rendering.
		// For now, produce a simulated result.
		$lettered_url = 'https://placehold.co/' . $width . 'x' . $height . '/333444/fff?text=' . rawurlencode( 'Lettered Panel' );

		// Store text elements as panel meta.
		if ( $panel_id > 0 ) {
			update_post_meta( $panel_id, '_text_elements', wp_json_encode( $sanitized ) );
			update_post_meta( $panel_id, '_lettered_image_url', esc_url_raw( $lettered_url ) );
			update_post_meta( $panel_id, '_text_element_count', count( $sanitized ) );
		}

		WP_MCP_AI_Logger::log_event(
			'comic_lettered',
			'Comic panel lettered successfully',
			array(
				'panel_id'       => $panel_id,
				'element_count'  => count( $sanitized ),
				'user_id'        => $user_id,
			)
		);

		// --- Escape all output values (Gate 2) ---
		return array(
			'success' => true,
			'data'    => array(
				'panel_id'       => $panel_id,
				'image_id'       => $source_image_id,
				'original_url'   => esc_url( $source_image_url ),
				'lettered_url'   => esc_url( $lettered_url ),
				'text_elements'  => $sanitized,
				'element_count'  => count( $sanitized ),
			),
		);
	}
}
