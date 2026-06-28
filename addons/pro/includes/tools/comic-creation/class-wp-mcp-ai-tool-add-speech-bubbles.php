<?php
/**
 * Tool that adds speech bubble metadata to a comic panel.
 *
 * Takes a panel ID and a JSON array of bubble definitions (text, position,
 * size, speaker, style) and stores them as panel post meta (`_speech_bubbles`).
 * The bubble data can later be used by rendering tools to overlay speech
 * bubbles onto panel images.
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
		return __( 'Add Speech Bubbles', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Adds speech bubble metadata to a comic panel. Accepts a JSON array of bubble definitions with text, position (x, y, w, h), speaker name, and visual style. Stores bubble data as panel post meta for later rendering.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'ID of the `mcp_ai_comic_panel` post to add bubbles to.', 'mcp-ai-wpoos-pro' ),
				),
				'bubbles'  => array(
					'type'        => 'string',
					'description' => __( 'JSON-encoded array of bubble objects. Each bubble: { text: string, x: int, y: int, w: int, h: int, speaker: string, style: string }. Style options: "speech", "thought", "shout", "whisper", "narration".', 'mcp-ai-wpoos-pro' ),
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
				__( 'You do not have permission to add speech bubbles.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --- Sanitize all arguments at entry (Gate 1) ---
		$panel_id = isset( $arguments['panel_id'] ) ? absint( $arguments['panel_id'] ) : 0;
		$bubbles_raw = isset( $arguments['bubbles'] ) ? $arguments['bubbles'] : '';

		// Validate panel.
		if ( $panel_id <= 0 ) {
			return new WP_Error(
				'wp_mcp_ai_missing_panel_id',
				__( 'A panel_id is required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$panel_post = get_post( $panel_id );
		if ( ! $panel_post || 'mcp_ai_comic_panel' !== $panel_post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_panel_not_found',
				__( 'The specified comic panel was not found.', 'mcp-ai-wpoos-pro' ),
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
				__( 'The bubbles parameter must be a valid JSON array of bubble objects.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_array( $bubbles ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_bubbles',
				__( 'Bubbles must be a JSON array.', 'mcp-ai-wpoos-pro' ),
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
				'panel_id'      => $panel_id,
				'bubble_count'  => count( $sanitized_bubbles ),
				'bubbles'       => $sanitized_bubbles,
			),
		);
	}
}
