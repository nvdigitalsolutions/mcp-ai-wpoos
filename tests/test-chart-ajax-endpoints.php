<?php
/**
 * Test chart AJAX endpoints registration
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for chart AJAX endpoint registration
 */
class Test_Chart_AJAX_Endpoints extends WP_Ajax_UnitTestCase {

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

		// Create a test user with admin capabilities.
		$this->test_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
	}

	/**
	 * Test that wp_mcp_ai_get_provider_distribution AJAX action is registered.
	 */
	public function test_provider_distribution_ajax_action_registered() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Set up AJAX request.
		$_POST['action'] = 'wp_mcp_ai_get_provider_distribution';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_token_charts' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_get_provider_distribution' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - AJAX handlers call wp_die().
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success (even with no data, it should succeed).
		$this->assertTrue( $response['success'], 'Provider distribution AJAX endpoint should be registered and respond successfully' );
		$this->assertArrayHasKey( 'data', $response, 'Response should have data key' );
	}

	/**
	 * Test that wp_mcp_ai_get_model_distribution AJAX action is registered.
	 */
	public function test_model_distribution_ajax_action_registered() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Set up AJAX request.
		$_POST['action'] = 'wp_mcp_ai_get_model_distribution';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_token_charts' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_get_model_distribution' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - AJAX handlers call wp_die().
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success (even with no data, it should succeed).
		$this->assertTrue( $response['success'], 'Model distribution AJAX endpoint should be registered and respond successfully' );
		$this->assertArrayHasKey( 'data', $response, 'Response should have data key' );
	}

	/**
	 * Test provider distribution with invalid nonce fails.
	 */
	public function test_provider_distribution_invalid_nonce_fails() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Set up AJAX request with invalid nonce.
		$_POST['action'] = 'wp_mcp_ai_get_provider_distribution';
		$_POST['nonce']  = 'invalid_nonce';

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_get_provider_distribution' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'], 'Invalid nonce should fail' );
		$this->assertStringContainsString( 'security token', strtolower( $response['data']['message'] ) );
	}

	/**
	 * Test model distribution with invalid nonce fails.
	 */
	public function test_model_distribution_invalid_nonce_fails() {
		// Set up admin user.
		wp_set_current_user( $this->test_user_id );

		// Set up AJAX request with invalid nonce.
		$_POST['action'] = 'wp_mcp_ai_get_model_distribution';
		$_POST['nonce']  = 'invalid_nonce';

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_get_model_distribution' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'], 'Invalid nonce should fail' );
		$this->assertStringContainsString( 'security token', strtolower( $response['data']['message'] ) );
	}
}
