<?php
/**
 * Tests for assistant memory integration.
 */
class WP_MCP_AI_Assistant_Memory_Test extends WP_UnitTestCase {

    /**
     * Ensure chat requests include assistant memory payloads.
     */
    public function test_chat_request_includes_memory_payload() {
        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );

        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Memory Assistant',
                'post_status' => 'publish',
            )
        );

        $content = str_repeat( 'Knowledge line. ', 400 );
        $upload  = wp_upload_bits( 'knowledge.txt', null, $content );
        $this->assertFalse( $upload['error'] );

        $attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );
        wp_update_post(
            array(
                'ID'         => $attachment_id,
                'post_title' => 'Knowledge Base',
            )
        );

        update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MEMORY_FILES, array( $attachment_id ) );
        update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_VECTOR_STORE_ID, 'store_123' );

        if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
            remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
        }

        $mock_client = $this->getMockBuilder( WP_MCP_AI_OpenAI_Client::class )
            ->onlyMethods( array( 'create_chat_completion' ) )
            ->getMock();

        $mock_client
            ->expects( $this->once() )
            ->method( 'create_chat_completion' )
            ->with(
                $this->callback(
                    function ( $messages ) {
                        return is_array( $messages ) && ! empty( $messages );
                    }
                ),
                $this->callback(
                    function ( $options ) use ( $attachment_id ) {
                        $this->assertArrayHasKey( 'memory_documents', $options );
                        $this->assertArrayHasKey( 'memory_files', $options );
                        $this->assertSame( array( $attachment_id ), $options['memory_files'] );
                        $this->assertSame( 'store_123', $options['vector_store_id'] );

                        $documents = $options['memory_documents'];
                        $this->assertNotEmpty( $documents );
                        $document = $documents[0];
                        $this->assertArrayHasKey( 'chunks', $document );
                        $this->assertNotEmpty( $document['chunks'] );
                        $first_chunk = $document['chunks'][0];
                        $this->assertIsString( $first_chunk );
                        $this->assertNotEmpty( trim( $first_chunk ) );
                        $this->assertLessThanOrEqual( WP_MCP_AI_REST::MEMORY_MAX_DOCUMENT_CHARS + 50, strlen( $first_chunk ) );

                        return true;
                    }
                )
            )
            ->willReturn(
                array(
                    'id'      => 'chatcmpl-test',
                    'choices' => array(),
                )
            );

        $registry                               = WP_MCP_AI_Tool_Registry::get_instance();
        $GLOBALS['wp_mcp_ai_rest_controller']    = new WP_MCP_AI_REST( $registry, $mock_client );

        rest_get_server();
        do_action( 'rest_api_init' );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_param( 'assistant_id', $assistant_id );
        $request->set_param(
            'messages',
            array(
                array(
                    'role'    => 'user',
                    'content' => 'Hello',
                ),
            )
        );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
    }
}
