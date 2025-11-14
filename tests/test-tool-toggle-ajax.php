<?php
/**
 * Tests for Tool Toggle AJAX Handler
 *
 * @package WP_MCP_AI
 */

/**
 * Test Tool Toggle AJAX functionality.
 */
class Test_Tool_Toggle_AJAX extends WP_Ajax_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Regular user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create regular user.
		$this->user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Clean up disabled tools option.
		delete_option( 'wp_mcp_ai_disabled_tools' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_disabled_tools' );
		parent::tearDown();
	}

	/**
	 * Test that non-admin users cannot toggle tools.
	 */
	public function test_non_admin_cannot_toggle_tools() {
		wp_set_current_user( $this->user_id );

		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_admin' );
		$_POST['tool_slug']   = 'search_content';
		$_POST['tool_action'] = 'disable';

		try {
			$this->_handleAjax( 'wp_mcp_ai_toggle_tool' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', strtolower( $response['data']['message'] ) );
	}

	/**
	 * Test that admin can disable a tool.
	 */
	public function test_admin_can_disable_tool() {
		wp_set_current_user( $this->admin_user_id );

		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_admin' );
		$_POST['tool_slug']   = 'search_content';
		$_POST['tool_action'] = 'disable';

		try {
			$this->_handleAjax( 'wp_mcp_ai_toggle_tool' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertTrue( $response['success'] );
		$this->assertFalse( $response['data']['enabled'] );

		// Verify tool is actually disabled.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->assertFalse( $registry->is_tool_enabled( 'search_content' ) );
	}

	/**
	 * Test that admin can enable a tool.
	 */
	public function test_admin_can_enable_tool() {
		wp_set_current_user( $this->admin_user_id );

		// Disable tool first.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->disable_tool( 'search_content' );

		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_admin' );
		$_POST['tool_slug']   = 'search_content';
		$_POST['tool_action'] = 'enable';

		try {
			$this->_handleAjax( 'wp_mcp_ai_toggle_tool' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['enabled'] );

		// Verify tool is actually enabled.
		$this->assertTrue( $registry->is_tool_enabled( 'search_content' ) );
	}

	/**
	 * Test that missing tool slug returns error.
	 */
	public function test_missing_tool_slug_returns_error() {
		wp_set_current_user( $this->admin_user_id );

		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_admin' );
		$_POST['tool_action'] = 'disable';
		// tool_slug is missing.

		try {
			$this->_handleAjax( 'wp_mcp_ai_toggle_tool' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'required', strtolower( $response['data']['message'] ) );
	}

	/**
	 * Test that invalid action returns error.
	 */
	public function test_invalid_action_returns_error() {
		wp_set_current_user( $this->admin_user_id );

		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_admin' );
		$_POST['tool_slug']   = 'search_content';
		$_POST['tool_action'] = 'invalid_action';

		try {
			$this->_handleAjax( 'wp_mcp_ai_toggle_tool' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'invalid', strtolower( $response['data']['message'] ) );
	}

	/**
	 * Test that non-existent tool returns error.
	 */
	public function test_nonexistent_tool_returns_error() {
		wp_set_current_user( $this->admin_user_id );

		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_admin' );
		$_POST['tool_slug']   = 'nonexistent_tool';
		$_POST['tool_action'] = 'disable';

		try {
			$this->_handleAjax( 'wp_mcp_ai_toggle_tool' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'not found', strtolower( $response['data']['message'] ) );
	}

	/**
	 * Test that invalid nonce is rejected.
	 */
	public function test_invalid_nonce_is_rejected() {
		wp_set_current_user( $this->admin_user_id );

		$_POST['nonce']       = 'invalid_nonce';
		$_POST['tool_slug']   = 'search_content';
		$_POST['tool_action'] = 'disable';

		$this->expectException( 'WPAjaxDieStopException' );
		$this->_handleAjax( 'wp_mcp_ai_toggle_tool' );
	}
}
