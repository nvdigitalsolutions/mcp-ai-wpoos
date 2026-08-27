<?php
/**
 * Tests for streaming chat responses as Server-Sent Events.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Chat_Event_Stream_Test extends WP_UnitTestCase {
	/**
	 * Ensure the Accept header alone does not trigger streaming.
	 *
	 * MCP clients like LM Studio send "Accept: text/event-stream" by default
	 * but expect JSON responses (Streamable HTTP transport, MCP 2024-11-05
	 * spec), so streaming is driven exclusively by the explicit stream param.
	 */
	public function test_chat_request_returns_json_when_accept_header_alone_requests_event_stream() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Stream Assistant',
				'post_status' => 'publish',
			)
		);

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'id'      => 'chatcmpl-stream',
					'choices' => array(),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello there',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/event-stream' );

		list( $response, $captured ) = $this->dispatch_chat_and_capture( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		// Stray environment output (e.g. database error markup) may leak into
		// the capture buffer on some hosts, so assert on the absence of SSE
		// frames rather than on a completely empty buffer.
		$this->assertStringNotContainsString( 'data: [DONE]', $captured, 'No SSE frames should be emitted when only the Accept header requests streaming.' );
		$this->assertStringNotContainsString( 'event: message', $captured );
		$this->assertArrayHasKey( 'assistant_id', (array) $response->get_data() );

		wp_set_current_user( 0 );
	}

	/**
	 * Ensure mixed Accept header values do not interfere with explicit streaming.
	 */
	public function test_chat_request_streams_response_when_stream_param_set_with_mixed_accept_header() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Stream Assistant',
				'post_status' => 'publish',
			)
		);

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'id'      => 'chatcmpl-stream-mixed-accept',
					'choices' => array(),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'stream', true );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Check accept header parsing',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'application/json;q=0.9, text/event-stream, */*;q=0.1' );

		list( $response, $captured ) = $this->dispatch_chat_and_capture( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'retry:', $captured );
		$this->assertStringContainsString( 'event: message', $captured );
		$this->assertStringContainsString( 'data: {', $captured );
		$this->assertStringContainsString( 'data: [DONE]', $captured );

		wp_set_current_user( 0 );
	}

	/**
	 * Ensure the stream flag triggers event stream responses even without the Accept header.
	 */
	public function test_chat_request_streams_response_when_stream_flag_set() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Stream Assistant',
				'post_status' => 'publish',
			)
		);

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'id'      => 'chatcmpl-stream-flag',
					'choices' => array(),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'stream', 'true' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Stream please',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		list( $response, $captured ) = $this->dispatch_chat_and_capture( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'retry:', $captured );
		$this->assertStringContainsString( 'event: message', $captured );
		$this->assertStringContainsString( 'data: {', $captured );
		$this->assertStringContainsString( 'data: [DONE]', $captured );

		wp_set_current_user( 0 );
	}

	/**
	 * Ensure numeric stream flags trigger event stream responses.
	 */
	public function test_chat_request_streams_response_when_stream_flag_is_integer() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Stream Assistant',
				'post_status' => 'publish',
			)
		);

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'id'      => 'chatcmpl-stream-flag-int',
					'choices' => array(),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'stream', 1 );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Stream please with int',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		list( $response, $captured ) = $this->dispatch_chat_and_capture( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'retry:', $captured );
		$this->assertStringContainsString( 'event: message', $captured );
		$this->assertStringContainsString( 'data: {', $captured );
		$this->assertStringContainsString( 'data: [DONE]', $captured );

		wp_set_current_user( 0 );
	}

	/**
	 * Ensure associative stream flags trigger event stream responses.
	 */
	public function test_chat_request_streams_response_when_stream_flag_is_array() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Stream Assistant Array',
				'post_status' => 'publish',
			)
		);

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'id'      => 'chatcmpl-stream-flag-array',
					'choices' => array(),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'stream', array( 'type' => 'sse' ) );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Stream please with array',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		list( $response, $captured ) = $this->dispatch_chat_and_capture( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'retry:', $captured );
		$this->assertStringContainsString( 'event: message', $captured );
		$this->assertStringContainsString( 'data: {', $captured );
		$this->assertStringContainsString( 'data: [DONE]', $captured );

		wp_set_current_user( 0 );
	}

	/**
	 * Ensure array stream flags can explicitly disable streaming.
	 */
	public function test_chat_request_returns_json_when_stream_flag_disabled() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Stream Assistant Disabled',
				'post_status' => 'publish',
			)
		);

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'id'      => 'chatcmpl-stream-flag-disabled',
					'choices' => array(),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'stream', array( 'enabled' => false ) );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Do not stream',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		list( $response, $captured ) = $this->dispatch_chat_and_capture( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		// Stray environment output may leak into the capture buffer on some
		// hosts, so assert on the absence of SSE frames rather than on a
		// completely empty buffer.
		$this->assertStringNotContainsString( 'data: [DONE]', $captured, 'No SSE frames should be emitted when streaming is disabled.' );
		$this->assertStringNotContainsString( 'event: message', $captured );
		$this->assertArrayHasKey( 'assistant_id', (array) $response->get_data() );

		wp_set_current_user( 0 );
	}

	/**
	 * Ensure explicit stream disables override Accept header negotiation.
	 */
	public function test_chat_request_returns_json_when_stream_flag_disabled_even_with_accept_header() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Stream Assistant Disabled by Accept',
				'post_status' => 'publish',
			)
		);

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'id'      => 'chatcmpl-stream-flag-disabled-accept',
					'choices' => array(),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'stream', array( 'enabled' => false ) );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Do not stream despite accept header',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/event-stream' );

		list( $response, $captured ) = $this->dispatch_chat_and_capture( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		// Stray environment output may leak into the capture buffer on some
		// hosts, so assert on the absence of SSE frames rather than on a
		// completely empty buffer.
		$this->assertStringNotContainsString( 'data: [DONE]', $captured, 'No SSE frames should be emitted when streaming is explicitly disabled.' );
		$this->assertStringNotContainsString( 'event: message', $captured );
		$this->assertArrayHasKey( 'assistant_id', (array) $response->get_data() );

		wp_set_current_user( 0 );
	}

	/**
	 * Ensure assistant credential bearer tokens can stream chat responses.
	 */
	public function test_chat_request_streams_response_with_assistant_credential_token() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Credential Stream Assistant',
				'post_status' => 'publish',
			)
		);

		$issuer_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $issuer_id );

		$issued = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $issuer_id );
		$this->assertIsArray( $issued );
		$this->assertArrayHasKey( 'token', $issued );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'id'      => 'chatcmpl-stream-credential',
					'choices' => array(),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'stream', true );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Stream with credential',
				),
			)
		);
		$request->set_header( 'Authorization', 'Bearer ' . $issued['token'] );

		list( $response, $captured ) = $this->dispatch_chat_and_capture( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'retry:', $captured );
		$this->assertStringContainsString( 'event: message', $captured );
		$this->assertStringContainsString( 'data: {', $captured );
		$this->assertStringContainsString( 'data: [DONE]', $captured );
	}

	/**
	 * Ensure guest tokens can stream chat responses.
	 */
	public function test_chat_request_streams_response_with_guest_token() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Guest Stream Assistant',
				'post_status' => 'publish',
			)
		);

		$token = WP_MCP_AI_Shortcode::generate_guest_token( $assistant_id );
		$this->assertNotEmpty( $token );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'id'      => 'chatcmpl-stream-guest',
					'choices' => array(),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'stream', true );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Stream with guest token',
				),
			)
		);
		$request->set_header( 'X-WP-MCP-AI-Guest', $token );
		// Guest tokens are origin-bound (audit F-AUTHZ-04) — send the matching Origin header.
		$request->set_header( 'Origin', home_url() );

		list( $response, $captured ) = $this->dispatch_chat_and_capture( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'retry:', $captured );
		$this->assertStringContainsString( 'event: message', $captured );
		$this->assertStringContainsString( 'data: {', $captured );
		$this->assertStringContainsString( 'data: [DONE]', $captured );
	}

	/**
	 * Dispatch a chat request and capture any echoed SSE frames.
	 *
	 * The streaming path echoes frames directly (via echo) and cleans output
	 * buffers inside send_sse_headers(), so capture requires a callback buffer
	 * that survives the handler's buffer cleanup. Existing buffers are
	 * flattened first and the original level restored afterwards so PHPUnit's
	 * output-buffer tracking stays balanced.
	 *
	 * @param WP_REST_Request $request Request to dispatch.
	 * @return array{0: WP_REST_Response, 1: string} Response and captured output.
	 */
	protected function dispatch_chat_and_capture( WP_REST_Request $request ) {
		$initial_level = ob_get_level();

		// Flatten all buffers so the handler's buffer cleanup (which keeps only
		// the outermost buffer alive) cannot destroy our capture buffer.
		while ( ob_get_level() > 0 ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Deliberate: end_clean may fail on restricted hosts; level is re-checked next iteration.
			@ob_end_clean();
		}

		$captured = '';
		ob_start(
			static function ( $chunk ) use ( &$captured ) {
				$captured .= $chunk;
				return '';
			}
		);

		$response = rest_get_server()->dispatch( $request );

		while ( ob_get_level() > 0 ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Deliberate: see above.
			@ob_end_clean();
		}

		// Restore the original buffer count so PHPUnit does not flag the test
		// as risky for leaving output buffers open.
		for ( $i = 0; $i < $initial_level; $i++ ) {
			ob_start();
		}

		return array( $response, $captured );
	}

	/**
	 * Bootstraps the REST controller with a mock language model client.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $client Mock client instance.
	 */
	protected function bootstrap_rest_controller( WP_MCP_AI_Language_Model_Router $client ) {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}
}
