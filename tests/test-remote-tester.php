<?php

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-remote-tester.php';

/**
 * Tests for the remote MCP API connectivity tester.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Remote_Tester_Test extends WP_UnitTestCase {
	/**
	 * The tester instance under test.
	 *
	 * @var WP_MCP_AI_Remote_Tester
	 */
	protected $tester;

	/**
	 * Set up the tester for each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tester = new WP_MCP_AI_Remote_Tester();
	}

	/**
	 * Ensure invalid base URLs are rejected.
	 */
	public function test_probe_rejects_invalid_base_url() {
		$result = $this->tester->probe( '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_remote_invalid_base_url', $result->get_error_code() );
	}

	/**
	 * The tester should surface assistant counts and token scope for successful requests.
	 */
	public function test_probe_successful_request_returns_assistant_count_and_scope() {
		$directory_body = wp_json_encode(
			array(
				'assistants'  => array(
					array(
						'id'    => 1,
						'title' => 'Alpha',
					),
					array(
						'id'    => 2,
						'title' => 'Beta',
					),
				),
				'token_scope' => array(
					'type'         => 'local_token',
					'assistant_id' => 1,
				),
			)
		);

		$chat_body = wp_json_encode(
			array(
				'assistant_id' => 1,
				'probe'        => array(
					'status'     => 'ok',
					'checked_at' => '2024-05-01T00:00:00Z',
				),
				'message'      => 'Chat probe acknowledged.',
			)
		);

		$captured_urls   = array();
		$captured_bodies = array();

		$callback = static function ( $preempt, $args, $url ) use ( $directory_body, $chat_body, &$captured_urls, &$captured_bodies ) {
			$captured_urls[] = $url;

			$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';

			if ( 'POST' === $method ) {
				$captured_bodies[] = isset( $args['body'] ) ? $args['body'] : '';

				return array(
					'body'     => $chat_body,
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
					'cookies'  => array(),
				);
			}

			return array(
				'body'     => $directory_body,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => array(),
				'cookies'  => array(),
			);
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		try {
			$result = $this->tester->probe( 'https://example.com/wp-json/mcp-ai/v1' );
		} finally {
			remove_filter( 'pre_http_request', $callback, 10 );
		}

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'https://example.com/wp-json/mcp-ai/v1', $result['base_url'] );
		$this->assertCount( 2, $captured_urls );
		$this->assertSame( 'https://example.com/wp-json/mcp-ai/v1/assistants', $captured_urls[0] );
		$this->assertSame( 'https://example.com/wp-json/mcp-ai/v1/chat', $captured_urls[1] );
		$this->assertNotEmpty( $result['checks'] );
		$this->assertCount( 2, $result['checks'] );

		$this->assertNotEmpty( $captured_bodies );
		$encoded_payload = json_decode( $captured_bodies[0], true );
		$this->assertSame( 1, $encoded_payload['assistant_id'] );
		$this->assertTrue( $encoded_payload['options']['probe'] );

		$check = $result['checks'][0];
		$this->assertSame( 'success', $check['status'] );
		$this->assertSame( 200, $check['http_code'] );
		$this->assertSame( 2, $check['details']['assistant_count'] );
		$this->assertSame( 'local_token', $check['details']['token_scope']['type'] );

		$chat_check = $result['checks'][1];
		$this->assertSame( 'success', $chat_check['status'] );
		$this->assertSame( 200, $chat_check['http_code'] );
		$this->assertSame( 'ok', $chat_check['details']['probe_status'] );
		$this->assertSame( 1, $chat_check['details']['assistant_id'] );
		$this->assertArrayHasKey( 'responses', $result );
		$this->assertArrayHasKey( 'chat', $result['responses'] );
	}

	/**
	 * HTTP transport failures should be surfaced with the WP_Error message and code.
	 */
	public function test_probe_reports_wp_error_responses() {
		$callback = static function ( $preempt, $args, $url ) {
			return new WP_Error( 'http_request_failed', 'Timed out' );
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		try {
			$result = $this->tester->probe( 'https://example.com/wp-json/mcp-ai/v1' );
		} finally {
			remove_filter( 'pre_http_request', $callback, 10 );
		}

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['checks'] );

		$check = $result['checks'][0];
		$this->assertSame( 'error', $check['status'] );
		$this->assertNull( $check['http_code'] );
		$this->assertSame( 'http_request_failed', $check['details']['error_code'] );
		$this->assertStringContainsString( 'Timed out', $check['message'] );
	}

	/**
	 * REST error payloads should be propagated when the response is not successful.
	 */
	public function test_probe_includes_rest_error_details_on_failure() {
		$body = wp_json_encode(
			array(
				'code'    => 'wp_mcp_ai_missing_credentials',
				'message' => 'Authentication is required.',
				'data'    => array( 'status' => 401 ),
			)
		);

		$callback = static function ( $preempt, $args, $url ) use ( $body ) {
			return array(
				'body'     => $body,
				'response' => array(
					'code'    => 403,
					'message' => 'Forbidden',
				),
				'headers'  => array(),
				'cookies'  => array(),
			);
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		try {
			$result = $this->tester->probe( 'https://example.com/wp-json/mcp-ai/v1' );
		} finally {
			remove_filter( 'pre_http_request', $callback, 10 );
		}

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );

		$check = $result['checks'][0];
		$this->assertSame( 'error', $check['status'] );
		$this->assertSame( 403, $check['http_code'] );
		$this->assertSame( 'wp_mcp_ai_missing_credentials', $check['details']['rest_error_code'] );
		$this->assertSame( 'Authentication is required.', $check['details']['rest_error_message'] );
		$this->assertSame( 401, $check['details']['rest_error_status'] );
	}

	/**
	 * When no assistant can be resolved, the chat probe should surface an error.
	 */
	public function test_probe_returns_error_when_chat_assistant_unavailable() {
		$directory_body = wp_json_encode(
			array(
				'assistants'  => array(),
				'token_scope' => array(
					'type' => 'local_token',
				),
			)
		);

		$post_called = false;

		$callback = static function ( $preempt, $args, $url ) use ( $directory_body, &$post_called ) {
			$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';

			if ( 'POST' === $method ) {
				$post_called = true;
			}

			return array(
				'body'     => $directory_body,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => array(),
				'cookies'  => array(),
			);
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		try {
			$result = $this->tester->probe( 'https://example.com/wp-json/mcp-ai/v1' );
		} finally {
			remove_filter( 'pre_http_request', $callback, 10 );
		}

		$this->assertFalse( $post_called, 'Chat probe should not be attempted without an assistant ID.' );
		$this->assertFalse( $result['success'] );
		$this->assertCount( 2, $result['checks'] );

		$chat_check = $result['checks'][1];
		$this->assertSame( 'error', $chat_check['status'] );
		$this->assertNull( $chat_check['http_code'] );
		$this->assertStringContainsString( 'assistant ID', $chat_check['message'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 'wp_mcp_ai_remote_missing_assistant', $result['error']['code'] );
		$this->assertArrayHasKey( 'responses', $result );
		$this->assertArrayNotHasKey( 'chat', $result['responses'] );
	}

	/**
	 * Chat probe failures should bubble up REST error metadata.
	 */
	public function test_probe_reports_chat_failures() {
		$directory_body = wp_json_encode(
			array(
				'assistants' => array(
					array(
						'id'    => 42,
						'title' => 'Delta',
					),
				),
			)
		);

		$chat_body = wp_json_encode(
			array(
				'code'    => 'wp_mcp_ai_missing_credentials',
				'message' => 'Authentication required.',
				'data'    => array( 'status' => 401 ),
			)
		);

		$callback = static function ( $preempt, $args, $url ) use ( $directory_body, $chat_body ) {
			$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';

			if ( 'POST' === $method ) {
				return array(
					'body'     => $chat_body,
					'response' => array(
						'code'    => 403,
						'message' => 'Forbidden',
					),
					'headers'  => array(),
					'cookies'  => array(),
				);
			}

			return array(
				'body'     => $directory_body,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => array(),
				'cookies'  => array(),
			);
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		try {
			$result = $this->tester->probe( 'https://example.com/wp-json/mcp-ai/v1' );
		} finally {
			remove_filter( 'pre_http_request', $callback, 10 );
		}

		$this->assertFalse( $result['success'] );
		$this->assertCount( 2, $result['checks'] );

		$chat_check = $result['checks'][1];
		$this->assertSame( 'error', $chat_check['status'] );
		$this->assertSame( 403, $chat_check['http_code'] );
		$this->assertSame( 'wp_mcp_ai_missing_credentials', $chat_check['details']['rest_error_code'] );
		$this->assertSame( 401, $chat_check['details']['rest_error_status'] );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_credentials', $result['error']['code'] );
		$this->assertArrayHasKey( 'responses', $result );
		$this->assertArrayHasKey( 'chat', $result['responses'] );
		$this->assertSame( 403, $result['responses']['chat']['code'] );
	}

	/**
	 * Headers, timeout, and assistant hints should be forwarded to the request.
	 */
	public function test_probe_applies_custom_headers_and_timeout() {
		$directory_body = wp_json_encode(
			array(
				'assistants' => array(),
			)
		);

		$chat_body = wp_json_encode(
			array(
				'assistant_id' => 88,
				'probe'        => array(
					'status'     => 'ok',
					'checked_at' => '2024-05-01T00:00:00Z',
				),
			)
		);

		$captured_requests = array();

		$callback = static function ( $preempt, $args, $url ) use ( $directory_body, $chat_body, &$captured_requests ) {
			$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';

			$captured_requests[] = array(
				'method' => $method,
				'args'   => $args,
				'url'    => $url,
			);

			$body = ( 'POST' === $method ) ? $chat_body : $directory_body;

			return array(
				'body'     => $body,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => array(),
				'cookies'  => array(),
			);
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		try {
			$result = $this->tester->probe(
				'https://example.com/wp-json/mcp-ai/v1',
				array(
					'timeout'      => 25,
					'verify_ssl'   => false,
					'token'        => 'test-token',
					'guest_token'  => 'guest-123',
					'nonce'        => 'nonce-456',
					'assistant_id' => 88,
					'user_agent'   => 'Custom-UA',
				)
			);
		} finally {
			remove_filter( 'pre_http_request', $callback, 10 );
		}

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $captured_requests );

		$directory_request = $captured_requests[0];
		$this->assertSame( 'GET', $directory_request['method'] );
		$this->assertStringContainsString( 'assistant_id=88', $directory_request['url'] );

		$chat_request = null;
		foreach ( $captured_requests as $request ) {
			if ( 'POST' === $request['method'] ) {
				$chat_request = $request;
				break;
			}
		}

		$this->assertNotNull( $chat_request, 'Chat probe request was not captured.' );
		$this->assertSame( 'https://example.com/wp-json/mcp-ai/v1/chat', $chat_request['url'] );

		$chat_args = $chat_request['args'];
		$this->assertSame( 25, $chat_args['timeout'] );
		$this->assertFalse( $chat_args['sslverify'] );
		$this->assertSame( 'Custom-UA', $chat_args['user-agent'] );
		$this->assertSame( 'Bearer test-token', $chat_args['headers']['Authorization'] );
		$this->assertSame( 'guest-123', $chat_args['headers']['X-WP-MCP-AI-Guest'] );
		$this->assertSame( 'nonce-456', $chat_args['headers']['X-WP-Nonce'] );
		$this->assertSame( 'application/json', $chat_args['headers']['Content-Type'] );

		$decoded_body = json_decode( $chat_args['body'], true );
		$this->assertSame( 88, $decoded_body['assistant_id'] );
		$this->assertTrue( $decoded_body['options']['probe'] );
	}
}
