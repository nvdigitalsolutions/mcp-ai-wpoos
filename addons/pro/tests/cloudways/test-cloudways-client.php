<?php
/**
 * Cloudways Toolkit — Client Unit Tests
 *
 * Tests for WP_MCP_AI_Cloudways_Client: token caching, refresh,
 * error mapping, and base URL filtering.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.15
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudways Client Tests
 */
class Test_Cloudways_Client extends WP_UnitTestCase {

	/**
	 * Original options for restoration.
	 *
	 * @var array
	 */
	private $original_options;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Save original options state.
		$this->original_options = get_option( 'wp_mcp_ai_settings', array() );

		// Seed credentials.
		$settings                             = get_option( 'wp_mcp_ai_settings', array() );
		$settings['cloudways_email']          = 'test@example.com';
		$settings['cloudways_api_key']        = 'test_api_key_12345';
		$settings['enable_cloudways_toolkit'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Reset singleton.
		$ref  = new ReflectionClass( 'WP_MCP_AI_Cloudways_Client' );
		$inst = $ref->getProperty( 'instance' );
		$inst->setAccessible( true );
		$inst->setValue( null );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Restore original options.
		update_option( 'wp_mcp_ai_settings', $this->original_options );

		// Reset singleton.
		$ref  = new ReflectionClass( 'WP_MCP_AI_Cloudways_Client' );
		$inst = $ref->getProperty( 'instance' );
		$inst->setAccessible( true );
		$inst->setValue( null );

		parent::tearDown();
	}

	/**
	 * Test singleton accessor.
	 */
	public function test_client_instance_is_singleton() {
		$a = WP_MCP_AI_Cloudways_Client::instance();
		$b = WP_MCP_AI_Cloudways_Client::instance();

		$this->assertSame( $a, $b );
	}

	/**
	 * Test is_configured returns true with credentials.
	 */
	public function test_is_configured_returns_true_with_credentials() {
		$client = WP_MCP_AI_Cloudways_Client::instance();
		$this->assertTrue( $client->is_configured() );
	}

	/**
	 * Test is_configured returns false without credentials.
	 */
	public function test_is_configured_returns_false_without_email() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		unset( $settings['cloudways_email'] );
		update_option( 'wp_mcp_ai_settings', $settings );

		$ref  = new ReflectionClass( 'WP_MCP_AI_Cloudways_Client' );
		$inst = $ref->getProperty( 'instance' );
		$inst->setAccessible( true );
		$inst->setValue( null );

		$client = WP_MCP_AI_Cloudways_Client::instance();
		$this->assertFalse( $client->is_configured() );
	}

	/**
	 * Test get_access_token returns the cached token.
	 */
	public function test_get_access_token_returns_cached_token() {
		$settings                               = get_option( 'wp_mcp_ai_settings', array() );
		$settings['cloudways_access_token']     = 'cached_token_value';
		$settings['cloudways_token_expires_at'] = time() + 3600;
		update_option( 'wp_mcp_ai_settings', $settings );

		$token = WP_MCP_AI_Cloudways_Client::instance()->get_access_token();
		$this->assertSame( 'cached_token_value', $token );
	}

	/**
	 * Test get_access_token returns WP_Error without credentials.
	 */
	public function test_get_access_token_returns_error_without_credentials() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		unset( $settings['cloudways_email'], $settings['cloudways_api_key'] );
		update_option( 'wp_mcp_ai_settings', $settings );

		$ref  = new ReflectionClass( 'WP_MCP_AI_Cloudways_Client' );
		$inst = $ref->getProperty( 'instance' );
		$inst->setAccessible( true );
		$inst->setValue( null );

		$token = WP_MCP_AI_Cloudways_Client::instance()->get_access_token();
		$this->assertWPError( $token );
		$this->assertSame( 'wp_mcp_ai_cloudways_no_credentials', $token->get_error_code() );
	}

	/**
	 * Test GET request returns decoded JSON on success.
	 */
	public function test_get_returns_data_on_success() {
		$expected = array(
			'servers' => array(
				array(
					'id'    => 1,
					'label' => 'Test Server',
				),
			),
		);

		add_filter(
			'pre_http_request',
			function () use ( $expected ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $expected ),
				);
			},
			10
		);

		// Seed a valid token.
		$settings                               = get_option( 'wp_mcp_ai_settings', array() );
		$settings['cloudways_access_token']     = 'test_token';
		$settings['cloudways_token_expires_at'] = time() + 3600;
		update_option( 'wp_mcp_ai_settings', $settings );

		$result = WP_MCP_AI_Cloudways_Client::instance()->get( '/server' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'servers', $result );
	}

	/**
	 * Test GET request returns WP_Error on HTTP error.
	 */
	public function test_get_returns_error_on_http_error() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 401 ),
					'body'     => wp_json_encode( array( 'message' => 'Unauthorized' ) ),
				);
			},
			10
		);

		$settings                               = get_option( 'wp_mcp_ai_settings', array() );
		$settings['cloudways_access_token']     = 'bad_token';
		$settings['cloudways_token_expires_at'] = time() + 3600;
		update_option( 'wp_mcp_ai_settings', $settings );

		$result = WP_MCP_AI_Cloudways_Client::instance()->get( '/server' );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_cloudways_unauthorized', $result->get_error_code() );
	}

	/**
	 * Test disconnect clears cached tokens.
	 */
	public function test_disconnect_clears_tokens() {
		$settings                               = get_option( 'wp_mcp_ai_settings', array() );
		$settings['cloudways_access_token']     = 'some_token';
		$settings['cloudways_token_expires_at'] = time() + 3600;
		$settings['cloudways_connected']        = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		WP_MCP_AI_Cloudways_Client::instance()->disconnect();

		$updated = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertArrayNotHasKey( 'cloudways_access_token', $updated );
		$this->assertArrayNotHasKey( 'cloudways_connected', $updated );
	}
}
