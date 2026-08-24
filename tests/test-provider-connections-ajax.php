<?php
/**
 * AJAX tests for provider connection test handlers (new providers not yet covered).
 *
 * Extends coverage of the existing `test-async-ajax-provider-testing.php` cluster.
 * Covers the 4-point contract for:
 *   - wp_mcp_ai_test_anthropic_connection (WP_MCP_AI_Admin_AJAX_Handlers::handle_test_anthropic_connection)
 *   - wp_mcp_ai_test_exa_connection        (WP_MCP_AI_Admin_AJAX_Handlers::handle_test_exa_connection)
 *   - wp_mcp_ai_test_perplexity_connection (WP_MCP_AI_Admin_AJAX_Handlers::handle_test_perplexity_connection)
 *   - wp_mcp_ai_test_plaid_connection      (WP_MCP_AI_Admin_AJAX_Handlers::handle_test_plaid_connection)
 *   - wp_mcp_ai_test_tavily_connection     (WP_MCP_AI_Admin_AJAX_Handlers::handle_test_tavily_connection)
 *   - wp_mcp_ai_test_remote_connection     (WP_MCP_AI_Pro_Remote_Sites_Admin::ajax_test_connection, Pro)
 *
 * All five base handlers share nonce `wp-mcp-ai-settings` and `manage_options`.
 * All stub outbound HTTP so no real API credentials are needed.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- inherits camelCase $_last_response from WP_Ajax_UnitTestCase.

/**
 * AJAX cluster: Provider connection tests (Anthropic, Exa, Perplexity, Plaid, Tavily, Remote).
 */
// Load the Pro admin class under test; the pro addon loads it only in admin
// context, so require it here to keep the suite runnable standalone (mirrors
// CI, where earlier admin-context tests load it).
if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	$wp_mcp_ai_remote_sites_admin = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php';
	if ( file_exists( $wp_mcp_ai_remote_sites_admin ) ) {
		require_once $wp_mcp_ai_remote_sites_admin;
	}
	unset( $wp_mcp_ai_remote_sites_admin );
}

