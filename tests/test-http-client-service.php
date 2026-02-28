<?php
/**
 * Tests for Symfony HTTP Client Service integration
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Http_Client_Service
 *
 * Tests for the Symfony HttpClient service integration.
 */
class Test_WP_MCP_AI_Http_Client_Service extends WP_UnitTestCase {

	/**
	 * HTTP client service instance.
	 *
	 * @var WP_MCP_AI\Http\WP_MCP_AI_Http_Client_Service
	 */
	private $http_service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load HTTP client service.
		require_once dirname( __DIR__ ) . '/includes/http/class-wp-mcp-ai-http-client-service.php';
		$this->http_service = \WP_MCP_AI\Http\WP_MCP_AI_Http_Client_Service::get_instance();
	}

	/**
	 * Test that the HTTP client service is a singleton.
	 */
	public function test_http_client_service_is_singleton() {
		$instance1 = \WP_MCP_AI\Http\WP_MCP_AI_Http_Client_Service::get_instance();
		$instance2 = \WP_MCP_AI\Http\WP_MCP_AI_Http_Client_Service::get_instance();

		$this->assertSame( $instance1, $instance2, 'HTTP client service should be a singleton' );
	}

	/**
	 * Test default timeout value.
	 */
	public function test_default_timeout() {
		$timeout = $this->http_service->get_default_timeout();

		$this->assertIsFloat( $timeout, 'Default timeout should be a float' );
		$this->assertGreaterThan( 0, $timeout, 'Default timeout should be positive' );
		$this->assertEquals( 30.0, $timeout, 'Default timeout should be 30 seconds' );
	}

	/**
	 * Test setting and getting timeout.
	 */
	public function test_set_and_get_timeout() {
		$original = $this->http_service->get_default_timeout();

		$this->http_service->set_default_timeout( 60.0 );
		$this->assertEquals( 60.0, $this->http_service->get_default_timeout(), 'Timeout should be updated' );

		// Restore original.
		$this->http_service->set_default_timeout( $original );
	}

	/**
	 * Test default max retries value.
	 */
	public function test_default_max_retries() {
		$max_retries = $this->http_service->get_max_retries();

		$this->assertIsInt( $max_retries, 'Max retries should be an integer' );
		$this->assertGreaterThanOrEqual( 0, $max_retries, 'Max retries should be non-negative' );
		$this->assertEquals( 3, $max_retries, 'Default max retries should be 3' );
	}

	/**
	 * Test setting and getting max retries.
	 */
	public function test_set_and_get_max_retries() {
		$original = $this->http_service->get_max_retries();

		$this->http_service->set_max_retries( 5 );
		$this->assertEquals( 5, $this->http_service->get_max_retries(), 'Max retries should be updated' );

		// Restore original.
		$this->http_service->set_max_retries( $original );
	}

	/**
	 * Test that get_client returns a Symfony HttpClientInterface instance.
	 */
	public function test_get_client_returns_http_client_interface() {
		$client = $this->http_service->get_client();

		$this->assertInstanceOf(
			'Symfony\Contracts\HttpClient\HttpClientInterface',
			$client,
			'get_client() should return a Symfony HttpClientInterface instance'
		);
	}

	/**
	 * Test GET request to a public endpoint.
	 *
	 * Uses httpbin.org which is a public testing API for HTTP requests.
	 * Skipped when network access is not available.
	 */
	public function test_get_request() {
		// Skip if running in offline environment.
		if ( ! $this->is_network_available() ) {
			$this->markTestSkipped( 'Network access not available in this environment.' );
		}

		$result = $this->http_service->get( 'https://httpbin.org/get' );

		$this->assertIsArray( $result, 'GET response should be an array' );
		$this->assertArrayHasKey( 'status', $result, 'Response should have status key' );
		$this->assertArrayHasKey( 'headers', $result, 'Response should have headers key' );
		$this->assertArrayHasKey( 'body', $result, 'Response should have body key' );
		$this->assertEquals( 200, $result['status'], 'Status should be 200' );
	}

	/**
	 * Test POST request to a public endpoint.
	 *
	 * Skipped when network access is not available.
	 */
	public function test_post_request() {
		// Skip if running in offline environment.
		if ( ! $this->is_network_available() ) {
			$this->markTestSkipped( 'Network access not available in this environment.' );
		}

		$result = $this->http_service->post(
			'https://httpbin.org/post',
			array(
				'json' => array( 'test' => 'value' ),
			)
		);

		$this->assertIsArray( $result, 'POST response should be an array' );
		$this->assertArrayHasKey( 'status', $result, 'Response should have status key' );
		$this->assertEquals( 200, $result['status'], 'Status should be 200' );
	}

	/**
	 * Test that a request to an invalid URL returns a WP_Error.
	 */
	public function test_request_invalid_url_returns_wp_error() {
		// Use an invalid hostname that cannot be resolved.
		$result = $this->http_service->get(
			'http://this-host-does-not-exist-wp-mcp-ai.invalid/',
			array( 'timeout' => 2 )
		);

		$this->assertInstanceOf( 'WP_Error', $result, 'Request to invalid URL should return WP_Error' );
		$this->assertStringContainsString(
			'http_client',
			$result->get_error_code(),
			'Error code should contain http_client'
		);
	}

	/**
	 * Test the request() method directly.
	 *
	 * Skipped when network access is not available.
	 */
	public function test_request_method() {
		// Skip if running in offline environment.
		if ( ! $this->is_network_available() ) {
			$this->markTestSkipped( 'Network access not available in this environment.' );
		}

		$result = $this->http_service->request( 'GET', 'https://httpbin.org/get' );

		$this->assertIsArray( $result, 'request() should return an array on success' );
		$this->assertArrayHasKey( 'status', $result, 'Response should have status key' );
		$this->assertEquals( 200, $result['status'], 'Status should be 200' );
	}

	/**
	 * Test that helper function wp_mcp_ai_get_http_client_service() is available.
	 */
	public function test_helper_function_exists() {
		$this->assertTrue(
			function_exists( 'wp_mcp_ai_get_http_client_service' ),
			'Helper function wp_mcp_ai_get_http_client_service() should exist'
		);

		$service = wp_mcp_ai_get_http_client_service();
		$this->assertInstanceOf(
			'WP_MCP_AI\Http\WP_MCP_AI_Http_Client_Service',
			$service,
			'Helper function should return an HTTP client service instance'
		);
	}

	/**
	 * Check whether network access is available in the current test environment.
	 *
	 * @return bool True if a connection to a known host succeeds.
	 */
	private function is_network_available() {
		$errno  = 0;
		$errstr = '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fsockopen -- intentional connectivity probe; @ suppresses warnings since failure is expected in offline environments
		$handle = @fsockopen( 'httpbin.org', 80, $errno, $errstr, 2 );
		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
			return true;
		}
		return false;
	}
}
