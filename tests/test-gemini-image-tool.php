<?php
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php';
/**
 * Tests for the Gemini image generation tool.
 */
class WP_MCP_AI_Gemini_Image_Tool_Test extends WP_UnitTestCase {

    /**
     * Reset global state between tests.
     */
    public function tearDown(): void {
        wp_set_current_user( 0 );
        delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
        parent::tearDown();
    }

    /**
     * The tool requires authentication.
     */
    public function test_execute_requires_authentication() {
        $tool   = new WP_MCP_AI_Tool_Generate_Gemini_Image();
        $result = $tool->execute( array( 'prompt' => 'A nano banana illustration' ), array() );

        $this->assertWPError( $result );
        $this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
    }

    /**
     * The tool requires a prompt argument.
     */
    public function test_execute_requires_prompt_argument() {
        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

        $tool   = new WP_MCP_AI_Tool_Generate_Gemini_Image();
        $result = $tool->execute( array(), array( 'user_id' => $user_id ) );

        $this->assertWPError( $result );
        $this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
    }

    /**
     * Successful execution stores the generated image as an attachment and returns metadata.
     */
    public function test_execute_generates_attachment_and_returns_metadata() {
        $settings = WP_MCP_AI_Admin_Settings::get_default_settings();
        $settings['gemini_api_key'] = 'gsk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );

        $tool             = new WP_MCP_AI_Tool_Generate_Gemini_Image();
        $captured_request = null;
        $binary_image     = random_bytes( 16 );
        $png_base64       = base64_encode( $binary_image );

        $http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64 ) {
            $captured_request = array(
                'args' => $args,
                'url'  => $url,
            );

            $payload = array(
                'candidates' => array(
                    array(
                        'content' => array(
                            'parts' => array(
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

        add_filter( 'pre_http_request', $http_stub, 10, 3 );

        $result = $tool->execute(
            array(
                'prompt'       => 'Create a Gemini Nano Banana hero image',
                'model'        => 'gemini-2.5-flash-image',
                'aspect_ratio' => '1:1',
                'mime_type'    => 'image/png',
                'file_name'    => 'nano-banana',
            ),
            array( 'user_id' => $user_id )
        );

        remove_filter( 'pre_http_request', $http_stub, 10 );

        $this->assertNotNull( $captured_request );
        $this->assertArrayHasKey( 'args', $captured_request );

        $payload = json_decode( $captured_request['args']['body'], true );
        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'generationConfig', $payload );
        $this->assertSame( 'image/png', $payload['generationConfig']['responseMimeType'] );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'attachment_id', $result );
        $this->assertArrayHasKey( 'url', $result );
        $this->assertSame( 'png', $result['format'] );
        $this->assertSame( 'image/png', $result['mime_type'] );
        $this->assertSame( 'gemini-2.5-flash-image', $result['model'] );
        $this->assertSame( '1:1', $result['aspect_ratio'] );

        $attachment_id = $result['attachment_id'];
        $this->assertNotEmpty( $attachment_id );
        $this->assertSame( 'attachment', get_post_type( $attachment_id ) );

        $file_path = get_attached_file( $attachment_id );
        $this->assertFileExists( $file_path );
        $this->assertSame( $binary_image, file_get_contents( $file_path ) );

        wp_delete_attachment( $attachment_id, true );
    }

    /**
     * The tool uses configured defaults when optional arguments are omitted.
     */
    public function test_execute_uses_defaults_when_arguments_missing() {
        $settings = WP_MCP_AI_Admin_Settings::get_default_settings();
        $settings['gemini_api_key']          = 'gsk-test';
        $settings['gemini_image_model']      = 'gemini-2.5-flash-image';
        $settings['gemini_image_mime_type']  = 'image/webp';
        $settings['gemini_image_aspect_ratio'] = '16:9';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );

        $tool             = new WP_MCP_AI_Tool_Generate_Gemini_Image();
        $captured_request = null;
        $binary_image     = random_bytes( 20 );
        $webp_base64      = base64_encode( $binary_image );

        $http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, $webp_base64 ) {
            $captured_request = array(
                'args' => $args,
                'url'  => $url,
            );

            $payload = array(
                'candidates' => array(
                    array(
                        'content' => array(
                            'parts' => array(
                                array(
                                    'inlineData' => array(
                                        'data'     => $webp_base64,
                                        'mimeType' => 'image/webp',
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

        add_filter( 'pre_http_request', $http_stub, 10, 3 );

        $result = $tool->execute(
            array(
                'prompt' => 'Render the Nano Banana mark in motion',
            ),
            array( 'user_id' => $user_id )
        );

        remove_filter( 'pre_http_request', $http_stub, 10 );

        $this->assertNotNull( $captured_request );
        $payload = json_decode( $captured_request['args']['body'], true );
        $this->assertIsArray( $payload );
        $this->assertSame( '16:9', $payload['generationConfig']['aspectRatio'] );
        $this->assertSame( 'image/webp', $payload['generationConfig']['responseMimeType'] );

        $this->assertIsArray( $result );
        $this->assertSame( 'image/webp', $result['mime_type'] );
        $this->assertSame( 'webp', $result['format'] );

        if ( ! empty( $result['attachment_id'] ) ) {
            wp_delete_attachment( $result['attachment_id'], true );
        }
    }
}
