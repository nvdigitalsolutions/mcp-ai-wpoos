<?php
/**
 * Tests for the Gemini client wrapper.
 */
class WP_MCP_AI_Gemini_Client_Test extends WP_UnitTestCase {

    /**
     * Ensure an error is returned when the Gemini API key is missing.
     */
    public function test_create_chat_completion_requires_api_key() {
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

        $client   = new WP_MCP_AI_Gemini_Client();
        $response = $client->create_chat_completion( array(), array() );

        $this->assertWPError( $response );
        $this->assertSame( 'wp_mcp_ai_missing_gemini_api_key', $response->get_error_code() );

        $data = $response->get_error_data();
        $this->assertIsArray( $data );
        $this->assertSame( 400, $data['status'] );
        $this->assertArrayHasKey( 'actions', $data );
        $this->assertArrayHasKey( 'configure_gemini_api_key', $data['actions'] );
    }

    /**
     * Ensure the Gemini client uses the configured default model when none is provided.
     */
    public function test_create_chat_completion_uses_default_model() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
        $defaults['gemini_api_key']       = 'gsk-test';
        $defaults['default_gemini_model'] = 'gemini-test-model';

        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

        $client           = new WP_MCP_AI_Gemini_Client();
        $captured_request = null;

        $filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request, $defaults ) {
            $captured_request = array( 'args' => $args, 'url' => $url );

            return array(
                'headers'  => array(),
                'body'     => wp_json_encode(
                    array(
                        'candidates' => array(
                            array(
                                'content'      => array(
                                    'parts' => array(
                                        array( 'text' => 'Hello from Gemini' ),
                                    ),
                                ),
                                'finishReason' => 'STOP',
                            ),
                        ),
                        'usageMetadata' => array(
                            'promptTokenCount'     => 10,
                            'candidatesTokenCount' => 20,
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
                'content' => 'Hello',
            ),
        );

        $response = $client->create_chat_completion( $messages, array() );

        remove_filter( 'pre_http_request', $filter_callback, 10 );

        $this->assertIsArray( $response );
        $this->assertArrayHasKey( 'choices', $response );
        $this->assertSame( 'gemini', $response['provider'] );
        $this->assertNotEmpty( $response['choices'] );

        $this->assertNotNull( $captured_request );
        $this->assertArrayHasKey( 'args', $captured_request );
        $this->assertArrayHasKey( 'body', $captured_request['args'] );

        $payload = json_decode( $captured_request['args']['body'], true );
        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'contents', $payload );
        $this->assertSame( $defaults['default_gemini_model'], $this->extract_model_from_url( $captured_request['url'] ) );
    }

    /**
     * Ensure an error is returned when attempting to generate an image without an API key.
     */
    public function test_generate_image_requires_api_key() {
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

        $client   = new WP_MCP_AI_Gemini_Client();
        $response = $client->generate_image( 'A playful otter', array() );

        $this->assertWPError( $response );
        $this->assertSame( 'wp_mcp_ai_missing_gemini_api_key', $response->get_error_code() );
    }

    /**
     * Ensure image generation sends the expected payload and returns decoded binary data.
     */
    public function test_generate_image_sends_expected_payload() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
        $defaults['gemini_api_key'] = 'gsk-test';

        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

        $client           = new WP_MCP_AI_Gemini_Client();
        $captured_request = null;
        $binary_image     = random_bytes( 16 );
        $png_base64       = base64_encode( $binary_image );

        $filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64 ) {
            $captured_request = array( 'args' => $args, 'url' => $url );

            $payload = array(
                'candidates' => array(
                    array(
                        'content' => array(
                            'parts' => array(
                                array(
                                    'text' => 'Suggested prompt: A brighter banana on a teal background',
                                ),
                                array(
                                    'inlineData' => array(
                                        'data'     => $png_base64,
                                        'mimeType' => 'image/png',
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            );

            return array(
                'body'     => wp_json_encode( $payload ),
                'response' => array( 'code' => 200 ),
                'headers'  => array( 'content-type' => 'application/json' ),
            );
        };

        add_filter( 'pre_http_request', $filter_callback, 10, 3 );

        $response = $client->generate_image(
            'A banana for scale',
            array(
                'model'        => 'gemini-2.5-flash-image',
                'aspect_ratio' => '16:9',
                'mime_type'    => 'image/png',
            )
        );

        remove_filter( 'pre_http_request', $filter_callback, 10 );

        $this->assertNotNull( $captured_request );
        $this->assertArrayHasKey( 'args', $captured_request );

        $payload = json_decode( $captured_request['args']['body'], true );
        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'generationConfig', $payload );
        $this->assertSame( '16:9', $payload['generationConfig']['aspectRatio'] );
        $this->assertSame( 'image/png', $payload['generationConfig']['responseMimeType'] );
        $this->assertSame( 'gemini-2.5-flash-image', $this->extract_model_from_url( $captured_request['url'] ) );

        $this->assertIsArray( $response );
        $this->assertArrayHasKey( 'image', $response );
        $this->assertSame( $binary_image, $response['image'] );
        $this->assertSame( 'image/png', $response['mime_type'] );
        $this->assertSame( 'png', $response['format'] );
        $this->assertSame( 'gemini-2.5-flash-image', $response['model'] );
        $this->assertSame( '16:9', $response['aspect_ratio'] );
        $this->assertArrayHasKey( 'created', $response );
        $this->assertIsInt( $response['created'] );
        $this->assertSame( 'Suggested prompt: A brighter banana on a teal background', $response['revised_prompt'] );
    }

    /**
     * Extract the model slug from the generated Gemini endpoint URL.
     *
     * @param string $url Request URL.
     * @return string|null
     */
    protected function extract_model_from_url( $url ) {
        $path = wp_parse_url( $url, PHP_URL_PATH );

        if ( ! is_string( $path ) ) {
            return null;
        }

        $pattern = '#/models/([^:]+):generateContent$#';
        if ( preg_match( $pattern, $path, $matches ) ) {
            return rawurldecode( $matches[1] );
        }

        return null;
    }
}
