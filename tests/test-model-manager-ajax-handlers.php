<?php
/**
 * Test model manager AJAX handlers for async operations.
 *
 * Tests AJAX endpoints in the model manager that handle async model
 * discovery and research operations with tool integration.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for model manager AJAX endpoints.
 */
class Test_Model_Manager_AJAX_Handlers extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Setup test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure admin context is initialized.
		if ( ! did_action( 'admin_init' ) ) {
			do_action( 'admin_init' );
		}

		// The handler file is only loaded under is_admin() in the production
		// loader, which is false under CLI phpunit. Load it and register its
		// wp_ajax_* actions here; wp-phpunit restores the once-per-process
		// hook snapshot after every test, so re-register per test.
		if ( ! class_exists( 'WP_MCP_AI_Model_Manager_Ajax' ) ) {
			$path = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-model-manager-ajax.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		if ( class_exists( 'WP_MCP_AI_Model_Manager_Ajax' )
			&& ! has_action( 'wp_ajax_wp_mcp_ai_discover_models' ) ) {
			WP_MCP_AI_Model_Manager_Ajax::init();
		}
	}

	/**
	 * Extract the human-readable error message from a handler response.
	 *
	 * The model-manager handlers pass a plain string to wp_send_json_error()
	 * (the admin JS renders response.data directly), while other handlers use
	 * the array( 'message' => ... ) shape. Accept both.
	 *
	 * @param array $response Decoded response from dispatch().
	 * @return string Error message.
	 */
	protected function ajax_error_message( $response ) {
		if ( isset( $response['data'] ) && is_array( $response['data'] ) && isset( $response['data']['message'] ) ) {
			return (string) $response['data']['message'];
		}
		if ( isset( $response['data'] ) && is_string( $response['data'] ) ) {
			return $response['data'];
		}
		return '';
	}

	/**
	 * Test discover models AJAX endpoint.
	 */
	public function test_discover_models_success() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_discover_models',
			array(
				'provider' => 'openai',
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_model_manager' ),
			)
		);

		// Verify response structure (discovery may fail without API credentials).
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test discover models requires permissions.
	 */
	public function test_discover_models_requires_permissions() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_discover_models',
			array(
				'provider' => 'openai',
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_model_manager' ),
			)
		);

		// Verify failure due to permissions.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', $this->ajax_error_message( $response ) );
	}

	/**
	 * Test discover models requires valid nonce.
	 */
	public function test_discover_models_requires_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_discover_models',
			array(
				'provider' => 'openai',
				'nonce'    => 'invalid_nonce',
			)
		);

		// Nonce failures die with -1 and no JSON body.
		$this->assertAjaxForbidden( $response );
	}

	/**
	 * Test discover models responds without provider.
	 *
	 * Provider is optional in the current handler contract (empty means
	 * "check all configured providers"), so the request must still produce a
	 * well-formed JSON response.
	 */
	public function test_discover_models_without_provider_still_responds() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_discover_models',
			array(
				'nonce' => wp_create_nonce( 'wp_mcp_ai_model_manager' ),
			)
		);

		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test research model AJAX endpoint.
	 */
	public function test_research_model_success() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_research_model',
			array(
				'model_id' => 'gpt-4o',
				'provider' => 'openai',
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_model_manager' ),
			)
		);

		// Verify response structure (research may fail without web search enabled).
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test research model requires permissions.
	 */
	public function test_research_model_requires_permissions() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_research_model',
			array(
				'model_id' => 'gpt-4o',
				'provider' => 'openai',
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_model_manager' ),
			)
		);

		// Verify failure due to permissions.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', $this->ajax_error_message( $response ) );
	}

	/**
	 * Test research model requires model ID.
	 */
	public function test_research_model_requires_model_id() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_research_model',
			array(
				'provider' => 'openai',
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_model_manager' ),
			)
		);

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Model ID', $this->ajax_error_message( $response ) );
	}

	/**
	 * Test add model config AJAX endpoint.
	 */
	public function test_add_model_config_success() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_add_model_config',
			array(
				'model_id' => 'test-model',
				'config'   => wp_json_encode(
					array(
						'name'           => 'Test Model',
						'provider'       => 'openai',
						'context_window' => 128000,
					)
				),
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_model_manager' ),
			)
		);

		// Verify success.
		$this->assertTrue( $response['success'], 'Model config should be added' );
	}

	/**
	 * Test add model config requires permissions.
	 */
	public function test_add_model_config_requires_permissions() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_add_model_config',
			array(
				'model_id' => 'test-model',
				'config'   => wp_json_encode(
					array(
						'name'           => 'Test Model',
						'provider'       => 'openai',
						'context_window' => 128000,
					)
				),
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_model_manager' ),
			)
		);

		// Verify failure due to permissions.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', $this->ajax_error_message( $response ) );
	}

	/**
	 * Test add model config validates required fields.
	 */
	public function test_add_model_config_validates_fields() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_add_model_config',
			array(
				'nonce' => wp_create_nonce( 'wp_mcp_ai_model_manager' ),
			)
		);

		// Verify failure due to missing fields.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'required', $this->ajax_error_message( $response ) );
	}

	/**
	 * Test concurrent model discovery operations.
	 */
	public function test_concurrent_model_discovery() {
		$this->as_admin();

		$response1 = $this->dispatch(
			'wp_mcp_ai_discover_models',
			array(
				'provider' => 'openai',
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_model_manager' ),
			)
		);

		$response2 = $this->dispatch(
			'wp_mcp_ai_discover_models',
			array(
				'provider' => 'anthropic',
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_model_manager' ),
			)
		);

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
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_research_model',
			array(
				'model_id' => 'claude-3-opus',
				'provider' => 'anthropic',
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_model_manager' ),
			)
		);

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
		$this->as_admin();

		$config = array(
			'name'           => 'Update Test Model',
			'provider'       => 'openai',
			'context_window' => 128000,
			'cost_per_1k'    => 0.01,
		);

		// First, add a model config.
		$response1 = $this->dispatch(
			'wp_mcp_ai_add_model_config',
			array(
				'model_id' => 'update-test-model',
				'config'   => wp_json_encode( $config ),
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_model_manager' ),
			)
		);
		$this->assertTrue( $response1['success'], 'Initial model config should be added' );

		// Update the same model with new costs.
		$config['cost_per_1k'] = 0.015;

		$response2 = $this->dispatch(
			'wp_mcp_ai_add_model_config',
			array(
				'model_id'  => 'update-test-model',
				'config'    => wp_json_encode( $config ),
				'overwrite' => '1',
				'nonce'     => wp_create_nonce( 'wp_mcp_ai_model_manager' ),
			)
		);

		// Verify update succeeded.
		$this->assertTrue( $response2['success'], 'Model config should be updated' );
	}
}
