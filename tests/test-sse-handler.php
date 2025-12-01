<?php
/**
 * Tests for WP_MCP_AI_SSE_Handler class.
 *
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test SSE Handler functionality.
 */
class Test_SSE_Handler extends WP_UnitTestCase {

	/**
	 * SSE handler instance.
	 *
	 * @var WP_MCP_AI_SSE_Handler
	 */
	private $handler;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-sse-handler.php';
		$this->handler = new WP_MCP_AI_SSE_Handler();
	}

	/**
	 * Test handler instantiation.
	 */
	public function test_handler_instantiation() {
		$this->assertInstanceOf( 'WP_MCP_AI_SSE_Handler', $this->handler );
	}

	/**
	 * Test request_wants_event_stream with explicit stream parameter as true.
	 */
	public function test_request_wants_event_stream_with_explicit_true() {
		$request = new WP_REST_Request();
		$request->set_param( 'stream', true );

		$result = $this->handler->request_wants_event_stream( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test request_wants_event_stream with explicit stream parameter as false.
	 */
	public function test_request_wants_event_stream_with_explicit_false() {
		$request = new WP_REST_Request();
		$request->set_param( 'stream', false );

		$result = $this->handler->request_wants_event_stream( $request );

		$this->assertFalse( $result );
	}

	/**
	 * Test request_wants_event_stream with stream object containing enabled true.
	 */
	public function test_request_wants_event_stream_with_stream_object_enabled_true() {
		$request = new WP_REST_Request();
		$request->set_param( 'stream', array( 'enabled' => true ) );

		$result = $this->handler->request_wants_event_stream( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test request_wants_event_stream with stream object containing enabled false.
	 */
	public function test_request_wants_event_stream_with_stream_object_enabled_false() {
		$request = new WP_REST_Request();
		$request->set_param( 'stream', array( 'enabled' => false ) );

		$result = $this->handler->request_wants_event_stream( $request );

		$this->assertFalse( $result );
	}

	/**
	 * Test request_wants_event_stream with non-empty stream array (no enabled key).
	 */
	public function test_request_wants_event_stream_with_non_empty_stream_array() {
		$request = new WP_REST_Request();
		$request->set_param( 'stream', array( 'other_key' => 'value' ) );

		$result = $this->handler->request_wants_event_stream( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test request_wants_event_stream with Accept header for text/event-stream.
	 *
	 * UPDATED: Accept header should NOT trigger SSE mode (LM Studio fix).
	 * Only explicit stream parameter should trigger SSE.
	 */
	public function test_request_wants_event_stream_with_accept_header() {
		$request = new WP_REST_Request();
		$request->set_header( 'accept', 'text/event-stream' );

		$result = $this->handler->request_wants_event_stream( $request );

		// Changed from assertTrue to assertFalse - Accept header is now ignored.
		$this->assertFalse( $result, 'Accept header should NOT trigger SSE mode (LM Studio fix)' );
	}

	/**
	 * Test request_wants_event_stream with Accept header containing text/event-stream.
	 *
	 * UPDATED: Accept header should NOT trigger SSE mode (LM Studio fix).
	 * Only explicit stream parameter should trigger SSE.
	 */
	public function test_request_wants_event_stream_with_accept_header_mixed() {
		$request = new WP_REST_Request();
		$request->set_header( 'accept', 'application/json, text/event-stream, */*' );

		$result = $this->handler->request_wants_event_stream( $request );

		// Changed from assertTrue to assertFalse - Accept header is now ignored.
		$this->assertFalse( $result, 'Accept header should NOT trigger SSE mode (LM Studio fix)' );
	}

	/**
	 * Test request_wants_event_stream with no indicators returns false.
	 */
	public function test_request_wants_event_stream_with_no_indicators() {
		$request = new WP_REST_Request();

		$result = $this->handler->request_wants_event_stream( $request );

		$this->assertFalse( $result );
	}

	/**
	 * Test request_wants_event_stream with non-SSE Accept header returns false.
	 */
	public function test_request_wants_event_stream_with_non_sse_accept_header() {
		$request = new WP_REST_Request();
		$request->set_header( 'accept', 'application/json' );

		$result = $this->handler->request_wants_event_stream( $request );

		$this->assertFalse( $result );
	}

	/**
	 * Test LM Studio scenario: Accept header with explicit stream parameter.
	 *
	 * This tests the fix for the LM Studio 500 error issue.
	 * LM Studio sends Accept: text/event-stream but the explicit stream parameter
	 * should control whether SSE is used, not the Accept header.
	 */
	public function test_lm_studio_scenario_accept_header_with_explicit_stream_true() {
		$request = new WP_REST_Request();
		$request->set_header( 'accept', 'text/event-stream' );
		$request->set_param( 'stream', 'true' );

		$result = $this->handler->request_wants_event_stream( $request );

		$this->assertTrue( $result, 'Explicit stream=true should enable SSE even with Accept header' );
	}

	/**
	 * Test LM Studio scenario: Accept header without stream parameter.
	 *
	 * This is the exact scenario that was causing the 500 error.
	 * LM Studio sends Accept: text/event-stream but expects JSON.
	 */
	public function test_lm_studio_scenario_accept_header_without_stream_param() {
		$request = new WP_REST_Request();
		$request->set_header( 'accept', 'text/event-stream' );
		// No stream parameter.

		$result = $this->handler->request_wants_event_stream( $request );

		$this->assertFalse( $result, 'Accept header alone should NOT trigger SSE (LM Studio fix)' );
	}

	/**
	 * Test build_event_stream_chunk with basic event and data.
	 */
	public function test_build_event_stream_chunk_basic() {
		$chunk = $this->handler->build_event_stream_chunk( 'message', 'Hello World' );

		$expected = "event: message\ndata: Hello World\n\n";

		$this->assertEquals( $expected, $chunk );
	}

	/**
	 * Test build_event_stream_chunk with event, data, and ID.
	 */
	public function test_build_event_stream_chunk_with_id() {
		$chunk = $this->handler->build_event_stream_chunk( 'update', 'Status OK', '12345' );

		$expected = "id: 12345\nevent: update\ndata: Status OK\n\n";

		$this->assertEquals( $expected, $chunk );
	}

	/**
	 * Test build_event_stream_chunk with empty event name.
	 */
	public function test_build_event_stream_chunk_empty_event() {
		$chunk = $this->handler->build_event_stream_chunk( '', 'Data only' );

		$expected = "data: Data only\n\n";

		$this->assertEquals( $expected, $chunk );
	}

	/**
	 * Test build_event_stream_chunk with multiline data.
	 */
	public function test_build_event_stream_chunk_multiline_data() {
		$data  = "Line 1\nLine 2\nLine 3";
		$chunk = $this->handler->build_event_stream_chunk( 'message', $data );

		$expected = "event: message\ndata: Line 1\ndata: Line 2\ndata: Line 3\n\n";

		$this->assertEquals( $expected, $chunk );
	}

	/**
	 * Test build_event_stream_chunk with JSON data.
	 */
	public function test_build_event_stream_chunk_with_json() {
		$data  = wp_json_encode(
			array(
				'status' => 'success',
				'count'  => 42,
			)
		);
		$chunk = $this->handler->build_event_stream_chunk( 'result', $data );

		$this->assertStringContainsString( 'event: result', $chunk );
		$this->assertStringContainsString( 'data: ', $chunk );
		$this->assertStringContainsString( '"status":"success"', $chunk );
	}

	/**
	 * Test stream_event_stream_payload returns WP_REST_Response.
	 */
	public function test_stream_event_stream_payload_returns_response() {
		$payload = array( 'message' => 'Test payload' );

		$response = $this->handler->stream_event_stream_payload( $payload );

		$this->assertInstanceOf( 'WP_REST_Response', $response );
	}

	/**
	 * Test stream_event_stream_payload sets correct status code.
	 */
	public function test_stream_event_stream_payload_status_code() {
		$payload = array( 'message' => 'Test payload' );

		$response = $this->handler->stream_event_stream_payload( $payload );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test stream_event_stream_payload sets correct Content-Type header.
	 */
	public function test_stream_event_stream_payload_content_type() {
		$payload = array( 'message' => 'Test payload' );

		$response = $this->handler->stream_event_stream_payload( $payload );
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'Content-Type', $headers );
		$this->assertEquals( 'text/event-stream; charset=UTF-8', $headers['Content-Type'] );
	}

	/**
	 * Test stream_event_stream_payload sets cache-control headers.
	 */
	public function test_stream_event_stream_payload_cache_headers() {
		$payload = array( 'message' => 'Test payload' );

		$response = $this->handler->stream_event_stream_payload( $payload );
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString( 'no-cache', $headers['Cache-Control'] );
	}

	/**
	 * Test stream_event_stream_payload sets CORS headers.
	 */
	public function test_stream_event_stream_payload_cors_headers() {
		$payload = array( 'message' => 'Test payload' );

		$response = $this->handler->stream_event_stream_payload( $payload );
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'Access-Control-Allow-Origin', $headers );
		$this->assertEquals( '*', $headers['Access-Control-Allow-Origin'] );
	}

	/**
	 * Test stream_event_stream_payload with custom event name.
	 */
	public function test_stream_event_stream_payload_custom_event() {
		$payload = array( 'status' => 'processing' );

		$response = $this->handler->stream_event_stream_payload( $payload, 'status_update' );

		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test stream_event_stream_payload with empty event name defaults to 'message'.
	 */
	public function test_stream_event_stream_payload_empty_event_defaults() {
		$payload = array( 'data' => 'test' );

		$response = $this->handler->stream_event_stream_payload( $payload, '' );

		$this->assertInstanceOf( 'WP_REST_Response', $response );
		// The handler should use 'message' as default event name.
	}

	/**
	 * Test stream_event_stream_payload with non-encodable payload.
	 */
	public function test_stream_event_stream_payload_non_encodable() {
		// Create a payload with an unencodable resource.
		$resource = fopen( 'php://memory', 'r' );
		$payload  = array( 'resource' => $resource );

		$response = $this->handler->stream_event_stream_payload( $payload );

		// Should fall back to rest_ensure_response.
		$this->assertInstanceOf( 'WP_REST_Response', $response );

		fclose( $resource );
	}

	/**
	 * Test send_sse_event doesn't throw errors (output buffering test).
	 */
	public function test_send_sse_event_no_errors() {
		ob_start();
		$this->handler->send_sse_event( 'test', array( 'key' => 'value' ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'event: test', $output );
		$this->assertStringContainsString( 'data: ', $output );
		$this->assertStringContainsString( '"key":"value"', $output );
	}

	/**
	 * Test send_sse_done outputs correct marker.
	 */
	public function test_send_sse_done_output() {
		ob_start();
		$this->handler->send_sse_done();
		$output = ob_get_clean();

		$this->assertEquals( "data: [DONE]\n\n", $output );
	}

	/**
	 * Test send_sse_headers doesn't throw errors.
	 *
	 * Note: Can't fully test header sending in unit tests, but we can verify
	 * the method executes without fatal errors.
	 */
	public function test_send_sse_headers_no_errors() {
		// Headers already sent in test environment, so this just verifies no fatal error.
		$this->handler->send_sse_headers();

		// If we get here without fatal error, test passes.
		$this->assertTrue( true );
	}
}
