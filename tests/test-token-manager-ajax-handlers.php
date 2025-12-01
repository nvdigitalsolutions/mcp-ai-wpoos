<?php
/**
 * Test token manager AJAX handlers
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for token manager AJAX endpoints
 */
class Test_Token_Manager_AJAX_Handlers extends WP_Ajax_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $test_user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a test user.
		$this->test_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Clean up any existing data.
		delete_user_meta( $this->test_user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY );
		delete_option( WP_MCP_AI_Tool_Token_Limits::LIMITS_OPTION );
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		delete_user_meta( $this->test_user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY );
		delete_option( WP_MCP_AI_Tool_Token_Limits::LIMITS_OPTION );

		parent::tearDown();
	}

	/**
	 * Test save tool limits AJAX endpoint with correct nonce.
	 */
	public function test_save_tool_limits_success() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Set up AJAX request with correct nonce.
		$_POST['action'] = 'wp_mcp_ai_save_tool_limits';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_dashboard' );
		$_POST['limits'] = array(
			'run_crawl4ai_job' => 250000,
			'general_tools'    => 150000,
		);

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_save_tool_limits' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - AJAX handlers call wp_die().
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'AJAX request should succeed' );
		$this->assertStringContainsString( 'saved', strtolower( $response['data']['message'] ) );

		// Verify limits were actually saved.
		$this->assertEquals( 250000, WP_MCP_AI_Tool_Token_Limits::get_tool_limit( 'run_crawl4ai_job' ) );
		$this->assertEquals( 150000, WP_MCP_AI_Tool_Token_Limits::get_tool_limit( 'general_tools' ) );
	}

	/**
	 * Test save tool limits with no changes.
	 */
	public function test_save_tool_limits_no_changes() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// First, set some initial limits.
		WP_MCP_AI_Tool_Token_Limits::set_tool_limit( 'run_crawl4ai_job', 250000 );
		WP_MCP_AI_Tool_Token_Limits::set_tool_limit( 'general_tools', 150000 );

		// Set up AJAX request with the same limits (no changes).
		$_POST['action'] = 'wp_mcp_ai_save_tool_limits';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_dashboard' );
		$_POST['limits'] = array(
			'run_crawl4ai_job' => 250000,
			'general_tools'    => 150000,
		);

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_save_tool_limits' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success with no_changes flag.
		$this->assertTrue( $response['success'], 'AJAX request should succeed' );
		$this->assertTrue( isset( $response['data']['no_changes'] ), 'Response should have no_changes flag' );
		$this->assertTrue( $response['data']['no_changes'], 'no_changes flag should be true' );
		$this->assertStringContainsString( 'no changes', strtolower( $response['data']['message'] ) );

		// Verify limits are still the same.
		$this->assertEquals( 250000, WP_MCP_AI_Tool_Token_Limits::get_tool_limit( 'run_crawl4ai_job' ) );
		$this->assertEquals( 150000, WP_MCP_AI_Tool_Token_Limits::get_tool_limit( 'general_tools' ) );
	}

	/**
	 * Test save tool limits with invalid nonce.
	 */
	public function test_save_tool_limits_invalid_nonce() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Set up AJAX request with invalid nonce.
		$_POST['action'] = 'wp_mcp_ai_save_tool_limits';
		$_POST['nonce']  = 'invalid_nonce';
		$_POST['limits'] = array(
			'run_crawl4ai_job' => 250000,
		);

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_save_tool_limits' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'security token', strtolower( $response['data']['message'] ) );
	}

	/**
	 * Test save tool limits without proper permissions.
	 */
	public function test_save_tool_limits_insufficient_permissions() {
		// Set up subscriber user (no manage_options capability).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set up AJAX request.
		$_POST['action'] = 'wp_mcp_ai_save_tool_limits';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_dashboard' );
		$_POST['limits'] = array(
			'run_crawl4ai_job' => 250000,
		);

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_save_tool_limits' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', strtolower( $response['data']['message'] ) );
	}

	/**
	 * Test reset user token usage AJAX endpoint.
	 */
	public function test_reset_user_token_usage_success() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Add some usage data.
		update_user_meta(
			$this->test_user_id,
			WP_MCP_AI_Usage_Tracker::USER_META_KEY,
			array(
				'openai' => array(
					'gpt-4' => array(
						'requests'     => 10,
						'total_tokens' => 5000,
					),
				),
			)
		);

		// Set up AJAX request.
		$_POST['action']  = 'wp_mcp_ai_reset_user_token_usage';
		$_POST['nonce']   = wp_create_nonce( 'wp_mcp_ai_dashboard' );
		$_POST['user_id'] = $this->test_user_id;

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_reset_user_token_usage' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'AJAX request should succeed' );

		// Verify data was deleted.
		$usage = get_user_meta( $this->test_user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY, true );
		$this->assertEmpty( $usage );
	}

	/**
	 * Test reset all token usage AJAX endpoint.
	 */
	public function test_reset_all_token_usage_success() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Create another test user with usage data.
		$user2_id = $this->factory->user->create();
		update_user_meta(
			$user2_id,
			WP_MCP_AI_Usage_Tracker::USER_META_KEY,
			array(
				'openai' => array(
					'gpt-4' => array(
						'requests'     => 5,
						'total_tokens' => 2500,
					),
				),
			)
		);

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
		$this->assertTrue( $response['success'], 'AJAX request should succeed' );
		$this->assertStringContainsString( 'reset', strtolower( $response['data']['message'] ) );

		// Verify data was deleted for all users.
		$usage1 = get_user_meta( $this->test_user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY, true );
		$usage2 = get_user_meta( $user2_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY, true );
		$this->assertEmpty( $usage1 );
		$this->assertEmpty( $usage2 );

		// Clean up.
		wp_delete_user( $user2_id );
	}

	/**
	 * Test that old nonce fails (regression test).
	 */
	public function test_save_tool_limits_with_old_nonce_fails() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Set up AJAX request with OLD nonce (wp-mcp-ai-settings).
		$_POST['action'] = 'wp_mcp_ai_save_tool_limits';
		$_POST['nonce']  = wp_create_nonce( 'wp-mcp-ai-settings' );
		$_POST['limits'] = array(
			'run_crawl4ai_job' => 250000,
		);

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_save_tool_limits' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure - old nonce should NOT work.
		$this->assertFalse( $response['success'], 'Old nonce should fail' );
		$this->assertStringContainsString( 'security token', strtolower( $response['data']['message'] ) );
	}

	/**
	 * Test save tool limits with model preferences.
	 */
	public function test_save_tool_limits_with_model_preferences() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Set up AJAX request with model preferences.
		$_POST['action']            = 'wp_mcp_ai_save_tool_limits';
		$_POST['nonce']             = wp_create_nonce( 'wp_mcp_ai_dashboard' );
		$_POST['limits']            = array();
		$_POST['multipliers']       = array();
		$_POST['model_preferences'] = array(
			'run_crawl4ai_job' => 'gpt-4o',
			'search_content'   => 'claude-3-5-sonnet-20241022',
			'web_search'       => 'default',
		);

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_save_tool_limits' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'AJAX request should succeed' );

		// Verify model preferences were saved.
		$this->assertEquals( 'gpt-4o', WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( 'run_crawl4ai_job' ) );
		$this->assertEquals( 'claude-3-5-sonnet-20241022', WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( 'search_content' ) );
		$this->assertEquals( 'default', WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( 'web_search' ) );
	}

	/**
	 * Test save tool limits with combined settings (limits, multipliers, model preferences).
	 */
	public function test_save_tool_limits_combined() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Set up AJAX request with all settings.
		$_POST['action']            = 'wp_mcp_ai_save_tool_limits';
		$_POST['nonce']             = wp_create_nonce( 'wp_mcp_ai_dashboard' );
		$_POST['limits']            = array(
			'test_tool' => 100000,
		);
		$_POST['multipliers']       = array(
			'test_tool' => 2.5,
		);
		$_POST['model_preferences'] = array(
			'test_tool' => 'gpt-4o-mini',
		);

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_save_tool_limits' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'AJAX request should succeed' );

		// Verify all settings were saved.
		$this->assertEquals( 100000, WP_MCP_AI_Tool_Token_Limits::get_tool_limit( 'test_tool' ) );
		$this->assertEquals( 2.5, WP_MCP_AI_Tool_Token_Limits::get_tool_multiplier( 'test_tool' ) );
		$this->assertEquals( 'gpt-4o-mini', WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( 'test_tool' ) );
	}
}
