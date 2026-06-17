<?php
/**
 * Extended Cognition Toolkit MCP Server
 *
 * Phase 6 Tier-2 promotion. See docs/ADR_002_toolkit_mcp_servers.md.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extended Cognition MCP server.
 *
 * Exposes sensory-input capture and multimodal context tools (screen, audio,
 * camera, motion). Tools-only server — workflow plumbing without a CPT-shaped
 * ingestion surface.
 */
class WP_MCP_AI_Extended_Cognition_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'extended-cognition';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Extended Cognition', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Multimodal sensory-input capture (screen, audio, visual, motion), context analysis, and sensor-permission management for the Extended Cognition toolkit. Tools-only server.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * Get the ingestion surfaces for this server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array();
	}

	/**
	 * Get the candidate tool slugs for this server.
	 *
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs the Extended Cognition MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_extended_cognition_candidate_tools',
			array(
				'ext_cog_capture_screen',
				'ext_cog_capture_audio',
				'ext_cog_capture_visual',
				'ext_cog_get_motion_context',
				'ext_cog_analyze_sensory_input',
				'ext_cog_remember_sensory_context',
				'ext_cog_manage_sensor_permissions',
				'ext_cog_detect_objects',
				'ext_cog_recognize_products',
				'ext_cog_analyze_video_feed',
			)
		);
	}
}
