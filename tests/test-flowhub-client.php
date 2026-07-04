<?php
/**
 * FlowHub Client Tests.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

/**
 * Test class for WP_MCP_AI_FlowHub_Client.
 */
class Test_FlowHub_Client extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_Client' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
		}
	}

	// ------------------------------------------------------------------ //
	// Constants Tests
	// ------------------------------------------------------------------ //

	public function test_default_api_base_url() {
		$ref = new ReflectionClass( 'WP_MCP_AI_FlowHub_Client' );
		$this->assertEquals( 'https://api.flowhub.co/v0/', $ref->getConstant( 'DEFAULT_API_BASE_URL' ) );
	}

	public function test_max_response_size() {
		$ref = new ReflectionClass( 'WP_MCP_AI_FlowHub_Client' );
		$this->assertEquals( 5242880, $ref->getConstant( 'MAX_RESPONSE_SIZE' ) );
	}

	public function test_default_timeout() {
		$ref = new ReflectionClass( 'WP_MCP_AI_FlowHub_Client' );
		$this->assertEquals( 30, $ref->getConstant( 'DEFAULT_TIMEOUT' ) );
	}

	public function test_max_retries() {
		$ref = new ReflectionClass( 'WP_MCP_AI_FlowHub_Client' );
		$this->assertEquals( 3, $ref->getConstant( 'MAX_RETRIES' ) );
	}

	// ------------------------------------------------------------------ //
	// Constructor Tests
	// ------------------------------------------------------------------ //

	public function test_constructor_with_defaults() {
		$client = new WP_MCP_AI_FlowHub_Client();
		$this->assertInstanceOf( 'WP_MCP_AI_FlowHub_Client', $client );
	}

	public function test_constructor_with_params() {
		$client = new WP_MCP_AI_FlowHub_Client( 'test_id', 'test_key', 'https://custom.api.com/v1/', 60 );
		$this->assertInstanceOf( 'WP_MCP_AI_FlowHub_Client', $client );
	}

	// ------------------------------------------------------------------ //
	// from_settings Tests
	// ------------------------------------------------------------------ //

	public function test_from_settings_returns_error_when_no_credentials() {
		delete_option( 'wp_mcp_ai_flowhub_toolkit_settings' );
		$result = WP_MCP_AI_FlowHub_Client::from_settings();
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_flowhub_missing_credentials', $result->get_error_code() );
	}

	public function test_from_settings_returns_client_when_configured() {
		update_option( 'wp_mcp_ai_flowhub_toolkit_settings', array(
			'client_id' => 'test_client',
			'api_key'   => 'test_key',
		) );
		$result = WP_MCP_AI_FlowHub_Client::from_settings();
		$this->assertInstanceOf( 'WP_MCP_AI_FlowHub_Client', $result );
		delete_option( 'wp_mcp_ai_flowhub_toolkit_settings' );
	}

	// ------------------------------------------------------------------ //
	// Error State Tests
	// ------------------------------------------------------------------ //

	public function test_get_last_error_defaults_to_empty() {
		$client = new WP_MCP_AI_FlowHub_Client();
		$this->assertEquals( '', $client->get_last_error() );
	}

	public function test_get_last_response_code_defaults_to_null() {
		$client = new WP_MCP_AI_FlowHub_Client();
		$this->assertNull( $client->get_last_response_code() );
	}
}
