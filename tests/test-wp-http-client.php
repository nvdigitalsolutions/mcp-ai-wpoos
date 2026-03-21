<?php
/**
 * Tests for WP_MCP_AI_WP_HTTP_Client
 *
 * Validates that the WordPress HTTP Client adapter correctly wraps
 * wp_remote_get / wp_remote_post, applies defaults, rejects empty URLs,
 * and invokes callbacks for streamed responses.
 *
 * @package WP_MCP_AI
 * @group   infrastructure
 * @group   http-client
 */

/**
 * Test case for WP_MCP_AI_WP_HTTP_Client.
 */
class Test_WP_MCP_AI_WP_HTTP_Client extends WP_UnitTestCase {

	/**
	 * SUT instance.
	 *
	 * @var WP_MCP_AI_WP_HTTP_Client
	 */
	private $client;

	/**
	 * Set up a fresh client instance before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->client = new WP_MCP_AI_WP_HTTP_Client();
	}

	// -------------------------------------------------------------------------
	// Interface contract
	// -------------------------------------------------------------------------

	/**
	 * The class should implement the HTTP client interface.
	 */
	public function test_implements_interface() {
		$this->assertInstanceOf( Interface_WP_MCP_AI_HTTP_Client::class, $this->client );
	}

	// -------------------------------------------------------------------------
	// Empty URL guards
	// -------------------------------------------------------------------------

