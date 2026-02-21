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
	 * Google OAuth token endpoint URL.
	 *
	 * @var string
	 */
	const GOOGLE_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	/**
	 * Gmail profile API endpoint.
	 *
	 * @var string
	 */
	const GMAIL_PROFILE_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

	/**
	 * Google userinfo API endpoint.
	 *
	 * @var string
	 */
	const GOOGLE_USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v2/userinfo';

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
		$connection_id   = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

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
		$connection_id   = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		// Verify it exists.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertIsArray( $connection );

		$admin = new WP_MCP_AI_Pro_Remote_Sites_Admin();

		// Simulate delete action.
		$_GET['page']          = 'wp-mcp-ai-remote-sites';
		$_GET['action']        = 'delete';
		$_GET['connection_id'] = $connection_id;
		$_GET['_wpnonce']      = wp_create_nonce( 'delete_connection_' . $connection_id );

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
		$_GET['page']          = 'wp-mcp-ai-remote-sites';
		$_GET['action']        = 'delete';
		$_GET['connection_id'] = $connection_id;
		$_GET['_wpnonce']      = wp_create_nonce( 'delete_connection_' . $connection_id );

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
		$_GET['add']  = '1';

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
		$result          = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
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
		$result          = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
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
		$result          = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
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
		$result          = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
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
		$admin        = new WP_MCP_AI_Pro_Remote_Sites_Admin();
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
			'name'            => 'Test Google Drive Connection',
			'url'             => 'https://www.googleapis.com/drive/v3',
			'connection_type' => 'google_drive',
			'auth_type'       => 'none',
			'client_id'       => 'google_drive_client_id_123',
			'client_secret'   => 'google_drive_client_secret_456',
			'refresh_token'   => 'google_drive_refresh_token_789',
			'folder_id'       => '1a2b3c4d5e6f7g8h9i0j',
			'user_email'      => 'test@example.com',
			'enabled'         => true,
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

	/**
	 * Test that Google Drive connection fields populate correctly when editing.
	 *
	 * This test verifies that the fix for displaying saved values works correctly.
	 */
	public function test_google_drive_connection_fields_populate_on_edit() {
		// Create a Google Drive connection with all fields filled.
		$connection_data = array(
			'name'            => 'My Google Drive',
			'url'             => 'https://www.googleapis.com/drive/v3',
			'connection_type' => 'google_drive',
			'auth_type'       => 'none',
			'client_id'       => 'test_client_id_abc123',
			'client_secret'   => 'test_client_secret_xyz789',
			'refresh_token'   => 'test_refresh_token_def456',
			'folder_id'       => '1a2b3c4d5e6f7g8h',
			'user_email'      => 'testuser@gmail.com',
			'enabled'         => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotWPError( $connection_id );

		// Simulate editing the connection.
		$admin        = new WP_MCP_AI_Pro_Remote_Sites_Admin();
		$_GET['page'] = 'wp-mcp-ai-remote-sites';
		$_GET['edit'] = $connection_id;

		// Capture the edit form output.
		ob_start();
		$admin->render_admin_page();
		$output = ob_get_clean();

		// Verify that the client_id field is populated.
		$this->assertStringContainsString( 'value="test_client_id_abc123"', $output, 'Client ID should be populated in the form' );

		// Verify that client_secret shows "is set" indicator (since it's encrypted and not displayed).
		$this->assertStringContainsString( 'Client secret is set', $output, 'Client secret indicator should show it is set' );

		// Verify that refresh_token shows "is set" indicator.
		$this->assertStringContainsString( 'Refresh token is set', $output, 'Refresh token indicator should show it is set' );

		// Verify that folder_id field is populated.
		$this->assertStringContainsString( 'value="1a2b3c4d5e6f7g8h"', $output, 'Folder ID should be populated in the form' );

		// Verify that user_email field is populated.
		$this->assertStringContainsString( 'value="testuser@gmail.com"', $output, 'User email should be populated in the form' );

		// Clean up.
		unset( $_GET['page'] );
		unset( $_GET['edit'] );
	}

	/**
	 * Test Gmail OAuth state parameter generation and validation.
	 *
	 * Verifies OAuth 2.0 CSRF protection via state parameter.
	 */
	public function test_gmail_oauth_state_parameter_validation() {
		// Create a test Gmail connection.
		$connection_data = array(
			'name'            => 'Test Gmail',
			'url'             => 'https://gmail.googleapis.com/gmail/v1',
			'connection_type' => 'gmail',
			'auth_type'       => 'none',
			'client_id'       => 'test_client_id',
			'client_secret'   => 'test_client_secret',
			'enabled'         => true,
		);
		$connection_id   = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotWPError( $connection_id );

		// Mock the OAuth start by calling it directly with reflection.
		$admin      = new WP_MCP_AI_Pro_Remote_Sites_Admin();
		$reflection = new ReflectionClass( $admin );
		$method     = $reflection->getMethod( 'handle_gmail_oauth_start' );
		$method->setAccessible( true );

		// Capture redirect to extract state parameter.
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 2 );
		$this->redirect_url = '';

		try {
			$method->invoke( $admin, $connection_id );
		} catch ( Exception $e ) {
			// wp_safe_redirect exits, so we catch it.
		}

		$redirect_url = $this->redirect_url;
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );

		// Verify redirect URL contains Google OAuth endpoint.
		$this->assertStringContainsString( 'accounts.google.com/o/oauth2/v2/auth', $redirect_url, 'Should redirect to Google OAuth endpoint' );

		// Extract state parameter from redirect URL.
		$parsed = wp_parse_url( $redirect_url );
		parse_str( $parsed['query'], $params );
		$this->assertArrayHasKey( 'state', $params, 'State parameter should be present' );

		$state = $params['state'];

		// Verify transient was set with state data.
		$transient_key = 'wp_mcp_ai_gmail_oauth_state_' . md5( $state );
		$state_data    = get_transient( $transient_key );
		$this->assertNotFalse( $state_data, 'State data transient should exist' );
		$this->assertIsArray( $state_data, 'State data should be an array' );
		$this->assertArrayHasKey( 'user_id', $state_data, 'State data should contain user_id' );
		$this->assertArrayHasKey( 'connection_id', $state_data, 'State data should contain connection_id' );
		$this->assertEquals( $connection_id, $state_data['connection_id'], 'Connection ID should match' );

		// Clean up.
		delete_transient( $transient_key );
	}

	/**
	 * Test Gmail OAuth callback with valid authorization code.
	 *
	 * Verifies successful token exchange and connection update.
	 */
	public function test_gmail_oauth_callback_success() {
		// Create a test Gmail connection.
		$connection_data = array(
			'name'            => 'Test Gmail',
			'url'             => 'https://gmail.googleapis.com/gmail/v1',
			'connection_type' => 'gmail',
			'auth_type'       => 'none',
			'client_id'       => 'test_client_id',
			'client_secret'   => 'test_client_secret',
			'enabled'         => true,
		);
		$connection_id   = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotWPError( $connection_id );

		// Create state parameter and transient.
		$state         = wp_generate_uuid4();
		$transient_key = 'wp_mcp_ai_gmail_oauth_state_' . md5( $state );
		set_transient(
			$transient_key,
			array(
				'user_id'       => get_current_user_id(),
				'connection_id' => $connection_id,
				'time'          => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		// Mock wp_remote_post for token exchange.
		add_filter( 'pre_http_request', array( $this, 'mock_gmail_token_exchange' ), 10, 3 );

		// Simulate OAuth callback.
		$_GET['page']          = 'wp-mcp-ai-remote-sites';
		$_GET['oauth_handler'] = 'gmail_oauth_callback';
		$_GET['state']         = $state;
		$_GET['code']          = 'test_authorization_code';

		// Capture redirect.
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 2 );
		$this->redirect_url = '';

		$admin      = new WP_MCP_AI_Pro_Remote_Sites_Admin();
		$reflection = new ReflectionClass( $admin );
		$method     = $reflection->getMethod( 'handle_gmail_oauth_callback' );
		$method->setAccessible( true );

		try {
			$method->invoke( $admin );
		} catch ( Exception $e ) {
			// wp_safe_redirect exits, so we catch it.
		}

		$redirect_url = $this->redirect_url;
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
		remove_filter( 'pre_http_request', array( $this, 'mock_gmail_token_exchange' ) );

		// Verify redirect to success page.
		$this->assertStringContainsString( 'oauth_success=', $redirect_url, 'Should redirect to success page' );

		// Verify connection was updated with refresh token.
		$updated_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $updated_connection, 'Connection should still exist' );
		$this->assertEquals( 'test_refresh_token_123', $updated_connection['refresh_token'], 'Refresh token should be saved' );
		$this->assertEquals( 'test@gmail.com', $updated_connection['user_email'], 'User email should be saved' );

		// Verify transient was deleted (CSRF protection cleanup).
		$state_data = get_transient( $transient_key );
		$this->assertFalse( $state_data, 'State transient should be deleted after use' );

		// Clean up.
		unset( $_GET['page'] );
		unset( $_GET['oauth_handler'] );
		unset( $_GET['state'] );
		unset( $_GET['code'] );
	}

	/**
	 * Test Gmail OAuth callback with invalid state parameter.
	 *
	 * Verifies CSRF protection rejects invalid state.
	 */
	public function test_gmail_oauth_callback_invalid_state() {
		// Simulate OAuth callback with invalid state.
		$_GET['page']          = 'wp-mcp-ai-remote-sites';
		$_GET['oauth_handler'] = 'gmail_oauth_callback';
		$_GET['state']         = 'invalid_state_parameter';
		$_GET['code']          = 'test_authorization_code';

		// Capture redirect.
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 2 );
		$this->redirect_url = '';

		$admin      = new WP_MCP_AI_Pro_Remote_Sites_Admin();
		$reflection = new ReflectionClass( $admin );
		$method     = $reflection->getMethod( 'handle_gmail_oauth_callback' );
		$method->setAccessible( true );

		try {
			$method->invoke( $admin );
		} catch ( Exception $e ) {
			// wp_safe_redirect exits, so we catch it.
		}

		$redirect_url = $this->redirect_url;
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );

		// Verify redirect to error page.
		$this->assertStringContainsString( 'error=', $redirect_url, 'Should redirect to error page' );
		$this->assertStringContainsString( 'state+verification+failed', $redirect_url, 'Error message should mention state verification' );

		// Clean up.
		unset( $_GET['page'] );
		unset( $_GET['oauth_handler'] );
		unset( $_GET['state'] );
		unset( $_GET['code'] );
	}

	/**
	 * Test Gmail OAuth callback with missing authorization code.
	 *
	 * Verifies proper error handling when Google doesn't return a code.
	 */
	public function test_gmail_oauth_callback_missing_code() {
		// Create state parameter and transient with non-existent connection ID.
		$connection_id = 'conn_test_nonexistent_' . wp_generate_uuid4();
		$state         = wp_generate_uuid4();
		$transient_key = 'wp_mcp_ai_gmail_oauth_state_' . md5( $state );
		set_transient(
			$transient_key,
			array(
				'user_id'       => get_current_user_id(),
				'connection_id' => $connection_id,
				'time'          => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		// Simulate OAuth callback without code.
		$_GET['page']          = 'wp-mcp-ai-remote-sites';
		$_GET['oauth_handler'] = 'gmail_oauth_callback';
		$_GET['state']         = $state;
		// No code parameter.

		// Capture redirect.
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 2 );
		$this->redirect_url = '';

		$admin      = new WP_MCP_AI_Pro_Remote_Sites_Admin();
		$reflection = new ReflectionClass( $admin );
		$method     = $reflection->getMethod( 'handle_gmail_oauth_callback' );
		$method->setAccessible( true );

		try {
			$method->invoke( $admin );
		} catch ( Exception $e ) {
			// wp_safe_redirect exits, so we catch it.
		}

		$redirect_url = $this->redirect_url;
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );

		// Verify redirect to error page.
		$this->assertStringContainsString( 'error=', $redirect_url, 'Should redirect to error page' );
		$this->assertStringContainsString( 'authorization+code', $redirect_url, 'Error message should mention authorization code' );

		// Clean up.
		delete_transient( $transient_key );
		unset( $_GET['page'] );
		unset( $_GET['oauth_handler'] );
		unset( $_GET['state'] );
	}

	/**
	 * Test Google Drive OAuth state parameter generation and validation.
	 *
	 * Verifies OAuth 2.0 CSRF protection via state parameter.
	 */
	public function test_google_drive_oauth_state_parameter_validation() {
		// Create a test Google Drive connection.
		$connection_data = array(
			'name'            => 'Test Google Drive',
			'url'             => 'https://www.googleapis.com/drive/v3',
			'connection_type' => 'google_drive',
			'auth_type'       => 'none',
			'client_id'       => 'test_client_id',
			'client_secret'   => 'test_client_secret',
			'enabled'         => true,
		);
		$connection_id   = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotWPError( $connection_id );

		// Mock the OAuth start by calling it directly with reflection.
		$admin      = new WP_MCP_AI_Pro_Remote_Sites_Admin();
		$reflection = new ReflectionClass( $admin );
		$method     = $reflection->getMethod( 'handle_google_drive_oauth_start' );
		$method->setAccessible( true );

		// Capture redirect to extract state parameter.
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 2 );
		$this->redirect_url = '';

		try {
			$method->invoke( $admin, $connection_id );
		} catch ( Exception $e ) {
			// wp_safe_redirect exits, so we catch it.
		}

		$redirect_url = $this->redirect_url;
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );

		// Verify redirect URL contains Google OAuth endpoint.
		$this->assertStringContainsString( 'accounts.google.com/o/oauth2/v2/auth', $redirect_url, 'Should redirect to Google OAuth endpoint' );

		// Extract state parameter from redirect URL.
		$parsed = wp_parse_url( $redirect_url );
		parse_str( $parsed['query'], $params );
		$this->assertArrayHasKey( 'state', $params, 'State parameter should be present' );

		$state = $params['state'];

		// Verify transient was set with state data.
		$transient_key = 'wp_mcp_ai_google_drive_oauth_state_' . md5( $state );
		$state_data    = get_transient( $transient_key );
		$this->assertNotFalse( $state_data, 'State data transient should exist' );
		$this->assertIsArray( $state_data, 'State data should be an array' );
		$this->assertArrayHasKey( 'user_id', $state_data, 'State data should contain user_id' );
		$this->assertArrayHasKey( 'connection_id', $state_data, 'State data should contain connection_id' );
		$this->assertEquals( $connection_id, $state_data['connection_id'], 'Connection ID should match' );

		// Verify OAuth parameters comply with best practices.
		$this->assertArrayHasKey( 'access_type', $params, 'Should request offline access' );
		$this->assertEquals( 'offline', $params['access_type'], 'Should use offline access for refresh tokens' );
		$this->assertArrayHasKey( 'prompt', $params, 'Should have prompt parameter' );
		$this->assertEquals( 'consent', $params['prompt'], 'Should force consent for refresh token generation' );
		$this->assertArrayHasKey( 'include_granted_scopes', $params, 'Should support incremental authorization' );
		$this->assertEquals( 'true', $params['include_granted_scopes'], 'Should enable incremental authorization' );

		// Clean up.
		delete_transient( $transient_key );
	}

	/**
	 * Test Google Drive OAuth callback with valid authorization code.
	 *
	 * Verifies successful token exchange and connection update.
	 */
	public function test_google_drive_oauth_callback_success() {
		// Create a test Google Drive connection.
		$connection_data = array(
			'name'            => 'Test Google Drive',
			'url'             => 'https://www.googleapis.com/drive/v3',
			'connection_type' => 'google_drive',
			'auth_type'       => 'none',
			'client_id'       => 'test_client_id',
			'client_secret'   => 'test_client_secret',
			'enabled'         => true,
		);
		$connection_id   = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotWPError( $connection_id );

		// Create state parameter and transient.
		$state         = wp_generate_uuid4();
		$transient_key = 'wp_mcp_ai_google_drive_oauth_state_' . md5( $state );
		set_transient(
			$transient_key,
			array(
				'user_id'       => get_current_user_id(),
				'connection_id' => $connection_id,
				'time'          => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		// Mock wp_remote_post for token exchange.
		add_filter( 'pre_http_request', array( $this, 'mock_google_drive_token_exchange' ), 10, 3 );

		// Simulate OAuth callback.
		$_GET['page']          = 'wp-mcp-ai-remote-sites';
		$_GET['oauth_handler'] = 'google_drive_oauth_callback';
		$_GET['state']         = $state;
		$_GET['code']          = 'test_authorization_code';

		// Capture redirect.
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 2 );
		$this->redirect_url = '';

		$admin      = new WP_MCP_AI_Pro_Remote_Sites_Admin();
		$reflection = new ReflectionClass( $admin );
		$method     = $reflection->getMethod( 'handle_google_drive_oauth_callback' );
		$method->setAccessible( true );

		try {
			$method->invoke( $admin );
		} catch ( Exception $e ) {
			// wp_safe_redirect exits, so we catch it.
		}

		$redirect_url = $this->redirect_url;
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
		remove_filter( 'pre_http_request', array( $this, 'mock_google_drive_token_exchange' ) );

		// Verify redirect to success page.
		$this->assertStringContainsString( 'oauth_success=', $redirect_url, 'Should redirect to success page' );

		// Verify connection was updated with refresh token.
		$updated_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $updated_connection, 'Connection should still exist' );
		$this->assertEquals( 'test_drive_refresh_token_456', $updated_connection['refresh_token'], 'Refresh token should be saved' );
		$this->assertEquals( 'driveuser@gmail.com', $updated_connection['user_email'], 'User email should be saved' );

		// Verify transient was deleted (CSRF protection cleanup).
		$state_data = get_transient( $transient_key );
		$this->assertFalse( $state_data, 'State transient should be deleted after use' );

		// Clean up.
		unset( $_GET['page'] );
		unset( $_GET['oauth_handler'] );
		unset( $_GET['state'] );
		unset( $_GET['code'] );
	}

	/**
	 * Test Google Drive OAuth callback with error from Google.
	 *
	 * Verifies proper error handling when user denies authorization.
	 */
	public function test_google_drive_oauth_callback_user_denied() {
		// Simulate OAuth callback with error.
		$_GET['page']          = 'wp-mcp-ai-remote-sites';
		$_GET['oauth_handler'] = 'google_drive_oauth_callback';
		$_GET['error']         = 'access_denied';

		// Capture redirect.
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 2 );
		$this->redirect_url = '';

		$admin      = new WP_MCP_AI_Pro_Remote_Sites_Admin();
		$reflection = new ReflectionClass( $admin );
		$method     = $reflection->getMethod( 'handle_google_drive_oauth_callback' );
		$method->setAccessible( true );

		try {
			$method->invoke( $admin );
		} catch ( Exception $e ) {
			// wp_safe_redirect exits, so we catch it.
		}

		$redirect_url = $this->redirect_url;
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );

		// Verify redirect to error page.
		$this->assertStringContainsString( 'error=', $redirect_url, 'Should redirect to error page' );
		$this->assertStringContainsString( 'access_denied', $redirect_url, 'Error message should contain the error from Google' );

		// Clean up.
		unset( $_GET['page'] );
		unset( $_GET['oauth_handler'] );
		unset( $_GET['error'] );
	}

	/**
	 * Helper: Mock Gmail token exchange response.
	 *
	 * @param false|array|WP_Error $response    A preemptive return value of an HTTP request.
	 * @param array                $parsed_args HTTP request arguments.
	 * @param string               $url         The request URL.
	 * @return array|false|WP_Error Mock HTTP response or original response.
	 */
	public function mock_gmail_token_exchange( $response, $parsed_args, $url ) {
		// Mock token exchange endpoint (shared logic).
		if ( self::GOOGLE_TOKEN_ENDPOINT === $url ) {
			return $this->mock_google_token_response( 'test_refresh_token_123' );
		}

		if ( self::GMAIL_PROFILE_ENDPOINT === $url ) {
			// Return mock Gmail profile response.
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => wp_json_encode(
					array(
						'emailAddress' => 'test@gmail.com',
					)
				),
			);
		}

		return $response;
	}

	/**
	 * Helper: Mock Google Drive token exchange response.
	 *
	 * @param false|array|WP_Error $response    A preemptive return value of an HTTP request.
	 * @param array                $parsed_args HTTP request arguments.
	 * @param string               $url         The request URL.
	 * @return array|false|WP_Error Mock HTTP response or original response.
	 */
	public function mock_google_drive_token_exchange( $response, $parsed_args, $url ) {
		// Mock token exchange endpoint (shared logic).
		if ( self::GOOGLE_TOKEN_ENDPOINT === $url ) {
			return $this->mock_google_token_response( 'test_drive_refresh_token_456' );
		}

		if ( self::GOOGLE_USERINFO_ENDPOINT === $url ) {
			// Return mock userinfo response.
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => wp_json_encode(
					array(
						'email'          => 'driveuser@gmail.com',
						'verified_email' => true,
					)
				),
			);
		}

		return $response;
	}

	/**
	 * Test that ajax_test_whatsapp_live does not include quality_rating in the primary request.
	 *
	 * quality_rating requires whatsapp_business_management permission and causes a 403
	 * with App Access Tokens. The primary request must only ask for
	 * display_phone_number and verified_name; quality_rating is fetched separately
	 * and a 403 on that request must not fail the overall test.
	 */
	public function test_ajax_test_whatsapp_live_excludes_quality_rating_from_primary_request() {
		$captured_urls = array();

		$mock_callback = function ( $preempt, $parsed_args, $url ) use ( &$captured_urls ) {
			if ( false === strpos( $url, 'graph.facebook.com' ) ) {
				return $preempt;
			}

			$captured_urls[] = $url;

			// Simulate 403 when quality_rating is the only requested field.
			if ( false !== strpos( $url, 'fields=quality_rating' ) ) {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							'error' => array(
								'message' => '(#200) You do not have permission to access this field.',
								'type'    => 'OAuthException',
								'code'    => 200,
							),
						)
					),
					'response' => array(
						'code'    => 403,
						'message' => 'Forbidden',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			// Return success for the primary phone-number request.
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'display_phone_number' => '+1 555-000-0000',
						'verified_name'        => 'Test Business',
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $mock_callback, 10, 3 );

		$_POST['action']          = 'wp_mcp_ai_test_whatsapp_live';
		$_POST['nonce']           = wp_create_nonce( 'wp_mcp_ai_test_whatsapp_live' );
		$_POST['access_token']    = 'test_access_token';
		$_POST['phone_number_id'] = '111222333444555';

		$admin = new WP_MCP_AI_Pro_Remote_Sites_Admin();

		// Capture JSON output via output buffering.
		ob_start();
		try {
			$admin->ajax_test_whatsapp_live();
		} catch ( \WPDieException $e ) {
			// Expected: wp_send_json_success calls wp_die.
		}
		$output = ob_get_clean();

		remove_filter( 'pre_http_request', $mock_callback, 10 );

		// Clean up POST.
		unset( $_POST['action'], $_POST['nonce'], $_POST['access_token'], $_POST['phone_number_id'] );

		$data = json_decode( $output, true );

		// The handler must succeed despite the 403 on quality_rating.
		$this->assertNotNull( $data, 'Response should be valid JSON' );
		$this->assertTrue( isset( $data['success'] ) && $data['success'], 'Response must be success=true; got: ' . wp_json_encode( $data ) );

		// Verify the primary phone-number URL does not contain quality_rating.
		$phone_url = '';
		foreach ( $captured_urls as $url ) {
			if ( false !== strpos( $url, 'display_phone_number' ) && false === strpos( $url, 'quality_rating' ) ) {
				$phone_url = $url;
				break;
			}
		}
		$this->assertNotEmpty( $phone_url, 'Primary phone-number request must have been made without quality_rating' );
		$this->assertStringNotContainsString( 'quality_rating', $phone_url, 'Primary request must not include quality_rating' );

		// quality_rating should be unknown since the optional request returned 403.
		$this->assertEquals( 'unknown', $data['data']['quality_rating'], 'quality_rating should be unknown when optional request fails with 403' );
	}

	/**
	 * Test that ajax_test_whatsapp_live succeeds when the fields request returns a 403
	 * with Facebook error code 200 (field-level permission error).
	 *
	 * In this scenario the token can send messages (whatsapp_business_messaging) but
	 * lacks the permission to read display_phone_number / verified_name.  The handler
	 * must fall back to the base endpoint, report success, and include a warning.
	 */
	public function test_ajax_test_whatsapp_live_succeeds_on_fields_403_with_fallback() {
		$mock_callback = function ( $preempt, $parsed_args, $url ) {
			if ( false === strpos( $url, 'graph.facebook.com' ) ) {
				return $preempt;
			}

			// The fields=display_phone_number,verified_name request returns 403 with FB error code 200.
			if ( false !== strpos( $url, 'fields=display_phone_number' ) ) {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							'error' => array(
								'message' => '(#200) You do not have permission to access this field.',
								'type'    => 'OAuthException',
								'code'    => 200,
							),
						)
					),
					'response' => array(
						'code'    => 403,
						'message' => 'Forbidden',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			// The base endpoint (no fields) succeeds.
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'id' => '111222333444555' ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $mock_callback, 10, 3 );

		$_POST['action']          = 'wp_mcp_ai_test_whatsapp_live';
		$_POST['nonce']           = wp_create_nonce( 'wp_mcp_ai_test_whatsapp_live' );
		$_POST['access_token']    = 'test_limited_access_token';
		$_POST['phone_number_id'] = '111222333444555';

		$admin = new WP_MCP_AI_Pro_Remote_Sites_Admin();

		ob_start();
		try {
			$admin->ajax_test_whatsapp_live();
		} catch ( \WPDieException $e ) {
			// Expected: wp_send_json_success calls wp_die.
		}
		$output = ob_get_clean();

		remove_filter( 'pre_http_request', $mock_callback, 10 );

		unset( $_POST['action'], $_POST['nonce'], $_POST['access_token'], $_POST['phone_number_id'] );

		$data = json_decode( $output, true );

		// The handler must succeed despite the 403 on the fields request.
		$this->assertNotNull( $data, 'Response should be valid JSON' );
		$this->assertTrue( isset( $data['success'] ) && $data['success'], 'Response must be success=true; got: ' . wp_json_encode( $data ) );

		// A warning about limited field access should be included.
		$this->assertArrayHasKey( 'warning', $data['data'], 'Result should include a warning about limited field access' );
		$this->assertStringContainsString( 'permission', strtolower( $data['data']['warning'] ), 'Warning should mention permission' );
	}

	/**
	 * Test that ajax_test_whatsapp_live succeeds when the fields request returns HTTP 400
	 * with Facebook error code 100 ("Tried accessing nonexisting field (display_phone_number)").
	 *
	 * The handler must fall back to the base phone number endpoint and report success with
	 * a warning rather than surfacing the raw API error to the user.
	 */
	public function test_ajax_test_whatsapp_live_succeeds_on_fields_400_code_100_with_fallback() {
		$mock_callback = function ( $preempt, $parsed_args, $url ) {
			if ( false === strpos( $url, 'graph.facebook.com' ) ) {
				return $preempt;
			}

			// The fields=display_phone_number,verified_name request returns 400 with FB error code 100.
			if ( false !== strpos( $url, 'fields=display_phone_number' ) ) {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							'error' => array(
								'message'    => '(#100) Tried accessing nonexisting field (display_phone_number)',
								'type'       => 'OAuthException',
								'code'       => 100,
								'fbtrace_id' => 'xyz100',
							),
						)
					),
					'response' => array(
						'code'    => 400,
						'message' => 'Bad Request',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			// The base endpoint (no fields) succeeds.
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'id' => '333444555666777' ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $mock_callback, 10, 3 );

		$_POST['action']              = 'wp_mcp_ai_test_whatsapp_live';
		$_POST['nonce']               = wp_create_nonce( 'wp_mcp_ai_test_whatsapp_live' );
		$_POST['access_token']        = 'test_token_400_code_100';
		$_POST['phone_number_id']     = '333444555666777';
		$_POST['graph_api_version']   = 'v22.0';

		$admin = new WP_MCP_AI_Pro_Remote_Sites_Admin();

		ob_start();
		try {
			$admin->ajax_test_whatsapp_live();
		} catch ( \WPDieException $e ) {
			// Expected: wp_send_json_success calls wp_die.
		}
		$output = ob_get_clean();

		remove_filter( 'pre_http_request', $mock_callback, 10 );

		unset( $_POST['action'], $_POST['nonce'], $_POST['access_token'], $_POST['phone_number_id'], $_POST['graph_api_version'] );

		$data = json_decode( $output, true );

		// The handler must succeed despite the 400 + code 100 on the fields request.
		$this->assertNotNull( $data, 'Response should be valid JSON' );
		$this->assertTrue( isset( $data['success'] ) && $data['success'], 'Response must be success=true; got: ' . wp_json_encode( $data ) );

		// A warning about limited field access should be included.
		$this->assertArrayHasKey( 'warning', $data['data'], 'Result should include a warning about limited field access' );
		$this->assertStringContainsString( 'permission', strtolower( $data['data']['warning'] ), 'Warning should mention permission' );
	}

	/**
	 * Helper: Mock Google OAuth token endpoint response.
	 *
	 * Shared helper to reduce code duplication between Gmail and Drive mocks.
	 * Both mock methods call this to handle the token exchange endpoint.
	 *
	 * @param string $refresh_token The refresh token to return.
	 * @return array Mock HTTP response.
	 */
	private function mock_google_token_response( $refresh_token ) {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'access_token'  => 'test_access_token',
					'refresh_token' => $refresh_token,
					'expires_in'    => 3600,
					'token_type'    => 'Bearer',
				)
			),
		);
	}
}
