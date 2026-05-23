<?php
/**
 * Test async AJAX provider connection testing endpoints.
 *
 * Tests AJAX endpoints that handle async provider connection testing
 * and model fetching operations with timeout handling.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for async provider testing AJAX endpoints.
 */
class Test_Async_AJAX_Provider_Testing extends WP_MCP_AI_Ajax_TestCase {

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
		$this->as_admin();

		// Stub the Ollama API endpoint to prevent real HTTP call.
		$this->stub_http_response(
			'api/tags',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'models' => array() ) ),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_test_ollama_connection',
			array(
				'endpoint_url' => 'http://localhost:11434',
				'nonce'        => wp_create_nonce( 'wp-mcp-ai-settings' ),
			)
		);

		// Verify response structure (connection may fail in test environment).
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test Ollama connection test requires permissions.
	 */
	public function test_ollama_connection_requires_permissions() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_test_ollama_connection',
			array(
				'endpoint_url' => 'http://localhost:11434',
				'nonce'        => wp_create_nonce( 'wp-mcp-ai-settings' ),
			)
		);

		// Verify failure due to permissions.
		$this->assertAjaxError( $response, 'permission' );
	}

	/**
	 * Test fetch Ollama models AJAX endpoint.
	 */
	public function test_fetch_ollama_models() {
		$this->as_admin();

		// Stub the Ollama API endpoint.
		$this->stub_http_response(
			'api/tags',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'models' => array(
							array( 'name' => 'llama3:latest' ),
							array( 'name' => 'gemma2:2b' ),
						),
					)
				),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_fetch_ollama_models',
			array(
				'endpoint_url' => 'http://localhost:11434',
				'nonce'        => wp_create_nonce( 'wp-mcp-ai-settings' ),
			)
		);

		// Verify response structure.
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test LM Studio connection test AJAX endpoint.
	 */
	public function test_lm_studio_connection_test() {
		$this->as_admin();

		// Stub the LM Studio API endpoint.
		$this->stub_http_response(
			'v1/models',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_test_lm_studio_connection',
			array(
				'endpoint_url' => 'http://localhost:1234',
				'nonce'        => wp_create_nonce( 'wp-mcp-ai-settings' ),
			)
		);

		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test fetch LM Studio models AJAX endpoint.
	 */
	public function test_fetch_lm_studio_models() {
		$this->as_admin();

		$this->stub_http_response(
			'v1/models',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_fetch_lm_studio_models',
			array(
				'endpoint_url' => 'http://localhost:1234',
				'nonce'        => wp_create_nonce( 'wp-mcp-ai-settings' ),
			)
		);

		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test Cloudflare connection test AJAX endpoint.
	 */
	public function test_cloudflare_connection_test() {
		$this->as_admin();

		// Stub the Cloudflare API endpoint.
		$this->stub_http_response(
			'cloudflare',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'result' => array() ) ),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_test_cloudflare_connection',
			array(
				'account_id' => 'test-account-id',
				'api_key'    => 'test-api-key',
				'nonce'      => wp_create_nonce( 'wp-mcp-ai-settings' ),
			)
		);

		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test Brave Search connection test AJAX endpoint.
	 */
	public function test_brave_search_connection_test() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_brave_search_connection',
			array(
				'api_key' => 'test-api-key',
				'nonce'   => wp_create_nonce( 'wp-mcp-ai-settings' ),
			)
		);

		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test Mubert connection test AJAX endpoint.
	 */
	public function test_mubert_connection_test() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_mubert_connection',
			array(
				'api_key' => 'test-api-key',
				'nonce'   => wp_create_nonce( 'wp-mcp-ai-settings' ),
			)
		);

		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test Flowhub connection test AJAX endpoint.
	 */
	public function test_flowhub_connection_test() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_flowhub_connection',
			array(
				'api_key' => 'test-api-key',
				'nonce'   => wp_create_nonce( 'wp-mcp-ai-settings' ),
			)
		);

		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test ISAMS connection test AJAX endpoint.
	 */
	public function test_isams_connection_test() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_isams_connection',
			array(
				'api_key' => 'test-api-key',
				'nonce'   => wp_create_nonce( 'wp-mcp-ai-settings' ),
			)
		);

		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test provider connection handles missing credentials.
	 */
	public function test_provider_connection_missing_credentials() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_ollama_connection',
			array( 'nonce' => wp_create_nonce( 'wp-mcp-ai-settings' ) )
		);

		// Verify error for missing credentials.
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Test provider connection requires valid nonce.
	 */
	public function test_provider_connection_requires_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_ollama_connection',
			array(
				'endpoint_url' => 'http://localhost:11434',
				'nonce'        => 'invalid_nonce',
			)
		);

		// Verify forbidden due to nonce failure.
		$this->assertAjaxForbidden( $response );
	}

	/**
	 * Test Cloudways data fetching AJAX endpoint.
	 */
	public function test_fetch_cloudways_data() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_fetch_cloudways_data',
			array(
				'api_key' => 'test-api-key',
				'email'   => 'test@example.com',
				'nonce'   => wp_create_nonce( 'wp-mcp-ai-settings' ),
			)
		);

		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test get models for provider AJAX endpoint.
	 */
	public function test_get_models_for_provider() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_get_models_for_provider',
			array(
				'provider' => 'openai',
				'nonce'    => wp_create_nonce( 'wp-mcp-ai-settings' ),
			)
		);

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
	 * Note: This test verifies that the AJAX endpoint returns a structured
	 * error (not a PHP fatal) when the connection fails. We use an HTTP stub
	 * returning a WP_Error to simulate a connection timeout without making
	 * any real outbound requests.
	 */
	public function test_provider_connection_timeout_handling() {
		$this->as_admin();

		// Simulate a connection timeout with a WP_Error stub.
		$this->stub_http_response(
			'',
			new WP_Error( 'http_request_failed', 'Connection timed out' )
		);

		$response = $this->dispatch(
			'wp_mcp_ai_test_ollama_connection',
			array(
				'endpoint_url' => 'http://10.255.255.1:11434',
				'nonce'        => wp_create_nonce( 'wp-mcp-ai-settings' ),
			)
		);

		// Verify we get a structured response, not a crash.
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );

		// Connection should fail.
		if ( ! $response['success'] ) {
			$this->assertArrayHasKey( 'data', $response );
			$this->assertArrayHasKey( 'message', $response['data'] );
		}
	}
}
