<?php
/**
 * Tests the assistant blueprints REST surface (Proposal 084 Phase 5c):
 * GET /mcp-ai-pro/v1/blueprints (+ detail) and POST …/install — all
 * edit_posts-gated and reusing the Unified Blueprints page's promoted seams
 * (no logic forks). Zero-network: the catalogue reads committed blueprint
 * JSONs under the addons/pro/includes/tools toolkit examples directories.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Pro_Blueprints_REST_Test extends WP_UnitTestCase {

	/**
	 * Enable the CRM toolkit so its blueprint library is listed.
	 */
	private function enable_crm_toolkit() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_crm_toolkit' => 1 ) );
	}

	/**
	 * Non-editors are refused.
	 */
	public function test_subscriber_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$controller = new WP_MCP_AI_Pro_Blueprints_REST_Controller();
		$result     = $controller->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Editors (the wp-admin page's capability) are allowed.
	 */
	public function test_editor_can_access() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$controller = new WP_MCP_AI_Pro_Blueprints_REST_Controller();
		$this->assertTrue( $controller->permissions_check() );
	}

	/**
	 * The list envelope groups enabled toolkits with the canonical shape.
	 */
	public function test_list_envelope() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$this->enable_crm_toolkit();

		$controller = new WP_MCP_AI_Pro_Blueprints_REST_Controller();
		$response   = $controller->get_list();
		$data       = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'total', $data['data'] );
		$this->assertArrayHasKey( 'toolkits', $data['data'] );
		$this->assertIsInt( $data['data']['total'] );

		$slugs = wp_list_pluck( $data['data']['toolkits'], 'slug' );
		$this->assertContains( 'crm', $slugs, 'The enabled CRM toolkit must be listed.' );

		foreach ( $data['data']['toolkits'] as $toolkit ) {
			$this->assertArrayHasKey( 'label', $toolkit );
			$this->assertArrayHasKey( 'blueprints', $toolkit );
			foreach ( $toolkit['blueprints'] as $bp ) {
				$this->assertArrayHasKey( 'slug', $bp );
				$this->assertArrayHasKey( 'name', $bp );
				$this->assertArrayHasKey( 'description', $bp );
				$this->assertArrayHasKey( 'tool_count', $bp );
				$this->assertArrayHasKey( 'installed', $bp );
				$this->assertIsBool( $bp['installed'] );
				$this->assertArrayHasKey( 'assistant_id', $bp );
			}
		}
	}

	/**
	 * Toolkits without an enabled toggle stay hidden from the catalogue.
	 */
	public function test_disabled_toolkit_is_hidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		update_option( 'wp_mcp_ai_settings', array() );

		$controller = new WP_MCP_AI_Pro_Blueprints_REST_Controller();
		$data       = $controller->get_list()->get_data();

		$slugs = wp_list_pluck( $data['data']['toolkits'], 'slug' );
		$this->assertNotContains( 'crm', $slugs, 'Disabled mapped toolkits must not be listed.' );
	}

	/**
	 * The detail endpoint returns the normalised payload for a real blueprint.
	 */
	public function test_detail_payload() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$this->enable_crm_toolkit();

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/blueprints/crm/agency-account-manager' );
		$request->set_param( 'toolkit', 'crm' );
		$request->set_param( 'slug', 'agency-account-manager' );

		$controller = new WP_MCP_AI_Pro_Blueprints_REST_Controller();
		$response   = $controller->get_details( $request );
		$this->assertNotWPError( $response );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'Agency Account Manager', $data['data']['name'] );
		$this->assertArrayHasKey( 'instructions', $data['data'] );
		$this->assertNotEmpty( $data['data']['tools'] );
		$this->assertSame( 'account_executive', $data['data']['defaults']['profession'] );
	}

	/**
	 * Unknown blueprints produce a WP_Error.
	 */
	public function test_detail_unknown_slug_errors() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$this->enable_crm_toolkit();

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/blueprints/crm/does-not-exist' );
		$request->set_param( 'toolkit', 'crm' );
		$request->set_param( 'slug', 'does-not-exist' );

		$controller = new WP_MCP_AI_Pro_Blueprints_REST_Controller();
		$this->assertWPError( $controller->get_details( $request ) );
	}

	/**
	 * Installing a blueprint creates a source-tracked assistant.
	 */
	public function test_install_creates_assistant() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$this->enable_crm_toolkit();

		$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/blueprints/crm/agency-account-manager/install' );
		$request->set_param( 'toolkit', 'crm' );
		$request->set_param( 'slug', 'agency-account-manager' );
		$request->set_param( 'overwrite', false );

		$controller = new WP_MCP_AI_Pro_Blueprints_REST_Controller();
		$response   = $controller->install( $request );
		$this->assertNotWPError( $response );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertGreaterThan( 0, $data['data']['assistant_id'] );

		$assistant_id = $data['data']['assistant_id'];
		$this->assertSame(
			'agency-account-manager',
			get_post_meta( $assistant_id, '_blueprint_source', true )
		);
		$this->assertSame( 'mcp_ai_assistant', get_post_type( $assistant_id ) );

		// The installed map now recognises the blueprint (drives the "View
		// Assistant" state on the console page).
		$map = WP_MCP_AI_Unified_Blueprints_Page::get_installed_blueprint_map();
		$this->assertArrayHasKey( 'Agency Account Manager', $map );
	}

	/**
	 * The blueprint routes are registered under the Pro namespace.
	 */
	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes( 'mcp-ai-pro/v1' );

		if ( ! isset( $routes['/mcp-ai-pro/v1/blueprints'] ) ) {
			add_action(
				'rest_api_init',
				function () {
					( new WP_MCP_AI_Pro_Blueprints_REST_Controller() )->register_routes();
				}
			);
			$GLOBALS['wp_rest_server'] = null;
			rest_get_server();
			$routes = rest_get_server()->get_routes( 'mcp-ai-pro/v1' );
		}

		$this->assertArrayHasKey( '/mcp-ai-pro/v1/blueprints', $routes );
		$this->assertArrayHasKey(
			'/mcp-ai-pro/v1/blueprints/(?P<toolkit>[a-z0-9-]+)/(?P<slug>[a-z0-9-]+)',
			$routes
		);
		$this->assertArrayHasKey(
			'/mcp-ai-pro/v1/blueprints/(?P<toolkit>[a-z0-9-]+)/(?P<slug>[a-z0-9-]+)/install',
			$routes
		);
	}
}
