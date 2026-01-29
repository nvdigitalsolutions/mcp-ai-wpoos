<?php
/**
 * Test async AJAX provider connection testing endpoints.
 *
 * Tests AJAX endpoints that handle async provider connection testing
 * and model fetching operations with timeout handling.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for async provider testing AJAX endpoints.
 */
class Test_Async_AJAX_Provider_Testing extends WP_Ajax_UnitTestCase {

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
	 * Test Ollama connection test AJAX endpoint.
	 */
	public function test_ollama_connection_test() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']   = 'wp_mcp_ai_test_ollama_connection';
		$_POST['base_url'] = 'http://localhost:11434';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_test_ollama_connection' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure (connection may fail in test environment).
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test Ollama connection test requires permissions.
	 */
	public function test_ollama_connection_requires_permissions() {
		// Create subscriber user (no manage_options).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set up AJAX request.
		$_POST['action']   = 'wp_mcp_ai_test_ollama_connection';
		$_POST['base_url'] = 'http://localhost:11434';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_test_ollama_connection' );
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
	 * Test fetch Ollama models AJAX endpoint.
	 */
	public function test_fetch_ollama_models() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']   = 'wp_mcp_ai_fetch_ollama_models';
		$_POST['base_url'] = 'http://localhost:11434';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_fetch_ollama_models' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure (fetch may fail in test environment).
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test LM Studio connection test AJAX endpoint.
	 */
	public function test_lm_studio_connection_test() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']   = 'wp_mcp_ai_test_lm_studio_connection';
		$_POST['base_url'] = 'http://localhost:1234';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_test_lm_studio_connection' );
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
	 * Test fetch LM Studio models AJAX endpoint.
	 */
	public function test_fetch_lm_studio_models() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']   = 'wp_mcp_ai_fetch_lm_studio_models';
		$_POST['base_url'] = 'http://localhost:1234';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_fetch_lm_studio_models' );
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
	 * Test Cloudflare connection test AJAX endpoint.
	 */
	public function test_cloudflare_connection_test() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']     = 'wp_mcp_ai_test_cloudflare_connection';
		$_POST['account_id'] = 'test-account-id';
		$_POST['api_key']    = 'test-api-key';
		$_POST['nonce']      = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_test_cloudflare_connection' );
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
	 * Test Brave Search connection test AJAX endpoint.
	 */
	public function test_brave_search_connection_test() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']  = 'wp_mcp_ai_test_brave_search_connection';
		$_POST['api_key'] = 'test-api-key';
		$_POST['nonce']   = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_test_brave_search_connection' );
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
	 * Test Mubert connection test AJAX endpoint.
	 */
	public function test_mubert_connection_test() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']  = 'wp_mcp_ai_test_mubert_connection';
		$_POST['api_key'] = 'test-api-key';
		$_POST['nonce']   = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_test_mubert_connection' );
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
	 * Test Flowhub connection test AJAX endpoint.
	 */
	public function test_flowhub_connection_test() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']  = 'wp_mcp_ai_test_flowhub_connection';
		$_POST['api_key'] = 'test-api-key';
		$_POST['nonce']   = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_test_flowhub_connection' );
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
	 * Test ISAMS connection test AJAX endpoint.
	 */
	public function test_isams_connection_test() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']  = 'wp_mcp_ai_test_isams_connection';
		$_POST['api_key'] = 'test-api-key';
		$_POST['nonce']   = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_test_isams_connection' );
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
	 * Test provider connection handles missing credentials.
	 */
	public function test_provider_connection_missing_credentials() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request without required credentials.
		$_POST['action'] = 'wp_mcp_ai_test_ollama_connection';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_dashboard' );
		// Intentionally omitting base_url.

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_test_ollama_connection' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify error for missing credentials.
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Test provider connection requires valid nonce.
	 */
	public function test_provider_connection_requires_nonce() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request with invalid nonce.
		$_POST['action']   = 'wp_mcp_ai_test_ollama_connection';
		$_POST['base_url'] = 'http://localhost:11434';
		$_POST['nonce']    = 'invalid_nonce';

		// Expect failure due to nonce check.
		$this->expectException( 'WPAjaxDieStopException' );

		$this->_handleAjax( 'wp_mcp_ai_test_ollama_connection' );
	}

	/**
	 * Test Cloudways data fetching AJAX endpoint.
	 */
	public function test_fetch_cloudways_data() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']  = 'wp_mcp_ai_fetch_cloudways_data';
		$_POST['api_key'] = 'test-api-key';
		$_POST['email']   = 'test@example.com';
		$_POST['nonce']   = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_fetch_cloudways_data' );
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
	 * Test get models for provider AJAX endpoint.
	 */
	public function test_get_models_for_provider() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']   = 'wp_mcp_ai_get_models_for_provider';
		$_POST['provider'] = 'openai';
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_get_models_for_provider' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure (should return available models).
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );

		if ( $response['success'] ) {
			$this->assertArrayHasKey( 'data', $response );
			$this->assertArrayHasKey( 'models', $response['data'] );
			$this->assertIsArray( $response['data']['models'] );
		}
	}

	/**
	 * Test timeout handling for slow provider connections.
	 *
	 * Note: This test simulates timeout scenarios by testing response structure,
	 * as actual timeouts are difficult to test in unit tests.
	 */
	public function test_provider_connection_timeout_handling() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Use an invalid URL that should timeout.
		$_POST['action']   = 'wp_mcp_ai_test_ollama_connection';
		$_POST['base_url'] = 'http://10.255.255.1:11434'; // Non-routable IP.
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_test_ollama_connection' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure (should fail gracefully).
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );

		// If connection fails, should have error message.
		if ( ! $response['success'] ) {
			$this->assertArrayHasKey( 'data', $response );
			$this->assertArrayHasKey( 'message', $response['data'] );
		}
	}
}
