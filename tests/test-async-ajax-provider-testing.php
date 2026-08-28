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
	 * Nonce action used by the admin settings AJAX handlers.
	 */
	const SETTINGS_NONCE = 'wp-mcp-ai-settings';

	/**
	 * Nonce action accepted by the model-selector handler.
	 */
	const MODEL_SELECTOR_NONCE = 'wp-mcp-ai-model-selector';

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
		// loader, which is false under CLI phpunit. Load it and register the
		// wp_ajax_* actions exercised by this suite; wp-phpunit restores its
		// once-per-process hook snapshot after every test, so re-register per
		// test when the hook is missing. Some actions are also registered by
		// WP_MCP_AI_Settings_Dashboard during bootstrap (snapshot), but the
		// Flowhub/iSAMS actions are only registered by
		// WP_MCP_AI_Admin_Settings which never loads under CLI.
		if ( ! class_exists( 'WP_MCP_AI_Admin_AJAX_Handlers' ) ) {
			$path = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		if ( class_exists( 'WP_MCP_AI_Admin_AJAX_Handlers' ) ) {
			$handlers = new WP_MCP_AI_Admin_AJAX_Handlers();
			$actions  = array(
				'wp_ajax_wp_mcp_ai_test_ollama_connection',
				'wp_ajax_wp_mcp_ai_fetch_ollama_models',
				'wp_ajax_wp_mcp_ai_test_lm_studio_connection',
				'wp_ajax_wp_mcp_ai_fetch_lm_studio_models',
				'wp_ajax_wp_mcp_ai_test_cloudflare_connection',
				'wp_ajax_wp_mcp_ai_test_brave_search_connection',
				'wp_ajax_wp_mcp_ai_test_mubert_connection',
				'wp_ajax_wp_mcp_ai_test_flowhub_connection',
				'wp_ajax_wp_mcp_ai_test_isams_connection',
				'wp_ajax_wp_mcp_ai_fetch_cloudways_data',
				'wp_ajax_wp_mcp_ai_get_models_for_provider',
			);

			foreach ( $actions as $action ) {
				if ( ! has_action( $action ) ) {
					add_action( $action, array( $handlers, 'safe_ajax_handler' ) );
				}
			}
		}
	}

	/**
	 * Test Ollama connection test AJAX endpoint.
	 */
	public function test_ollama_connection_test() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_ollama_connection',
			array(
				'endpoint_url' => 'http://localhost:11434',
				'nonce'        => wp_create_nonce( self::SETTINGS_NONCE ),
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
				'nonce'        => wp_create_nonce( self::SETTINGS_NONCE ),
			)
		);

		// Verify failure due to permissions.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', $response['data']['message'] );
	}

	/**
	 * Test fetch Ollama models AJAX endpoint.
	 */
	public function test_fetch_ollama_models() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_fetch_ollama_models',
			array(
				'endpoint_url' => 'http://localhost:11434',
				'nonce'        => wp_create_nonce( self::SETTINGS_NONCE ),
			)
		);

		// Verify response structure (fetch may fail in test environment).
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test LM Studio connection test AJAX endpoint.
	 */
	public function test_lm_studio_connection_test() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_lm_studio_connection',
			array(
				'endpoint_url' => 'http://localhost:1234',
				'nonce'        => wp_create_nonce( self::SETTINGS_NONCE ),
			)
		);

		// Verify response structure.
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test fetch LM Studio models AJAX endpoint.
	 */
	public function test_fetch_lm_studio_models() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_fetch_lm_studio_models',
			array(
				'endpoint_url' => 'http://localhost:1234',
				'nonce'        => wp_create_nonce( self::SETTINGS_NONCE ),
			)
		);

		// Verify response structure.
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test Cloudflare connection test AJAX endpoint.
	 */
	public function test_cloudflare_connection_test() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_cloudflare_connection',
			array(
				'zone_id'   => 'test-zone-id',
				'api_token' => 'test-api-token',
				'nonce'     => wp_create_nonce( self::SETTINGS_NONCE ),
			)
		);

		// Verify response structure.
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
				'nonce'   => wp_create_nonce( self::SETTINGS_NONCE ),
			)
		);

		// Verify response structure.
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
				'nonce'   => wp_create_nonce( self::SETTINGS_NONCE ),
			)
		);

		// Verify response structure.
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
				'client_id' => 'test-client-id',
				'api_key'   => 'test-api-key',
				'nonce'     => wp_create_nonce( self::SETTINGS_NONCE ),
			)
		);

		// Verify response structure.
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
				'api_url'    => 'https://example.com/',
				'api_key'    => 'test-api-key',
				'api_secret' => 'test-api-secret',
				'nonce'      => wp_create_nonce( self::SETTINGS_NONCE ),
			)
		);

		// Verify response structure.
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test provider connection handles missing credentials.
	 */
	public function test_provider_connection_missing_credentials() {
		$this->as_admin();

		// Intentionally omitting endpoint_url.
		$response = $this->dispatch(
			'wp_mcp_ai_test_ollama_connection',
			array(
				'nonce' => wp_create_nonce( self::SETTINGS_NONCE ),
			)
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

		// Nonce failures die with -1 and no JSON body.
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
				'nonce'   => wp_create_nonce( self::SETTINGS_NONCE ),
			)
		);

		// Verify response structure.
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
				'nonce'    => wp_create_nonce( self::MODEL_SELECTOR_NONCE ),
			)
		);

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
		$this->as_admin();

		// Use an invalid URL that should timeout.
		$response = $this->dispatch(
			'wp_mcp_ai_test_ollama_connection',
			array(
				'endpoint_url' => 'http://10.255.255.1:11434', // Non-routable IP.
				'nonce'        => wp_create_nonce( self::SETTINGS_NONCE ),
			)
		);

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
