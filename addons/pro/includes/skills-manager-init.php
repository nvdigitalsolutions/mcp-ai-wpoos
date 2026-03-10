<?php
/**
 * Skills Manager Toolkit — Pro Initialization.
 *
 * Loads and wires up the Pro Skill Manager components:
 *  - WP_MCP_AI_Skill_Manager_Admin_Page  (admin UI: list, upload, editor)
 *  - WP_MCP_AI_Skill_Manager_REST_Controller (REST API: CRUD)
 *
 * This file is require_once'd from wp_mcp_ai_pro_init() inside
 * mcp-ai-wpoos-pro.php. It must be loaded only after the Core plugin has
 * initialised (WP_MCP_AI_PATH is defined).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.8.0
 * @see     https://agentskills.io/specification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Admin page (UI) ──────────────────────────────────────────────────────────
if ( is_admin() ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-skill-manager-admin-page.php';
	WP_MCP_AI_Skill_Manager_Admin_Page::init();
}

// ── REST API controller ───────────────────────────────────────────────────────
require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-skill-manager-rest-controller.php';
new WP_MCP_AI_Skill_Manager_REST_Controller();
