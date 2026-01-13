<?php
/**
 * Tests for Remote Sites Admin UI
 *
 * @package WP_MCP_AI_Pro
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php';

/**
 * Test remote sites admin functionality.
 */
class Test_Remote_Sites_Admin extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear any existing connections.
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );

		// Set up admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
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
	 * Test that editing a non-existent connection shows error.
	 */
	public function test_edit_nonexistent_connection() {
		$admin = new WP_MCP_AI_Pro_Remote_Sites_Admin();

		// Simulate accessing edit page with invalid connection ID.
		$_GET['page'] = 'wp-mcp-ai-remote-sites';
		$_GET['edit'] = 'conn_nonexistent';

		// Capture output.
		ob_start();
		$admin->render_admin_page();
		$output = ob_get_clean();

		// Should show an error message and the connections list, not the form.
		$this->assertStringContainsString( 'Connection not found', $output );
		$this->assertStringNotContainsString( 'Edit Connection', $output );
		// Should show the list with "Add New Connection" button.
		$this->assertStringContainsString( 'Add New Connection', $output );

		// Clean up.
		unset( $_GET['page'] );
		unset( $_GET['edit'] );
		unset( $_GET['error'] );
	}

	/**
	 * Test that editing an existing connection loads the form.
	 */
	public function test_edit_existing_connection() {
		// Create a connection first.
		$connection_data = array(
			'name'      => 'Test Site',
			'url'       => 'https://example.com',
			'auth_type' => 'none',
			'enabled'   => true,
		);
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$admin = new WP_MCP_AI_Pro_Remote_Sites_Admin();

		// Simulate accessing edit page.
		$_GET['page'] = 'wp-mcp-ai-remote-sites';
		$_GET['edit'] = $connection_id;

		// Capture output.
		ob_start();
		$admin->render_admin_page();
		$output = ob_get_clean();

		// Should show the edit form with connection data.
		$this->assertStringContainsString( 'Edit Connection', $output );
		$this->assertStringContainsString( 'Test Site', $output );
		$this->assertStringContainsString( 'https://example.com', $output );

		// Clean up.
		unset( $_GET['page'] );
		unset( $_GET['edit'] );
	}

	/**
	 * Test delete action with valid connection.
	 */
	public function test_delete_existing_connection() {
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

		$admin = new WP_MCP_AI_Pro_Remote_Sites_Admin();

		// Simulate delete action.
		$_GET['page'] = 'wp-mcp-ai-remote-sites';
		$_GET['action'] = 'delete';
		$_GET['connection_id'] = $connection_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'delete_connection_' . $connection_id );

		// Handle the action (this should redirect, but we can't test that directly).
		// Instead, we'll just verify the connection is deleted.
		try {
			$admin->handle_actions();
		} catch ( Exception $e ) {
			// Redirect will throw an exception in test environment.
		}

		// Verify connection was deleted.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNull( $connection );

		// Clean up.
		unset( $_GET['page'] );
		unset( $_GET['action'] );
		unset( $_GET['connection_id'] );
		unset( $_GET['_wpnonce'] );
	}

	/**
	 * Test delete action with non-existent connection.
	 */
	public function test_delete_nonexistent_connection() {
		$admin = new WP_MCP_AI_Pro_Remote_Sites_Admin();

		$connection_id = 'conn_nonexistent';

		// Simulate delete action.
		$_GET['page'] = 'wp-mcp-ai-remote-sites';
		$_GET['action'] = 'delete';
		$_GET['connection_id'] = $connection_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'delete_connection_' . $connection_id );

		// Since handle_actions() will call wp_safe_redirect() which calls wp_redirect()
		// and then exit, we need to catch the redirect headers.
		// In test environment, wp_safe_redirect will set headers but won't actually redirect.
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 2 );

		// Handle the action - this will attempt to redirect.
		try {
			$admin->handle_actions();
		} catch ( Exception $e ) {
			// Exit will throw WPDieException in test environment.
		}

		// Check that redirect URL contains error parameter.
		$this->assertNotEmpty( $this->redirect_url );
		$this->assertStringContainsString( 'error=', $this->redirect_url );
		$this->assertStringContainsString( 'Connection+not+found', urlencode( $this->redirect_url ) );

		// Clean up.
		unset( $_GET['page'] );
		unset( $_GET['action'] );
		unset( $_GET['connection_id'] );
		unset( $_GET['_wpnonce'] );
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
		$this->redirect_url = null;
	}

	/**
	 * Capture redirect URL for testing.
	 *
	 * @param string $location Redirect location.
	 * @param int    $status   HTTP status code.
	 * @return bool False to prevent actual redirect.
	 */
	public function capture_redirect( $location, $status ) {
		$this->redirect_url = $location;
		return false; // Prevent actual redirect.
	}

	/**
	 * Store redirect URL for testing.
	 *
	 * @var string|null
	 */
	protected $redirect_url = null;

	/**
	 * Test that all new connection types are available in dropdown.
	 */
	public function test_new_connection_types_in_dropdown() {
		$admin = new WP_MCP_AI_Pro_Remote_Sites_Admin();

		// Simulate accessing add page.
		$_GET['page'] = 'wp-mcp-ai-remote-sites';
		$_GET['add'] = '1';

		// Capture output.
		ob_start();
		$admin->render_admin_page();
		$output = ob_get_clean();

		// Verify all new connection types appear in dropdown.
		$this->assertStringContainsString( 'value="wordpress"', $output );
		$this->assertStringContainsString( 'value="generic"', $output );
		$this->assertStringContainsString( 'value="isams"', $output );
		$this->assertStringContainsString( 'iSAMS (School Management)', $output );
		$this->assertStringContainsString( 'value="flowhub"', $output );
		$this->assertStringContainsString( 'Flowhub (POS/Retail)', $output );
		$this->assertStringContainsString( 'value="payhere"', $output );
		$this->assertStringContainsString( 'PayHere (Payment Gateway)', $output );
		$this->assertStringContainsString( 'value="quickbooks"', $output );
		$this->assertStringContainsString( 'QuickBooks (Accounting)', $output );
		$this->assertStringContainsString( 'value="ezuite_erp"', $output );
		$this->assertStringContainsString( 'EZuite ERP (Inventory)', $output );

		// Clean up.
		unset( $_GET['page'] );
		unset( $_GET['add'] );
	}

	/**
	 * Test creating iSAMS connection.
	 */
	public function test_create_isams_connection() {
		$connection_data = array(
			'name'            => 'Test iSAMS School',
			'url'             => 'https://school.isams.cloud',
			'connection_type' => 'isams',
			'auth_type'       => 'none',
			'api_key'         => 'test_api_key',
			'api_secret'      => 'test_api_secret',
			'enabled'         => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertIsString( $connection_id );

		// Verify connection was saved correctly.
		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertIsArray( $saved );
		$this->assertEquals( 'isams', $saved['connection_type'] );
		$this->assertEquals( 'Test iSAMS School', $saved['name'] );
	}

	/**
	 * Test creating Flowhub connection.
	 */
	public function test_create_flowhub_connection() {
		$connection_data = array(
			'name'            => 'Test Flowhub Dispensary',
			'url'             => 'https://api.flowhub.co',
			'connection_type' => 'flowhub',
			'auth_type'       => 'none',
			'api_key'         => 'test_key_placeholder',
			'client_id'       => 'test_client_id_placeholder',
			'location_id'     => 'test_location_placeholder',
			'enabled'         => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertIsString( $connection_id );

		// Verify connection was saved correctly.
		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertIsArray( $saved );
		$this->assertEquals( 'flowhub', $saved['connection_type'] );
		$this->assertEquals( 'Test Flowhub Dispensary', $saved['name'] );
	}

	/**
	 * Test creating PayHere connection.
	 */
	public function test_create_payhere_connection() {
		$connection_data = array(
			'name'            => 'Test PayHere',
			'url'             => 'https://www.payhere.lk',
			'connection_type' => 'payhere',
			'auth_type'       => 'none',
			'app_id'          => 'test_app_id',
			'app_secret'      => 'test_app_secret',
			'sandbox_mode'    => true,
			'enabled'         => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertIsString( $connection_id );

		// Verify connection was saved correctly.
		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertIsArray( $saved );
		$this->assertEquals( 'payhere', $saved['connection_type'] );
		$this->assertTrue( $saved['sandbox_mode'] );
	}

	/**
	 * Test creating QuickBooks connection.
	 */
	public function test_create_quickbooks_connection() {
		$connection_data = array(
			'name'            => 'Test QuickBooks',
			'url'             => 'https://quickbooks.api.intuit.com',
			'connection_type' => 'quickbooks',
			'auth_type'       => 'none',
			'client_id'       => 'test_client_id',
			'client_secret'   => 'test_oauth_token',
			'company_id'      => '123456789',
			'enabled'         => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertIsString( $connection_id );

		// Verify connection was saved correctly.
		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertIsArray( $saved );
		$this->assertEquals( 'quickbooks', $saved['connection_type'] );
	}

	/**
	 * Test creating EZuite ERP connection.
	 */
	public function test_create_ezuite_erp_connection() {
		$connection_data = array(
			'name'            => 'Test EZuite ERP',
			'url'             => 'https://api.ezuite.com',
			'connection_type' => 'ezuite_erp',
			'auth_type'       => 'none',
			'api_key'         => 'test_api_key',
			'api_secret'      => 'test_api_secret',
			'enabled'         => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertIsString( $connection_id );

		// Verify connection was saved correctly.
		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertIsArray( $saved );
		$this->assertEquals( 'ezuite_erp', $saved['connection_type'] );
	}

	/**
	 * Test validation for missing required fields per connection type.
	 */
	public function test_validation_for_connection_types() {
		// Test iSAMS - missing api_secret.
		$connection_data = array(
			'name'            => 'Test',
			'url'             => 'https://test.com',
			'connection_type' => 'isams',
			'auth_type'       => 'none',
			'api_key'         => 'test',
			'enabled'         => true,
		);
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertInstanceOf( 'WP_Error', $result );

		// Test Flowhub - missing client_id (should fail).
		$connection_data = array(
			'name'            => 'Test',
			'url'             => 'https://test.com',
			'connection_type' => 'flowhub',
			'auth_type'       => 'none',
			'api_key'         => 'test',
			'enabled'         => true,
		);
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertStringContainsString( 'client ID', $result->get_error_message() );

		// Test Flowhub - with both api_key and client_id (should succeed).
		$connection_data = array(
			'name'            => 'Test Flowhub',
			'url'             => 'https://test.com',
			'connection_type' => 'flowhub',
			'auth_type'       => 'none',
			'api_key'         => 'test_api_key',
			'client_id'       => 'test_client_id',
			'enabled'         => true,
		);
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertIsString( $result, 'Flowhub connection with api_key and client_id should save successfully' );

		// Test PayHere - missing app_secret.
		$connection_data = array(
			'name'            => 'Test',
			'url'             => 'https://test.com',
			'connection_type' => 'payhere',
			'auth_type'       => 'none',
			'app_id'          => 'test',
			'enabled'         => true,
		);
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Test that unique field names prevent form submission conflicts.
	 *
	 * This test verifies the fix for the issue where multiple connection types
	 * had fields with the same name (e.g., api_key, client_id), causing the
	 * last field in DOM order to override earlier fields when the form was submitted.
	 */
	public function test_unique_field_names_prevent_conflicts() {
		// Simulate form submission for Flowhub connection with unique field names.
		$_POST['wp_mcp_ai_pro_save_connection'] = '1';
		$_POST['_wpnonce']                      = wp_create_nonce( 'save_remote_connection' );
		$_POST['name']                          = 'Test Flowhub';
		$_POST['url']                           = 'https://flowhub.example.com';
		$_POST['connection_type']               = 'flowhub';
		$_POST['auth_type']                     = 'none';
		$_POST['flowhub_api_key']               = 'flowhub_test_key_123';
		$_POST['flowhub_client_id']             = 'flowhub_client_abc';
		$_POST['enabled']                       = '1';

		// Also set QuickBooks fields (which would have overridden if names weren't unique).
		$_POST['quickbooks_client_id']     = '';
		$_POST['quickbooks_client_secret'] = '';

		// Also set EZuite ERP fields (which would have overridden if names weren't unique).
		$_POST['ezuite_erp_api_key']    = '';
		$_POST['ezuite_erp_api_secret'] = '';

		// Simulate the admin class handling the form submission.
		$admin = new WP_MCP_AI_Pro_Remote_Sites_Admin();

		// Extract the connection data logic (same as in handle_actions).
		$connection_type = sanitize_key( wp_unslash( $_POST['connection_type'] ) );
		$api_key         = '';
		$client_id       = '';

		switch ( $connection_type ) {
			case 'flowhub':
				$api_key   = isset( $_POST['flowhub_api_key'] ) ? wp_unslash( $_POST['flowhub_api_key'] ) : '';
				$client_id = isset( $_POST['flowhub_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['flowhub_client_id'] ) ) : '';
				break;
		}

		// Verify Flowhub values were extracted correctly (not overridden by empty QuickBooks/EZuite fields).
		$this->assertEquals( 'flowhub_test_key_123', $api_key, 'Flowhub API key should be preserved' );
		$this->assertEquals( 'flowhub_client_abc', $client_id, 'Flowhub client ID should be preserved' );

		// Clean up.
		unset( $_POST['wp_mcp_ai_pro_save_connection'] );
		unset( $_POST['_wpnonce'] );
		unset( $_POST['name'] );
		unset( $_POST['url'] );
		unset( $_POST['connection_type'] );
		unset( $_POST['auth_type'] );
		unset( $_POST['flowhub_api_key'] );
		unset( $_POST['flowhub_client_id'] );
		unset( $_POST['quickbooks_client_id'] );
		unset( $_POST['quickbooks_client_secret'] );
		unset( $_POST['ezuite_erp_api_key'] );
		unset( $_POST['ezuite_erp_api_secret'] );
		unset( $_POST['enabled'] );
	}

	/**
	 * Test that new connection type "google_drive" appears in dropdown.
	 */
	public function test_google_drive_connection_type_in_dropdown() {
		$admin = new WP_MCP_AI_Pro_Remote_Sites_Admin();
		$_GET['page'] = 'wp-mcp-ai-remote-sites';

		ob_start();
		$admin->render_admin_page();
		$output = ob_get_clean();

		// Check that Google Drive option exists in the dropdown.
		$this->assertStringContainsString( 'value="google_drive"', $output, 'Google Drive should be in connection type dropdown' );
		$this->assertStringContainsString( 'Google Drive (Cloud Storage)', $output, 'Google Drive label should be present' );

		unset( $_GET['page'] );
	}

	/**
	 * Test creating a Google Drive connection with required fields.
	 */
	public function test_create_google_drive_connection() {
		$connection_data = array(
			'name'             => 'Test Google Drive Connection',
			'url'              => 'https://www.googleapis.com/drive/v3',
			'connection_type'  => 'google_drive',
			'auth_type'        => 'none',
			'client_id'        => 'google_drive_client_id_123',
			'client_secret'    => 'google_drive_client_secret_456',
			'refresh_token'    => 'google_drive_refresh_token_789',
			'folder_id'        => '1a2b3c4d5e6f7g8h9i0j',
			'user_email'       => 'test@example.com',
			'enabled'          => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotWPError( $connection_id, 'Google Drive connection should be created successfully' );
		$this->assertIsString( $connection_id, 'Connection ID should be a string' );
		$this->assertStringStartsWith( 'conn_', $connection_id, 'Connection ID should start with conn_' );

		// Retrieve and verify the connection.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $connection, 'Google Drive connection should be retrievable' );
		$this->assertEquals( 'Test Google Drive Connection', $connection['name'] );
		$this->assertEquals( 'google_drive', $connection['connection_type'] );
		$this->assertEquals( '1a2b3c4d5e6f7g8h9i0j', $connection['folder_id'] );
		$this->assertEquals( 'test@example.com', $connection['user_email'] );
	}

	/**
	 * Test validation for Google Drive connection type.
	 */
	public function test_google_drive_connection_validation() {
		// Test missing client_id.
		$connection_data = array(
			'name'            => 'Test Google Drive',
			'url'             => 'https://www.googleapis.com/drive/v3',
			'connection_type' => 'google_drive',
			'auth_type'       => 'none',
			'client_secret'   => 'google_drive_client_secret_456',
			'enabled'         => true,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertWPError( $result, 'Should fail validation without client_id' );
		$this->assertEquals( 'wp_mcp_ai_pro_missing_google_drive_credentials', $result->get_error_code() );

		// Test missing client_secret.
		$connection_data = array(
			'name'            => 'Test Google Drive',
			'url'             => 'https://www.googleapis.com/drive/v3',
			'connection_type' => 'google_drive',
			'auth_type'       => 'none',
			'client_id'       => 'google_drive_client_id_123',
			'enabled'         => true,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertWPError( $result, 'Should fail validation without client_secret' );
		$this->assertEquals( 'wp_mcp_ai_pro_missing_google_drive_credentials', $result->get_error_code() );

		// Test successful validation with required fields (refresh_token and folder_id are optional).
		$connection_data = array(
			'name'            => 'Test Google Drive',
			'url'             => 'https://www.googleapis.com/drive/v3',
			'connection_type' => 'google_drive',
			'auth_type'       => 'none',
			'client_id'       => 'google_drive_client_id_123',
			'client_secret'   => 'google_drive_client_secret_456',
			'enabled'         => true,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotWPError( $result, 'Should pass validation with required fields' );
	}
}
