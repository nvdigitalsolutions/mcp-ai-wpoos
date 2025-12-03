<?php
/**
 * Test bypass_cookie_check_for_plugin_endpoints method.
 *
 * Verifies that the plugin bypasses WordPress's default cookie authentication
 * check for plugin endpoints, preventing "rest_cookie_invalid_nonce" errors
 * when using credentials: 'omit' with X-WP-Nonce headers.
 */
class WP_MCP_AI_REST_Bypass_Cookie_Check_Test extends WP_UnitTestCase {

	/**
	 * @var WP_MCP_AI_REST REST controller instance.
	 */
	private $rest_controller;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create REST controller instance.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$client   = new WP_MCP_AI_Language_Model_Router();
		$this->rest_controller = new WP_MCP_AI_REST( $registry, $client );
	}

	/**
	 * Test that the bypass filter returns true for plugin endpoints.
	 */
	public function test_bypass_returns_true_for_plugin_endpoints() {
		// Simulate a request to our plugin's REST endpoint.
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp-ai/v1/tools';

		// Call the bypass method with null (no authentication checked yet).
		$result = $this->rest_controller->bypass_cookie_check_for_plugin_endpoints( null );

		// Should return true to indicate authentication is handled.
		$this->assertTrue( $result, 'Should return true for plugin endpoints' );
	}

	/**
	 * Test that the bypass filter returns null for non-plugin endpoints.
	 */
	public function test_bypass_returns_null_for_other_endpoints() {
		// Simulate a request to a different REST endpoint.
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';

		// Call the bypass method with null.
		$result = $this->rest_controller->bypass_cookie_check_for_plugin_endpoints( null );

		// Should return null (unchanged) for non-plugin endpoints.
		$this->assertNull( $result, 'Should return null for non-plugin endpoints' );
	}

	/**
	 * Test that the bypass filter preserves existing WP_Error objects.
	 */
	public function test_bypass_preserves_wp_error() {
		// Simulate a request to our plugin's REST endpoint.
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp-ai/v1/chat';

		// Create a WP_Error object.
		$error = new WP_Error( 'test_error', 'Test error message' );

		// Call the bypass method with the error.
		$result = $this->rest_controller->bypass_cookie_check_for_plugin_endpoints( $error );

		// Should return the same error object.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $error, $result, 'Should preserve WP_Error objects' );
	}

	/**
	 * Test that the bypass filter works with various plugin endpoints.
	 *
	 * @dataProvider plugin_endpoint_provider
	 */
	public function test_bypass_works_for_all_plugin_endpoints( $endpoint ) {
		// Simulate a request to the endpoint.
		$_SERVER['REQUEST_URI'] = $endpoint;

		// Call the bypass method.
		$result = $this->rest_controller->bypass_cookie_check_for_plugin_endpoints( null );

		// Should return true for all plugin endpoints.
		$this->assertTrue( $result, "Should return true for endpoint: {$endpoint}" );
	}

	/**
	 * Data provider for plugin endpoints.
	 *
	 * @return array
	 */
	public function plugin_endpoint_provider() {
		return array(
			'tools endpoint'       => array( '/wp-json/mcp-ai/v1/tools' ),
			'chat endpoint'        => array( '/wp-json/mcp-ai/v1/chat' ),
			'assistants endpoint'  => array( '/wp-json/mcp-ai/v1/assistants' ),
			'token endpoint'       => array( '/wp-json/mcp-ai/v1/tokens' ),
			'analytics endpoint'   => array( '/wp-json/mcp-ai/v1/analytics' ),
		);
	}

	/**
	 * Test that the bypass filter works in subdirectory installations.
	 */
	public function test_bypass_works_in_subdirectory_installation() {
		// Simulate a WordPress installation in a subdirectory.
		// The rest_get_url_prefix() function might return a different prefix.
		$_SERVER['REQUEST_URI'] = '/wordpress/wp-json/mcp-ai/v1/tools';

		// Call the bypass method.
		$result = $this->rest_controller->bypass_cookie_check_for_plugin_endpoints( null );

		// Should return true even in subdirectory installations.
		$this->assertTrue( $result, 'Should work in subdirectory installations' );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}
}
