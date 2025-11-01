<?php
/**
 * Tests for streaming chat responses as Server-Sent Events.
 */
class WP_MCP_AI_REST_Chat_Event_Stream_Test extends WP_UnitTestCase {
    /**
     * Ensure chat responses are streamed when the Accept header requests an event stream.
     */
    public function test_chat_request_streams_response_when_accept_header_requests_event_stream() {
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

        $existing_keys = array();
        if ( isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook ) {
            $existing_keys = array_keys( $GLOBALS['wp_filter']['rest_pre_serve_request']->callbacks[10] ?? array() );
        }

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

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $headers = $response->get_headers();

        $this->assertSame( 'text/event-stream; charset=UTF-8', $headers['Content-Type'] ?? '' );
        $this->assertSame( '*', $headers['Access-Control-Allow-Origin'] ?? '' );
        $this->assertSame( 'Accept, Authorization', $headers['Vary'] ?? '' );

        $hook = isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook
            ? $GLOBALS['wp_filter']['rest_pre_serve_request']
            : null;

        $this->assertInstanceOf( WP_Hook::class, $hook );

        $current_keys = array_keys( $hook->callbacks[10] ?? array() );
        $added_keys   = array_diff( $current_keys, $existing_keys );

        $this->assertNotEmpty( $added_keys );

        $closure_key = array_pop( $added_keys );
        $closure     = $hook->callbacks[10][ $closure_key ]['function'];

        ob_start();
        $served = call_user_func( $closure, false, $response, $request, rest_get_server() );
        $output = ob_get_clean();

        $this->assertTrue( $served );
        $this->assertStringContainsString( 'event: message', $output );
        $this->assertStringContainsString( 'data: {', $output );
        $this->assertStringContainsString( 'event: close', $output );
        $this->assertStringContainsString( '[DONE]', $output );

        if ( isset( $hook->callbacks[10][ $closure_key ] ) ) {
            unset( $hook->callbacks[10][ $closure_key ] );
        }

        wp_set_current_user( 0 );
    }

    /**
     * Ensure Accept headers with additional values still trigger streaming responses.
     */
    public function test_chat_request_streams_response_with_mixed_accept_header_values() {
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

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $headers = $response->get_headers();

        $this->assertSame( 'text/event-stream; charset=UTF-8', $headers['Content-Type'] ?? '' );
        $this->assertSame( '*', $headers['Access-Control-Allow-Origin'] ?? '' );
        $this->assertSame( 'Accept, Authorization', $headers['Vary'] ?? '' );

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

        $existing_keys = array();
        if ( isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook ) {
            $existing_keys = array_keys( $GLOBALS['wp_filter']['rest_pre_serve_request']->callbacks[10] ?? array() );
        }

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

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $headers = $response->get_headers();

        $this->assertSame( 'text/event-stream; charset=UTF-8', $headers['Content-Type'] ?? '' );
        $this->assertSame( '*', $headers['Access-Control-Allow-Origin'] ?? '' );
        $this->assertSame( 'Accept, Authorization', $headers['Vary'] ?? '' );

        $hook = isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook
            ? $GLOBALS['wp_filter']['rest_pre_serve_request']
            : null;

        $this->assertInstanceOf( WP_Hook::class, $hook );

        $current_keys = array_keys( $hook->callbacks[10] ?? array() );
        $added_keys   = array_diff( $current_keys, $existing_keys );

        $this->assertNotEmpty( $added_keys );

        $closure_key = array_pop( $added_keys );
        $closure     = $hook->callbacks[10][ $closure_key ]['function'];

        ob_start();
        $served = call_user_func( $closure, false, $response, $request, rest_get_server() );
        $output = ob_get_clean();

        $this->assertTrue( $served );
        $this->assertStringContainsString( 'event: message', $output );
        $this->assertStringContainsString( 'event: close', $output );

        if ( isset( $hook->callbacks[10][ $closure_key ] ) ) {
            unset( $hook->callbacks[10][ $closure_key ] );
        }

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

        $existing_keys = array();
        if ( isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook ) {
            $existing_keys = array_keys( $GLOBALS['wp_filter']['rest_pre_serve_request']->callbacks[10] ?? array() );
        }

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

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $headers = $response->get_headers();

        $this->assertSame( 'text/event-stream; charset=UTF-8', $headers['Content-Type'] ?? '' );
        $this->assertSame( '*', $headers['Access-Control-Allow-Origin'] ?? '' );
        $this->assertSame( 'Accept, Authorization', $headers['Vary'] ?? '' );

        $hook = isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook
            ? $GLOBALS['wp_filter']['rest_pre_serve_request']
            : null;

        $this->assertInstanceOf( WP_Hook::class, $hook );

        $current_keys = array_keys( $hook->callbacks[10] ?? array() );
        $added_keys   = array_diff( $current_keys, $existing_keys );

        $this->assertNotEmpty( $added_keys );

        $closure_key = array_pop( $added_keys );
        $closure     = $hook->callbacks[10][ $closure_key ]['function'];

        ob_start();
        $served = call_user_func( $closure, false, $response, $request, rest_get_server() );
        $output = ob_get_clean();

        $this->assertTrue( $served );
        $this->assertStringContainsString( 'event: message', $output );
        $this->assertStringContainsString( 'event: close', $output );

        if ( isset( $hook->callbacks[10][ $closure_key ] ) ) {
            unset( $hook->callbacks[10][ $closure_key ] );
        }

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

        $existing_keys = array();
        if ( isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook ) {
            $existing_keys = array_keys( $GLOBALS['wp_filter']['rest_pre_serve_request']->callbacks[10] ?? array() );
        }

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

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'text/event-stream; charset=UTF-8', $response->get_headers()['Content-Type'] ?? '' );

