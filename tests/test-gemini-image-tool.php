<?php
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php';

/**
 * Tests for the Gemini image generation tool.
 */
class WP_MCP_AI_Gemini_Image_Tool_Test extends WP_UnitTestCase {

    /**
     * Clean up between tests.
     */
    public function tearDown(): void {
        wp_set_current_user( 0 );
        delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
        parent::tearDown();
    }

    /**
     * The tool should return inline content and a download URL when an image is generated successfully.
     */
    public function test_execute_returns_inline_content_payload() {
        $settings = WP_MCP_AI_Admin_Settings::get_default_settings();
        $settings['gemini_api_key'] = 'gsk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );

        $tool             = new WP_MCP_AI_Tool_Generate_Gemini_Image();
        $captured_request = null;
        $png_base64       = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';

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
                'prompt'     => 'A friendly otter in a teacup',
                'mime_type'  => 'image/png',
                'file_name'  => 'otter-teacup',
            ),
            array( 'user_id' => $user_id )
        );

        remove_filter( 'pre_http_request', $http_stub, 10 );

        $this->assertNotNull( $captured_request );
        $this->assertIsArray( $result );

        $this->assertArrayHasKey( 'content', $result );
        $this->assertIsArray( $result['content'] );
        $this->assertSame( 'base64', $result['content']['encoding'] );
        $this->assertSame( $png_base64, $result['content']['data'] );
        $this->assertSame( 'image/png', $result['content']['mime_type'] );
        $this->assertSame( 'data:image/png;base64,' . $result['content']['data'], $result['content']['data_url'] );

        $this->assertArrayHasKey( 'download_url', $result );
        $this->assertSame( $result['url'], $result['download_url'] );

        if ( ! empty( $result['attachment_id'] ) ) {
            wp_delete_attachment( $result['attachment_id'], true );
        }
    }
}
