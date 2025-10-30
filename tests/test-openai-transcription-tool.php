<?php
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio.php';

/**
 * Tests for the OpenAI audio transcription tool.
 */
class WP_MCP_AI_OpenAI_Transcription_Tool_Test extends WP_UnitTestCase {

    /**
     * Clean up after each test run.
     */
    public function tearDown(): void {
        wp_set_current_user( 0 );
        delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
        parent::tearDown();
    }

    /**
     * The tool requires an attachment identifier.
     */
    public function test_execute_requires_attachment_id() {
        $tool   = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
        $result = $tool->execute( array(), array() );

        $this->assertWPError( $result );
        $this->assertSame( 'wp_mcp_ai_missing_audio_attachment', $result->get_error_code() );
    }

    /**
     * The tool requires an authenticated user or token context.
     */
    public function test_execute_requires_authentication() {
        $attachment_id = $this->create_audio_attachment();

        $tool   = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
        $result = $tool->execute( array( 'attachment_id' => $attachment_id ), array() );

        $this->assertWPError( $result );
        $this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );

        wp_delete_attachment( $attachment_id, true );
    }

    /**
     * Successful execution returns transcription details when OpenAI responds.
     */
    public function test_execute_returns_transcription_payload() {
        $settings = WP_MCP_AI_Admin_Settings::get_default_settings();
        $settings['openai_api_key'] = 'sk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );

        $attachment_id = $this->create_audio_attachment( $user_id );

        $tool             = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
        $captured_request = null;

        $http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
            $captured_request = array(
                'args' => $args,
                'url'  => $url,
            );

            $payload = array(
                'text'     => 'Hello translated world',
                'language' => 'en',
                'duration' => 1.5,
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
                'attachment_id'   => $attachment_id,
                'translate'       => true,
                'response_format' => 'json',
            ),
            array( 'user_id' => $user_id )
        );

        remove_filter( 'pre_http_request', $http_stub, 10 );

        $this->assertNotNull( $captured_request );
        $this->assertSame( WP_MCP_AI_OpenAI_Client::AUDIO_TRANSLATIONS_ENDPOINT, $captured_request['url'] );

        $this->assertIsArray( $result );
        $this->assertSame( $attachment_id, $result['attachment_id'] );
        $this->assertSame( 'Hello translated world', $result['text'] );
        $this->assertTrue( $result['translated'] );
        $this->assertSame( 'json', $result['response_format'] );
        $this->assertSame( 'en', $result['language'] );
        $this->assertSame( 1.5, $result['duration'] );

        wp_delete_attachment( $attachment_id, true );
    }

    /**
     * Translation can be disabled which should use the transcription endpoint and flag.
     */
    public function test_execute_can_disable_translation() {
        $settings = WP_MCP_AI_Admin_Settings::get_default_settings();
        $settings['openai_api_key'] = 'sk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );

        $attachment_id = $this->create_audio_attachment( $user_id );

        $tool             = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
        $captured_request = null;

        $http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
            $captured_request = array(
                'args' => $args,
                'url'  => $url,
            );

            return array(
                'body'     => wp_json_encode( array( 'text' => 'Bonjour le monde', 'language' => 'fr' ) ),
                'response' => array( 'code' => 200 ),
                'headers'  => array( 'content-type' => 'application/json' ),
            );
        };

        add_filter( 'pre_http_request', $http_stub, 10, 3 );

        $result = $tool->execute(
            array(
                'attachment_id'   => $attachment_id,
                'translate'       => false,
                'response_format' => 'verbose_json',
            ),
            array( 'user_id' => $user_id )
        );

        remove_filter( 'pre_http_request', $http_stub, 10 );

        $this->assertNotNull( $captured_request );
        $this->assertSame( WP_MCP_AI_OpenAI_Client::AUDIO_TRANSCRIPTIONS_ENDPOINT, $captured_request['url'] );

        $this->assertIsArray( $result );
        $this->assertFalse( $result['translated'] );
        $this->assertSame( 'verbose_json', $result['response_format'] );

        wp_delete_attachment( $attachment_id, true );
    }

    /**
     * Attachments that are saved without an audio MIME type should fall back to extension detection.
     */
    public function test_execute_detects_mime_type_from_file_extension() {
        $settings = WP_MCP_AI_Admin_Settings::get_default_settings();
        $settings['openai_api_key'] = 'sk-test';
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );

        $attachment_id = $this->create_audio_attachment( $user_id, 'application/octet-stream' );

        $tool             = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
        $captured_request = null;

        $http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
            $captured_request = array(
                'args' => $args,
                'url'  => $url,
            );

            $payload = array(
                'text'     => 'Detected mime works',
                'language' => 'en',
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
                'attachment_id' => $attachment_id,
            ),
            array( 'user_id' => $user_id )
        );

        remove_filter( 'pre_http_request', $http_stub, 10 );

        $this->assertNotNull( $captured_request );
        $this->assertIsArray( $result );
        $this->assertSame( $attachment_id, $result['attachment_id'] );
        $this->assertSame( 'audio/mpeg', $result['mime_type'] );
        $this->assertSame( 'Detected mime works', $result['text'] );

        wp_delete_attachment( $attachment_id, true );
    }

    /**
     * Helper to create a dummy audio attachment for testing.
     *
     * @param int $author_id Optional author identifier.
     * @return int Attachment ID.
     */
    protected function create_audio_attachment( $author_id = 0, $mime_type_override = null ) {
        $upload_dir = wp_upload_dir();

        $tmp_file = wp_tempnam( 'tool-audio.mp3' );
        file_put_contents( $tmp_file, 'FAKEAUDIO' );

        $filename     = 'tool-audio-' . wp_generate_password( 8, false, false ) . '.mp3';
        $destination  = trailingslashit( $upload_dir['path'] ) . $filename;
        wp_mkdir_p( dirname( $destination ) );
        copy( $tmp_file, $destination );
        unlink( $tmp_file );

        $filetype = wp_check_filetype( $filename, null );

        $attachment = array(
            'post_mime_type' => null === $mime_type_override ? $filetype['type'] : $mime_type_override,
            'post_title'     => 'Sample Audio',
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_author'    => $author_id,
        );

        $attachment_id = wp_insert_attachment( $attachment, $destination );

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $destination );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        return $attachment_id;
    }
}
