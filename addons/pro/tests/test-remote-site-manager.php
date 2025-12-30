<?php
/**
 * Tests for Remote WordPress/WooCommerce Connection Manager
 *
 * @package WP_MCP_AI_Pro
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

/**
 * Test remote site connection manager functionality.
 */
class Test_Remote_Site_Manager extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		
		// Clear any existing connections.
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up test connections.
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
		
		parent::tearDown();
	}

	/**
	 * Test that remote site manager class exists.
	 */
	public function test_remote_site_manager_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) );
	}

	/**
	 * Test saving a connection.
	 */
	public function test_save_connection() {
		$connection_data = array(
			'name'            => 'Test Site',
			'url'             => 'https://example.com',
			'auth_type'       => 'none',
			'has_woocommerce' => false,
			'enabled'         => true,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
		$this->assertStringStartsWith( 'conn_', $result );
	}

	/**
	 * Test saving a connection with application password auth.
	 */
	public function test_save_connection_with_auth() {
		$connection_data = array(
			'name'            => 'Test Site with Auth',
			'url'             => 'https://example.com',
			'auth_type'       => 'application_password',
			'username'        => 'testuser',
			'password'        => 'test_password_123',
			'has_woocommerce' => true,
			'enabled'         => true,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotWPError( $result );
		
		// Verify connection was saved.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );
		$this->assertIsArray( $connection );
		$this->assertEquals( 'Test Site with Auth', $connection['name'] );
		$this->assertEquals( 'application_password', $connection['auth_type'] );
		$this->assertTrue( $connection['has_woocommerce'] );
		
		// Verify password is encrypted.
		$this->assertNotEquals( 'test_password_123', $connection['password'] );
	}

	/**
	 * Test validation of missing name.
	 */
	public function test_validation_missing_name() {
		$connection_data = array(
			'url'       => 'https://example.com',
			'auth_type' => 'none',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_pro_missing_name', $result->get_error_code() );
	}

	/**
	 * Test validation of missing URL.
	 */
	public function test_validation_missing_url() {
		$connection_data = array(
			'name'      => 'Test Site',
			'auth_type' => 'none',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_pro_missing_url', $result->get_error_code() );
	}

	/**
	 * Test validation of invalid URL.
	 */
	public function test_validation_invalid_url() {
		$connection_data = array(
			'name'      => 'Test Site',
			'url'       => 'not-a-valid-url',
			'auth_type' => 'none',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_pro_invalid_url', $result->get_error_code() );
	}

	/**
	 * Test validation of missing credentials for application password auth.
	 */
	public function test_validation_missing_credentials() {
		$connection_data = array(
			'name'      => 'Test Site',
			'url'       => 'https://example.com',
			'auth_type' => 'application_password',
			'username'  => 'testuser',
			// Missing password.
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_pro_missing_credentials', $result->get_error_code() );
	}

	/**
	 * Test getting all connections.
	 */
	public function test_get_all_connections() {
		// Save two connections.
		$connection1 = array(
			'name'      => 'Site 1',
			'url'       => 'https://site1.com',
			'auth_type' => 'none',
			'enabled'   => true,
		);

		$connection2 = array(
			'name'      => 'Site 2',
			'url'       => 'https://site2.com',
			'auth_type' => 'none',
			'enabled'   => false,
		);

		$id1 = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection1 );
		$id2 = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection2 );

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		$this->assertIsArray( $connections );
		$this->assertCount( 2, $connections );
		$this->assertArrayHasKey( $id1, $connections );
		$this->assertArrayHasKey( $id2, $connections );
	}

	/**
	 * Test deleting a connection.
	 */
	public function test_delete_connection() {
		// Create a connection.
		$connection_data = array(
			'name'      => 'Test Site',
			'url'       => 'https://example.com',
			'auth_type' => 'none',
			'enabled'   => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		// Verify it exists.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertIsArray( $connection );

		// Delete it.
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $connection_id );
		$this->assertTrue( $result );

		// Verify it no longer exists.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNull( $connection );
	}

	/**
	 * Test updating a connection.
	 */
	public function test_update_connection() {
		// Create a connection.
		$connection_data = array(
			'name'      => 'Test Site',
			'url'       => 'https://example.com',
			'auth_type' => 'none',
			'enabled'   => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		// Update it.
		$updated_data = array(
			'id'        => $connection_id,
			'name'      => 'Updated Test Site',
			'url'       => 'https://updated-example.com',
			'auth_type' => 'none',
			'enabled'   => false,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $updated_data );
		$this->assertEquals( $connection_id, $result );

		// Verify changes.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertEquals( 'Updated Test Site', $connection['name'] );
		$this->assertEquals( 'https://updated-example.com/', $connection['url'] );
		$this->assertFalse( $connection['enabled'] );
	}

	/**
	 * Test encryption and decryption of sensitive data.
	 */
	public function test_encryption_decryption() {
		$connection_data = array(
			'name'      => 'Test Site',
			'url'       => 'https://example.com',
			'auth_type' => 'basic_auth',
			'username'  => 'testuser',
			'password'  => 'my_secret_password_123',
			'enabled'   => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		// Password should be encrypted in storage.
		$this->assertNotEquals( 'my_secret_password_123', $connection['password'] );
		
		// Verify encryption produces base64 string.
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9+\/=]+$/', $connection['password'] );
	}

	/**
	 * Test build API URL method.
	 */
	public function test_build_api_url() {
		// Test WordPress REST API URL.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Remote_Site_Manager' );
		$method = $reflection->getMethod( 'build_api_url' );
		$method->setAccessible( true );

		$url = $method->invokeArgs( null, array( 'https://example.com', 'wp/v2/posts' ) );
		$this->assertEquals( 'https://example.com/wp-json/wp/v2/posts', $url );

		// Test WooCommerce REST API URL.
		$wc_url = $method->invokeArgs( null, array( 'https://example.com', 'wc/v3/products' ) );
		$this->assertEquals( 'https://example.com/wp-json/wc/v3/products', $wc_url );
	}
}
