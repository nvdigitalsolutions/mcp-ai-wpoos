<?php
/**
 * Tests for REST chat message attachment handling.
 */
class WP_MCP_AI_REST_Message_Attachments_Test extends WP_UnitTestCase {

    /**
     * Ensure plain string messages are normalised into input_text segments.
     */
    public function test_text_message_is_normalised_to_segment() {
        $assistant_id = $this->create_assistant_post();
        $this->dispatch_chat_request(
            $assistant_id,
            array(
                array(
                    'role'    => 'user',
                    'content' => 'Hello world',
                ),
            ),
            function ( $messages ) {
                $this->assertNotEmpty( $messages );
                $first = $messages[0];

                $this->assertArrayHasKey( 'content', $first );
                $this->assertIsArray( $first['content'] );
                $this->assertSame( 'input_text', $first['content'][0]['type'] );
                $this->assertSame( 'Hello world', $first['content'][0]['text'] );

                return true;
            },
            function ( $options ) {
                $this->assertArrayNotHasKey( 'attachments', $options );

                return true;
            }
        );
    }

    /**
     * Ensure image attachments are transformed into attachment-backed segments.
     */
    public function test_image_attachment_segment_is_prepared() {
        $assistant_id = $this->create_assistant_post();
        $attachment_id = $this->create_image_attachment( 'vision.png' );

        $expected_file_id = 'wp-attachment-' . $attachment_id;

        $this->dispatch_chat_request(
            $assistant_id,
            array(
                array(
                    'role'    => 'user',
                    'content' => array(
                        array(
                            'type' => 'input_text',
                            'text' => 'Describe this image',
                        ),
                        array(
                            'type'          => 'input_image',
                            'attachment_id' => $attachment_id,
                            'detail'        => 'high',
                        ),
                    ),
                ),
            ),
            function ( $messages ) use ( $expected_file_id ) {
                $this->assertNotEmpty( $messages );
                $segments = $messages[0]['content'];

                $this->assertCount( 2, $segments );
                $this->assertSame( 'input_text', $segments[0]['type'] );
                $this->assertSame( 'Describe this image', $segments[0]['text'] );

                $image_segment = $segments[1];
                $this->assertSame( 'input_image', $image_segment['type'] );
                $this->assertSame( $expected_file_id, $image_segment['image_file']['file_id'] );
                $this->assertSame( 'high', $image_segment['detail'] );

                return true;
            },
            function ( $options ) use ( $expected_file_id ) {
                $this->assertArrayHasKey( 'attachments', $options );
                $this->assertNotEmpty( $options['attachments'] );
                $attachment = $options['attachments'][0];

                $this->assertSame( $expected_file_id, $attachment['id'] );
                $this->assertSame( 'image/png', $attachment['mime_type'] );
                $this->assertNotEmpty( $attachment['data'] );

                return true;
            }
        );
    }

    /**
     * Ensure file attachments are converted into file segments with attachment payloads.
     */
    public function test_file_attachment_segment_is_prepared() {
        $assistant_id  = $this->create_assistant_post();
        $attachment_id = $this->create_text_attachment( 'notes.txt', 'Important notes.' );
        $expected_file_id = 'wp-attachment-' . $attachment_id;

        $this->dispatch_chat_request(
            $assistant_id,
            array(
                array(
                    'role'    => 'user',
                    'content' => array(
                        array(
                            'type'          => 'input_file',
                            'attachment_id' => $attachment_id,
                        ),
                    ),
                ),
            ),
            function ( $messages ) use ( $expected_file_id ) {
                $this->assertNotEmpty( $messages );

                $segment = $messages[0]['content'][0];
                $this->assertSame( 'input_file', $segment['type'] );
                $this->assertSame( $expected_file_id, $segment['file_id'] );

                return true;
            },
            function ( $options ) use ( $expected_file_id ) {
                $this->assertArrayHasKey( 'attachments', $options );
                $this->assertNotEmpty( $options['attachments'] );

                $attachment = $options['attachments'][0];
                $this->assertSame( $expected_file_id, $attachment['id'] );
                $this->assertSame( 'text/plain', $attachment['mime_type'] );
                $this->assertSame( base64_encode( 'Important notes.' ), $attachment['data'] );

                return true;
            }
        );
    }

    /**
     * Dispatch the REST request and apply expectations against the payload.
     *
     * @param int      $assistant_id Assistant post ID.
     * @param array    $messages     Message payload.
     * @param callable $message_assertion Callback that inspects messages.
     * @param callable $options_assertion Callback that inspects options.
     */
    protected function dispatch_chat_request( $assistant_id, array $messages, callable $message_assertion, callable $options_assertion ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
            wp_set_current_user( $user_id );
        }

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
                $this->callback( $message_assertion ),
                $this->callback( $options_assertion )
            )
            ->willReturn(
                array(
                    'id'      => 'chatcmpl-test',
                    'choices' => array(),
                )
            );

        $registry                            = WP_MCP_AI_Tool_Registry::get_instance();
        $GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

        rest_get_server();
        do_action( 'rest_api_init' );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_param( 'assistant_id', $assistant_id );
        $request->set_param( 'messages', $messages );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
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
                'post_title'  => 'Test Assistant',
                'post_status' => 'publish',
            )
        );

        $this->assertNotWPError( $assistant_id );
        $this->assertNotEmpty( $assistant_id );

        return $assistant_id;
    }

    /**
     * Create an image attachment for testing.
     *
     * @param string $filename File name.
     * @return int
     */
    protected function create_image_attachment( $filename ) {
        $binary = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg=='
        );

        $upload = wp_upload_bits( $filename, null, $binary );
        $this->assertFalse( $upload['error'] );

        $attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

        wp_update_post(
            array(
                'ID'          => $attachment_id,
                'post_title'  => 'Vision Image',
                'post_status' => 'inherit',
            )
        );

        return $attachment_id;
    }

    /**
     * Create a text attachment.
     *
     * @param string $filename File name.
     * @param string $contents File contents.
     * @return int
     */
    protected function create_text_attachment( $filename, $contents ) {
        $upload = wp_upload_bits( $filename, null, $contents );
        $this->assertFalse( $upload['error'] );

        $attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

        wp_update_post(
            array(
                'ID'          => $attachment_id,
                'post_title'  => 'Notes Document',
                'post_status' => 'inherit',
            )
        );

        return $attachment_id;
    }
}
