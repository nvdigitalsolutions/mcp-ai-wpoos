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
			'auth_type' => 'application_password',
			'username'  => 'testuser',
			'password'  => 'original_password',
			'enabled'   => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		// Update it without providing password (should keep existing).
		$updated_data = array(
			'id'        => $connection_id,
			'name'      => 'Updated Test Site',
			'url'       => 'https://updated-example.com',
			'auth_type' => 'application_password',
			'username'  => 'testuser',
			// Note: password intentionally omitted to test that existing password is preserved.
			'enabled'   => false,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $updated_data );
		$this->assertEquals( $connection_id, $result );

		// Verify changes.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertEquals( 'Updated Test Site', $connection['name'] );
		$this->assertEquals( 'https://updated-example.com/', $connection['url'] );
		$this->assertFalse( $connection['enabled'] );
		// Password should still be encrypted and present.
		$this->assertNotEmpty( $connection['password'] );
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
		$connection    = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

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
		$method     = $reflection->getMethod( 'build_api_url' );
		$method->setAccessible( true );

		$url = $method->invokeArgs( null, array( 'https://example.com', 'wp/v2/posts' ) );
		$this->assertEquals( 'https://example.com/wp-json/wp/v2/posts', $url );

		// Test WooCommerce REST API URL.
		$wc_url = $method->invokeArgs( null, array( 'https://example.com', 'wc/v3/products' ) );
		$this->assertEquals( 'https://example.com/wp-json/wc/v3/products', $wc_url );
	}

	/**
	 * Test saving connection with WooCommerce authentication.
	 */
	public function test_save_connection_with_woocommerce_auth() {
		$connection_data = array(
			'name'            => 'Test WooCommerce Site',
			'url'             => 'https://example.com',
			'auth_type'       => 'woocommerce',
			'consumer_key'    => 'ck_1234567890abcdef',
			'consumer_secret' => 'cs_1234567890abcdef',
			'has_woocommerce' => true,
			'enabled'         => true,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotWPError( $result );

		// Verify connection was saved.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );
		$this->assertIsArray( $connection );
		$this->assertEquals( 'Test WooCommerce Site', $connection['name'] );
		$this->assertEquals( 'woocommerce', $connection['auth_type'] );
		$this->assertTrue( $connection['has_woocommerce'] );

		// Verify consumer key and secret are encrypted.
		$this->assertNotEquals( 'ck_1234567890abcdef', $connection['consumer_key'] );
		$this->assertNotEquals( 'cs_1234567890abcdef', $connection['consumer_secret'] );
	}

	/**
	 * Test validation of missing WooCommerce credentials.
	 */
	public function test_validation_missing_woocommerce_credentials() {
		$connection_data = array(
			'name'         => 'Test Site',
			'url'          => 'https://example.com',
			'auth_type'    => 'woocommerce',
			'consumer_key' => 'ck_1234567890abcdef',
			// Missing consumer_secret.
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_pro_missing_wc_keys', $result->get_error_code() );
	}

	/**
	 * Test updating connection with WooCommerce auth keeps existing credentials.
	 */
	public function test_update_woocommerce_connection_preserves_credentials() {
		// Create a connection with WooCommerce auth.
		$connection_data = array(
			'name'            => 'Test WooCommerce Site',
			'url'             => 'https://example.com',
			'auth_type'       => 'woocommerce',
			'consumer_key'    => 'ck_original_key',
			'consumer_secret' => 'cs_original_secret',
			'has_woocommerce' => true,
			'enabled'         => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		// Update without providing credentials.
		$updated_data = array(
			'id'              => $connection_id,
			'name'            => 'Updated WooCommerce Site',
			'url'             => 'https://updated-example.com',
			'auth_type'       => 'woocommerce',
			// Note: consumer_key and consumer_secret intentionally omitted.
			'has_woocommerce' => true,
			'enabled'         => false,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $updated_data );
		$this->assertEquals( $connection_id, $result );

		// Verify credentials are still present.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertEquals( 'Updated WooCommerce Site', $connection['name'] );
		$this->assertNotEmpty( $connection['consumer_key'] );
		$this->assertNotEmpty( $connection['consumer_secret'] );
	}

	/**
	 * Test that Flowhub client can be loaded without fatal errors.
	 *
	 * This test verifies that WP_MCP_AI_Flowhub_Client properly loads its dependencies
	 * (WP_MCP_AI_Logger and WP_MCP_AI_HTTP) when instantiated.
	 */
	public function test_flowhub_client_loads_dependencies() {
		// Verify the Flowhub client file exists.
		$flowhub_client_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
		$this->assertFileExists( $flowhub_client_file );

		// Load the Flowhub client (this should load dependencies automatically).
		require_once $flowhub_client_file;

		// Verify all required classes are loaded.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Logger' ), 'WP_MCP_AI_Logger should be loaded' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_HTTP' ), 'WP_MCP_AI_HTTP should be loaded' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Flowhub_Client' ), 'WP_MCP_AI_Flowhub_Client should be loaded' );

		// Verify Flowhub client can be instantiated.
		$client = new WP_MCP_AI_Flowhub_Client();
		$this->assertInstanceOf( 'WP_MCP_AI_Flowhub_Client', $client );
	}

	/**
	 * Test that PayHere client can be loaded without fatal errors.
	 *
	 * This test verifies that WP_MCP_AI_PayHere_Client properly loads its dependencies
	 * (WP_MCP_AI_Logger and WP_MCP_AI_HTTP) when instantiated.
	 */
	public function test_payhere_client_loads_dependencies() {
		// Verify the PayHere client file exists.
		$payhere_client_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-payhere-client.php';
		$this->assertFileExists( $payhere_client_file );

		// Load the PayHere client (this should load dependencies automatically).
		require_once $payhere_client_file;

		// Verify all required classes are loaded.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Logger' ), 'WP_MCP_AI_Logger should be loaded' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_HTTP' ), 'WP_MCP_AI_HTTP should be loaded' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_PayHere_Client' ), 'WP_MCP_AI_PayHere_Client should be loaded' );

		// Verify PayHere client can be instantiated.
		$client = new WP_MCP_AI_PayHere_Client();
		$this->assertInstanceOf( 'WP_MCP_AI_PayHere_Client', $client );
	}

	/**
	 * Test that EZuite ERP connection type is handled correctly in test_connection.
	 *
	 * This test verifies that the test_connection method properly routes EZuite ERP
	 * connections to the test_ezuite_connection handler instead of attempting to
	 * test WordPress REST API endpoints.
	 */
	public function test_ezuite_connection_type_routing() {
		// Create an EZuite ERP connection.
		$connection_data = array(
			'name'            => 'Test EZuite Connection',
			'url'             => 'https://api.ezuite.com/api/External_Api/Action_Api/Invoke',
			'connection_type' => 'ezuite_erp',
			'auth_type'       => 'none',
			'api_key'         => 'test-api-key-123',
			'enabled'         => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotWPError( $connection_id );

		// Retrieve the connection.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertIsArray( $connection );
		$this->assertEquals( 'ezuite_erp', $connection['connection_type'] );

		// Call test_connection with the EZuite connection.
		// We expect it to fail with connection error (not 404) since we're using a fake API key.
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection_id );

		// The result should be a WP_Error, but NOT a wp_mcp_ai_pro_invalid_connection error.
		// It should be a connection failure or API error (which proves the routing worked).
		$this->assertWPError( $result );
		$this->assertNotEquals( 'wp_mcp_ai_pro_invalid_connection', $result->get_error_code() );

		// The error should be one of the EZuite-specific errors.
		$valid_error_codes = array(
			'wp_mcp_ai_pro_connection_failed',
			'wp_mcp_ai_pro_api_error',
			'wp_mcp_ai_pro_invalid_response',
			'wp_mcp_ai_pro_ezuite_error',
		);

		$this->assertContains(
			$result->get_error_code(),
			$valid_error_codes,
			'Expected an EZuite-specific error code, got: ' . $result->get_error_code()
		);
	}

	/**
	 * Test saving a Gmail connection with all fields.
	 */
	public function test_save_gmail_connection_with_all_fields() {
		$connection_data = array(
			'name'            => 'Test Gmail Connection',
			'url'             => 'https://gmail.googleapis.com',
			'connection_type' => 'gmail',
			'auth_type'       => 'none',
			'client_id'       => 'test_client_id_123.apps.googleusercontent.com',
			'client_secret'   => 'test_client_secret_xyz',
			'refresh_token'   => 'test_refresh_token_abc',
			'user_email'      => 'test@gmail.com',
			'enabled'         => true,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
		$this->assertStringStartsWith( 'conn_', $result );

		// Retrieve the saved connection.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertIsArray( $connection );
		$this->assertEquals( 'Test Gmail Connection', $connection['name'] );
		$this->assertEquals( 'gmail', $connection['connection_type'] );
		$this->assertEquals( 'test_client_id_123.apps.googleusercontent.com', $connection['client_id'] );
		$this->assertEquals( 'test@gmail.com', $connection['user_email'] );

		// Verify sensitive fields are encrypted.
		$this->assertNotEquals( 'test_client_secret_xyz', $connection['client_secret'] );
		$this->assertNotEmpty( $connection['client_secret'] );
		$this->assertNotEquals( 'test_refresh_token_abc', $connection['refresh_token'] );
		$this->assertNotEmpty( $connection['refresh_token'] );
	}

	/**
	 * Test updating Gmail connection preserves existing client_id and secret when not provided.
	 */
	public function test_update_gmail_connection_preserves_existing_values() {
		// First, create a Gmail connection.
		$connection_data = array(
			'name'            => 'Gmail Connection',
			'url'             => 'https://gmail.googleapis.com',
			'connection_type' => 'gmail',
			'auth_type'       => 'none',
			'client_id'       => 'original_client_id.apps.googleusercontent.com',
			'client_secret'   => 'original_client_secret',
			'refresh_token'   => 'original_refresh_token',
			'user_email'      => 'original@gmail.com',
			'enabled'         => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotWPError( $connection_id );

		// Now update the connection without providing client_secret and refresh_token.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => 'Updated Gmail Connection',
			'url'             => 'https://gmail.googleapis.com',
			'connection_type' => 'gmail',
			'auth_type'       => 'none',
			'client_id'       => 'updated_client_id.apps.googleusercontent.com',
			'client_secret'   => '', // Empty - should preserve existing.
			'refresh_token'   => '', // Empty - should preserve existing.
			'user_email'      => 'updated@gmail.com',
			'enabled'         => true,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );
		$this->assertNotWPError( $result );
		$this->assertEquals( $connection_id, $result );

		// Retrieve the updated connection.
		$updated_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		// Verify that name, client_id, and user_email were updated.
		$this->assertEquals( 'Updated Gmail Connection', $updated_connection['name'] );
		$this->assertEquals( 'updated_client_id.apps.googleusercontent.com', $updated_connection['client_id'] );
		$this->assertEquals( 'updated@gmail.com', $updated_connection['user_email'] );

		// Verify that client_secret and refresh_token were preserved (still encrypted).
		$this->assertNotEmpty( $updated_connection['client_secret'] );
		$this->assertNotEquals( 'original_client_secret', $updated_connection['client_secret'] );
		$this->assertNotEmpty( $updated_connection['refresh_token'] );
		$this->assertNotEquals( 'original_refresh_token', $updated_connection['refresh_token'] );
	}
}
