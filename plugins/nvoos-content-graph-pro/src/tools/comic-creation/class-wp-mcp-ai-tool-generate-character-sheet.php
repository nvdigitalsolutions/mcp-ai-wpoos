<?php
/**
 * WP_MCP_AI_Tool_Generate_Character_Sheet (ecosystem port - Wave F2, comic-creation tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/comic-creation/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger requires gain exists-check seams resolving from the addon's
 * D8-compat `src/` copies.
 *
 * Tool that generates a character reference sheet using AI image generation.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}

/**
 * Provides a Pro tool for generating a character reference sheet.
 */
class WP_MCP_AI_Tool_Generate_Character_Sheet implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_character_sheet';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Character Sheet', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates a character reference image using AI image generation based on a name, description, and style notes. Creates a character post in WordPress and uploads the generated image as an attachment.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'The character name.', 'nvoos-content-graph-pro' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Physical description of the character (appearance, clothing, distinctive features).', 'nvoos-content-graph-pro' ),
				),
				'style_notes' => array(
					'type'        => 'string',
					'description' => __( 'Art style direction for the character (e.g., "manga", "american comic", "realistic digital painting").', 'nvoos-content-graph-pro' ),
				),
				'pose'        => array(
					'type'        => 'string',
					'description' => __( 'Pose or stance for the character reference (e.g., "standing hero pose", "action stance", "neutral front-facing").', 'nvoos-content-graph-pro' ),
					'default'     => 'neutral front-facing character reference sheet',
				),
			),
			'required'             => array( 'name', 'description' ),
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
				__( 'You do not have permission to generate character sheets.', 'nvoos-content-graph-pro' )
			);
		}

		// --- Sanitize all arguments at entry (Gate 1) ---
		$name        = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		$description = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$style_notes = isset( $arguments['style_notes'] ) ? sanitize_text_field( $arguments['style_notes'] ) : '';
		$pose        = isset( $arguments['pose'] ) ? sanitize_text_field( $arguments['pose'] ) : 'neutral front-facing character reference sheet';

		// Validate required fields.
		if ( '' === $name ) {
			return new WP_Error(
				'wp_mcp_ai_missing_name',
				__( 'A character name is required.', 'nvoos-content-graph-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $description ) {
			return new WP_Error(
				'wp_mcp_ai_missing_description',
				__( 'A character description is required.', 'nvoos-content-graph-pro' ),
				array( 'status' => 400 )
			);
		}

		WP_MCP_AI_Logger::log_event(
			'character_sheet_generation_started',
			'Starting character sheet generation',
			array(
				'character_name' => $name,
				'style_notes'    => $style_notes,
				'user_id'        => $user_id,
			)
		);

		// TODO: Integrate with actual AI image generation API (DALL-E, Gemini Imagen, etc.)
		// For now, produce a simulated result.
		$image_url     = $this->generate_simulated_character_image( $name, $description, $style_notes, $pose );
		$attachment_id = $this->simulate_image_attachment( $name, $user_id );

		// Create character post.
		$post_data = array(
			'post_type'    => 'mcp_ai_comic_char',
			'post_title'   => wp_strip_all_tags( $name ),
			'post_content' => $description,
			'post_status'  => 'publish',
			'post_author'  => $user_id,
		);

		$character_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $character_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_character_creation_failed',
				__( 'Failed to create the character post.', 'nvoos-content-graph-pro' ),
				array( 'status' => 500 )
			);
		}

		// Store metadata.
		update_post_meta( $character_id, '_character_style_notes', $style_notes );
		update_post_meta( $character_id, '_character_pose', $pose );
		update_post_meta( $character_id, '_character_image_id', $attachment_id );
		update_post_meta( $character_id, '_character_image_url', esc_url_raw( $image_url ) );

		if ( $attachment_id > 0 ) {
			set_post_thumbnail( $character_id, $attachment_id );
		}

		WP_MCP_AI_Logger::log_event(
			'character_sheet_generated',
			'Character sheet generated successfully',
			array(
				'character_id' => $character_id,
				'name'         => $name,
				'user_id'      => $user_id,
			)
		);

		// --- Escape all output values (Gate 2) ---
		return array(
			'success' => true,
			'data'    => array(
				'character_id'  => $character_id,
				'name'          => esc_html( $name ),
				'image_url'     => esc_url( $image_url ),
				'attachment_id' => $attachment_id,
				'edit_url'      => esc_url( get_edit_post_link( $character_id, 'raw' ) ),
			),
		);
	}

	/**
	 * Generate a simulated character image URL.
	 *
	 * TODO: Replace with actual AI image generation API call.
	 *
	 * @param string $name        Character name.
	 * @param string $description Character description.
	 * @param string $style_notes Art style direction.
	 * @param string $pose        Pose description.
	 * @return string Placeholder image URL.
	 */
	private function generate_simulated_character_image( $name, $description, $style_notes, $pose ) {
		// Simulated placeholder — real implementation would call AI image API.
		return 'https://placehold.co/512x768/333/eee?text=' . rawurlencode( $name . ' Character Sheet' );
	}

	/**
	 * Simulate creating a WordPress media attachment.
	 *
	 * TODO: Replace with actual media sideload from generated image URL.
	 *
	 * @param string $name    Character name for attachment title.
	 * @param int    $user_id User ID.
	 * @return int Simulated attachment ID.
	 */
	private function simulate_image_attachment( $name, $user_id ) {
		$attachment_data = array(
			'post_title'     => sprintf(
				/* translators: %s: character name */
				__( '%s - Character Reference Sheet', 'nvoos-content-graph-pro' ),
				$name
			),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/png',
			'post_author'    => $user_id,
		);

		$attachment_id = wp_insert_attachment( $attachment_data, '', 0, true );

		if ( is_wp_error( $attachment_id ) ) {
			return 0;
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $name );

		return $attachment_id;
	}
}
