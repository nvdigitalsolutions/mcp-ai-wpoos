<?php
/**
 * Helper client that forces the Chat Completions endpoint during detection.
 */
class WP_MCP_AI_Force_Chat_Client extends WP_MCP_AI_OpenAI_Client {

    /** @inheritDoc */
    protected function should_use_responses_api( array $messages, array $options ) {
        return false;
    }
}

/**
 * Tests for the OpenAI client wrapper.
 */
class WP_MCP_AI_OpenAI_Client_Test extends WP_UnitTestCase {

    /**
     * Ensure missing API key errors include actionable guidance.
     */
    public function test_create_chat_completion_requires_api_key() {
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

        $client   = new WP_MCP_AI_OpenAI_Client();
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

        $this->assertWPError( $response );
        $this->assertSame( 'wp_mcp_ai_missing_api_key', $response->get_error_code() );

        $data = $response->get_error_data();
        $this->assertIsArray( $data );
        $this->assertSame( 400, $data['status'] );
        $this->assertArrayHasKey( 'actions', $data );
        $this->assertArrayHasKey( 'configure_openai_api_key', $data['actions'] );
    }

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

        $this->assertArrayHasKey( 'headers', $captured_request );
        $this->assertArrayNotHasKey( 'OpenAI-Beta', $captured_request['headers'] );

