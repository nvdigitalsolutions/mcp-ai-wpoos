<?php
/**
 * Tests ensuring tool messages are paired with matching tool calls before dispatch.
 */
class WP_MCP_AI_REST_Tool_Message_Sanitization_Test extends WP_UnitTestCase {

    /**
     * Tool messages without a matching call should be discarded from the payload.
     */
    public function test_chat_request_ignores_tool_messages_without_matching_call() {
        $assistant_id = $this->create_assistant_post();

        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );

        $mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
            ->disableOriginalConstructor()
            ->onlyMethods( array( 'create_chat_completion' ) )
            ->getMock();

        $mock_client
            ->expects( $this->once() )
            ->method( 'create_chat_completion' )
            ->with(
                $this->callback(
                    function ( $messages ) {
                        $this->assertIsArray( $messages );
                        $this->assertCount( 4, $messages );

                        $this->assertSame( 'user', $messages[0]['role'] );
                        $this->assertSame( 'assistant', $messages[1]['role'] );
                        $this->assertArrayHasKey( 'tool_calls', $messages[1] );
                        $this->assertCount( 1, $messages[1]['tool_calls'] );
                        $this->assertSame( 'call_abc', $messages[1]['tool_calls'][0]['id'] );

                        $this->assertSame( 'tool', $messages[2]['role'] );
                        $this->assertArrayHasKey( 'tool_call_id', $messages[2] );
                        $this->assertSame( 'call_abc', $messages[2]['tool_call_id'] );

                        $this->assertSame( 'user', $messages[3]['role'] );

                        return true;
                    }
                ),
                $this->isType( 'array' )
            )
            ->willReturn(
                array(
                    'id'      => 'chatcmpl-test',
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
                    'content' => 'Please create an image.',
                ),
                array(
                    'role'       => 'assistant',
                    'content'    => 'Sure, calling the image tool now.',
                    'tool_calls' => array(
                        array(
                            'id'       => 'call_abc',
                            'type'     => 'function',
                            'function' => array(
                                'name'      => 'create_image',
                                'arguments' => json_encode( array( 'prompt' => 'An apple' ) ),
                            ),
                        ),
                    ),
                ),
                array(
                    'role'    => 'tool',
                    'content' => 'Result without an identifier.',
                ),
                array(
                    'role'         => 'tool',
                    'content'      => 'Result with the wrong identifier.',
                    'tool_call_id' => 'call_xyz',
                ),
                array(
                    'role'         => 'tool',
                    'content'      => 'Image created successfully.',
                    'tool_call_id' => 'call_abc',
                ),
                array(
                    'role'    => 'user',
                    'content' => 'Thanks! Can you describe it?',
                ),
            )
        );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
    }

    /**
     * Prepare the REST controller instance for testing.
     *
     * @param WP_MCP_AI_Language_Model_Router $mock_client Mocked language model router.
     */
    protected function bootstrap_rest_controller( WP_MCP_AI_Language_Model_Router $mock_client ) {
        if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
            remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
        }

        $registry                            = WP_MCP_AI_Tool_Registry::get_instance();
        $GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

        rest_get_server();
        do_action( 'rest_api_init' );
    }

    /**
     * Create a published assistant post for testing.
     *
     * @return int
     */
    protected function create_assistant_post() {
        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Tool Sanitization Assistant',
                'post_status' => 'publish',
            )
        );

        $this->assertNotWPError( $assistant_id );
        $this->assertNotEmpty( $assistant_id );

        return $assistant_id;
    }
}