        $hook = isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook
            ? $GLOBALS['wp_filter']['rest_pre_serve_request']
            : null;

        $this->assertInstanceOf( WP_Hook::class, $hook );

        $current_keys = array_keys( $hook->callbacks[10] ?? array() );
        $added_keys   = array_diff( $current_keys, $existing_keys );

        $this->assertNotEmpty( $added_keys );

        $closure_key = array_pop( $added_keys );
        $closure     = $hook->callbacks[10][ $closure_key ]['function'];

        ob_start();
        $served = call_user_func( $closure, false, $response, $request, rest_get_server() );
        $output = ob_get_clean();

        $this->assertTrue( $served );
        $this->assertStringContainsString( 'event: message', $output );
        $this->assertStringContainsString( 'event: close', $output );

        if ( isset( $hook->callbacks[10][ $closure_key ] ) ) {
            unset( $hook->callbacks[10][ $closure_key ] );
        }

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

        $existing_keys = array();
        if ( isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook ) {
            $existing_keys = array_keys( $GLOBALS['wp_filter']['rest_pre_serve_request']->callbacks[10] ?? array() );
        }

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

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertNotSame( 'text/event-stream; charset=UTF-8', $response->get_headers()['Content-Type'] ?? '' );

        $hook = isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook
            ? $GLOBALS['wp_filter']['rest_pre_serve_request']
            : null;

        if ( $hook instanceof WP_Hook ) {
            $current_keys = array_keys( $hook->callbacks[10] ?? array() );
            $added_keys   = array_diff( $current_keys, $existing_keys );
            $this->assertEmpty( $added_keys, 'Streaming callback should not be registered when stream disabled.' );
        }

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

        $existing_keys = array();
        if ( isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook ) {
            $existing_keys = array_keys( $GLOBALS['wp_filter']['rest_pre_serve_request']->callbacks[10] ?? array() );
        }

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

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertNotSame( 'text/event-stream; charset=UTF-8', $response->get_headers()['Content-Type'] ?? '' );

        $hook = isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook
            ? $GLOBALS['wp_filter']['rest_pre_serve_request']
            : null;

        if ( $hook instanceof WP_Hook ) {
            $current_keys = array_keys( $hook->callbacks[10] ?? array() );
            $added_keys   = array_diff( $current_keys, $existing_keys );
            $this->assertEmpty( $added_keys, 'Streaming callback should not be registered when stream explicitly disabled.' );
        }

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

        $existing_keys = array();
        if ( isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook ) {
            $existing_keys = array_keys( $GLOBALS['wp_filter']['rest_pre_serve_request']->callbacks[10] ?? array() );
        }

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_param( 'assistant_id', $assistant_id );
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
        $request->set_header( 'Accept', 'text/event-stream' );

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'text/event-stream; charset=UTF-8', $response->get_headers()['Content-Type'] ?? '' );

        $hook = isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook
            ? $GLOBALS['wp_filter']['rest_pre_serve_request']
            : null;

        $this->assertInstanceOf( WP_Hook::class, $hook );

        $current_keys = array_keys( $hook->callbacks[10] ?? array() );
        $added_keys   = array_diff( $current_keys, $existing_keys );

        $this->assertNotEmpty( $added_keys );

        $closure_key = array_pop( $added_keys );
        $closure     = $hook->callbacks[10][ $closure_key ]['function'];

        ob_start();
        $served = call_user_func( $closure, false, $response, $request, rest_get_server() );
        $output = ob_get_clean();

        $this->assertTrue( $served );
        $this->assertStringContainsString( 'event: message', $output );
        $this->assertStringContainsString( 'event: close', $output );

        if ( isset( $hook->callbacks[10][ $closure_key ] ) ) {
            unset( $hook->callbacks[10][ $closure_key ] );
        }
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

        $existing_keys = array();
        if ( isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook ) {
            $existing_keys = array_keys( $GLOBALS['wp_filter']['rest_pre_serve_request']->callbacks[10] ?? array() );
        }

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_param( 'assistant_id', $assistant_id );
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
        $request->set_header( 'Accept', 'text/event-stream' );

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'text/event-stream; charset=UTF-8', $response->get_headers()['Content-Type'] ?? '' );

        $hook = isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook
            ? $GLOBALS['wp_filter']['rest_pre_serve_request']
            : null;

        $this->assertInstanceOf( WP_Hook::class, $hook );

        $current_keys = array_keys( $hook->callbacks[10] ?? array() );
        $added_keys   = array_diff( $current_keys, $existing_keys );

        $this->assertNotEmpty( $added_keys );

        $closure_key = array_pop( $added_keys );
        $closure     = $hook->callbacks[10][ $closure_key ]['function'];

        ob_start();
        $served = call_user_func( $closure, false, $response, $request, rest_get_server() );
        $output = ob_get_clean();

        $this->assertTrue( $served );
        $this->assertStringContainsString( 'event: message', $output );
        $this->assertStringContainsString( 'event: close', $output );

        if ( isset( $hook->callbacks[10][ $closure_key ] ) ) {
            unset( $hook->callbacks[10][ $closure_key ] );
        }
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

        $registry = WP_MCP_AI_Tool_Registry::get_instance();
        $GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $client );

        rest_get_server();
        do_action( 'rest_api_init' );
    }
}
