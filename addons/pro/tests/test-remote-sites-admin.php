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
}
