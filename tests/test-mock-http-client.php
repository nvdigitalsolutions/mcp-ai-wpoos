<?php
/**
 * Tests for WP_MCP_AI_Mock_HTTP_Client.
 *
 * Verifies that the mock HTTP client correctly implements the
 * Interface_WP_MCP_AI_HTTP_Client contract, queues and dispatches responses,
 * tracks request history, and handles edge cases.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for the mock HTTP client.
 */
class Test_WP_MCP_AI_Mock_HTTP_Client extends WP_UnitTestCase {

	/**
	 * SUT instance.
	 *
	 * @var WP_MCP_AI_Mock_HTTP_Client
	 */
	private $client;

	/**
	 * Set up the test environment.
	 */
	public function set_up() {
		parent::set_up();
		$this->client = new WP_MCP_AI_Mock_HTTP_Client();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		$this->client->reset();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Interface contract
	// -------------------------------------------------------------------------

	/**
	 * The mock client should implement the HTTP client interface.
	 */
	public function test_implements_interface() {
		$this->assertInstanceOf( Interface_WP_MCP_AI_HTTP_Client::class, $this->client );
	}

	// -------------------------------------------------------------------------
	// Empty URL guard
	// -------------------------------------------------------------------------

	/**
	 * GET with an empty URL should return a WP_Error.
	 */
	public function test_get_empty_url_returns_wp_error() {
		$result = $this->client->get( '' );
		$this->assertWPError( $result );
	}

	/**
	 * POST with an empty URL should return a WP_Error.
	 */
	public function test_post_empty_url_returns_wp_error() {
		$result = $this->client->post( '' );
		$this->assertWPError( $result );
	}

	/**
	 * Stream with an empty URL should return a WP_Error.
	 */
	public function test_stream_empty_url_returns_wp_error() {
		$result = $this->client->stream( '' );
		$this->assertWPError( $result );
	}

	// -------------------------------------------------------------------------
	// Queued responses
	// -------------------------------------------------------------------------

	/**
	 * GET should return a queued response matching the URL pattern.
	 */
	public function test_get_returns_queued_response() {
		$this->client->queue_success( 'api.example.com', 200, array( 'ok' => true ) );

		$response = $this->client->get( 'https://api.example.com/v1/data' );

		$this->assertIsArray( $response );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$this->assertStringContainsString( '"ok":true', wp_remote_retrieve_body( $response ) );
	}

	/**
	 * POST should return a queued response matching the URL pattern.
	 */
	public function test_post_returns_queued_response() {
		$this->client->queue_success( 'api.example.com', 201, array( 'created' => true ) );

		$response = $this->client->post( 'https://api.example.com/create' );

		$this->assertSame( 201, wp_remote_retrieve_response_code( $response ) );
	}

	/**
	 * A queued error should result in a WP_Error from the client.
	 */
	public function test_queued_error_returns_wp_error() {
		$this->client->queue_error( 'evil.example.com', 'http_request_failed', 'timeout' );

		$response = $this->client->get( 'https://evil.example.com/' );

		$this->assertWPError( $response );
		$this->assertSame( 'http_request_failed', $response->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Request history
	// -------------------------------------------------------------------------

	/**
	 * The client should track each request in its history.
	 */
	public function test_request_history_tracks_calls() {
		$this->client->queue_success( 'api.example.com', 200, array() );

		$this->client->get( 'https://api.example.com/resource' );
		$this->client->post( 'https://api.example.com/resource' );

		$this->assertSame( 2, $this->client->get_request_count() );

		$history = $this->client->get_request_history();
		$this->assertSame( 'GET', $history[0]['method'] );
		$this->assertSame( 'POST', $history[1]['method'] );
	}

	/**
	 * Reset should clear the request history.
	 */
	public function test_reset_clears_history() {
		$this->client->queue_success( 'api.example.com', 200, array() );
		$this->client->get( 'https://api.example.com/' );

		$this->assertSame( 1, $this->client->get_request_count() );

		$this->client->reset();

		$this->assertSame( 0, $this->client->get_request_count() );
		$this->assertEmpty( $this->client->get_request_history() );
	}

	// -------------------------------------------------------------------------
	// Stream emulation
	// -------------------------------------------------------------------------

	/**
	 * Stream should invoke the callback for each non-empty line of the response body.
	 */
	public function test_stream_invokes_callback_per_line() {
		$this->client->queue_success( 'stream.example.com', 200, "chunk1\nchunk2\nchunk3" );

		$chunks = array();
		$this->client->stream(
			'https://stream.example.com/events',
			array(),
			function ( $line ) use ( &$chunks ) {
				$chunks[] = $line;
			}
		);

		$this->assertCount( 3, $chunks );
		$this->assertSame( 'chunk1', $chunks[0] );
		$this->assertSame( 'chunk3', $chunks[2] );
	}

	/**
	 * Stream should skip empty lines in the response body.
	 */
	public function test_stream_skips_empty_lines() {
		$this->client->queue_success( 'stream.example.com', 200, "line1\n\nline2\n\n" );

		$chunks = array();
		$this->client->stream(
			'https://stream.example.com/events',
			array(),
			function ( $line ) use ( &$chunks ) {
				$chunks[] = $line;
			}
		);

		$this->assertCount( 2, $chunks );
	}

	// -------------------------------------------------------------------------
	// Response shape compatibility
	// -------------------------------------------------------------------------

	/**
	 * Responses already in wp_remote_* shape should pass through unchanged.
	 */
	public function test_response_in_wp_remote_shape_passes_through() {
		$this->client->queue_response(
			'api.example.com',
			array(
				'response' => array(
					'code'    => 418,
					'message' => "I'm a teapot",
				),
				'body'     => 'teapot',
				'headers'  => array(
					'x-teapot' => 'yes',
				),
			)
		);

		$response = $this->client->get( 'https://api.example.com/brew' );

		$this->assertSame( 418, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( 'teapot', wp_remote_retrieve_body( $response ) );
	}

	// -------------------------------------------------------------------------
	// Unstubbed fallback
	// -------------------------------------------------------------------------

	/**
	 * An unstubbed URL should return a generic 200 with empty JSON body.
	 */
	public function test_unstubbed_url_returns_generic_200() {
		$response = $this->client->get( 'https://unknown.example.com/' );

		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( '{}', wp_remote_retrieve_body( $response ) );
	}
}
