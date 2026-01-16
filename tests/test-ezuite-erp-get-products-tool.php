<?php
/**
 * Tests for WP_MCP_AI_Tool_EZuite_ERP_Get_Products class.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test cases for the EZuite ERP Get Products tool.
 *
 * @group tools
 * @group ezuite-erp
 * @group pro-tools
 */
class WP_MCP_AI_Tool_EZuite_ERP_Get_Products_Test extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_EZuite_ERP_Get_Products
	 */
	protected $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Check if WP_MCP_AI_PRO_PATH constant is defined.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			// Fallback: calculate path from WP_MCP_AI_PATH.
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$pro_path = realpath( WP_MCP_AI_PATH . '../addons/pro/' );
				if ( $pro_path ) {
					define( 'WP_MCP_AI_PRO_PATH', trailingslashit( $pro_path ) );
				} else {
					$this->markTestSkipped( 'Pro addon path not found.' );
					return;
				}
			} else {
				$this->markTestSkipped( 'WP_MCP_AI_PATH or WP_MCP_AI_PRO_PATH not defined.' );
				return;
			}
		}

		// Load the pro tool file.
		$pro_tool_path = WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-ezuite-erp-get-products.php';
		if ( ! file_exists( $pro_tool_path ) ) {
			$this->markTestSkipped( 'Pro addon not available. EZuite ERP Get Products tool is a pro tool.' );
			return;
		}

		// Load remote site manager dependency.
		$remote_manager_path = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		if ( file_exists( $remote_manager_path ) ) {
			require_once $remote_manager_path;
		}

		require_once $pro_tool_path;

		$this->tool = new WP_MCP_AI_Tool_EZuite_ERP_Get_Products();

		$this->admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Test tool slug.
	 */
	public function test_get_slug() {
		$this->assertEquals( 'ezuite_erp_get_products', $this->tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_get_name() {
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertIsString( $this->tool->get_name() );
		$this->assertEquals( 'Get EZuite ERP Products', $this->tool->get_name() );
	}

	/**
	 * Test tool description.
	 */
	public function test_get_description() {
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertIsString( $this->tool->get_description() );
		$this->assertStringContainsString( 'EZuite ERP', $this->tool->get_description() );
		$this->assertStringContainsString( 'product', $this->tool->get_description() );
	}

	/**
	 * Test parameters schema contains required properties.
	 */
	public function test_get_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'connection_id', $schema['properties'] );
		$this->assertArrayHasKey( 'location_code', $schema['properties'] );
		$this->assertArrayHasKey( 'item_code', $schema['properties'] );
		$this->assertArrayHasKey( 'limit', $schema['properties'] );

		// Test required fields.
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'connection_id', $schema['required'] );

		// Test default values.
		$this->assertEquals( 'ALL', $schema['properties']['location_code']['default'] );
		$this->assertEquals( 10, $schema['properties']['limit']['default'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_get_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'network-dependent', $flags );
		$this->assertContains( 'rate-limited', $flags );
	}

	/**
	 * Test execution without permission.
	 */
	public function test_execute_without_permission() {
		wp_set_current_user( $this->subscriber_id );

		$result = $this->tool->execute(
			array( 'connection_id' => 'test_conn' ),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test missing connection_id error.
	 */
	public function test_missing_connection_id() {
		wp_set_current_user( $this->admin_id );

		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_pro_missing_connection', $result->get_error_code() );
	}

	/**
	 * Test invalid connection_id error.
	 */
	public function test_invalid_connection_id() {
		wp_set_current_user( $this->admin_id );

		$result = $this->tool->execute(
			array( 'connection_id' => 'invalid_connection' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_pro_connection_not_found', $result->get_error_code() );
	}

	/**
	 * Test wrong connection type error.
	 */
	public function test_wrong_connection_type() {
		wp_set_current_user( $this->admin_id );

		// Create a WordPress connection instead of EZuite ERP.
		$connection_id = $this->create_test_connection( 'wordpress' );

		$result = $this->tool->execute(
			array( 'connection_id' => $connection_id ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_pro_wrong_connection_type', $result->get_error_code() );
	}

	/**
	 * Test disabled connection error.
	 */
	public function test_disabled_connection() {
		wp_set_current_user( $this->admin_id );

		// Create a disabled connection.
		$connection_id = $this->create_test_connection( 'ezuite_erp', false );

		$result = $this->tool->execute(
			array( 'connection_id' => $connection_id ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_pro_connection_disabled', $result->get_error_code() );
	}

	/**
	 * Test rate limiting.
	 */
	public function test_rate_limiting() {
		wp_set_current_user( $this->admin_id );

		// Create a test connection.
		$connection_id = $this->create_test_connection();

		// Simulate reaching the rate limit by setting the transient.
		set_transient( 'wp_mcp_ai_pro_ezuite_erp_get_products_' . $this->admin_id, 30, MINUTE_IN_SECONDS );

		$result = $this->tool->execute(
			array( 'connection_id' => $connection_id ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_pro_rate_limit_exceeded', $result->get_error_code() );

		// Clean up.
		delete_transient( 'wp_mcp_ai_pro_ezuite_erp_get_products_' . $this->admin_id );
	}

	/**
	 * Test limit parameter validation.
	 */
	public function test_limit_parameter() {
		wp_set_current_user( $this->admin_id );

		$connection_id = $this->create_test_connection();

		// Test with limit over maximum (should be capped at 100).
		$schema = $this->tool->get_parameters_schema();
		$this->assertEquals( 100, $schema['properties']['limit']['maximum'] );
		$this->assertEquals( 1, $schema['properties']['limit']['minimum'] );
	}

	/**
	 * Test location code parameter.
	 */
	public function test_location_code_parameter() {
		wp_set_current_user( $this->admin_id );

		$schema = $this->tool->get_parameters_schema();

		// Verify location_code is a string parameter.
		$this->assertEquals( 'string', $schema['properties']['location_code']['type'] );
		$this->assertEquals( 'ALL', $schema['properties']['location_code']['default'] );
	}

	/**
	 * Helper method to create a test connection.
	 *
	 * @param string $connection_type Connection type (default: ezuite_erp).
	 * @param bool   $enabled         Whether the connection is enabled.
	 * @return string Connection ID.
	 */
	protected function create_test_connection( $connection_type = 'ezuite_erp', $enabled = true ) {
		$connections = get_option( 'wp_mcp_ai_pro_remote_sites', array() );

		$connection_id = 'test_conn_' . wp_generate_password( 8, false );

		$connections[ $connection_id ] = array(
			'id'              => $connection_id,
			'name'            => 'Test EZuite Connection',
			'url'             => 'https://api.ezuite.com/api/External_Api/Action_Api/Invoke',
			'connection_type' => $connection_type,
			'auth_type'       => 'none',
			'api_key'         => base64_encode( 'test-api-key-123' ), // Mock encrypted key.
			'enabled'         => $enabled,
			'created'         => current_time( 'mysql' ),
			'updated'         => current_time( 'mysql' ),
		);

		update_option( 'wp_mcp_ai_pro_remote_sites', $connections );

		return $connection_id;
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clean up test connections.
		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		parent::tearDown();
	}
}
