<?php
/**
 * Tests for admin key rotation interface.
 *
 * @package WP_MCP_AI
 */

/**
 * @group encryption
 * @group admin
 * @group key-rotation
 */
class WP_MCP_AI_Admin_Key_Rotation_Tests extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected $editor_user_id;

	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->admin_user_id = $this->factory->user->create(
			array( 'role' => 'administrator' )
		);

		// Create editor user (without manage_options capability).
		$this->editor_user_id = $this->factory->user->create(
			array( 'role' => 'editor' )
		);

		// Clear any existing master key.
		delete_option( WP_MCP_AI_Encryption::MASTER_KEY_OPTION );
	}

	public function tearDown(): void {
		delete_option( WP_MCP_AI_Encryption::MASTER_KEY_OPTION );
		delete_transient( 'wp_mcp_ai_key_rotation_success' );
		delete_transient( 'wp_mcp_ai_key_rotation_error' );

		parent::tearDown();
	}

	/**
	 * Test that admin interface initializes properly.
	 */
	public function test_admin_interface_init() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Admin_Key_Rotation' ) );
		$this->assertTrue( has_action( 'admin_post_wp_mcp_ai_rotate_master_key' ) !== false );
		$this->assertTrue( has_action( 'admin_notices' ) !== false );
	}

	/**
	 * Test rotation request requires admin capability.
	 */
	public function test_rotation_requires_admin_capability() {
		// Set current user to editor (no manage_options capability).
		wp_set_current_user( $this->editor_user_id );

		// Attempt to rotate key - should fail with permission error.
		$this->expectException( 'WPDieException' );

		$_POST['_wpnonce'] = wp_create_nonce( 'wp_mcp_ai_rotate_master_key' );
		WP_MCP_AI_Admin_Key_Rotation::handle_rotation_request();
	}

	/**
	 * Test rotation request requires valid nonce.
	 */
	public function test_rotation_requires_valid_nonce() {
		// Set current user to admin.
		wp_set_current_user( $this->admin_user_id );

		// Attempt to rotate with invalid nonce - should fail.
		$this->expectException( 'WPDieException' );

		$_POST['_wpnonce'] = 'invalid-nonce';
		WP_MCP_AI_Admin_Key_Rotation::handle_rotation_request();
	}

	/**
	 * Test successful rotation sets success transient.
	 */
	public function test_successful_rotation_sets_transient() {
		// Set current user to admin.
		wp_set_current_user( $this->admin_user_id );

		// Set valid nonce.
		$_POST['_wpnonce'] = wp_create_nonce( 'wp_mcp_ai_rotate_master_key' );

		// Mock the redirect to prevent actual redirect.
		add_filter( 'wp_redirect', array( $this, 'mock_redirect' ), 10, 2 );

		try {
			WP_MCP_AI_Admin_Key_Rotation::handle_rotation_request();
		} catch ( Exception $e ) {
			// Redirect will throw exception in tests.
		}

		// Verify success transient was set.
		$success = get_transient( 'wp_mcp_ai_key_rotation_success' );
		$this->assertTrue( $success );

		// Verify no error transient.
		$error = get_transient( 'wp_mcp_ai_key_rotation_error' );
		$this->assertFalse( $error );
	}

	/**
	 * Test failed rotation sets error transient.
	 */
	public function test_failed_rotation_sets_error_transient() {
		// Create corrupted encrypted data that will fail rotation.
		$post_id = $this->factory->post->create();
		update_post_meta( $post_id, 'wp_mcp_ai_encrypted_secret', 'corrupted-data' );

		// Set current user to admin.
		wp_set_current_user( $this->admin_user_id );

		// Set valid nonce.
		$_POST['_wpnonce'] = wp_create_nonce( 'wp_mcp_ai_rotate_master_key' );

		// Mock the redirect to prevent actual redirect.
		add_filter( 'wp_redirect', array( $this, 'mock_redirect' ), 10, 2 );

		try {
			WP_MCP_AI_Admin_Key_Rotation::handle_rotation_request();
		} catch ( Exception $e ) {
			// Redirect will throw exception in tests.
		}

		// Verify error transient was set.
		$error = get_transient( 'wp_mcp_ai_key_rotation_error' );
		$this->assertIsArray( $error );
		$this->assertArrayHasKey( 'message', $error );
		$this->assertArrayHasKey( 'code', $error );
		$this->assertEquals( 'wp_mcp_ai_decrypt_failed', $error['code'] );

		// Verify no success transient.
		$success = get_transient( 'wp_mcp_ai_key_rotation_success' );
		$this->assertFalse( $success );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Mock redirect function for testing.
	 *
	 * @param string $location Redirect location.
	 * @param int    $status   HTTP status code.
	 * @return bool
	 */
	public function mock_redirect( $location, $status ) {
		// Store redirect location for verification.
		$this->redirect_location = $location;
		$this->redirect_status   = $status;

		// Prevent actual redirect.
		return false;
	}

	/**
	 * Test render_rotation_section displays correctly.
	 */
	public function test_render_rotation_section() {
		// Generate a master key.
		WP_MCP_AI_Encryption::get_master_key();

		// Capture output.
		ob_start();
		WP_MCP_AI_Admin_Key_Rotation::render_rotation_section();
		$output = ob_get_clean();

		// Verify output contains expected elements.
		$this->assertStringContainsString( 'Master Encryption Key Rotation', $output );
		$this->assertStringContainsString( 'Rotate Master Key', $output );
		$this->assertStringContainsString( 'wp_mcp_ai_rotate_master_key', $output );
	}

	/**
	 * Test render_rotation_section without master key.
	 */
	public function test_render_rotation_section_without_key() {
		// Ensure no master key exists.
		delete_option( WP_MCP_AI_Encryption::MASTER_KEY_OPTION );

		// Capture output.
		ob_start();
		WP_MCP_AI_Admin_Key_Rotation::render_rotation_section();
		$output = ob_get_clean();

		// Verify output shows message about no key.
		$this->assertStringContainsString( 'No master encryption key has been generated yet', $output );
	}
}
