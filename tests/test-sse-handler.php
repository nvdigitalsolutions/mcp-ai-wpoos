<?php
/**
 * Tests for WP_MCP_AI_SSE_Handler class.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
	 * Capture handler output despite its buffer management.
	 *
	 * The handler flushes/discards output buffers as part of its streaming
	 * protocol. A callback-based buffer survives every flush/clean/end because
	 * each of those operations delivers the affected content to the callback.
	 *
	 * @param callable $emit Emitter to run.
	 * @return string Captured output.
	 */
	protected function capture_output( $emit ) {
		$captured = '';
		ob_start(
			static function ( $chunk ) use ( &$captured ) {
				$captured .= $chunk;
				return '';
			}
		);
		$emit();
		ob_end_clean();
		return $captured;
	}

	/**
	 * Send_sse_event() emits a named frame with JSON-encoded data.
	 */
	public function test_send_sse_event_emits_named_frame() {
		$output = $this->capture_output(
			function () {
				$this->handler->send_sse_event( 'message', 'Hello World' );
			}
		);

		$this->assertSame( "event: message\ndata: \"Hello World\"\n\n", $output );
	}

	/**
	 * Send_sse_event_with_id() emits an id: line before the frame.
	 */
	public function test_send_sse_event_with_id_emits_id_line() {
		$output = $this->capture_output(
			function () {
				$this->handler->send_sse_event_with_id( 'update', array( 'status' => 'ok' ), '12345' );
			}
		);

		$this->assertSame( "id: 12345\nevent: update\ndata: {\"status\":\"ok\"}\n\n", $output );
	}

	/**
	 * Send_sse_event() keeps the event line even for an empty event name.
	 */
	public function test_send_sse_event_empty_event_name() {
		$output = $this->capture_output(
			function () {
				$this->handler->send_sse_event( '', 'Data only' );
			}
		);

		$this->assertSame( "event: \ndata: \"Data only\"\n\n", $output );
	}

	/**
	 * Send_sse_event() JSON-encodes data, so newlines become \n escapes.
	 */
	public function test_send_sse_event_multiline_data() {
		$output = $this->capture_output(
			function () {
				$this->handler->send_sse_event( 'message', "Line 1\nLine 2" );
			}
		);

		$this->assertSame( "event: message\ndata: \"Line 1\\nLine 2\"\n\n", $output );
	}

	/**
	 * Send_sse_event() emits the JSON-encoded payload on the data: line.
	 */
	public function test_send_sse_event_with_json() {
		$output = $this->capture_output(
			function () {
				$this->handler->send_sse_event(
					'result',
					array(
						'status' => 'success',
						'count'  => 42,
					)
				);
			}
		);

		$this->assertStringContainsString( 'event: result', $output );
		$this->assertStringContainsString( '"status":"success"', $output );
		$this->assertStringContainsString( '"count":42', $output );
	}

	/**
	 * Stream_event_stream_payload() completes the stream without terminating
	 * the test process.
	 *
	 * The method was redesigned from a pure response builder into a blocking
	 * emitter (headers + named frame + [DONE] + finish()); finish() returns
	 * instead of exiting under tests. Frame formats are asserted by the
	 * send_sse_event()/send_sse_done() tests above, so this test guards the
	 * lifecycle: payload variants must not throw or kill the run.
	 */
	public function test_stream_event_stream_payload_completes() {
		$this->handler->stream_event_stream_payload( array( 'message' => 'Test payload' ) );
		$this->handler->stream_event_stream_payload( array( 'status' => 'processing' ), 'status_update' );
		$this->handler->stream_event_stream_payload( array( 'data' => 'test' ), '' );

		// Non-encodable payload must not throw — the handler emits an error frame.
		$resource = fopen( 'php://memory', 'r' );
		$this->handler->stream_event_stream_payload( array( 'resource' => $resource ) );
		fclose( $resource );

		// Reaching this point proves none of the calls terminated the run.
		$this->assertTrue( true );
	}

	/**
	 * Test send_sse_event doesn't throw errors and emits the JSON payload.
	 */
	public function test_send_sse_event_no_errors() {
		$output = $this->capture_output(
			function () {
				$this->handler->send_sse_event( 'test', array( 'key' => 'value' ) );
			}
		);

		$this->assertStringContainsString( 'event: test', $output );
		$this->assertStringContainsString( '"key":"value"', $output );
	}

	/**
	 * Test send_sse_done outputs correct marker.
	 */
	public function test_send_sse_done_output() {
		$output = $this->capture_output(
			function () {
				$this->handler->send_sse_done();
			}
		);

		$this->assertSame( "data: [DONE]\n\n", $output );
	}
}
