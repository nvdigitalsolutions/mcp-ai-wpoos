<?php
/**
 * Tests for Shopify Connection Resolver
 *
 * Tests the automatic resolution of Shopify connection IDs from
 * assistant context, the new remote_shopify_connection tool, and
 * the updated Shopify tool schemas.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Shopify Connection Resolver.
 */
class Test_Shopify_Connection_Resolver extends WP_UnitTestCase {

	/**
	 * Remote Shopify connection tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Remote_Shopify_Connection
	 */
	protected $tool;

	/**
	 * Shopify Products tool instance.
	 *
	 * @var WP_MCP_AI_Pro_Tool_Shopify_Products
	 */
	protected $products_tool;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Test Shopify connection ID.
	 *
	 * @var string
	 */
	protected $connection_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/trait-wp-mcp-ai-shopify-connection-resolver.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/remote-connections/class-wp-mcp-ai-tool-remote-shopify-connection.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-products.php';

		$this->tool          = new WP_MCP_AI_Tool_Remote_Shopify_Connection();
		$this->products_tool = new WP_MCP_AI_Pro_Tool_Shopify_Products();

		// Create test assistant.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Shopify Assistant',
				'post_status' => 'publish',
			)
		);

		// Create test Shopify connection.
		$this->connection_id = $this->create_shopify_connection( 'Test Shopify Store' );

		// Enable connection for assistant.
		update_post_meta(
			$this->assistant_id,
			'_wp_mcp_ai_pro_remote_connections',
			array( $this->connection_id )
		);
	}

	/**
	 * Create a test Shopify connection.
	 *
	 * @param string $name    Connection name.
	 * @param string $api_mode API mode (admin_api or catalog_api).
	 * @return string Connection ID.
	 */
	protected function create_shopify_connection( $name = 'Test Shopify', $api_mode = 'admin_api' ) {
		$connection_data = array(
			'name'             => $name,
			'url'              => 'https://test-store.myshopify.com',
			'connection_type'  => 'shopify',
			'shopify_api_mode' => $api_mode,
			'auth_type'        => 'none',
			'api_key'          => 'shpat_test_token_' . wp_generate_password( 16, false ),
			'enabled'          => true,
		);

		// Catalog API mode also needs api_secret.
		if ( 'catalog_api' === $api_mode ) {
			$connection_data['api_secret'] = 'shpss_test_secret_' . wp_generate_password( 16, false );
		}

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		return $connection_id;
	}

	// ─── remote_shopify_connection tool tests ────────────────────────────

	/**
	 * Test that list_connections returns Shopify connections.
	 */
	public function test_list_connections_returns_shopify_connections() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$arguments = array( 'action' => 'list_connections' );
		$context   = array(
			'assistant_id' => $this->assistant_id,
			'user_id'      => $admin_user,
		);

		$result = $this->tool->execute( $arguments, $context );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'connections', $result );
		$this->assertArrayHasKey( 'count', $result );
		$this->assertGreaterThanOrEqual( 1, $result['count'] );

		// Check that our connection is in the list.
		$found = false;
		foreach ( $result['connections'] as $conn ) {
			if ( $conn['id'] === $this->connection_id ) {
				$found = true;
				$this->assertEquals( 'Test Shopify Store', $conn['name'] );
				break;
			}
		}
		$this->assertTrue( $found, 'Test connection should be in list_connections results.' );
	}

	/**
	 * Test that list_connections filters by assistant.
	 */
	public function test_list_connections_filters_by_assistant() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Create a second Shopify connection NOT enabled for the assistant.
		$other_id = $this->create_shopify_connection( 'Other Shopify Store' );

		$arguments = array( 'action' => 'list_connections' );
		$context   = array(
			'assistant_id' => $this->assistant_id,
			'user_id'      => $admin_user,
		);

		$result = $this->tool->execute( $arguments, $context );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		// Only the connection enabled for the assistant should appear.
		$this->assertEquals( 1, $result['count'] );
		$this->assertEquals( $this->connection_id, $result['connections'][0]['id'] );

		// Cleanup extra connection.
		WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $other_id );
	}

	/**
	 * Test tool slug.
	 */
	public function test_tool_slug() {
		$this->assertEquals( 'remote_shopify_connection', $this->tool->get_slug() );
	}

	/**
	 * Test tool schema.
	 */
	public function test_tool_schema_structure() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertArrayHasKey( 'connection_id', $schema['properties'] );
		$this->assertContains( 'action', $schema['required'] );
		// connection_id should NOT be required.
		$this->assertNotContains( 'connection_id', $schema['required'] );
	}

	// ─── Shopify Products tool auto-resolution tests ─────────────────────

	/**
	 * Test that connection_id is no longer required in Shopify Products schema.
	 */
	public function test_products_schema_connection_id_not_required() {
		$schema = $this->products_tool->get_parameters_schema();

		$this->assertContains( 'action', $schema['required'] );
		$this->assertNotContains( 'connection_id', $schema['required'] );
	}

	/**
	 * Test that products tool schema mentions auto-resolution.
	 */
	public function test_products_schema_mentions_auto_resolution() {
		$schema = $this->products_tool->get_parameters_schema();

		$description = $schema['properties']['connection_id']['description'];
		$this->assertStringContainsString( 'automatically', $description );
	}

	/**
	 * Test auto-resolution with single Shopify connection.
	 *
	 * When only one Shopify connection is available for the assistant,
	 * the tool should auto-resolve it without requiring connection_id.
	 *
	 * Note: This test does NOT make actual Shopify API calls. It verifies
	 * the connection resolution works by checking the tool gets past the
	 * connection validation step (it will fail at the API call, not at
	 * "missing connection").
	 */
	public function test_auto_resolution_single_connection() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Call without connection_id — should auto-resolve.
		$arguments = array( 'action' => 'list' );
		$context   = array(
			'assistant_id' => $this->assistant_id,
			'user_id'      => $admin_user,
		);

		$result = $this->products_tool->execute( $arguments, $context );

		// The tool should NOT return "missing connection" error.
		// It may return a different error (e.g., from Shopify API call failure)
		// but NOT the connection resolution error.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals( 'wp_mcp_ai_shopify_missing_connection', $result->get_error_code() );
		}
	}

	/**
	 * Test that explicit connection_id is still respected.
	 */
	public function test_explicit_connection_id_used() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$arguments = array(
			'action'        => 'list',
			'connection_id' => $this->connection_id,
		);
		$context   = array(
			'assistant_id' => $this->assistant_id,
			'user_id'      => $admin_user,
		);

		$result = $this->products_tool->execute( $arguments, $context );

		// Should not fail with connection resolution errors.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals( 'wp_mcp_ai_shopify_missing_connection', $result->get_error_code() );
			$this->assertNotEquals( 'wp_mcp_ai_shopify_connection_not_found', $result->get_error_code() );
		}
	}

	/**
	 * Test error when multiple Shopify connections and no connection_id.
	 */
	public function test_error_multiple_connections_no_id() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Create a second Shopify connection and enable it for the assistant.
		$second_id = $this->create_shopify_connection( 'Second Shopify Store' );
		update_post_meta(
			$this->assistant_id,
			'_wp_mcp_ai_pro_remote_connections',
			array( $this->connection_id, $second_id )
		);

		$arguments = array( 'action' => 'list' );
		$context   = array(
			'assistant_id' => $this->assistant_id,
			'user_id'      => $admin_user,
		);

		$result = $this->products_tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_shopify_missing_connection', $result->get_error_code() );
		// Error message should list available connections.
		$this->assertStringContainsString( 'Available Shopify connections', $result->get_error_message() );

		// Cleanup.
		WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $second_id );
	}

	/**
	 * Test error when no Shopify connections exist.
	 */
	public function test_error_no_shopify_connections() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Remove the Shopify connection.
		WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $this->connection_id );

		$arguments = array( 'action' => 'list' );
		$context   = array(
			'assistant_id' => $this->assistant_id,
			'user_id'      => $admin_user,
		);

		$result = $this->products_tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_shopify_missing_connection', $result->get_error_code() );

		// Recreate for other tests.
		$this->connection_id = $this->create_shopify_connection( 'Test Shopify Store' );
		update_post_meta(
			$this->assistant_id,
			'_wp_mcp_ai_pro_remote_connections',
			array( $this->connection_id )
		);
	}

	/**
	 * Test that invalid explicit connection_id returns error with available list.
	 */
	public function test_invalid_connection_id_shows_available() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$arguments = array(
			'action'        => 'list',
			'connection_id' => 'conn_nonexistent',
		);
		$context   = array(
			'assistant_id' => $this->assistant_id,
			'user_id'      => $admin_user,
		);

		$result = $this->products_tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_shopify_connection_not_found', $result->get_error_code() );
		// Should include available connections in error message.
		$this->assertStringContainsString( 'Available Shopify connections', $result->get_error_message() );
	}

	/**
	 * Test that connection not enabled for assistant returns proper error.
	 */
	public function test_connection_not_enabled_for_assistant() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Create a second connection NOT enabled for assistant.
		$other_id = $this->create_shopify_connection( 'Unauthorized Store' );

		$arguments = array(
			'action'        => 'list',
			'connection_id' => $other_id,
		);
		$context   = array(
			'assistant_id' => $this->assistant_id,
			'user_id'      => $admin_user,
		);

		$result = $this->products_tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_shopify_not_enabled', $result->get_error_code() );

		// Cleanup.
		WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $other_id );
	}

	/**
	 * Test that disabled connection returns proper error.
	 */
	public function test_disabled_connection_error() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Create a disabled Shopify connection.
		$disabled_data = array(
			'name'             => 'Disabled Store',
			'url'              => 'https://disabled.myshopify.com',
			'connection_type'  => 'shopify',
			'shopify_api_mode' => 'admin_api',
			'auth_type'        => 'none',
			'api_key'          => 'shpat_disabled_token_test',
			'enabled'          => false,
		);
		$disabled_id   = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $disabled_data );

		// Enable it for assistant.
		update_post_meta(
			$this->assistant_id,
			'_wp_mcp_ai_pro_remote_connections',
			array( $this->connection_id, $disabled_id )
		);

		$arguments = array(
			'action'        => 'list',
			'connection_id' => $disabled_id,
		);
		$context   = array(
			'assistant_id' => $this->assistant_id,
			'user_id'      => $admin_user,
		);

		$result = $this->products_tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_shopify_disabled', $result->get_error_code() );

		// Cleanup.
		WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $disabled_id );
	}

	// ─── Capability flag tests ────────────────────────────────────────────

	/**
	 * Test that remote_shopify_connection has proper capability flags.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'external-api', $flags );
	}

	/**
	 * Clean up test environment.
	 */
	public function tearDown(): void {
		// Delete test connection.
		if ( $this->connection_id ) {
			WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $this->connection_id );
		}

		// Delete test assistant.
		if ( $this->assistant_id ) {
			wp_delete_post( $this->assistant_id, true );
		}

		// Clean up all connections.
		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		parent::tearDown();
	}
}
