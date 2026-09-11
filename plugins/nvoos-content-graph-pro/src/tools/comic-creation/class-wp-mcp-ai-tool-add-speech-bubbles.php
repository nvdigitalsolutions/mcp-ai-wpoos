<?php
/**
 * WP_MCP_AI_Tool_Add_Speech_Bubbles (ecosystem port - Wave F2, comic-creation tool batch).
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
 * Tool that adds speech bubble metadata to a comic panel.
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
 * Provides a Pro tool for adding speech bubble metadata to a comic panel.
 */
class WP_MCP_AI_Tool_Add_Speech_Bubbles implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'add_speech_bubbles';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Add Speech Bubbles', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Adds speech bubble metadata to a comic panel. Accepts a JSON array of bubble definitions with text, position (x, y, w, h), speaker name, and visual style. Stores bubble data as panel post meta for later rendering.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'panel_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the `mcp_ai_comic_panel` post to add bubbles to.', 'nvoos-content-graph-pro' ),
				),
				'bubbles'  => array(
					'type'        => 'string',
					'description' => __( 'JSON-encoded array of bubble objects. Each bubble: { text: string, x: int, y: int, w: int, h: int, speaker: string, style: string }. Style options: "speech", "thought", "shout", "whisper", "narration".', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'panel_id', 'bubbles' ),
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
			'profession_tags'       => array( 'artist', 'letterer', 'writer' ),
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
				__( 'You do not have permission to add speech bubbles.', 'nvoos-content-graph-pro' )
			);
		}

		// --- Sanitize all arguments at entry (Gate 1) ---
		$panel_id    = isset( $arguments['panel_id'] ) ? absint( $arguments['panel_id'] ) : 0;
		$bubbles_raw = isset( $arguments['bubbles'] ) ? $arguments['bubbles'] : '';

		// Validate panel.
		if ( $panel_id <= 0 ) {
			return new WP_Error(
				'wp_mcp_ai_missing_panel_id',
				__( 'A panel_id is required.', 'nvoos-content-graph-pro' ),
				array( 'status' => 400 )
			);
		}

		$panel_post = get_post( $panel_id );
		if ( ! $panel_post || 'mcp_ai_comic_panel' !== $panel_post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_panel_not_found',
				__( 'The specified comic panel was not found.', 'nvoos-content-graph-pro' ),
				array( 'status' => 404 )
			);
		}

		// Decode and sanitize bubbles.
		if ( is_string( $bubbles_raw ) ) {
			$bubbles = json_decode( $bubbles_raw, true );
		} elseif ( is_array( $bubbles_raw ) ) {
			$bubbles = $bubbles_raw;
		} else {
			$bubbles = null;
		}

		if ( null === $bubbles || JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_bubbles',
				__( 'The bubbles parameter must be a valid JSON array of bubble objects.', 'nvoos-content-graph-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_array( $bubbles ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_bubbles',
				__( 'Bubbles must be a JSON array.', 'nvoos-content-graph-pro' ),
				array( 'status' => 400 )
			);
		}

		// Sanitize each bubble entry.
		$sanitized_bubbles   = array();
		$valid_bubble_styles = array( 'speech', 'thought', 'shout', 'whisper', 'narration' );

		foreach ( $bubbles as $index => $bubble ) {
			if ( ! is_array( $bubble ) ) {
				continue;
			}

			$sanitized = array(
				'text'    => isset( $bubble['text'] ) ? sanitize_text_field( $bubble['text'] ) : '',
				'x'       => isset( $bubble['x'] ) ? absint( $bubble['x'] ) : 0,
				'y'       => isset( $bubble['y'] ) ? absint( $bubble['y'] ) : 0,
				'w'       => isset( $bubble['w'] ) ? absint( $bubble['w'] ) : 200,
				'h'       => isset( $bubble['h'] ) ? absint( $bubble['h'] ) : 80,
				'speaker' => isset( $bubble['speaker'] ) ? sanitize_text_field( $bubble['speaker'] ) : '',
				'style'   => 'speech',
			);

			if ( isset( $bubble['style'] ) && in_array( $bubble['style'], $valid_bubble_styles, true ) ) {
				$sanitized['style'] = $bubble['style'];
			}

			if ( '' === $sanitized['text'] ) {
				continue; // Skip empty bubbles.
			}

			$sanitized_bubbles[] = $sanitized;
		}

		// Store as panel meta.
		update_post_meta( $panel_id, '_speech_bubbles', wp_json_encode( $sanitized_bubbles ) );
		update_post_meta( $panel_id, '_bubble_count', count( $sanitized_bubbles ) );

		WP_MCP_AI_Logger::log_event(
			'speech_bubbles_added',
			'Speech bubbles added to panel',
			array(
				'panel_id'     => $panel_id,
				'bubble_count' => count( $sanitized_bubbles ),
				'user_id'      => $user_id,
			)
		);

		// --- Escape all output values (Gate 2) ---
		return array(
			'success' => true,
			'data'    => array(
				'panel_id'     => $panel_id,
				'bubble_count' => count( $sanitized_bubbles ),
				'bubbles'      => $sanitized_bubbles,
			),
		);
	}
}
