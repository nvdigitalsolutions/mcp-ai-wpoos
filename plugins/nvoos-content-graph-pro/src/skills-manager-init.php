<?php
/**
 * Skills Manager Toolkit — Pro Initialization (ecosystem port — Wave F1,
 * sub-cluster 3b-3).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/skills-manager-init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Loads and wires up the Pro Skill Manager
 * components:
 *  - WP_MCP_AI_Skill_Manager_Admin_Page  (admin UI: list, upload, editor)
 *  - WP_MCP_AI_Skill_Research_Admin_Page (research + chat UI)
 *  - WP_MCP_AI_Skill_Settings_Admin_Page (settings UI)
 *  - WP_MCP_AI_Skill_Manager_REST_Controller    (REST API: CRUD)
 *  - WP_MCP_AI_Skill_Catalogue_REST_Controller  (REST API: catalogues)
 *  - WP_MCP_AI_Skill_Catalogue_Service          (catalogue service + cron)
 *
 * Documented deviations from the monolith copy:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/'`.
 * 3. The daily catalogue-refresh cron is scheduled only standalone — the
 *    base Pro addon owns the same scheduling monolith
 *    (`defined( 'WP_MCP_AI_PATH' )` gate).
 *
 * @package NvoosContentGraphPro
 * @since   1.0.0
 * @see     https://agentskills.io/specification
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Admin pages (UI) ──────────────────────────────────────────────────────────
if ( is_admin() ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-skill-manager-admin-page.php';
	WP_MCP_AI_Skill_Manager_Admin_Page::init();

	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-skill-research-admin-page.php';
	WP_MCP_AI_Skill_Research_Admin_Page::init();

	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-skill-settings-admin-page.php';
	WP_MCP_AI_Skill_Settings_Admin_Page::init();
}

// ── REST API controllers ───────────────────────────────────────────────────────
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/rest/class-wp-mcp-ai-skill-manager-rest-controller.php';
new WP_MCP_AI_Skill_Manager_REST_Controller();

// ── Catalogue service + REST + cron (Phase 2) ─────────────────────────────────
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-skill-catalogue-service.php';
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/rest/class-wp-mcp-ai-skill-catalogue-rest-controller.php';
new WP_MCP_AI_Skill_Catalogue_REST_Controller();

// Bind the daily refresh hook and ensure the event is scheduled.
add_action( WP_MCP_AI_Skill_Catalogue_Service::CRON_HOOK, array( 'WP_MCP_AI_Skill_Catalogue_Service', 'handle_cron' ) );
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
	add_action(
		'init',
		function () {
			// Schedule on first init after activation; cheap no-op once scheduled.
			WP_MCP_AI_Skill_Catalogue_Service::schedule_cron();
		},
		20
	);
}
