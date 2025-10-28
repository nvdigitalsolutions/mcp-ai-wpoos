<?php
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php';
/**
 * Tests for the OpenAI image generation tool.
 */
class WP_MCP_AI_OpenAI_Image_Tool_Test extends WP_UnitTestCase {

    /**
     * Clean up global state after each test.
     */
    public function tearDown(): void {
        wp_set_current_user( 0 );
        delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
        parent::tearDown();
    }

    /**
     * The tool requires an authenticated user or token context.
     */
    public function test_execute_requires_authentication() {
        $tool   = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
        $result = $tool->execute( array( 'prompt' => 'A friendly robot' ), array() );

        $this->assertWPError( $result );
        $this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
    }

    /**
     * The tool must receive the prompt argument before contacting OpenAI.
     */
    public function test_execute_requires_prompt_argument() {
        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

        $tool   = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
        $result = $tool->execute( array(), array( 'user_id' => $user_id ) );

        $this->assertWPError( $result );
        $this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
    }

    /**
     * Successful execution stores the generated image as an attachment and returns metadata.
     */
    public function test_execute_generates_attachment_and_returns_metadata() {
        $settings = WP_MCP_AI_Admin_Settings::get_default_settings();
        $settings['openai_api_key'] = 'sk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );

        $tool             = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
        $captured_request = null;
        $png_base64       = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';
        $png_binary       = base64_decode( $png_base64 );

        $http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64 ) {
            $captured_request = array(
                'args' => $args,
                'url'  => $url,
            );

            $payload = array(
                'created' => 456,
                'data'    => array(
                    array(
                        'b64_json'       => $png_base64,
                        'revised_prompt' => 'A friendlier robot',
                    ),
                ),
            );

            return array(
                'body'     => wp_json_encode( $payload ),
                'response' => array( 'code' => 200 ),
                'headers'  => array( 'content-type' => 'application/json' ),
            );
        };

        add_filter( 'pre_http_request', $http_stub, 10, 3 );

        $result = $tool->execute(
            array(
                'prompt'     => 'A friendly robot painting a portrait',
                'model'      => 'gpt-image-test',
                'size'       => '1024x1536',
                'quality'    => 'high',
                'background' => 'transparent',
                'format'     => 'png',
                'file_name'  => 'robot-art',
            ),
            array( 'user_id' => $user_id )
        );

        remove_filter( 'pre_http_request', $http_stub, 10 );

        $this->assertNotNull( $captured_request );
        $this->assertSame( WP_MCP_AI_OpenAI_Client::IMAGES_ENDPOINT, $captured_request['url'] );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'attachment_id', $result );
        $this->assertArrayHasKey( 'url', $result );
        $this->assertSame( 'png', $result['format'] );
        $this->assertSame( '1024x1536', $result['size'] );
        $this->assertSame( 'high', $result['quality'] );
        $this->assertSame( 'gpt-image-test', $result['model'] );
        $this->assertSame( 'transparent', $result['background'] );
        $this->assertSame( 'A friendlier robot', $result['revised_prompt'] );
        $this->assertSame( 456, $result['created'] );

        $attachment_id = $result['attachment_id'];
        $this->assertNotEmpty( $attachment_id );
        $this->assertSame( 'attachment', get_post_type( $attachment_id ) );
        $this->assertSame( 'image/png', get_post_mime_type( $attachment_id ) );

        $file_path = get_attached_file( $attachment_id );
        $this->assertFileExists( $file_path );
        $this->assertSame( $png_binary, file_get_contents( $file_path ) );

        $this->assertGreaterThan( 0, (int) $result['bytes'] );

        wp_delete_attachment( $attachment_id, true );
    }

    /**
     * The tool should fall back to the configured image defaults when optional arguments are omitted.
     */
    public function test_execute_uses_configured_defaults_when_arguments_missing() {
        $settings = WP_MCP_AI_Admin_Settings::get_default_settings();
        $settings['openai_api_key']        = 'sk-test';
        $settings['openai_image_size']     = '1536x1024';
        $settings['openai_image_quality']  = 'high';
        $settings['openai_image_background'] = 'opaque';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );

        $tool             = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
        $captured_request = null;
        $png_base64       = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';

        $http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64 ) {
            $captured_request = array(
                'args' => $args,
                'url'  => $url,
            );

            $payload = array(
                'created' => 999,
                'data'    => array(
                    array(
                        'b64_json' => $png_base64,
                    ),
                ),
            );

            return array(
                'body'     => wp_json_encode( $payload ),
                'response' => array( 'code' => 200 ),
                'headers'  => array( 'content-type' => 'application/json' ),
            );
        };

        add_filter( 'pre_http_request', $http_stub, 10, 3 );

        $result = $tool->execute(
            array(
                'prompt' => 'A robot sketching a blueprint',
            ),
            array( 'user_id' => $user_id )
        );

        remove_filter( 'pre_http_request', $http_stub, 10 );

        $this->assertNotNull( $captured_request );
        $this->assertSame( WP_MCP_AI_OpenAI_Client::IMAGES_ENDPOINT, $captured_request['url'] );

        $payload = json_decode( $captured_request['args']['body'], true );
        $this->assertIsArray( $payload );
        $this->assertSame( '1536x1024', $payload['size'] );
        $this->assertSame( 'high', $payload['quality'] );
        $this->assertSame( 'opaque', $payload['background'] );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'url', $result );

        if ( ! empty( $result['attachment_id'] ) ) {
            wp_delete_attachment( $result['attachment_id'], true );
        }
    }
}
