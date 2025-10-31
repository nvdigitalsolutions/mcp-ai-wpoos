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
        $this->assertSame( 'text/event-stream', $response->get_headers()['Content-Type'] ?? '' );

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
        $this->assertSame( 'text/event-stream', $response->get_headers()['Content-Type'] ?? '' );

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
        $this->assertSame( 'text/event-stream', $response->get_headers()['Content-Type'] ?? '' );

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
