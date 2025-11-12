<?php
/**
 * Test chart AJAX handlers for Token Manager
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for chart AJAX endpoints
 */
class Test_Chart_AJAX_Handlers extends WP_Ajax_UnitTestCase {

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

		// Create a test admin user.
		$this->test_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Add some test usage data.
		$this->add_test_usage_data();
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		delete_user_meta( $this->test_user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY );

		parent::tearDown();
	}

	/**
	 * Add test usage data.
	 */
	private function add_test_usage_data() {
		$today = gmdate( 'Y-m-d' );
		$usage_data = array(
			'general_tools' => array(
				'total_tokens' => 5000,
				'requests'     => 10,
				'daily'        => array(
					$today => 5000,
				),
			),
		);
		update_user_meta( $this->test_user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage_data );
	}

	/**
	 * Test get usage trend AJAX endpoint with valid nonce.
	 */
	public function test_get_usage_trend_success() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Set up AJAX request.
		$_POST['action'] = 'wp_mcp_ai_get_usage_trend';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_token_charts' );
		$_POST['days']   = 7;

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_get_usage_trend' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - AJAX handlers call wp_die().
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'AJAX request should succeed' );
		$this->assertArrayHasKey( 'data', $response, 'Response should have data' );
		$this->assertArrayHasKey( 'labels', $response['data'], 'Data should have labels' );
		$this->assertArrayHasKey( 'datasets', $response['data'], 'Data should have datasets' );
	}

	/**
	 * Test get usage trend AJAX endpoint without nonce.
	 */
	public function test_get_usage_trend_without_nonce() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Set up AJAX request without nonce.
		$_POST['action'] = 'wp_mcp_ai_get_usage_trend';
		$_POST['days']   = 7;

		// Make AJAX request - should fail.
		try {
			$this->_handleAjax( 'wp_mcp_ai_get_usage_trend' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Should fail without nonce.
		$this->assertFalse( $response['success'], 'Should fail without valid nonce' );
	}

	/**
	 * Test get usage trend AJAX endpoint as non-admin.
	 */
	public function test_get_usage_trend_as_non_admin() {
		// Create non-admin user.
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		// Set up AJAX request.
		$_POST['action'] = 'wp_mcp_ai_get_usage_trend';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_token_charts' );
		$_POST['days']   = 7;

		// Make AJAX request - should fail.
		try {
			$this->_handleAjax( 'wp_mcp_ai_get_usage_trend' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Should fail for non-admin.
		$this->assertFalse( $response['success'], 'Should fail for non-admin user' );
	}

	/**
	 * Test get tier distribution AJAX endpoint.
	 */
	public function test_get_tier_distribution_success() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Set up AJAX request.
		$_POST['action'] = 'wp_mcp_ai_get_tier_distribution';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_token_charts' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_get_tier_distribution' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'AJAX request should succeed' );
		$this->assertArrayHasKey( 'data', $response );
		$this->assertArrayHasKey( 'labels', $response['data'] );
		$this->assertArrayHasKey( 'values', $response['data'] );
		$this->assertEquals( 3, count( $response['data']['labels'] ), 'Should have 3 tier labels' );
	}

	/**
	 * Test get tool breakdown AJAX endpoint.
	 */
	public function test_get_tool_breakdown_success() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Set up AJAX request.
		$_POST['action'] = 'wp_mcp_ai_get_tool_breakdown';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_token_charts' );
		$_POST['days']   = 7;
		$_POST['limit']  = 10;

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_get_tool_breakdown' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'AJAX request should succeed' );
		$this->assertArrayHasKey( 'data', $response );
		$this->assertArrayHasKey( 'labels', $response['data'] );
		$this->assertArrayHasKey( 'values', $response['data'] );
	}

	/**
	 * Test get tool breakdown with specific user.
	 */
	public function test_get_tool_breakdown_with_user_id() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Set up AJAX request with user_id.
		$_POST['action']  = 'wp_mcp_ai_get_tool_breakdown';
		$_POST['nonce']   = wp_create_nonce( 'wp_mcp_ai_token_charts' );
		$_POST['user_id'] = $this->test_user_id;
		$_POST['days']    = 7;
		$_POST['limit']   = 10;

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_get_tool_breakdown' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'AJAX request should succeed with user_id' );
	}

	/**
	 * Test get usage trend with different day parameters.
	 */
	public function test_get_usage_trend_different_days() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Test with 30 days.
		$_POST['action'] = 'wp_mcp_ai_get_usage_trend';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_token_charts' );
		$_POST['days']   = 30;

		try {
			$this->_handleAjax( 'wp_mcp_ai_get_usage_trend' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'], 'Should succeed with 30 days' );
		$this->assertEquals( 30, count( $response['data']['labels'] ), 'Should have 30 labels' );
	}
}
