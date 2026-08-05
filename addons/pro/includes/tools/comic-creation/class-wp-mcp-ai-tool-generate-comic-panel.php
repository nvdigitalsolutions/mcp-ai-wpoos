<?php
/**
 * Tool that generates AI artwork for a single comic panel.
 *
 * Takes a panel ID or raw description, optional character IDs, style, camera
 * angle, and dimensions to produce AI-generated panel artwork. Updates the
 * panel post with the generated image attachment.
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
 * Provides a Pro tool for generating a single comic panel image.
 */
class WP_MCP_AI_Tool_Generate_Comic_Panel implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_comic_panel';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Comic Panel', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates AI artwork for a single comic panel based on description, character references, style, camera angle, and dimensions. Updates the panel post with the generated image as a WordPress attachment.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'ID of an existing `mcp_ai_comic_panel` post to generate artwork for.', 'mcp-ai-wpoos-pro' ),
				),
				'description'   => array(
					'type'        => 'string',
					'description' => __( 'Text description of the panel content. Used when panel_id is not provided.', 'mcp-ai-wpoos-pro' ),
				),
				'character_ids' => array(
					'type'        => 'array',
					'description' => __( 'Array of character post IDs to reference in the panel.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'style'         => array(
					'type'        => 'string',
					'description' => __( 'Art style for the panel (e.g., "manga", "american-comic", "noir").', 'mcp-ai-wpoos-pro' ),
					'default'     => 'american-comic',
				),
				'camera_angle'  => array(
					'type'        => 'string',
					'description' => __( 'Camera angle for the panel composition.', 'mcp-ai-wpoos-pro' ),
				),
				'dimensions'    => array(
					'type'        => 'string',
					'description' => __( 'Image dimensions in WxH format (e.g., "800x1200").', 'mcp-ai-wpoos-pro' ),
					'default'     => '800x1200',
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
			'profession_tags'       => array( 'artist', 'illustrator' ),
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
			'consumes-tokens',
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
				__( 'You do not have permission to generate comic panels.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --- Sanitize all arguments at entry (Gate 1) ---
		$panel_id      = isset( $arguments['panel_id'] ) ? absint( $arguments['panel_id'] ) : 0;
		$description   = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$character_ids = isset( $arguments['character_ids'] ) && is_array( $arguments['character_ids'] )
			? array_map( 'absint', $arguments['character_ids'] )
			: array();
		$style         = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : 'american-comic';
		$camera_angle  = isset( $arguments['camera_angle'] ) ? sanitize_text_field( $arguments['camera_angle'] ) : '';
		$dimensions    = isset( $arguments['dimensions'] ) ? sanitize_text_field( $arguments['dimensions'] ) : '800x1200';

		// Resolve panel description.
		$script_id = 0;

		if ( $panel_id > 0 ) {
			$panel_post = get_post( $panel_id );

			if ( ! $panel_post || 'mcp_ai_comic_panel' !== $panel_post->post_type ) {
				return new WP_Error(
					'wp_mcp_ai_panel_not_found',
					__( 'The specified comic panel was not found.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 404 )
				);
			}

			if ( '' === $description ) {
				$description = $panel_post->post_content;
			}

			// Load stored metadata if not explicitly provided.
			if ( '' === $camera_angle ) {
				$camera_angle = get_post_meta( $panel_id, '_camera_angle', true );
			}
			$script_id = (int) get_post_meta( $panel_id, '_comic_script_id', true );
		}

		if ( '' === $description ) {
			return new WP_Error(
				'wp_mcp_ai_missing_description',
				__( 'Either panel_id or description must be provided.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Parse dimensions.
		$dims   = explode( 'x', strtolower( $dimensions ) );
		$width  = isset( $dims[0] ) ? absint( $dims[0] ) : 800;
		$height = isset( $dims[1] ) ? absint( $dims[1] ) : 1200;

		if ( $width < 256 || $width > 2048 ) {
			$width = 800;
		}
		if ( $height < 256 || $height > 2048 ) {
			$height = 1200;
		}

		WP_MCP_AI_Logger::log_event(
			'comic_panel_generation_started',
			'Starting comic panel image generation',
			array(
				'panel_id' => $panel_id,
				'style'    => $style,
				'width'    => $width,
				'height'   => $height,
				'user_id'  => $user_id,
			)
		);

		// TODO: Integrate with actual AI image generation API (DALL-E, Stable Diffusion, etc.)
		// For now, produce a simulated result.
		$image_url     = 'https://placehold.co/' . $width . 'x' . $height . '/222/ccc?text=' . rawurlencode( 'Panel ' . ( $panel_id ? $panel_id : 'New' ) );
		$attachment_id = $this->simulate_panel_attachment( $panel_id, $user_id, $width, $height );

		// Update panel post meta.
		if ( $panel_id > 0 ) {
			update_post_meta( $panel_id, '_generated_image_id', $attachment_id );
			update_post_meta( $panel_id, '_generated_image_url', esc_url_raw( $image_url ) );
			update_post_meta( $panel_id, '_panel_style', $style );

			if ( $attachment_id > 0 ) {
				set_post_thumbnail( $panel_id, $attachment_id );
			}
		}

		WP_MCP_AI_Logger::log_event(
			'comic_panel_generated',
			'Comic panel image generated successfully',
			array(
				'panel_id'      => $panel_id,
				'attachment_id' => $attachment_id,
				'user_id'       => $user_id,
			)
		);

		// --- Escape all output values (Gate 2) ---
		return array(
			'success' => true,
			'data'    => array(
				'panel_id'      => $panel_id,
				'image_url'     => esc_url( $image_url ),
				'attachment_id' => $attachment_id,
				'dimensions'    => array(
					'width'  => $width,
					'height' => $height,
				),
				'style'         => esc_html( $style ),
				'camera_angle'  => esc_html( $camera_angle ),
			),
		);
	}

	/**
	 * Simulate creating a panel image attachment.
	 *
	 * TODO: Replace with actual media sideload from generated image URL.
	 *
	 * @param int $panel_id Panel post ID.
	 * @param int $user_id  User ID.
	 * @param int $width    Image width.
	 * @param int $height   Image height.
	 * @return int Simulated attachment ID.
	 */
	private function simulate_panel_attachment( $panel_id, $user_id, $width, $height ) {
		$title = sprintf(
			/* translators: %d: panel ID */
			__( 'Comic Panel %d - Generated Artwork', 'mcp-ai-wpoos-pro' ),
			$panel_id ? $panel_id : 0
		);

		$attachment_data = array(
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/png',
			'post_author'    => $user_id,
		);

		$attachment_id = wp_insert_attachment( $attachment_data, '', 0, true );

		if ( is_wp_error( $attachment_id ) ) {
			return 0;
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $title );
		update_post_meta( $attachment_id, '_generated_width', $width );
		update_post_meta( $attachment_id, '_generated_height', $height );

		return $attachment_id;
	}
}
