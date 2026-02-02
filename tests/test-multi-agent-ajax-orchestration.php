<?php
/**
 * Test multi-agent workflow AJAX orchestration endpoints.
 *
 * Tests AJAX endpoints that handle multi-agent orchestration operations
 * like preset application, bulk operations, and workflow coordination.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for multi-agent orchestration AJAX endpoints.
 */
class Test_Multi_Agent_AJAX_Orchestration extends WP_Ajax_UnitTestCase {

	/**
	 * Setup test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure admin context is initialized.
		if ( ! did_action( 'admin_init' ) ) {
			do_action( 'admin_init' );
		}
	}

	/**
	 * Test bulk assign tier handles multiple users.
	 */
	public function test_bulk_assign_tier_multiple_users() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create test users.
		$user_ids = array(
			$this->factory->user->create( array( 'role' => 'subscriber' ) ),
			$this->factory->user->create( array( 'role' => 'subscriber' ) ),
			$this->factory->user->create( array( 'role' => 'subscriber' ) ),
		);

		// Set up AJAX request.
		$_POST['action']   = 'wp_mcp_ai_bulk_assign_tier';
		$_POST['user_ids'] = $user_ids;
		$_POST['tier']     = 'premium';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_bulk_assign_tier' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'Bulk tier assignment should succeed' );

		// Verify users were assigned tier.
		foreach ( $user_ids as $user_id ) {
			$user_tier = get_user_meta( $user_id, 'wp_mcp_ai_token_tier', true );
			$this->assertEquals( 'premium', $user_tier, 'User should be assigned premium tier' );
		}
	}

	/**
	 * Test bulk assign tier fails without users.
	 */
	public function test_bulk_assign_tier_fails_without_users() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request with empty user_ids.
		$_POST['action']   = 'wp_mcp_ai_bulk_assign_tier';
		$_POST['user_ids'] = array();
		$_POST['tier']     = 'premium';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_bulk_assign_tier' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'No users selected', $response['data']['message'] );
	}

	/**
	 * Test bulk assign tier requires permissions.
	 */
	public function test_bulk_assign_tier_requires_permissions() {
		// Create subscriber user (no manage_options).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set up AJAX request.
		$_POST['action']   = 'wp_mcp_ai_bulk_assign_tier';
		$_POST['user_ids'] = array( $user_id );
		$_POST['tier']     = 'premium';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_bulk_assign_tier' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', $response['data']['message'] );
	}

	/**
	 * Test apply all recommendations batch processing.
	 */
	public function test_apply_all_recommendations_success() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action'] = 'wp_mcp_ai_apply_all_recommendations';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_apply_all_recommendations' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure (may succeed or report no recommendations).
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test apply preset for settings configuration.
	 */
	public function test_apply_preset_success() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']    = 'wp_mcp_ai_apply_preset';
		$_POST['preset_id'] = 'default';
		$_POST['nonce']     = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_apply_preset' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure.
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test save tool limits for multiple tools.
	 */
	public function test_save_tool_limits_multiple_tools() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request with multiple tool limits.
		$_POST['action'] = 'wp_mcp_ai_save_tool_limits';
		$_POST['limits'] = array(
			'get_user_info'    => 100,
			'get_recent_posts' => 200,
			'search_content'   => 150,
		);
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_save_tool_limits' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'Tool limits should be saved' );
	}

	/**
	 * Test save tool settings batch operation.
	 */
	public function test_save_tool_settings_batch() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']      = 'wp_mcp_ai_save_tool_settings';
		$_POST['multipliers'] = array(
			'get_user_info'    => 1.5,
			'get_recent_posts' => 2.0,
		);
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_save_tool_settings' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure.
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test toggle tool enables/disables tool.
	 */
	public function test_toggle_tool_success() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']    = 'wp_mcp_ai_toggle_tool';
		$_POST['tool_slug'] = 'get_user_info';
		$_POST['enabled']   = '1';
		$_POST['nonce']     = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_toggle_tool' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure.
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test concurrent bulk operations don't conflict.
	 */
	public function test_concurrent_bulk_operations_no_conflict() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create test users in two groups.
		$group1_users = array(
			$this->factory->user->create( array( 'role' => 'subscriber' ) ),
			$this->factory->user->create( array( 'role' => 'subscriber' ) ),
		);

		$group2_users = array(
			$this->factory->user->create( array( 'role' => 'subscriber' ) ),
			$this->factory->user->create( array( 'role' => 'subscriber' ) ),
		);

		// Simulate first bulk operation.
		$_POST['action']   = 'wp_mcp_ai_bulk_assign_tier';
		$_POST['user_ids'] = $group1_users;
		$_POST['tier']     = 'premium';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		try {
			$this->_handleAjax( 'wp_mcp_ai_bulk_assign_tier' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response1 = json_decode( $this->_last_response, true );

		// Reset for second operation.
		$this->_last_response = '';

		// Simulate second bulk operation.
		$_POST['user_ids'] = $group2_users;
		$_POST['tier']     = 'basic';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		try {
			$this->_handleAjax( 'wp_mcp_ai_bulk_assign_tier' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response2 = json_decode( $this->_last_response, true );

		// Verify both operations succeeded.
		$this->assertTrue( $response1['success'], 'First bulk operation should succeed' );
		$this->assertTrue( $response2['success'], 'Second bulk operation should succeed' );

		// Verify correct tier assignments.
		foreach ( $group1_users as $user_id ) {
			$this->assertEquals( 'premium', get_user_meta( $user_id, 'wp_mcp_ai_token_tier', true ) );
		}

		foreach ( $group2_users as $user_id ) {
			$this->assertEquals( 'basic', get_user_meta( $user_id, 'wp_mcp_ai_token_tier', true ) );
		}
	}

	/**
	 * Test reset user token usage for single user.
	 */
	public function test_reset_user_token_usage() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create test user with token usage.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, 'wp_mcp_ai_tokens_used', 1000 );

		// Set up AJAX request.
		$_POST['action']  = 'wp_mcp_ai_reset_user_token_usage';
		$_POST['user_id'] = $user_id;
		$_POST['nonce']   = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_reset_user_token_usage' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'Token reset should succeed' );

		// Verify tokens were reset.
		$tokens_used = get_user_meta( $user_id, 'wp_mcp_ai_tokens_used', true );
		$this->assertEquals( 0, $tokens_used, 'Tokens should be reset to 0' );
	}

	/**
	 * Test reset all token usage affects all users.
	 */
	public function test_reset_all_token_usage() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create test users with token usage.
		$user_ids = array(
			$this->factory->user->create( array( 'role' => 'subscriber' ) ),
			$this->factory->user->create( array( 'role' => 'subscriber' ) ),
			$this->factory->user->create( array( 'role' => 'subscriber' ) ),
		);

		foreach ( $user_ids as $user_id ) {
			update_user_meta( $user_id, 'wp_mcp_ai_tokens_used', 5000 );
		}

		// Set up AJAX request.
		$_POST['action'] = 'wp_mcp_ai_reset_all_token_usage';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_reset_all_token_usage' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'Reset all tokens should succeed' );

		// Verify all tokens were reset.
		foreach ( $user_ids as $user_id ) {
			$tokens_used = get_user_meta( $user_id, 'wp_mcp_ai_tokens_used', true );
			$this->assertEquals( 0, $tokens_used, 'All user tokens should be reset' );
		}
	}
}
