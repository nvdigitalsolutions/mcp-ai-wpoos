<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-extended-cognition-mcp-server.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


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
		return __( 'Extended Cognition', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Multimodal sensory-input capture (screen, audio, visual, motion), context analysis, and sensor-permission management for the Extended Cognition toolkit. Tools-only server.',
			'nvoos-content-graph-pro'
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
