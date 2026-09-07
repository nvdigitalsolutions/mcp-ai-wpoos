<?php
/**
 * Characterization tests for the ported vault wiring (Wave F1, sub-cluster
 * 4b): init file, REST controller, tool adapter, and ecosystem tool
 * registration.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the vault
 *   subsystem; the public surface (filters, routes, tool contracts) is
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported wiring in
 *   `src/tools/vault/init.php` + `src/vault/class-wp-mcp-ai-vault-rest-controller.php`
 *   is asserted in full, including the ecosystem tool registration seam.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Vault wiring tests.
 */
class Test_Vault_Wiring extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// The registry boots lazily in the test env (plugins_loaded already
		// fired when the suite bootstrap loads WordPress) — require the vault
		// init per mode so its functions and hooks exist for the tests.
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/vault/init.php';
		} else {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/vault/init.php';
		}
		// Re-arm the file-level filter: an earlier test's registry boot
		// require_once'd this file mid-test, and the WP test framework's
		// hook-snapshot restore removed the registration afterwards.
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_vault_tools', 10 );
	}

	/**
	 * The vault init must wire the REST hook and the byte-identical tool
	 * filter (idempotent re-init).
	 */
	public function test_vault_init_wires_hooks(): void {
		wp_mcp_ai_pro_init_password_vault();

		$this->assertNotFalse( has_action( 'rest_api_init', 'wp_mcp_ai_pro_register_vault_rest_routes' ) );
		$this->assertNotFalse( has_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_vault_tools' ) );
	}

	/**
	 * The byte-identical tool filter must carry the vault tool map.
	 */
	public function test_vault_tools_filter_shape(): void {
		$tools = wp_mcp_ai_pro_register_vault_tools( array() );

		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Vault_Access', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Vault_Manage', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Generate_Password', $tools );
	}

	/**
	 * The vault REST routes must register under mcp-ai/v1 with the
	 * manage_options gate.
	 */
	public function test_vault_rest_routes_register_and_gate(): void {
		wp_mcp_ai_pro_init_password_vault();
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/vault/items', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/vault/items/(?P<id>[\\d]+)', $routes );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/vault/items' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The vault tool definitions must keep the byte-identical shape.
	 */
	public function test_vault_tool_definitions(): void {
		$access = new WP_MCP_AI_Pro_Tool_Vault_Access();
		$this->assertSame( 'vault_access', $access->get_slug() );

		$definition = $access->get_definition();
		$this->assertSame( 'vault_access', $definition['name'] );
		$this->assertSame( 'manage_options', $definition['required_capability'] );
		$this->assertArrayHasKey( 'input_schema', $definition );

		$manage = new WP_MCP_AI_Pro_Tool_Vault_Manage();
		$this->assertSame( 'vault_manage', $manage->get_slug() );
	}

	/**
	 * Subscribers must be rejected by the tools' own capability gate.
	 */
	public function test_vault_tools_enforce_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = ( new WP_MCP_AI_Pro_Tool_Vault_Access() )->execute( array( 'action' => 'list' ), array() );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Standalone only: the init must register the vault tools into the
	 * ecosystem registries via the adapter.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		wp_mcp_ai_pro_register_vault_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['vault_access'] ?? null );
		$this->assertNotNull( $parent->all()['vault_manage'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'vault_access' ) );
		$this->assertTrue( $core_tools->has( 'vault_manage' ) );
	}

	/**
	 * The adapter must bridge the WP-style tool contract onto the graph
	 * Tool interface (standalone only — the adapter has no monolith
	 * counterpart and its inner class is base-owned monolith).
	 */
	public function test_tool_adapter_contract(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the adapter has no monolith counterpart.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/vault/class-wp-mcp-ai-pro-tool-vault-access.php';

		$adapter = new WP_MCP_AI_Pro_Tool_Adapter( new WP_MCP_AI_Pro_Tool_Vault_Access() );

		$this->assertSame( 'vault_access', $adapter->getSlug() );
		$this->assertSame( 'vault_access', $adapter->getName() );
		$this->assertNotEmpty( $adapter->getDescription() );
		$this->assertArrayHasKey( 'type', $adapter->getParametersSchema() );
		$this->assertSame( 'manage_options', $adapter->getRequiredCapability() );
		$this->assertContains( 'read-only', $adapter->getCapabilityFlags() );
	}

	/**
	 * Standalone only: the registry's toolkit_vault module must boot once
	 * the init file exists.
	 */
	public function test_vault_module_boots_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base registry boots the module.' );
		}

		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();
		$this->assertTrue( $registry->is_loaded( 'toolkit_vault' ) );
	}
}
