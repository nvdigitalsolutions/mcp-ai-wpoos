<?php
/**
 * Tests for the OpenAI client wrapper.
 */
class WP_MCP_AI_OpenAI_Client_Test extends WP_UnitTestCase {

    /**
     * Ensure the client falls back to the global default model when none is provided.
     */
    public function test_create_chat_completion_uses_global_default_model() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
        $defaults['openai_api_key'] = 'sk-test';
        $defaults['default_model']  = 'gpt-unit-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

        $client            = new WP_MCP_AI_OpenAI_Client();
        $captured_request  = null;
        $filter_callback   = function ( $preempt, $args, $url ) use ( &$captured_request ) {
            $captured_request = $args;

            return array(
                'headers'  => array(),
                'body'     => wp_json_encode(
                    array(
                        'id'      => 'chatcmpl-test',
                        'choices' => array(),
                    )
                ),
                'response' => array(
                    'code'    => 200,
                    'message' => 'OK',
                ),
            );
        };

        add_filter( 'pre_http_request', $filter_callback, 10, 3 );

        $messages = array(
            array(
                'role'    => 'user',
                'content' => array(
                    array(
                        'type' => 'text',
                        'text' => 'Hello',
                    ),
                ),
            ),
        );

        $response = $client->create_chat_completion( $messages, array() );

        remove_filter( 'pre_http_request', $filter_callback, 10 );

        $this->assertIsArray( $response );
        $this->assertArrayHasKey( 'choices', $response );
        $this->assertNotNull( $captured_request );
        $this->assertArrayHasKey( 'body', $captured_request );

        $payload = json_decode( $captured_request['body'], true );

        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'model', $payload );
        $this->assertSame( 'gpt-unit-test', $payload['model'] );
        $this->assertArrayHasKey( 'messages', $payload );
        $this->assertSame( $defaults['request_timeout'], $captured_request['timeout'] );
    }
}
