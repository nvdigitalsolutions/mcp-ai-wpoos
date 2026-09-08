<?php
/**
 * Characterization tests for the Wave F2 cloudways tool batch — the sixty
 * Cloudways tools ported from the base Pro addon onto the shared tool base.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/cloudways/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Cloudways tool batch tests.
 */
class Test_Cloudways_Tools extends WP_UnitTestCase {

	/**
	 * The list-servers surface must be byte-identical.
	 */
	public function test_list_servers_surface(): void {
		$tool = new WP_MCP_AI_Tool_Cloudways_List_Servers();
		$this->assertSame( 'cloudways_list_servers', $tool->get_slug() );
		$this->assertSame( 'manage_options', $tool->get_required_capability() );
		$this->assertContains( 'read-only', $tool->get_capability_flags() );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Cloudways_List_Servers' => 'tools/cloudways/class-wp-mcp-ai-tool-cloudways-list-servers.php',
			'WP_MCP_AI_Tool_Cloudways_Get_Server'   => 'tools/cloudways/class-wp-mcp-ai-tool-cloudways-get-server.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The list-servers execute() degrades to a WP_Error without credentials
	 * (no external API probes are exercised).
	 */
	public function test_list_servers_execute_degrades(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Cloudways_List_Servers();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Standalone only: the init's tool filter must carry all sixty tools
	 * with the addon's file paths.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the cloudways tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_cloudways_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 60, $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Cloudways_List_Servers', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-list-servers.php',
			$tools['WP_MCP_AI_Tool_Cloudways_List_Servers']
		);
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Update', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the ported
	 * tools into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/init.php';
		wp_mcp_ai_pro_register_cloudways_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['cloudways_list_servers'] ?? null );
		$this->assertNotNull( $parent->all()['cloudways_get_server'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'cloudways_list_servers' ) );
		$this->assertTrue( $core_tools->has( 'cloudways_get_server' ) );
	}
}
