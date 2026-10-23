<?php
/**
 * Tests the console REST bridge registration (Proposal 084).
 *
 * The six console bridges (ops overview, workflow presets, workflow node
 * execution, records, dashboards, media command center) were originally
 * booted from wp_mcp_ai_pro_load_admin_sections(), which only runs in the
 * `admin_sections` module — an admin-context-gated registry module. The Pro
 * SPA renders on a front-end page and issues its REST calls from that
 * origin, where is_admin() is false, so the routes never registered and
 * every console page 404'd with "No route was found matching the URL and
 * request method" (live repro on the Complete console, 2026-10-21).
 *
 * They are now booted by the context-free `rest_console_bridges` module.
 * This test pins that wiring: after a non-admin registry boot (the CLI /
 * front-end REST scenario), a fresh rest_api_init must surface all six
 * route families under /mcp-ai-pro/v1.
 *
 * Skipped when the Pro addon classes are not loaded.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Pro_Console_Bridges_REST_Test extends WP_UnitTestCase {

	/**
	 * Skip when the Pro addon classes are absent.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Module_Registry' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}
	}

	/**
	 * The console bridges module has no admin context gate, so it loads in
	 * the non-admin (CLI / front-end REST) bootstrap.
	 */
	public function test_console_bridges_module_loads_without_admin_context() {
		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();

		$this->assertTrue(
			$registry->is_loaded( 'rest_console_bridges' ),
			'The rest_console_bridges module must load outside the admin context.'
		);
	}

	/**
	 * All six console bridge route families are registered after a fresh
	 * rest_api_init — the exact scenario that 404'd on the live console.
	 */
	public function test_console_bridge_routes_register_on_rest_api_init() {
		WP_MCP_AI_Pro_Module_Registry::get_instance()->boot();

		// Fresh server — rest_get_server() fires rest_api_init, which is
		// where the six controllers register their routes.
		$GLOBALS['wp_rest_server'] = null;
		$routes                    = rest_get_server()->get_routes( 'mcp-ai-pro/v1' );

		$expected = array(
			'/mcp-ai-pro/v1/ops/overview',
			'/mcp-ai-pro/v1/workflow-presets',
			'/mcp-ai-pro/v1/workflow-presets/(?P<id>[a-z0-9_\-]+)/install',
			'/mcp-ai-pro/v1/workflow-builder/nodes/execute',
			'/mcp-ai-pro/v1/records/schema',
			'/mcp-ai-pro/v1/dashboards/schema',
			'/mcp-ai-pro/v1/media-cc/health',
			'/mcp-ai-pro/v1/media-cc/compression-sweep',
		);

		foreach ( $expected as $route ) {
			$this->assertArrayHasKey(
				$route,
				$routes,
				"Console bridge route \"{$route}\" must be registered without an admin context."
			);
		}
	}
}
