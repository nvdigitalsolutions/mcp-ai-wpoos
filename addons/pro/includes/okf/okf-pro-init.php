<?php
/**
 * OKF Pro — Bootstrap initialization.
 *
 * Loads and wires the Pro OKF feature set:
 *  - OKF → Skill Bridge (load_skill resolution for `bundle:concept_id`)
 *  - assistant grant metabox
 *  - Auto-Enrichment Agent (site content → OKF concepts) + admin AJAX
 *  - Hybrid Knowledge Router (query classification)
 *  - the two Pro MCP tools (`okf_enrich_site_content`, `route_knowledge_query`)
 *
 * This file is require_once'd by the Pro module registry
 * (module `pro_okf_skill_bridge`). The Base OKF engine is always present
 * when Pro runs; tool registration hooks `wp_mcp_ai_bootstrapped` after
 * the Base OKF init (priority 32).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.62
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-okf-skill-bridge.php';
require_once __DIR__ . '/class-wp-mcp-ai-okf-enrichment-agent.php';
require_once __DIR__ . '/class-wp-mcp-ai-hybrid-knowledge-router.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-okf-concepts-metabox.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-okf-enrichment-admin.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/okf/class-wp-mcp-ai-tool-okf-enrich-site-content.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/okf/class-wp-mcp-ai-tool-route-knowledge-query.php';

WP_MCP_AI_OKF_Skill_Bridge::init();
WP_MCP_AI_OKF_Concepts_Metabox::init();
WP_MCP_AI_OKF_Enrichment_Admin::init();

/**
 * Register the Pro OKF tools once the Base engine has bootstrapped.
 *
 * Hooked after Base OKF init (priority 32) and Pro Paper Store (35).
 */
add_action(
	'wp_mcp_ai_bootstrapped',
	function () {
		if ( ! class_exists( 'WP_MCP_AI_OKF_Bundle_Manager' ) ) {
			return;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( 'WP_MCP_AI_Tool_OKF_Enrich_Site_Content' );
		$registry->register_tool( 'WP_MCP_AI_Tool_Route_Knowledge_Query' );
	},
	36
);
