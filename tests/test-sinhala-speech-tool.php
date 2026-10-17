<?php
/**
 * Sinhala Speech Tool
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sinhala-speech.php';

/**
 * Tests for the Sinhala speech generation tool.
 */
class WP_MCP_AI_Sinhala_Speech_Tool_Test extends WP_UnitTestCase {

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
		$tool   = new WP_MCP_AI_Tool_Generate_Sinhala_Speech();
		$result = $tool->execute( array( 'text' => 'ආයුබෝවන්' ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * The tool must receive the text argument before contacting a provider.
	 */
	public function test_execute_requires_text_argument() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_Generate_Sinhala_Speech();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_text', $result->get_error_code() );
	}

	/**
	 * A missing SpeechGen token returns a clear, actionable error.
	 */
	public function test_execute_returns_error_when_speechgen_token_missing() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Generate_Sinhala_Speech();
		$result = $tool->execute(
			array(
				'text'     => 'ආයුබෝවන්',
				'provider' => 'speechgen',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_sinhala_token', $result->get_error_code() );
	}

	/**
	 * Successful SpeechGen execution stores base64 audio and returns metadata.
	 */
	public function test_execute_speechgen_generates_attachment() {
		$settings                               = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['sinhala_speechgen_token']    = 'token-test';
		$settings['sinhala_speechgen_endpoint'] = 'https://example.com/tts';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Generate_Sinhala_Speech();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'body'     => wp_json_encode( array( 'audio' => base64_encode( 'FAKEAUDIO' ) ) ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'text'     => 'ආයුබෝවන්',
				'provider' => 'speechgen',
				'format'   => 'mp3',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertSame( 'https://example.com/tts', $captured_request['url'] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertSame( 'speechgen', $result['provider'] );
		$this->assertSame( 'mp3', $result['format'] );

		$attachment_id = $result['attachment_id'];
		$this->assertNotEmpty( $attachment_id );
		$this->assertSame( 'attachment', get_post_type( $attachment_id ) );
		$this->assertSame( 'audio/mpeg', get_post_mime_type( $attachment_id ) );

		$file_path = get_attached_file( $attachment_id );
		$this->assertFileExists( $file_path );
		$this->assertSame( 'FAKEAUDIO', file_get_contents( $file_path ) );

		wp_delete_attachment( $attachment_id, true );
	}
}
