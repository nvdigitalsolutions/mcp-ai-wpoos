<?php
/**
 * Music Generation Tool
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-music.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-music-service.php';

/**
 * Tests for the music generation tool.
 */
class WP_MCP_AI_Music_Generation_Tool_Test extends WP_UnitTestCase {

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
		$tool   = new WP_MCP_AI_Tool_Generate_Music();
		$result = $tool->execute( array( 'prompt' => 'jazz piano' ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * The tool must receive the prompt argument.
	 */
	public function test_execute_requires_prompt_argument() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_Generate_Music();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * The tool requires upload_files capability.
	 */
	public function test_execute_requires_upload_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_Generate_Music();
		$result = $tool->execute(
			array( 'prompt' => 'upbeat electronic music' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Successful execution generates music and returns metadata.
	 */
	public function test_execute_generates_music_and_returns_metadata() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_api_key'] = 'test-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Generate_Music();
		$captured_request = null;

		// Mock the HTTP request.
		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			// Simulate a successful music generation response.
			$payload = array(
				'predictions' => array(
					array(
						'audio_content' => base64_encode( 'FAKE_AUDIO_DATA' ),
						'audio_format'  => 'wav',
						'mime_type'     => 'audio/wav',
						'duration'      => 30.5,
						'sample_rate'   => 48000,
						'prompt'        => 'jazz piano trio',
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
				'prompt'    => 'jazz piano trio',
				'duration'  => 30,
				'genre'     => 'jazz',
				'mood'      => 'relaxed',
				'bpm'       => 100,
				'file_name' => 'jazz-piece',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( 'generateMusic', $captured_request['url'] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'format', $result );
		$this->assertArrayHasKey( 'duration', $result );
		$this->assertArrayHasKey( 'prompt', $result );

		$this->assertSame( 'wav', $result['format'] );
		$this->assertSame( 30.5, $result['duration'] );
		$this->assertSame( 'jazz piano trio', $result['prompt'] );
		$this->assertSame( 'jazz', $result['genre'] );
		$this->assertSame( 'relaxed', $result['mood'] );
		$this->assertSame( 100, $result['bpm'] );

		$attachment_id = $result['attachment_id'];
		$this->assertNotEmpty( $attachment_id );
		$this->assertSame( 'attachment', get_post_type( $attachment_id ) );
		$this->assertSame( 'audio/wav', get_post_mime_type( $attachment_id ) );

		$file_path = get_attached_file( $attachment_id );
		$this->assertFileExists( $file_path );
		$this->assertSame( 'FAKE_AUDIO_DATA', file_get_contents( $file_path ) );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Tool handles API errors gracefully.
	 */
	public function test_execute_handles_api_errors() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_api_key'] = 'test-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Generate_Music();

		// Mock an API error.
		$http_stub = function ( $preempt, $args, $url ) {
			return array(
				'body'     => wp_json_encode( array( 'error' => 'API quota exceeded' ) ),
				'response' => array( 'code' => 429 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array( 'prompt' => 'classical symphony' ),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_api_error', $result->get_error_code() );
	}

	/**
	 * Tool sanitizes and validates all parameters.
	 */
	public function test_execute_sanitizes_parameters() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_api_key'] = 'test-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Generate_Music();
		$captured_payload = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_payload ) {
			$captured_payload = json_decode( $args['body'], true );

			$payload = array(
				'predictions' => array(
					array(
						'audio_content' => base64_encode( 'TEST' ),
						'audio_format'  => 'wav',
						'mime_type'     => 'audio/wav',
						'duration'      => 15,
						'sample_rate'   => 48000,
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
				'prompt'      => '  <script>alert("test")</script>  jazz  ',
				'duration'    => 500, // Over max, should be capped.
				'bpm'         => 10,  // Under min, should be capped.
				'temperature' => 5.0, // Over max, should be capped.
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );

		// Check that sanitization occurred.
		$this->assertNotNull( $captured_payload );
		$this->assertIsArray( $captured_payload );

		// Duration should be capped at MAX_DURATION (300).
		$this->assertSame( 300, $captured_payload['instances'][0]['duration'] );

		// BPM should be capped at minimum (20).
		$this->assertSame( 20, $captured_payload['parameters']['bpm'] );

		// Temperature should be capped at maximum (2.0).
		$this->assertSame( 2.0, $captured_payload['instances'][0]['temperature'] );

		// Clean up.
		if ( isset( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}
}
