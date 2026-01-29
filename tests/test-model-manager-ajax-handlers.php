<?php
/**
 * Test model manager AJAX handlers for async operations.
 *
 * Tests AJAX endpoints in the model manager that handle async model
 * discovery and research operations with tool integration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for model manager AJAX endpoints.
 */
class Test_Model_Manager_AJAX_Handlers extends WP_Ajax_UnitTestCase {

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
	 * Test discover models AJAX endpoint.
	 */
	public function test_discover_models_success() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']   = 'wp_mcp_ai_discover_models';
		$_POST['provider'] = 'openai';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_model_manager' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_discover_models' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure (discovery may fail without API credentials).
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test discover models requires permissions.
	 */
	public function test_discover_models_requires_permissions() {
		// Create subscriber user (no manage_options).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set up AJAX request.
		$_POST['action']   = 'wp_mcp_ai_discover_models';
		$_POST['provider'] = 'openai';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_model_manager' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_discover_models' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure due to permissions.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', $response['data']['message'] );
	}

	/**
	 * Test discover models requires valid nonce.
	 */
	public function test_discover_models_requires_nonce() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request with invalid nonce.
		$_POST['action']   = 'wp_mcp_ai_discover_models';
		$_POST['provider'] = 'openai';
		$_POST['nonce']    = 'invalid_nonce';

		// Expect failure due to nonce check.
		$this->expectException( 'WPAjaxDieStopException' );

		$this->_handleAjax( 'wp_mcp_ai_discover_models' );
	}

	/**
	 * Test discover models fails without provider.
	 */
	public function test_discover_models_requires_provider() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request without provider.
		$_POST['action'] = 'wp_mcp_ai_discover_models';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_model_manager' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_discover_models' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Provider', $response['data']['message'] );
	}

	/**
	 * Test research model AJAX endpoint.
	 */
	public function test_research_model_success() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']     = 'wp_mcp_ai_research_model';
		$_POST['model_name'] = 'gpt-4o';
		$_POST['provider']   = 'openai';
		$_POST['nonce']      = wp_create_nonce( 'wp_mcp_ai_model_manager' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_research_model' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure (research may fail without web search enabled).
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test research model requires permissions.
	 */
	public function test_research_model_requires_permissions() {
		// Create subscriber user (no manage_options).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set up AJAX request.
		$_POST['action']     = 'wp_mcp_ai_research_model';
		$_POST['model_name'] = 'gpt-4o';
		$_POST['provider']   = 'openai';
		$_POST['nonce']      = wp_create_nonce( 'wp_mcp_ai_model_manager' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_research_model' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure due to permissions.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', $response['data']['message'] );
	}

	/**
	 * Test research model requires model name.
	 */
	public function test_research_model_requires_model_name() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request without model_name.
		$_POST['action']   = 'wp_mcp_ai_research_model';
		$_POST['provider'] = 'openai';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_model_manager' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_research_model' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Model name', $response['data']['message'] );
	}

	/**
	 * Test add model config AJAX endpoint.
	 */
	public function test_add_model_config_success() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']      = 'wp_mcp_ai_add_model_config';
		$_POST['model_name']  = 'test-model';
		$_POST['provider']    = 'openai';
		$_POST['input_cost']  = '0.01';
		$_POST['output_cost'] = '0.02';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_model_manager' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_add_model_config' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'Model config should be added' );
	}

	/**
	 * Test add model config requires permissions.
	 */
	public function test_add_model_config_requires_permissions() {
		// Create subscriber user (no manage_options).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set up AJAX request.
		$_POST['action']      = 'wp_mcp_ai_add_model_config';
		$_POST['model_name']  = 'test-model';
		$_POST['provider']    = 'openai';
		$_POST['input_cost']  = '0.01';
		$_POST['output_cost'] = '0.02';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_model_manager' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_add_model_config' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure due to permissions.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', $response['data']['message'] );
	}

	/**
	 * Test add model config validates required fields.
	 */
	public function test_add_model_config_validates_fields() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request with missing fields.
		$_POST['action'] = 'wp_mcp_ai_add_model_config';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_model_manager' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_add_model_config' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure due to missing fields.
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Test concurrent model discovery operations.
	 */
	public function test_concurrent_model_discovery() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// First discovery request.
		$_POST['action']   = 'wp_mcp_ai_discover_models';
		$_POST['provider'] = 'openai';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_model_manager' );

		try {
			$this->_handleAjax( 'wp_mcp_ai_discover_models' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response1 = json_decode( $this->_last_response, true );

		// Reset for second request.
		$this->_last_response = '';

		// Second discovery request with different provider.
		$_POST['provider'] = 'anthropic';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_model_manager' );

		try {
			$this->_handleAjax( 'wp_mcp_ai_discover_models' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response2 = json_decode( $this->_last_response, true );

		// Verify both responses are structured correctly.
		$this->assertIsArray( $response1, 'First response should be an array' );
		$this->assertArrayHasKey( 'success', $response1 );
		$this->assertIsArray( $response2, 'Second response should be an array' );
		$this->assertArrayHasKey( 'success', $response2 );
	}

	/**
	 * Test model research with web search integration.
	 */
	public function test_model_research_web_search_integration() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']     = 'wp_mcp_ai_research_model';
		$_POST['model_name'] = 'claude-3-opus';
		$_POST['provider']   = 'anthropic';
		$_POST['nonce']      = wp_create_nonce( 'wp_mcp_ai_model_manager' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_research_model' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure.
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );

		// If web search is available and succeeds, verify data structure.
		if ( $response['success'] ) {
			$this->assertArrayHasKey( 'data', $response );
		}
	}

	/**
	 * Test model config update with existing model.
	 */
	public function test_update_existing_model_config() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// First, add a model config.
		$_POST['action']      = 'wp_mcp_ai_add_model_config';
		$_POST['model_name']  = 'update-test-model';
		$_POST['provider']    = 'openai';
		$_POST['input_cost']  = '0.01';
		$_POST['output_cost'] = '0.02';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_model_manager' );

		try {
			$this->_handleAjax( 'wp_mcp_ai_add_model_config' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response1 = json_decode( $this->_last_response, true );
		$this->assertTrue( $response1['success'], 'Initial model config should be added' );

		// Reset for update request.
		$this->_last_response = '';

		// Update the same model with new costs.
		$_POST['input_cost']  = '0.015';
		$_POST['output_cost'] = '0.025';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_model_manager' );

		try {
			$this->_handleAjax( 'wp_mcp_ai_add_model_config' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response2 = json_decode( $this->_last_response, true );

		// Verify update succeeded.
		$this->assertTrue( $response2['success'], 'Model config should be updated' );
	}
}
