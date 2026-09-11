<?php
/**
 * Characterization tests for the Wave F6 remote-connections tools slice — the
 * remote WordPress/WooCommerce connection tool and the remote Shopify
 * connection tool ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/remote-connections/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Remote-connections tools tests.
 */
class Test_Remote_Connections_Tools extends WP_UnitTestCase {

	/**
	 * The two ported tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$wp = new WP_MCP_AI_Tool_Remote_WP_Connection();
		$this->assertSame( 'remote_wp_connection', $wp->get_slug() );
		$this->assertSame( 'Remote WordPress/WooCommerce Connection', $wp->get_name() );
		$this->assertSame( 'edit_posts', $wp->get_required_capability() );
		$this->assertSame( array( 'action' ), $wp->get_parameters_schema()['required'] );
		$this->assertContains( 'pro', $wp->get_capability_flags() );
		$this->assertContains( 'external-api', $wp->get_capability_flags() );
		$this->assertContains( 'write-capable', $wp->get_capability_flags() );

		$shopify = new WP_MCP_AI_Tool_Remote_Shopify_Connection();
		$this->assertSame( 'remote_shopify_connection', $shopify->get_slug() );
		$this->assertSame( 'Remote Shopify Connection', $shopify->get_name() );
		$this->assertSame( 'edit_posts', $shopify->get_required_capability() );
		$this->assertSame( array( 'action' ), $shopify->get_parameters_schema()['required'] );
		$this->assertContains( 'external-api', $shopify->get_capability_flags() );
		$this->assertContains( 'requires-credentials', $shopify->get_capability_flags() );
		$this->assertContains( 'requires-capability', $shopify->get_capability_flags() );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Remote_WP_Connection'      => 'tools/remote-connections/class-wp-mcp-ai-tool-remote-wp-connection.php',
			'WP_MCP_AI_Tool_Remote_Shopify_Connection' => 'tools/remote-connections/class-wp-mcp-ai-tool-remote-shopify-connection.php',
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
	 * The remote-WP execute() contracts degrade gracefully with no
	 * connections configured (no external HTTP is exercised).
	 */
	public function test_remote_wp_execute_contracts(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Remote_WP_Connection();

		// list_connections with no configured connections.
		$listing = $tool->execute( array( 'action' => 'list_connections' ), array( 'user_id' => $user_id ) );
		$this->assertIsArray( $listing );
		$this->assertSame( array(), $listing['connections'] );
		$this->assertSame( 0, $listing['count'] );
		$this->assertArrayHasKey( 'summary', $listing );

		// Non-list action without a connection_id.
		$missing = $tool->execute( array( 'action' => 'get_posts' ), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $missing );
		$this->assertSame( 'wp_mcp_ai_pro_missing_connection', $missing->get_error_code() );

		// Non-list action with an unknown connection_id.
		$unknown = $tool->execute(
			array(
				'action'        => 'get_posts',
				'connection_id' => 'nope',
			),
			array( 'user_id' => $user_id )
		);
		$this->assertInstanceOf( 'WP_Error', $unknown );
		$this->assertSame( 'wp_mcp_ai_pro_invalid_connection', $unknown->get_error_code() );

		// The capability gate rejects subscribers.
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$forbidden     = $tool->execute( array( 'action' => 'list_connections' ), array( 'user_id' => $subscriber_id ) );
		$this->assertInstanceOf( 'WP_Error', $forbidden );
		$this->assertSame( 'wp_mcp_ai_forbidden', $forbidden->get_error_code() );
	}

	/**
	 * The remote-Shopify execute() contracts degrade gracefully with no
	 * connections configured (no external HTTP is exercised).
	 */
	public function test_remote_shopify_execute_contracts(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Remote_Shopify_Connection();

		// list_connections with no configured connections.
		$listing = $tool->execute( array( 'action' => 'list_connections' ), array( 'user_id' => $user_id ) );
		$this->assertIsArray( $listing );
		$this->assertSame( array(), $listing['connections'] );
		$this->assertSame( 0, $listing['count'] );
		$this->assertArrayHasKey( 'hint', $listing );

		// Unknown actions are rejected.
		$invalid = $tool->execute( array( 'action' => 'nope' ), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $invalid );
		$this->assertSame( 'wp_mcp_ai_shopify_invalid_action', $invalid->get_error_code() );

		// The capability gate rejects subscribers.
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$forbidden     = $tool->execute( array( 'action' => 'list_connections' ), array( 'user_id' => $subscriber_id ) );
		$this->assertInstanceOf( 'WP_Error', $forbidden );
		$this->assertSame( 'wp_mcp_ai_shopify_forbidden', $forbidden->get_error_code() );
	}

	/**
	 * Standalone only: the init's tool filter must carry the two ported
	 * tools with the addon's file paths.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the remote-connections tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/remote-connections/init.php';
		// Re-arm the filter added inside the init's guard block — the WP test
		// framework may have restored an earlier hook snapshot.
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_remote_connections_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Remote_WP_Connection', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/remote-connections/class-wp-mcp-ai-tool-remote-wp-connection.php',
			$tools['WP_MCP_AI_Tool_Remote_WP_Connection']
		);
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Remote_Shopify_Connection', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/remote-connections/class-wp-mcp-ai-tool-remote-shopify-connection.php',
			$tools['WP_MCP_AI_Tool_Remote_Shopify_Connection']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register both tools
	 * into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/remote-connections/init.php';
		wp_mcp_ai_pro_register_remote_connections_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['remote_wp_connection'] ?? null );
		$this->assertNotNull( $parent->all()['remote_shopify_connection'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'remote_wp_connection' ) );
		$this->assertTrue( $core_tools->has( 'remote_shopify_connection' ) );
	}
}
