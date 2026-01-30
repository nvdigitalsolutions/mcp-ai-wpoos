<?php
/**
 * Test Password Vault AJAX Handlers
 *
 * Verifies that the Password Vault's AJAX handlers are properly
 * registered and function correctly without page reloads.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Password Vault AJAX functionality
 */
class Test_Password_Vault_AJAX extends WP_UnitTestCase {

	/**
	 * Admin instance
	 *
	 * @var WP_MCP_AI_Password_Vault_Admin
	 */
	private $admin;

	/**
	 * Set up before each test
	 */
	public function setUp(): void {
		parent::setUp();

		// Remove any previously registered actions to start fresh.
		remove_all_actions( 'wp_ajax_vault_generate_password' );
		remove_all_actions( 'wp_ajax_vault_generate_totp_secret' );
		remove_all_actions( 'admin_enqueue_scripts' );

		// Load required dependencies.
		if ( ! class_exists( 'WP_MCP_AI_Vault_Encryption_Service' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/vault/class-wp-mcp-ai-vault-encryption-service.php';
		}
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-password-vault-admin.php';

		// Create an instance of the admin class.
		$this->admin = new WP_MCP_AI_Password_Vault_Admin();
	}

	/**
	 * Test that AJAX handlers are registered
	 */
	public function test_ajax_handlers_registered() {
		// Verify password generation AJAX action is registered.
		$this->assertTrue(
			has_action( 'wp_ajax_vault_generate_password' ) !== false,
			'AJAX action wp_ajax_vault_generate_password should be registered'
		);

		// Verify TOTP generation AJAX action is registered.
		$this->assertTrue(
			has_action( 'wp_ajax_vault_generate_totp_secret' ) !== false,
			'AJAX action wp_ajax_vault_generate_totp_secret should be registered'
		);
	}

	/**
	 * Test that admin_enqueue_scripts action is registered
	 */
	public function test_enqueue_scripts_action_registered() {
		$this->assertTrue(
			has_action( 'admin_enqueue_scripts' ) !== false,
			'admin_enqueue_scripts action should be registered'
		);
	}

	/**
	 * Test that scripts are enqueued on the correct page
	 */
	public function test_scripts_enqueued_on_vault_page() {
		// Set up admin user.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Simulate being on the password vault page using $_GET.
		$_GET['page'] = 'wp-mcp-ai-password-vault';

		// Trigger the enqueue action with the expected hook.
		do_action( 'admin_enqueue_scripts', 'toplevel_page_wp-mcp-ai-password-vault' );

		// Verify style is enqueued.
		$this->assertTrue(
			wp_style_is( 'wp-mcp-ai-password-vault', 'enqueued' ),
			'Password vault CSS should be enqueued on vault page'
		);

		// Verify script is enqueued.
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-password-vault', 'enqueued' ),
			'Password vault JS should be enqueued on vault page'
		);

		// Verify localized data is present.
		global $wp_scripts;
		$localized_data = $wp_scripts->get_data( 'wp-mcp-ai-password-vault', 'data' );
		$this->assertNotEmpty( $localized_data, 'Localized script data should be present' );
		$this->assertStringContainsString( 'wpMcpAiVault', $localized_data, 'wpMcpAiVault object should be localized' );
	}

	/**
	 * Test that scripts are NOT enqueued on other pages
	 */
	public function test_scripts_not_enqueued_on_other_pages() {
		// Set up admin user.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Clear $_GET to simulate being on a different page.
		unset( $_GET['page'] );

		// Trigger the enqueue action with a different hook.
		do_action( 'admin_enqueue_scripts', 'index.php' );

		// Verify style is NOT enqueued.
		$this->assertFalse(
			wp_style_is( 'wp-mcp-ai-password-vault', 'enqueued' ),
			'Password vault CSS should NOT be enqueued on other pages'
		);

		// Verify script is NOT enqueued.
		$this->assertFalse(
			wp_script_is( 'wp-mcp-ai-password-vault', 'enqueued' ),
			'Password vault JS should NOT be enqueued on other pages'
		);
	}

	/**
	 * Test password generation handler returns JSON
	 */
	public function test_password_generation_returns_json() {
		// Set up admin user.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Set up request data.
		$_POST['_wpnonce']  = wp_create_nonce( 'vault_generate_password' );
		$_POST['length']    = 16;
		$_POST['uppercase'] = 1;
		$_POST['lowercase'] = 1;
		$_POST['numbers']   = 1;
		$_POST['symbols']   = 1;

		// Capture JSON output.
		ob_start();
		try {
			// This should call wp_send_json_success which exits.
			// We'll catch the output before the exit.
			$this->admin->handle_generate_password();
		} catch ( Exception $e ) {
			// wp_send_json_success() calls wp_die() which throws WPAjaxDieContinueException in tests.
			// Intentionally catching exception to test AJAX response.
			unset( $e );
		}
		$output = ob_get_clean();

		// Verify JSON response.
		$response = json_decode( $output, true );
		$this->assertNotNull( $response, 'Response should be valid JSON' );
		$this->assertTrue( $response['success'] ?? false, 'Response should indicate success' );
		$this->assertArrayHasKey( 'data', $response, 'Response should have data key' );
		$this->assertArrayHasKey( 'password', $response['data'], 'Response data should have password' );
		$this->assertArrayHasKey( 'strength', $response['data'], 'Response data should have strength' );
	}

	/**
	 * Clean up after each test
	 */
	public function tearDown(): void {
		unset( $_GET['page'] );
		unset( $_POST['_wpnonce'] );
		unset( $_POST['length'] );
		unset( $_POST['uppercase'] );
		unset( $_POST['lowercase'] );
		unset( $_POST['numbers'] );
		unset( $_POST['symbols'] );

		parent::tearDown();
	}
}
