<?php
/**
 * Tool that breaks down a comic script into individual panels.
 *
 * Takes a script ID or raw script text and produces numbered panel posts
 * (`mcp_ai_comic_panel`) with descriptions, dialogue, and camera angle
 * metadata. Each panel becomes a discrete entity that can be fed to the
 * image generation pipeline.
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
 * Provides a Pro tool for breaking down a comic script into numbered panels.
 */
class WP_MCP_AI_Tool_Breakdown_Comic_Panels implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'breakdown_comic_panels';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Breakdown Comic Panels', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Breaks down a comic script into individual numbered panels with descriptions, dialogue, and camera angle metadata. Creates `mcp_ai_comic_panel` posts for each panel. Accepts either a script ID or raw script text.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'script_id'   => array(
					'type'        => 'integer',
					'description' => __( 'ID of the `mcp_ai_comic_script` post to break down.', 'mcp-ai-wpoos-pro' ),
				),
				'script_text' => array(
					'type'        => 'string',
					'description' => __( 'Raw script text (JSON string) to break down. Used when script_id is not provided.', 'mcp-ai-wpoos-pro' ),
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
			'profession_tags'       => array( 'artist', 'content_manager', 'writer' ),
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
				__( 'You do not have permission to break down comic panels.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --- Sanitize all arguments at entry (Gate 1) ---
		$script_id   = isset( $arguments['script_id'] ) ? absint( $arguments['script_id'] ) : 0;
		$script_text = isset( $arguments['script_text'] ) ? trim( sanitize_textarea_field( $arguments['script_text'] ) ) : '';

		// Determine script source.
		$script_data = null;

		if ( $script_id > 0 ) {
			$script_post = get_post( $script_id );

			if ( ! $script_post || 'mcp_ai_comic_script' !== $script_post->post_type ) {
				return new WP_Error(
					'wp_mcp_ai_script_not_found',
					__( 'The specified comic script was not found.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 404 )
				);
			}

			$script_text = $script_post->post_content;
		}

		if ( '' === $script_text ) {
			return new WP_Error(
				'wp_mcp_ai_missing_script',
				__( 'Either script_id or script_text must be provided.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Parse script JSON.
		$script_data = json_decode( $script_text, true );
		if ( null === $script_data || JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_script',
				__( 'The script text is not valid JSON.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Extract scenes and panels.
		$scenes = isset( $script_data['scenes'] ) ? $script_data['scenes'] : array();
		if ( empty( $scenes ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_scenes',
				__( 'The script contains no scenes to break down.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Create panel posts.
		$panel_ids   = array();
		$panel_order = 0;

		foreach ( $scenes as $scene ) {
			$scene_number = isset( $scene['scene_number'] ) ? absint( $scene['scene_number'] ) : 0;
			$panels       = isset( $scene['panels'] ) ? $scene['panels'] : array();

			foreach ( $panels as $panel ) {
				$panel_order++;

				$panel_title = sprintf(
					/* translators: %d: panel order number */
					__( 'Comic Panel %d', 'mcp-ai-wpoos-pro' ),
					$panel_order
				);

				$panel_post_data = array(
					'post_type'    => 'mcp_ai_comic_panel',
					'post_title'   => wp_strip_all_tags( $panel_title ),
					'post_content' => isset( $panel['description'] ) ? sanitize_textarea_field( $panel['description'] ) : '',
					'post_status'  => 'publish',
					'post_author'  => $user_id,
					'menu_order'   => $panel_order,
				);

				$panel_id = wp_insert_post( $panel_post_data, true );

				if ( is_wp_error( $panel_id ) ) {
					continue;
				}

				// Store panel metadata.
				update_post_meta( $panel_id, '_panel_order', $panel_order );
				update_post_meta( $panel_id, '_scene_number', $scene_number );

				if ( isset( $panel['camera_angle'] ) ) {
					update_post_meta( $panel_id, '_camera_angle', sanitize_text_field( $panel['camera_angle'] ) );
				}
				if ( isset( $panel['mood'] ) ) {
					update_post_meta( $panel_id, '_panel_mood', sanitize_text_field( $panel['mood'] ) );
				}
				if ( isset( $panel['dialogue'] ) ) {
					update_post_meta( $panel_id, '_panel_dialogue', wp_json_encode( $panel['dialogue'] ) );
				}

				// Link to parent script if available.
				if ( $script_id > 0 ) {
					update_post_meta( $panel_id, '_comic_script_id', $script_id );
				}

				$panel_ids[] = $panel_id;
			}
		}

		WP_MCP_AI_Logger::log_event(
			'comic_panels_created',
			'Comic panels broken down from script',
			array(
				'script_id'   => $script_id,
				'panel_count' => count( $panel_ids ),
				'user_id'     => $user_id,
			)
		);

		// --- Escape all output values (Gate 2) ---
		return array(
			'success' => true,
			'data'    => array(
				'script_id'   => $script_id,
				'panel_count' => count( $panel_ids ),
				'panel_ids'   => array_map( 'absint', $panel_ids ),
			),
		);
	}
}