        $payload = json_decode( $captured_request['body'], true );

        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'model', $payload );
        $this->assertSame( 'gpt-unit-test', $payload['model'] );
        $this->assertArrayHasKey( 'messages', $payload );
        $this->assertSame( 'Hello', $payload['messages'][0]['content'] );
        $this->assertSame( $defaults['request_timeout'], $captured_request['timeout'] );
    }

    /**
     * Ensure multimodal messages retain their structured content segments.
     */
    public function test_create_chat_completion_preserves_multimodal_segments() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
        $defaults['openai_api_key'] = 'sk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

        $client           = new WP_MCP_AI_OpenAI_Client();
        $captured_request = null;

        $filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
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
                        'text' => 'Describe this image',
                    ),
                    array(
                        'type'      => 'input_image',
                        'image_url' => array( 'url' => 'https://example.com/image.png' ),
                    ),
                ),
            ),
        );

        $response = $client->create_chat_completion( $messages, array() );

        remove_filter( 'pre_http_request', $filter_callback, 10 );

        $this->assertIsArray( $response );
        $this->assertNotNull( $captured_request );

        $payload = json_decode( $captured_request['body'], true );

        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'messages', $payload );
        $this->assertIsArray( $payload['messages'][0]['content'] );
        $this->assertSame( 'text', $payload['messages'][0]['content'][0]['type'] );
        $this->assertSame( 'Describe this image', $payload['messages'][0]['content'][0]['text'] );
        $this->assertSame( 'input_image', $payload['messages'][0]['content'][1]['type'] );
    }

    /**
     * Ensure chat completion payloads include the tool name alongside the function definition.
     */
    public function test_chat_completion_payload_includes_tool_name() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
        $defaults['openai_api_key'] = 'sk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

        $client           = new WP_MCP_AI_Force_Chat_Client();
        $captured_request = null;

        $filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
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

        $tool_definition = array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'fetch_latest_posts',
                'description' => 'Fetches the latest posts.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(),
                ),
            ),
        );

        $response = $client->create_chat_completion(
            $messages,
            array(
                'tools' => array( $tool_definition ),
            )
        );

        remove_filter( 'pre_http_request', $filter_callback, 10 );

        $this->assertIsArray( $response );
        $this->assertArrayHasKey( 'choices', $response );
        $this->assertNotNull( $captured_request );

        $payload = json_decode( $captured_request['body'], true );

        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'tools', $payload );
        $this->assertIsArray( $payload['tools'] );
        $this->assertArrayHasKey( 0, $payload['tools'] );
        $this->assertArrayHasKey( 'name', $payload['tools'][0] );
        $this->assertSame( 'fetch_latest_posts', $payload['tools'][0]['name'] );
        $this->assertArrayHasKey( 'function', $payload['tools'][0] );
        $this->assertSame( 'fetch_latest_posts', $payload['tools'][0]['function']['name'] );
    }

    /**
     * Ensure requests containing attachments are routed through the Responses API.
     */
    public function test_create_chat_completion_with_attachments_uses_responses_api() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
        $defaults['openai_api_key'] = 'sk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

        $client          = new WP_MCP_AI_OpenAI_Client();
        $captured_request = array();

        $filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
            $captured_request = array(
                'url'  => $url,
                'args' => $args,
            );

            return array(
                'headers'  => array(),
                'body'     => wp_json_encode(
                    array(
                        'id'     => 'resp-test',
                        'output' => array(
                            array(
                                'role'    => 'assistant',
                                'content' => array(
                                    array(
                                        'type' => 'output_text',
                                        'text' => 'Hello from Responses API.',
                                    ),
                                ),
                            ),
                        ),
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
                        'type'      => 'input_file',
                        'file_id'   => 'file-123',
                    ),
                ),
            ),
        );

        $options = array(
            'attachments' => array(
                array(
                    'id'        => 'file-123',
                    'filename'  => 'notes.txt',
                    'mime_type' => 'text/plain',
                    'data'      => base64_encode( 'Example content' ),
                    'bytes'     => strlen( 'Example content' ),
                ),
            ),
        );

        $response = $client->create_chat_completion( $messages, $options );

        remove_filter( 'pre_http_request', $filter_callback, 10 );

        $this->assertNotEmpty( $captured_request );
        $this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );

        $this->assertArrayHasKey( 'headers', $captured_request['args'] );
        $this->assertArrayHasKey( 'OpenAI-Beta', $captured_request['args']['headers'] );
        $this->assertSame( 'responses=v1', $captured_request['args']['headers']['OpenAI-Beta'] );

        $payload = json_decode( $captured_request['args']['body'], true );

        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'input', $payload );
        $this->assertArrayNotHasKey( 'messages', $payload );
        $this->assertArrayNotHasKey( 'attachments', $payload );

        $this->assertArrayHasKey( 0, $payload['input'] );
        $content = $payload['input'][0]['content'];

        $this->assertIsArray( $content );
        $this->assertArrayHasKey( 0, $content );
        $file_segment = $content[0];

        $this->assertSame( 'input_file', $file_segment['type'] );
        $this->assertArrayHasKey( 'file_data', $file_segment );
        $this->assertIsString( $file_segment['file_data'] );
        $this->assertSame(
            'data:text/plain;base64,' . base64_encode( 'Example content' ),
            $file_segment['file_data']
        );
        $this->assertArrayHasKey( 'filename', $file_segment );
        $this->assertSame( 'notes.txt', $file_segment['filename'] );
        $this->assertArrayNotHasKey( 'file_id', $file_segment );

        $this->assertIsArray( $response );
        $this->assertArrayHasKey( 'choices', $response );
        $this->assertSame( 'Hello from Responses API.', $response['choices'][0]['message']['content'] );
    }

    /**
     * Ensure Responses API choices without message payloads are normalised for the chat UI.
     */
    public function test_responses_choices_are_transformed_into_chat_completion_shape() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
        $defaults['openai_api_key'] = 'sk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

        $client           = new WP_MCP_AI_OpenAI_Client();
        $captured_request = array();

        $filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
            $captured_request = array(
                'url'  => $url,
                'args' => $args,
            );

            return array(
                'headers'  => array(),
                'body'     => wp_json_encode(
                    array(
                        'id'      => 'resp-choices',
                        'choices' => array(
                            array(
                                'id'            => 'choice-1',
                                'type'          => 'message',
                                'role'          => 'assistant',
                                'content'       => array(
                                    array(
                                        'type' => 'output_text',
                                        'text' => array(
                                            'value'        => 'Processed PDF summary.',
                                            'annotations'  => array(),
                                        ),
                                    ),
                                ),
                                'finish_reason' => 'stop',
                            ),
                        ),
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
                        'type'    => 'input_file',
                        'file_id' => 'file-789',
                    ),
                ),
            ),
        );

        $options = array(
            'attachments' => array(
                array(
                    'id'        => 'file-789',
                    'filename'  => 'report.pdf',
                    'mime_type' => 'application/pdf',
                    'data'      => base64_encode( 'PDF data' ),
                    'bytes'     => strlen( 'PDF data' ),
                ),
            ),
        );

        $response = $client->create_chat_completion( $messages, $options );

        remove_filter( 'pre_http_request', $filter_callback, 10 );

        $this->assertNotEmpty( $captured_request );
        $this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );
        $this->assertArrayHasKey( 'headers', $captured_request['args'] );
        $this->assertArrayHasKey( 'OpenAI-Beta', $captured_request['args']['headers'] );
        $this->assertSame( 'responses=v1', $captured_request['args']['headers']['OpenAI-Beta'] );

        $this->assertIsArray( $response );
        $this->assertArrayHasKey( 'choices', $response );
        $this->assertArrayHasKey( 0, $response['choices'] );
        $this->assertArrayHasKey( 'message', $response['choices'][0] );
        $this->assertSame( 'Processed PDF summary.', $response['choices'][0]['message']['content'] );
        $this->assertSame( 'assistant', $response['choices'][0]['message']['role'] );
    }

    /**
     * Ensure Responses API output items using the text field are normalised for chat rendering.
     */
    public function test_responses_output_text_items_are_normalised() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
        $defaults['openai_api_key'] = 'sk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

        $client           = new WP_MCP_AI_OpenAI_Client();
        $captured_request = array();

        $filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
            $captured_request = array(
                'url'  => $url,
                'args' => $args,
            );

            return array(
                'headers'  => array(),
                'body'     => wp_json_encode(
                    array(
                        'id'     => 'resp-output-text',
                        'output' => array(
                            array(
                                'id'            => 'output-1',
                                'type'          => 'output_text',
                                'text'          => array(
                                    'value'       => 'Summary generated from attachment.',
                                    'annotations' => array(),
                                ),
                                'finish_reason' => 'stop',
                            ),
                        ),
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
                        'type'    => 'input_file',
                        'file_id' => 'file-456',
                    ),
                ),
            ),
        );

        $options = array(
            'attachments' => array(
                array(
                    'id'        => 'file-456',
                    'filename'  => 'handout.pdf',
                    'mime_type' => 'application/pdf',
                    'data'      => base64_encode( 'Attachment data' ),
                    'bytes'     => strlen( 'Attachment data' ),
                ),
            ),
        );

        $response = $client->create_chat_completion( $messages, $options );

        remove_filter( 'pre_http_request', $filter_callback, 10 );

        $this->assertNotEmpty( $captured_request );
        $this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );

        $this->assertIsArray( $response );
        $this->assertArrayHasKey( 'choices', $response );
        $this->assertArrayHasKey( 0, $response['choices'] );
        $this->assertArrayHasKey( 'message', $response['choices'][0] );
        $this->assertSame( 'Summary generated from attachment.', $response['choices'][0]['message']['content'] );
        $this->assertSame( 'assistant', $response['choices'][0]['message']['role'] );
    }

    /**
     * Ensure Responses API payloads include the tool name alongside the function definition.
     */
    public function test_responses_payload_includes_tool_name() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
        $defaults['openai_api_key'] = 'sk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

        $client           = new WP_MCP_AI_OpenAI_Client();
        $captured_request = array();

        $filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
            $captured_request = array(
                'url'  => $url,
                'args' => $args,
            );

            return array(
                'headers'  => array(),
                'body'     => wp_json_encode(
                    array(
                        'id'     => 'resp-test',
                        'output' => array(),
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
                        'type'    => 'input_file',
                        'file_id' => 'file-123',
                    ),
                ),
            ),
        );

        $tool_definition = array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'fetch_latest_posts',
                'description' => 'Fetches the latest posts.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(),
                ),
            ),
        );

        $options = array(
            'attachments' => array(
                array(
                    'id'        => 'file-123',
                    'filename'  => 'notes.txt',
                    'mime_type' => 'text/plain',
                    'data'      => base64_encode( 'Example content' ),
                    'bytes'     => strlen( 'Example content' ),
                ),
            ),
            'tools' => array( $tool_definition ),
        );

        $response = $client->create_chat_completion( $messages, $options );

        remove_filter( 'pre_http_request', $filter_callback, 10 );

        $this->assertIsArray( $response );
        $this->assertArrayHasKey( 'output', $response );
        $this->assertNotEmpty( $captured_request );
        $this->assertArrayHasKey( 'args', $captured_request );

        $payload = json_decode( $captured_request['args']['body'], true );

        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'tools', $payload );
        $this->assertIsArray( $payload['tools'] );
        $this->assertArrayHasKey( 0, $payload['tools'] );
        $this->assertArrayHasKey( 'name', $payload['tools'][0] );
        $this->assertSame( 'fetch_latest_posts', $payload['tools'][0]['name'] );
        $this->assertArrayHasKey( 'function', $payload['tools'][0] );
        $this->assertSame( 'fetch_latest_posts', $payload['tools'][0]['function']['name'] );
    }

    /**
     * Ensure attachments still route through the Responses API if detection is bypassed.
     */
    public function test_attachments_force_responses_api_when_detection_is_bypassed() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
        $defaults['openai_api_key'] = 'sk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

        $client           = new WP_MCP_AI_Force_Chat_Client();
        $captured_request = array();

        $filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
            $captured_request = array(
                'url'  => $url,
                'args' => $args,
            );

            return array(
                'headers'  => array(),
                'body'     => wp_json_encode(
                    array(
                        'id'     => 'resp-test',
                        'output' => array(),
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
                        'type'    => 'input_file',
                        'file_id' => 'file-123',
                    ),
                ),
            ),
        );

        $options = array(
            'attachments' => array(
                array(
                    'id'        => 'file-123',
                    'filename'  => 'notes.txt',
                    'mime_type' => 'text/plain',
                    'data'      => base64_encode( 'Example content' ),
                    'bytes'     => strlen( 'Example content' ),
                ),
            ),
        );

        $response = $client->create_chat_completion( $messages, $options );

        remove_filter( 'pre_http_request', $filter_callback, 10 );

        $this->assertNotEmpty( $captured_request );
        $this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );

        $this->assertArrayHasKey( 'headers', $captured_request['args'] );
        $this->assertArrayHasKey( 'OpenAI-Beta', $captured_request['args']['headers'] );
        $this->assertSame( 'responses=v1', $captured_request['args']['headers']['OpenAI-Beta'] );

        $payload = json_decode( $captured_request['args']['body'], true );

        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'input', $payload );
        $this->assertArrayNotHasKey( 'attachments', $payload );

        $this->assertArrayHasKey( 0, $payload['input'] );
        $content = $payload['input'][0]['content'];

        $this->assertIsArray( $content );
        $this->assertArrayHasKey( 0, $content );
        $file_segment = $content[0];

        $this->assertSame( 'input_file', $file_segment['type'] );
        $this->assertArrayHasKey( 'file_data', $file_segment );
        $this->assertIsString( $file_segment['file_data'] );
        $this->assertSame(
            'data:text/plain;base64,' . base64_encode( 'Example content' ),
            $file_segment['file_data']
        );
        $this->assertArrayHasKey( 'filename', $file_segment );
        $this->assertSame( 'notes.txt', $file_segment['filename'] );
        $this->assertArrayNotHasKey( 'file_id', $file_segment );

        $this->assertIsArray( $response );
        $this->assertArrayHasKey( 'output', $response );
    }

    /**
     * Ensure text segments are converted to input_text when using the Responses API.
     */
    public function test_responses_payload_normalises_text_segments() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
        $defaults['openai_api_key'] = 'sk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

        $client           = new WP_MCP_AI_OpenAI_Client();
        $captured_request = array();

        $filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
            $captured_request = array(
                'url'  => $url,
                'args' => $args,
            );

            return array(
                'headers'  => array(),
                'body'     => wp_json_encode(
                    array(
                        'id'     => 'resp-test',
                        'output' => array(),
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
                        'text' => 'Please review the attached notes.',
                    ),
                    array(
                        'type'    => 'input_file',
                        'file_id' => 'file-123',
                    ),
                ),
            ),
        );

        $options = array(
            'attachments' => array(
                array(
                    'id'        => 'file-123',
                    'filename'  => 'notes.txt',
                    'mime_type' => 'text/plain',
                    'data'      => base64_encode( 'Notes content' ),
                    'bytes'     => strlen( 'Notes content' ),
                ),
            ),
        );

        $client->create_chat_completion( $messages, $options );

        remove_filter( 'pre_http_request', $filter_callback, 10 );

        $this->assertNotEmpty( $captured_request );
        $this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );

        $payload = json_decode( $captured_request['args']['body'], true );

        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'input', $payload );
        $this->assertArrayHasKey( 0, $payload['input'] );
        $this->assertArrayHasKey( 'content', $payload['input'][0] );
        $this->assertSame( 'input_text', $payload['input'][0]['content'][0]['type'] );
        $this->assertSame( 'Please review the attached notes.', $payload['input'][0]['content'][0]['text'] );
        $this->assertSame( 'input_file', $payload['input'][0]['content'][1]['type'] );
        $this->assertArrayHasKey( 'file_data', $payload['input'][0]['content'][1] );
        $this->assertIsString( $payload['input'][0]['content'][1]['file_data'] );
        $this->assertSame(
            'data:text/plain;base64,' . base64_encode( 'Notes content' ),
            $payload['input'][0]['content'][1]['file_data']
        );
    }

    /**
     * Ensure prior assistant messages use the correct mode when calling the Responses API.
     */
    public function test_responses_payload_uses_output_text_for_assistant_segments() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
        $defaults['openai_api_key'] = 'sk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

        $client           = new WP_MCP_AI_OpenAI_Client();
        $captured_request = array();

        $filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
            $captured_request = array(
                'url'  => $url,
                'args' => $args,
            );

            return array(
                'headers'  => array(),
                'body'     => wp_json_encode(
                    array(
                        'id'     => 'resp-test',
                        'output' => array(),
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
                'role'    => 'assistant',
                'content' => 'Earlier summary.',
            ),
            array(
                'role'    => 'user',
                'content' => array(
                    array(
                        'type' => 'text',
                        'text' => 'Please summarise the PDF.',
                    ),
                    array(
                        'type'    => 'input_file',
                        'file_id' => 'file-123',
                    ),
                ),
            ),
        );

        $options = array(
            'attachments' => array(
                array(
                    'id'        => 'file-123',
                    'filename'  => 'notes.pdf',
                    'mime_type' => 'application/pdf',
                    'data'      => base64_encode( 'PDF contents' ),
                    'bytes'     => strlen( 'PDF contents' ),
                ),
            ),
        );

        $client->create_chat_completion( $messages, $options );

        remove_filter( 'pre_http_request', $filter_callback, 10 );

        $this->assertNotEmpty( $captured_request );
        $this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );

        $payload = json_decode( $captured_request['args']['body'], true );

        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'input', $payload );
        $this->assertSame( 'output_text', $payload['input'][0]['content'][0]['type'] );
        $this->assertSame( 'Earlier summary.', $payload['input'][0]['content'][0]['text'] );
        $this->assertSame( 'input_text', $payload['input'][1]['content'][0]['type'] );
        $this->assertSame( 'Please summarise the PDF.', $payload['input'][1]['content'][0]['text'] );
    }

    /**
     * Ensure tool role messages are emitted as output_text segments for Responses API requests.
     */
    public function test_responses_payload_uses_output_text_for_tool_segments() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
        $defaults['openai_api_key'] = 'sk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

        $client           = new WP_MCP_AI_OpenAI_Client();
        $captured_request = array();

        $filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
            $captured_request = array(
                'url'  => $url,
                'args' => $args,
            );

            return array(
                'headers'  => array(),
                'body'     => wp_json_encode(
                    array(
                        'id'     => 'resp-test',
                        'output' => array(),
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
                'role'    => 'assistant',
                'content' => 'Inspecting your PDF…',
            ),
            array(
                'role'        => 'tool',
                'tool_call_id' => 'call-123',
                'content'     => 'Processed attachment contents.',
            ),
            array(
                'role'    => 'user',
                'content' => array(
                    array(
                        'type' => 'text',
                        'text' => 'Summarise the findings.',
                    ),
                    array(
                        'type'    => 'input_file',
                        'file_id' => 'file-789',
                    ),
                ),
            ),
        );

        $options = array(
            'attachments' => array(
                array(
                    'id'        => 'file-789',
                    'filename'  => 'document.pdf',
                    'mime_type' => 'application/pdf',
                    'data'      => base64_encode( 'PDF content' ),
                    'bytes'     => strlen( 'PDF content' ),
                ),
            ),
        );

        $client->create_chat_completion( $messages, $options );

        remove_filter( 'pre_http_request', $filter_callback, 10 );

        $this->assertNotEmpty( $captured_request );
        $this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );

        $payload = json_decode( $captured_request['args']['body'], true );

        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'input', $payload );

        $this->assertSame( 'output_text', $payload['input'][0]['content'][0]['type'] );
        $this->assertSame( 'Inspecting your PDF…', $payload['input'][0]['content'][0]['text'] );

        $this->assertSame( 'output_text', $payload['input'][1]['content'][0]['type'] );
        $this->assertSame( 'Processed attachment contents.', $payload['input'][1]['content'][0]['text'] );

        $this->assertSame( 'input_text', $payload['input'][2]['content'][0]['type'] );
        $this->assertSame( 'Summarise the findings.', $payload['input'][2]['content'][0]['text'] );
    }
}
