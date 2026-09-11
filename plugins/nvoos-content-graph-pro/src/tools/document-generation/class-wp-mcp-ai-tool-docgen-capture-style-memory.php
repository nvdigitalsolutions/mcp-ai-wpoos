<?php
/**
 * WP_MCP_AI_Tool_DocGen_Capture_Style_Memory (ecosystem port - Wave F2, document-generation tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/document-generation/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger/trait requires gain exists-check seams resolving from the addon's D8-compat `src/` copies; the capture-tool-base require gains a class_exists seam resolving from `src/tools/capture/`.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation
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


if ( ! class_exists( 'WP_MCP_AI_Pro_Capture_Tool_Base' ) ) {
	if ( ! class_exists( 'WP_MCP_AI_Pro_Capture_Tool_Base' ) ) {
		$nvoos_content_graph_pro_pro_capture_tool_base = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/capture/class-wp-mcp-ai-pro-capture-tool-base.php';
		if ( file_exists( $nvoos_content_graph_pro_pro_capture_tool_base ) ) {
			require_once $nvoos_content_graph_pro_pro_capture_tool_base;
		}
	}
}

/**
 * MemPalace capture tool for Document Generation style memory & drafts.
 */
class WP_MCP_AI_Tool_DocGen_Capture_Style_Memory extends WP_MCP_AI_Pro_Capture_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'docgen_capture_style_memory';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Document Generation — Capture Style Memory', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Capture a user writing-style preference or draft into the MemPalace user drawer. This is one of only two toolkits allowed to provide a summary alongside the verbatim source — the original is kept at tier=archival, the summary becomes the tier=recall representative.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_prefix() {
		return 'user';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_key_name() {
		return 'user_id';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_key_description() {
		return __( 'WordPress user ID (or external user identifier). Forms the wing slug `user/{user_id}`.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_room_enum() {
		return array( 'style', 'drafts' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_capture_defaults() {
		return array(
			'tier'                => WP_MCP_AI_Memory_Capture_Service::TIER_RECALL,
			'importance'          => 0.55,
			'sensitivity'         => 'pii',
			'consent_basis'       => 'consent',
			'verbatim'            => true,
			'allow_summarisation' => true,
			'ttl'                 => 2 * 365 * DAY_IN_SECONDS,
			'source'              => 'docgen_capture_style_memory',
			'default_tags'        => array( 'docgen', 'style' ),
		);
	}
}