	/**
	 * get() with empty string returns WP_Error.
	 */
	public function test_get_empty_url_returns_wp_error() {
		$result = $this->client->get( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_url', $result->get_error_code() );
	}

	/**
	 * post() with empty string returns WP_Error.
	 */
	public function test_post_empty_url_returns_wp_error() {
		$result = $this->client->post( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_url', $result->get_error_code() );
	}

	/**
	 * stream() with empty string returns WP_Error.
	 */
	public function test_stream_empty_url_returns_wp_error() {
		$result = $this->client->stream( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_url', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Default timeout
	// -------------------------------------------------------------------------

	/**
	 * get() applies the default timeout to outbound requests.
	 */
	public function test_get_applies_default_timeout() {
		$captured = null;

		$filter = function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = $args;

			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );
		$this->client->get( 'https://example.com/' );
		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertArrayHasKey( 'timeout', $captured );
		$this->assertSame( WP_MCP_AI_WP_HTTP_Client::DEFAULT_TIMEOUT, $captured['timeout'] );
	}

	/**
	 * post() applies the default timeout to outbound requests.
	 */
	public function test_post_applies_default_timeout() {
		$captured = null;

		$filter = function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = $args;

			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );
		$this->client->post( 'https://example.com/' );
		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertArrayHasKey( 'timeout', $captured );
		$this->assertSame( WP_MCP_AI_WP_HTTP_Client::DEFAULT_TIMEOUT, $captured['timeout'] );
	}

	/**
	 * A caller-supplied timeout overrides the default.
	 */
	public function test_caller_timeout_overrides_default() {
		$captured = null;

		$filter = function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = $args;

			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );
		$this->client->get( 'https://example.com/', array( 'timeout' => 60 ) );
		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertSame( 60, $captured['timeout'] );
	}

	// -------------------------------------------------------------------------
	// get() pass-through
	// -------------------------------------------------------------------------

	/**
	 * get() sends a request to the expected URL and returns the response body.
	 */
	public function test_get_sends_request_and_returns_response() {
		$called = false;

		$filter = function ( $preempt, $args, $url ) use ( &$called ) {
			$this->assertSame( 'https://example.com/resource', $url );
			$called = true;

			return array(
				'headers'  => array(),
				'body'     => '{"ok":true}',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );
		$response = $this->client->get( 'https://example.com/resource' );
		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertTrue( $called );
		$this->assertSame( '{"ok":true}', wp_remote_retrieve_body( $response ) );
	}

	/**
	 * get() returns a WP_Error when the transport fails.
	 */
	public function test_get_returns_wp_error_on_transport_failure() {
		$filter = function ( $preempt, $args, $url ) {
			return new WP_Error( 'http_request_failed', 'Connection refused' );
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );
		$response = $this->client->get( 'https://example.com/' );
		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertWPError( $response );
	}

	// -------------------------------------------------------------------------
	// post() pass-through
	// -------------------------------------------------------------------------

	/**
	 * post() sends the request body to the expected URL.
	 */
	public function test_post_sends_body_to_url() {
		$captured = null;

		$filter = function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = array( 'url' => $url, 'args' => $args );

			return array(
				'headers'  => array(),
				'body'     => '{"created":true}',
				'response' => array( 'code' => 201, 'message' => 'Created' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );
		$this->client->post(
			'https://example.com/create',
			array( 'body' => '{"name":"test"}' )
		);
		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertSame( 'https://example.com/create', $captured['url'] );
		$this->assertSame( '{"name":"test"}', $captured['args']['body'] );
	}

	/**
	 * post() returns a WP_Error when the transport fails.
	 */
	public function test_post_returns_wp_error_on_transport_failure() {
		$filter = function ( $preempt, $args, $url ) {
			return new WP_Error( 'http_request_failed', 'Connection refused' );
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );
		$response = $this->client->post( 'https://example.com/' );
		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertWPError( $response );
	}

	// -------------------------------------------------------------------------
	// stream()
	// -------------------------------------------------------------------------

	/**
	 * stream() sets stream=true and blocking=true on the outbound request.
	 */
	public function test_stream_sets_stream_and_blocking_flags() {
		$captured = null;

		$filter = function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = $args;

			return array(
				'headers'  => array(),
				'body'     => "data: hello\ndata: [DONE]",
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );
		$this->client->stream( 'https://example.com/stream' );
		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertTrue( $captured['stream'] );
		$this->assertTrue( $captured['blocking'] );
	}

	/**
	 * stream() invokes the callback for each non-empty line of the response body.
	 */
	public function test_stream_invokes_callback_per_line() {
		$filter = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => "data: chunk1\ndata: chunk2\ndata: [DONE]",
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$received = array();
		$this->client->stream(
			'https://example.com/stream',
			array(),
			function ( $chunk, $done ) use ( &$received ) {
				$received[] = array( 'chunk' => $chunk, 'done' => $done );
			}
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertCount( 3, $received );
		$this->assertSame( 'data: chunk1', $received[0]['chunk'] );
		$this->assertFalse( $received[0]['done'] );
		$this->assertSame( 'data: chunk2', $received[1]['chunk'] );
		$this->assertFalse( $received[1]['done'] );
		$this->assertSame( 'data: [DONE]', $received[2]['chunk'] );
		$this->assertTrue( $received[2]['done'] );
	}

	/**
	 * stream() marks the last non-empty line as done=true even without [DONE] sentinel.
	 */
	public function test_stream_marks_last_line_as_done() {
		$filter = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => "line1\nline2\nline3",
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$received = array();
		$this->client->stream(
			'https://example.com/stream',
			array(),
			function ( $chunk, $done ) use ( &$received ) {
				$received[] = array( 'chunk' => $chunk, 'done' => $done );
			}
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertCount( 3, $received );
		$this->assertFalse( $received[0]['done'] );
		$this->assertFalse( $received[1]['done'] );
		$this->assertTrue( $received[2]['done'] );
	}

	/**
	 * stream() handles CRLF line endings as well as LF.
	 */
	public function test_stream_handles_crlf_line_endings() {
		$filter = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => "line1\r\nline2\r\nline3",
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$chunks = array();
		$this->client->stream(
			'https://example.com/stream',
			array(),
			function ( $chunk, $done ) use ( &$chunks ) {
				$chunks[] = $chunk;
			}
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertCount( 3, $chunks );
		$this->assertSame( 'line1', $chunks[0] );
		$this->assertSame( 'line2', $chunks[1] );
		$this->assertSame( 'line3', $chunks[2] );
	}

	/**
	 * stream() skips empty lines and doesn't deliver them to the callback.
	 */
	public function test_stream_skips_empty_lines() {
		$filter = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => "line1\n\nline2\n\n",
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$chunks = array();
		$this->client->stream(
			'https://example.com/stream',
			array(),
			function ( $chunk, $done ) use ( &$chunks ) {
				$chunks[] = $chunk;
			}
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertCount( 2, $chunks );
		$this->assertSame( 'line1', $chunks[0] );
		$this->assertSame( 'line2', $chunks[1] );
	}

	/**
	 * stream() without a callback still returns a summary array.
	 */
	public function test_stream_without_callback_returns_summary() {
		$filter = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => 'some data',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );
		$result = $this->client->stream( 'https://example.com/stream' );
		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'body', $result );
		$this->assertSame( 200, $result['status'] );
	}

	/**
	 * stream() returns a WP_Error when the transport fails.
	 */
	public function test_stream_returns_wp_error_on_transport_failure() {
		$filter = function ( $preempt, $args, $url ) {
			return new WP_Error( 'http_request_failed', 'Connection refused' );
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );
		$response = $this->client->stream( 'https://example.com/stream' );
		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertWPError( $response );
	}

	// -------------------------------------------------------------------------
	// Filter hooks
	// -------------------------------------------------------------------------

	/**
	 * get() fires the wp_mcp_ai_http_client_get_args filter.
	 */
	public function test_get_fires_args_filter() {
		$filter_fired = false;

		$args_filter = function ( $args, $url ) use ( &$filter_fired ) {
			$filter_fired = true;
			return $args;
		};

		$http_filter = function ( $preempt, $args, $url ) {
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ) );
		};

		add_filter( 'wp_mcp_ai_http_client_get_args', $args_filter, 10, 2 );
		add_filter( 'pre_http_request', $http_filter, 10, 3 );
		$this->client->get( 'https://example.com/' );
		remove_filter( 'wp_mcp_ai_http_client_get_args', $args_filter, 10 );
		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertTrue( $filter_fired );
	}

	/**
	 * post() fires the wp_mcp_ai_http_client_post_args filter.
	 */
	public function test_post_fires_args_filter() {
		$filter_fired = false;

		$args_filter = function ( $args, $url ) use ( &$filter_fired ) {
			$filter_fired = true;
			return $args;
		};

		$http_filter = function ( $preempt, $args, $url ) {
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ) );
		};

		add_filter( 'wp_mcp_ai_http_client_post_args', $args_filter, 10, 2 );
		add_filter( 'pre_http_request', $http_filter, 10, 3 );
		$this->client->post( 'https://example.com/' );
		remove_filter( 'wp_mcp_ai_http_client_post_args', $args_filter, 10 );
		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertTrue( $filter_fired );
	}

	/**
	 * stream() fires the wp_mcp_ai_http_client_stream_args filter.
	 */
	public function test_stream_fires_args_filter() {
		$filter_fired = false;

		$args_filter = function ( $args, $url ) use ( &$filter_fired ) {
			$filter_fired = true;
			return $args;
		};

		$http_filter = function ( $preempt, $args, $url ) {
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ) );
		};

		add_filter( 'wp_mcp_ai_http_client_stream_args', $args_filter, 10, 2 );
		add_filter( 'pre_http_request', $http_filter, 10, 3 );
		$this->client->stream( 'https://example.com/stream' );
		remove_filter( 'wp_mcp_ai_http_client_stream_args', $args_filter, 10 );
		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertTrue( $filter_fired );
	}
}
