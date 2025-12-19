<?php
/**
 * Tests for HTTP connection logging.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_HTTP_Logging_Tests extends WP_UnitTestCase {

	/**
	 * Captured log entries.
	 *
	 * @var array
	 */
	private $captured_entries = array();

	public function setUp(): void {
		parent::setUp();

		WP_MCP_AI_HTTP::bootstrap();

		$this->captured_entries = array();

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array( 'enable_logging' => true ) );

		add_filter( 'wp_mcp_ai_log_entry', array( $this, 'capture_log_entry' ), 10, 4 );
	}

	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_log_entry', array( $this, 'capture_log_entry' ), 10 );

		parent::tearDown();
	}

	/**
	 * Capture emitted log entries for inspection.
	 *
	 * @param array  $entry       Prepared log entry.
	 * @param string $type        Event type.
	 * @param string $message     Event message.
	 * @param array  $raw_context Raw context prior to sanitisation.
	 *
	 * @return array
	 */
	public function capture_log_entry( $entry, $type, $message, $raw_context ) {
		$this->captured_entries[] = $entry;

		return $entry;
	}

	public function test_logs_successful_http_response() {
		$request_args = array(
			'method'  => 'GET',
			'timeout' => 5,
			'headers' => array(
				'Authorization' => 'Bearer secret-token',
				'Accept'        => 'application/json',
			),
		);

		do_action(
			'http_api_debug',
			$request_args,
			'request',
			'Requests_Transport_cURL',
			$request_args,
			'https://api.example.com/test'
		);

		do_action(
			'http_api_debug',
			array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => array(
					'Content-Type' => 'application/json',
				),
				'body'     => '{"hello":"world"}',
			),
			'response',
			'Requests_Transport_cURL',
			$request_args,
			'https://api.example.com/test'
		);

		$this->assertCount( 2, $this->captured_entries, 'Expected outbound and inbound HTTP entries to be logged.' );

		$outbound = $this->captured_entries[0];
		$this->assertSame( 'http_request_outbound', $outbound['type'] );
		$this->assertSame( 'https://api.example.com/test', $outbound['context']['url'] );
		$this->assertSame( 'GET', $outbound['context']['method'] );
		$this->assertSame( 'Requests_Transport_cURL', $outbound['context']['transport'] );
		$this->assertArrayHasKey( 'request_headers', $outbound['context'] );
		$this->assertSame( '[redacted]', $outbound['context']['request_headers']['authorization'] );
		$this->assertArrayNotHasKey( 'response_body', $outbound['context'] );

		$inbound = $this->captured_entries[1];
		$this->assertSame( 'http_response_inbound', $inbound['type'] );
		$this->assertSame( 200, $inbound['context']['status_code'] );
		$this->assertSame( 'Requests_Transport_cURL', $inbound['context']['transport'] );
		$this->assertArrayHasKey( 'response_body', $inbound['context'] );
		$this->assertArrayHasKey( 'request_headers', $inbound['context'] );
		$this->assertSame( '[redacted]', $inbound['context']['request_headers']['authorization'] );
	}

	public function test_respects_logging_setting() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array( 'enable_logging' => false ) );

		$this->captured_entries = array();

		$request_args = array(
			'method' => 'GET',
		);

		do_action(
			'http_api_debug',
			$request_args,
			'request',
			'Requests_Transport_fsockopen',
			$request_args,
			'https://api.example.com/disabled'
		);

		do_action(
			'http_api_debug',
			array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => array(),
				'body'     => '',
			),
			'response',
			'Requests_Transport_fsockopen',
			$request_args,
			'https://api.example.com/disabled'
		);

		$this->assertEmpty( $this->captured_entries, 'Logging should be skipped when disabled.' );
	}

	public function test_logs_transport_errors() {
		$error = new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' );

		$this->captured_entries = array();

		$request_args = array(
			'method' => 'POST',
			'body'   => array( 'foo' => 'bar' ),
		);

		do_action(
			'http_api_debug',
			$request_args,
			'request',
			'Requests_Transport_cURL',
			$request_args,
			'https://api.example.com/error'
		);

		do_action(
			'http_api_debug',
			$error,
			'response',
			'Requests_Transport_cURL',
			$request_args,
			'https://api.example.com/error'
		);

		$this->assertCount( 2, $this->captured_entries, 'Expected outbound and error entries to be logged.' );

		$outbound = $this->captured_entries[0];
		$this->assertSame( 'http_request_outbound', $outbound['type'] );
		$this->assertSame( 'POST', $outbound['context']['method'] );

		$error_entry = $this->captured_entries[1];
		$this->assertSame( 'http_response_error', $error_entry['type'] );
		$this->assertSame( 'https://api.example.com/error', $error_entry['context']['url'] );
		$this->assertArrayHasKey( 'error', $error_entry['context'] );
		$this->assertSame( 'cURL error 28: Connection timed out', $error_entry['context']['error']['message'] );
	}
}
