<?php
/**
 * Tests for the WP_MCP_AI_HTTP_Test_Helper trait.
 *
 * Verifies that the trait correctly intercepts outbound HTTP requests, returns
 * stubbed responses, matches URL patterns, tracks request history, and handles
 * fixture loading.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for the HTTP test helper trait.
 */
class Test_HTTP_Test_Helper extends WP_UnitTestCase {

	use WP_MCP_AI_HTTP_Test_Helper;

	/**
	 * Set up the test environment and initialise HTTP stubs.
	 */
	public function set_up() {
		parent::set_up();
		$this->init_http_stubs();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		$this->reset_http_stubs();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// URL matching
	// -------------------------------------------------------------------------

	/**
	 * A stubbed response should be returned when the URL contains the pattern.
	 */
	public function test_mock_http_response_matches_url_substring() {
		$this->mock_http_response( 'api.example.com', 200, array( 'ok' => true ) );

		$response = wp_remote_get( 'https://api.example.com/v1/search?q=test' );

		$this->assertIsArray( $response );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$this->assertStringContainsString( '"ok":true', wp_remote_retrieve_body( $response ) );
	}

	/**
	 * A stubbed WP_Error should be returned for matching URLs.
	 */
	public function test_mock_http_error_returns_wp_error() {
		$this->mock_http_error( 'broken.example.com', 'http_request_failed', 'Connection refused' );

		$response = wp_remote_get( 'https://broken.example.com/api' );

		$this->assertWPError( $response );
		$this->assertSame( 'http_request_failed', $response->get_error_code() );
	}

	/**
	 * URLs that don't match any stub should return a generic 200.
	 */
	public function test_unstubbed_url_returns_generic_200() {
		$response = wp_remote_get( 'https://completely-unknown.example.com/' );

		$this->assertIsArray( $response );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( '{}', wp_remote_retrieve_body( $response ) );
	}

	// -------------------------------------------------------------------------
	// Custom headers
	// -------------------------------------------------------------------------

	/**
	 * Stubbed responses should pass through custom headers.
	 */
	public function test_mock_http_response_preserves_custom_headers() {
		$this->mock_http_response(
			'api.example.com',
			201,
			'{"created":true}',
			array( 'X-Custom-Header' => 'custom-value' )
		);

		$response = wp_remote_get( 'https://api.example.com/create' );

		$this->assertSame( 201, wp_remote_retrieve_response_code( $response ) );
		$headers = wp_remote_retrieve_headers( $response );
		if ( $headers instanceof \Requests_Utility_CaseInsensitiveDictionary ) {
			$this->assertSame( 'custom-value', $headers['X-Custom-Header'] );
		}
	}

	// -------------------------------------------------------------------------
	// Request tracking
	// -------------------------------------------------------------------------

	/**
	 * Assert that a request was made to a specific URL substring.
	 */
	public function test_assert_http_request_made_to() {
		$this->mock_http_response( 'api.example.com', 200, array() );

		wp_remote_get( 'https://api.example.com/v1/test' );

		$this->assert_http_request_made_to( 'api.example.com' );
		$this->assert_http_request_count( 1 );
	}

	/**
	 * Assert that request count zero passes when no requests are made.
	 */
	public function test_assert_http_request_count_0_when_no_requests() {
		$this->assert_http_request_count( 0 );
	}

	/**
	 * Assert no HTTP request was made when the URL substring does not match any intercepted URL.
	 */
	public function test_assert_no_http_request_made_to() {
		$this->mock_http_response( 'safe.example.com', 200, array() );
		wp_remote_get( 'https://safe.example.com/page' );

		$this->assert_no_http_request_made_to( 'evil.example.com' );
	}

	// -------------------------------------------------------------------------
	// Callable stubs
	// -------------------------------------------------------------------------

	/**
	 * A callable stub should receive the URL and args and can return conditional responses.
	 */
	public function test_mock_http_response_callable_receives_url_and_args() {
		$received = null;

		$this->mock_http_response_callable(
			function ( $url, $args ) use ( &$received ) {
				$received = array(
					'url'  => $url,
					'args' => $args,
				);
				return null; // Fall through to default.
			}
		);

		wp_remote_post(
			'https://api.example.com/submit',
			array( 'body' => 'data' )
		);

		$this->assertNotNull( $received );
		$this->assertSame( 'https://api.example.com/submit', $received['url'] );
	}

	// -------------------------------------------------------------------------
	// Fixture loading
	// -------------------------------------------------------------------------

	/**
	 * Load HTTP fixture should return decoded JSON.
	 */
	public function test_load_http_fixture_returns_array() {
		$fixture = $this->load_http_fixture( 'duckduckgo-success' );

		$this->assertIsArray( $fixture );
		$this->assertArrayHasKey( 'status', $fixture );
		$this->assertArrayHasKey( 'body', $fixture );
		$this->assertSame( 200, $fixture['status'] );
	}

	/**
	 * Mock HTTP response from fixture should load and stub in one call.
	 */
	public function test_mock_http_response_from_fixture() {
		$this->mock_http_response_from_fixture( 'api.duckduckgo.com', 'duckduckgo-success' );

		$response = wp_remote_get( 'https://api.duckduckgo.com/?q=wordpress&format=json' );

		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'Heading', $body );
	}

	// -------------------------------------------------------------------------
	// Network guard
	// -------------------------------------------------------------------------

	/**
	 * Is network available should return a boolean without hitting the stubs.
	 */
	public function test_is_network_available_returns_bool() {
		$result = $this->is_network_available();
		$this->assertIsBool( $result );
	}
}