class Test_Provider_Connections_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Nonce shared by all five base-plugin provider handlers.
	 */
	const NONCE = 'wp-mcp-ai-settings';

	/**
	 * Stub a successful JSON API response for all outbound HTTP requests.
	 */
	public function setUp(): void {
		parent::setUp();

		// Default stub: return a 200 with an empty JSON body.
		// Individual tests override this with more specific responses via
		// the inherited stub_http_response() helper.
		$this->stub_http_response(
			'',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{}',
				'headers'  => array( 'content-type' => 'application/json' ),
				'cookies'  => array(),
			)
		);
	}

	// ---
	// Helper: assert cap/nonce for a given action + key name.
	// ---

	/**
	 * Shared assertion for the "missing API key" validation path.
	 *
	 * @param string $action      WordPress AJAX action (without wp_ajax_ prefix).
	 * @param string $key_param   POST parameter name for the API key.
	 * @param string $key_phrase  Part of the expected error message.
	 */
	private function assert_empty_key_rejected( $action, $key_param, $key_phrase ) {
		$this->as_admin();

		$response = $this->dispatch(
			$action,
			array(
				'nonce'    => wp_create_nonce( self::NONCE ),
				$key_param => '',
			)
		);

		$this->assertAjaxError( $response, $key_phrase );
	}

	// ---
	// wp_mcp_ai_test_anthropic_connection
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_anthropic_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_anthropic_connection',
			array( 'api_key' => 'sk-ant-test' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_anthropic_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_test_anthropic_connection',
			array(
				'nonce'   => wp_create_nonce( self::NONCE ),
				'api_key' => 'sk-ant-test',
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the empty api key parameter. */
	public function test_anthropic_validates_empty_api_key() {
		$this->assert_empty_key_rejected(
			'wp_mcp_ai_test_anthropic_connection',
			'api_key',
			'Anthropic API key'
		);
	}

	/** Anthropic stubs api response. */
	public function test_anthropic_stubs_api_response() {
		$this->as_admin();

		// Stub an Anthropic 200 response.
		$this->stub_http_response(
			'api.anthropic.com',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'id'      => 'msg_test',
						'content' => array( array( 'text' => 'Hi' ) ),
					)
				),
				'headers'  => array( 'content-type' => 'application/json' ),
				'cookies'  => array(),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_test_anthropic_connection',
			array(
				'nonce'   => wp_create_nonce( self::NONCE ),
				'api_key' => 'sk-ant-test-key',
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_test_exa_connection
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_exa_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_exa_connection',
			array( 'api_key' => 'exa-test-key' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_exa_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_test_exa_connection',
			array(
				'nonce'   => wp_create_nonce( self::NONCE ),
				'api_key' => 'exa-test-key',
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the empty api key parameter. */
	public function test_exa_validates_empty_api_key() {
		$this->assert_empty_key_rejected(
			'wp_mcp_ai_test_exa_connection',
			'api_key',
			'Exa API key'
		);
	}

	/** Exa stubs api response. */
	public function test_exa_stubs_api_response() {
		$this->as_admin();

		$this->stub_http_response(
			'api.exa.ai',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'results' => array() ) ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'cookies'  => array(),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_test_exa_connection',
			array(
				'nonce'   => wp_create_nonce( self::NONCE ),
				'api_key' => 'exa-test-key',
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_test_perplexity_connection
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_perplexity_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_perplexity_connection',
			array( 'api_key' => 'pplx-test' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_perplexity_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_test_perplexity_connection',
			array(
				'nonce'   => wp_create_nonce( self::NONCE ),
				'api_key' => 'pplx-test',
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the empty api key parameter. */
	public function test_perplexity_validates_empty_api_key() {
		$this->assert_empty_key_rejected(
			'wp_mcp_ai_test_perplexity_connection',
			'api_key',
			'Perplexity API key'
		);
	}

	/** Perplexity stubs api response. */
	public function test_perplexity_stubs_api_response() {
		$this->as_admin();

		$this->stub_http_response(
			'api.perplexity.ai',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => 'Hi' ) ) ) ) ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'cookies'  => array(),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_test_perplexity_connection',
			array(
				'nonce'   => wp_create_nonce( self::NONCE ),
				'api_key' => 'pplx-test-key',
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_test_tavily_connection
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_tavily_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_tavily_connection',
			array( 'api_key' => 'tvly-test' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_tavily_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_test_tavily_connection',
			array(
				'nonce'   => wp_create_nonce( self::NONCE ),
				'api_key' => 'tvly-test',
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the empty api key parameter. */
	public function test_tavily_validates_empty_api_key() {
		$this->assert_empty_key_rejected(
			'wp_mcp_ai_test_tavily_connection',
			'api_key',
			'Tavily API key'
		);
	}

	/** Tavily stubs api response. */
	public function test_tavily_stubs_api_response() {
		$this->as_admin();

		$this->stub_http_response(
			'api.tavily.com',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'results' => array() ) ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'cookies'  => array(),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_test_tavily_connection',
			array(
				'nonce'   => wp_create_nonce( self::NONCE ),
				'api_key' => 'tvly-test-key',
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_test_plaid_connection
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_plaid_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_plaid_connection',
			array(
				'client_id' => 'id',
				'secret'    => 'secret',
			)
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_plaid_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_test_plaid_connection',
			array(
				'nonce'     => wp_create_nonce( self::NONCE ),
				'client_id' => 'id',
				'secret'    => 'secret',
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the empty client id parameter. */
	public function test_plaid_validates_empty_client_id() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_plaid_connection',
			array(
				'nonce'     => wp_create_nonce( self::NONCE ),
				'client_id' => '',
				'secret'    => 'secret',
			)
		);

		// Handler should reject missing client ID with an error.
		$this->assertAjaxError( $response );
	}

	/** Plaid stubs api response. */
	public function test_plaid_stubs_api_response() {
		$this->as_admin();

		$this->stub_http_response(
			'plaid.com',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'products' => array( 'transactions' ) ) ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'cookies'  => array(),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_test_plaid_connection',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'client_id'   => 'test_client_id',
				'secret'      => 'test_secret',
				'environment' => 'sandbox',
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_test_remote_connection (Pro addon)
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_remote_connection_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_remote_connection',
			array( 'url' => 'https://example.com/wp-json' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_remote_connection_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Remote_Sites_Admin (Pro) is not available.' );
		}

		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_test_remote_connection',
			array(
				'nonce' => wp_create_nonce( 'test_connection_ajax' ),
				'url'   => 'https://example.com/wp-json',
			)
		);

		$this->assertAjaxError( $response );
	}

	/** Verifies the response returns structured response for admin. */
	public function test_remote_connection_returns_structured_response_for_admin() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Remote_Sites_Admin (Pro) is not available.' );
		}

		$this->as_admin();

		$this->stub_http_response(
			'example.com',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'name'        => 'Test Site',
						'description' => '',
					)
				),
				'headers'  => array( 'content-type' => 'application/json' ),
				'cookies'  => array(),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_test_remote_connection',
			array(
				'nonce' => wp_create_nonce( 'test_connection_ajax' ),
				'url'   => 'https://example.com/wp-json',
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}
}
