<?php
/**
 * FlowHub Tools Tests.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

/**
 * Test class for FlowHub Tools.
 */
class Test_FlowHub_Tools extends WP_UnitTestCase {

	protected $admin_user_id;
	protected $subscriber_user_id;

	public function setUp(): void {
		parent::setUp();
		$this->admin_user_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		update_option( 'wp_mcp_ai_flowhub_toolkit_settings', array(
			'client_id'    => 'test_client',
			'api_key'      => 'test_key',
			'sync_interval' => 15,
		) );
	}

	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_flowhub_toolkit_settings' );
		parent::tearDown();
	}

	// ------------------------------------------------------------------ //
	// Inventory Tool
	// ------------------------------------------------------------------ //

	public function test_inventory_tool_slug() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_FlowHub_Inventory' ) ) {
			$this->markTestSkipped( 'Tool not loaded.' );
		}
		$tool = new WP_MCP_AI_Pro_Tool_FlowHub_Inventory();
		$this->assertEquals( 'flowhub_inventory', $tool->get_slug() );
	}

	public function test_inventory_tool_capability() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_FlowHub_Inventory' ) ) {
			$this->markTestSkipped( 'Tool not loaded.' );
		}
		$tool = new WP_MCP_AI_Pro_Tool_FlowHub_Inventory();
		$this->assertEquals( 'manage_woocommerce', $tool->get_required_capability() );
	}

	public function test_inventory_tool_denies_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_FlowHub_Inventory' ) ) {
			$this->markTestSkipped( 'Tool not loaded.' );
		}
		$tool   = new WP_MCP_AI_Pro_Tool_FlowHub_Inventory();
		$result = $tool->execute(
			array( 'action' => 'search' ),
			array( 'user_id' => $this->subscriber_user_id )
		);
		$this->assertWPError( $result );
	}

	public function test_inventory_tool_invalid_action() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_FlowHub_Inventory' ) ) {
			$this->markTestSkipped( 'Tool not loaded.' );
		}
		$tool   = new WP_MCP_AI_Pro_Tool_FlowHub_Inventory();
		$result = $tool->execute(
			array( 'action' => 'nonexistent' ),
			array( 'user_id' => $this->admin_user_id )
		);
		$this->assertWPError( $result );
	}

	// ------------------------------------------------------------------ //
	// Products Tool
	// ------------------------------------------------------------------ //

	public function test_products_tool_slug() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_FlowHub_Products' ) ) {
			$this->markTestSkipped( 'Tool not loaded.' );
		}
		$tool = new WP_MCP_AI_Pro_Tool_FlowHub_Products();
		$this->assertEquals( 'flowhub_products', $tool->get_slug() );
	}

	public function test_products_tool_denies_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_FlowHub_Products' ) ) {
			$this->markTestSkipped( 'Tool not loaded.' );
		}
		$tool   = new WP_MCP_AI_Pro_Tool_FlowHub_Products();
		$result = $tool->execute(
			array( 'action' => 'search' ),
			array( 'user_id' => $this->subscriber_user_id )
		);
		$this->assertWPError( $result );
	}

	// ------------------------------------------------------------------ //
	// Locations Tool
	// ------------------------------------------------------------------ //

	public function test_locations_tool_slug() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_FlowHub_Locations' ) ) {
			$this->markTestSkipped( 'Tool not loaded.' );
		}
		$tool = new WP_MCP_AI_Pro_Tool_FlowHub_Locations();
		$this->assertEquals( 'flowhub_locations', $tool->get_slug() );
	}

	public function test_locations_tool_denies_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_FlowHub_Locations' ) ) {
			$this->markTestSkipped( 'Tool not loaded.' );
		}
		$tool   = new WP_MCP_AI_Pro_Tool_FlowHub_Locations();
		$result = $tool->execute(
			array( 'action' => 'list' ),
			array( 'user_id' => $this->subscriber_user_id )
		);
		$this->assertWPError( $result );
	}

	// ------------------------------------------------------------------ //
	// Sync Tool
	// ------------------------------------------------------------------ //

	public function test_sync_tool_slug() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_FlowHub_Sync' ) ) {
			$this->markTestSkipped( 'Tool not loaded.' );
		}
		$tool = new WP_MCP_AI_Pro_Tool_FlowHub_Sync();
		$this->assertEquals( 'flowhub_sync', $tool->get_slug() );
	}

	public function test_sync_tool_requires_manage_options() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_FlowHub_Sync' ) ) {
			$this->markTestSkipped( 'Tool not loaded.' );
		}
		$tool = new WP_MCP_AI_Pro_Tool_FlowHub_Sync();
		$this->assertEquals( 'manage_options', $tool->get_required_capability() );
	}

	// ------------------------------------------------------------------ //
	// Settings Tool
	// ------------------------------------------------------------------ //

	public function test_settings_tool_slug() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_FlowHub_Settings' ) ) {
			$this->markTestSkipped( 'Tool not loaded.' );
		}
		$tool = new WP_MCP_AI_Pro_Tool_FlowHub_Settings();
		$this->assertEquals( 'flowhub_settings', $tool->get_slug() );
	}

	public function test_settings_tool_canonical_envelope() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_FlowHub_Settings' ) ) {
			$this->markTestSkipped( 'Tool not loaded.' );
		}
		$tool   = new WP_MCP_AI_Pro_Tool_FlowHub_Settings();
		$result = $tool->execute(
			array( 'action' => 'get_settings' ),
			array( 'user_id' => $this->admin_user_id )
		);
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'data', $result );
	}

	// ------------------------------------------------------------------ //
	// Two-Gate Sanitisation
	// ------------------------------------------------------------------ //

	public function test_errors_return_wp_error_not_false_envelope() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_FlowHub_Sync' ) ) {
			$this->markTestSkipped( 'Tool not loaded.' );
		}
		$tool   = new WP_MCP_AI_Pro_Tool_FlowHub_Sync();
		$result = $tool->execute(
			array( 'action' => 'sync_now' ),
			array( 'user_id' => $this->subscriber_user_id )
		);
		$this->assertWPError( $result );
	}

	// ------------------------------------------------------------------ //
	// Live request proxy (base flowhub_get_inventory)
	// ------------------------------------------------------------------ //

	/**
	 * flowhub_get_inventory attaches the connection proxy during live requests.
	 */
	public function test_get_inventory_attaches_connection_proxy() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Flowhub_Get_Inventory' ) ) {
			$this->markTestSkipped( 'Tool not loaded.' );
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro Remote Site Manager not available' );
		}

		delete_option( 'wp_mcp_ai_flowhub_toolkit_settings' );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		remove_all_filters( 'http_api_curl' );

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Proxied FlowHub',
				'url'             => 'https://api.flowhub.co',
				'connection_type' => 'flowhub',
				'auth_type'       => 'none',
				'client_id'       => 'test_client',
				'api_key'         => 'test_key',
				'enabled'         => true,
				'proxy_enabled'   => true,
				'proxy_url'       => 'http://proxy.local:3128',
			)
		);

		$proxy_hook_attached = false;
		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$proxy_hook_attached ) {
				$proxy_hook_attached = false !== has_filter( 'http_api_curl' );

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'data' => array() ) ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

		$tool   = new WP_MCP_AI_Tool_Flowhub_Get_Inventory();
		$result = $tool->execute(
			array( 'connection_id' => $connection_id ),
			array( 'user_id' => $this->admin_user_id )
		);

		remove_all_filters( 'pre_http_request' );
		WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $connection_id );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		$this->assertIsArray( $result, 'The mocked request should succeed.' );
		$this->assertTrue( $proxy_hook_attached, 'The proxy must be attached while the request runs.' );
		$this->assertFalse( has_filter( 'http_api_curl' ), 'The proxy must be removed after the request.' );
	}
}
