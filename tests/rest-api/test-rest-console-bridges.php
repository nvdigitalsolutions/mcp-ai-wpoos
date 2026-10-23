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

	/**
	 * The console bridge provider classes must be loaded eagerly by
	 * rest_console_bridges.
	 *
	 * In production these files are otherwise only loaded by the
	 * admin-gated admin_sections module, so on front-end-originated REST
	 * requests the bridges degraded silently: GET /workflow-presets
	 * returned an empty catalogue (the console Presets tab rendered "No
	 * presets available."), POST /workflow-builder/nodes/execute always
	 * 501'd "builder_unavailable", and the ops overview webhook list was
	 * silently empty (live repros on the Complete console).
	 *
	 * The test bootstrap's Pro fallback autoloader would mask this bug
	 * (a lazy class_exists() check loads the file on demand), so this test
	 * pins the EAGER include right after the registry boot — before any
	 * handler runs.
	 *
	 * @dataProvider provider_bridge_class_files
	 * @param string $filename The class file basename to assert included.
	 */
	public function test_bridge_provider_class_files_load_eagerly_on_registry_boot( $filename ) {
		WP_MCP_AI_Pro_Module_Registry::get_instance()->boot();

		$included = array();
		foreach ( get_included_files() as $file ) {
			if ( false !== strpos( str_replace( '\\', '/', $file ), $filename ) ) {
				$included[] = $file;
			}
		}

		$this->assertNotEmpty(
			$included,
			'Bridge provider class file ' . $filename . ' must be loaded eagerly by rest_console_bridges on every request.'
		);
	}

	/**
	 * The three bridge provider files that must load eagerly.
	 *
	 * @return array[]
	 */
	public static function provider_bridge_class_files() {
		return array(
			'workflow presets registry' => array( 'class-wp-mcp-ai-pro-workflow-presets.php' ),
			'workflow builder page'     => array( 'class-wp-mcp-ai-pro-workflow-builder-page.php' ),
			'webhook status page'       => array( 'class-wp-mcp-ai-pro-webhook-status-page.php' ),
		);
	}

	/**
	 * End-to-end contract: after a fresh rest_api_init, the route returns a
	 * non-empty catalogue for an administrator.
	 */
	public function test_workflow_presets_route_returns_catalogue_after_boot() {
		WP_MCP_AI_Pro_Module_Registry::get_instance()->boot();

		// Admin session — the route's permission callback requires manage_options.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$GLOBALS['wp_rest_server'] = null;
		$request                   = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/workflow-presets' );
		$response                  = rest_get_server()->dispatch( $request );
		$data                      = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'presets', $data );
		$this->assertNotEmpty(
			$data['presets'],
			'The workflow-presets route must return the preset catalogue on front-end boots — the presets class cannot depend on the admin-context admin_sections module.'
		);
		$this->assertNotEmpty( $data['categories'] );
	}

	/**
	 * End-to-end contract: the command-center overview loads its provider
	 * lazily and returns the success envelope with KPIs for an administrator.
	 *
	 * Note: the test bootstrap's Pro fallback autoloader can also resolve
	 * the provider class on demand, so this test cannot distinguish the
	 * lazy require from the autoload — it pins the response contract, while
	 * the production seam (load_provider() requiring the file) is exercised
	 * on every real install where no such autoloader exists.
	 */
	public function test_command_center_overview_returns_kpis_after_boot() {
		WP_MCP_AI_Pro_Module_Registry::get_instance()->boot();

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$GLOBALS['wp_rest_server'] = null;
		$request                   = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/command-center/overview' );
		$response                  = rest_get_server()->dispatch( $request );
		$data                      = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'], 'The command-center overview must succeed on front-end boots.' );
		$this->assertArrayHasKey( 'kpis', $data['data'] );
		$this->assertArrayHasKey( 'total_agents', $data['data']['kpis'] );
	}
}
