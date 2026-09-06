<?php
/**
 * Music Generation Tool
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-music.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-mubert-music-service.php';

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
	 *
	 * The tool now delegates to the Mubert service: a generation request to
	 * the Mubert API returns an audio URL, which the service then downloads.
	 * The mock must answer both requests.
	 */
	public function test_execute_generates_music_and_returns_metadata() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['mubert_api_key'] = 'test-mubert-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Generate_Music();
		$captured_request = null;

		// Mock the Mubert generation request, then the audio download request.
		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			if ( false !== strpos( $url, 'music-api.mubert.com' ) ) {
				$captured_request = array(
					'args' => $args,
					'url'  => $url,
				);

				// Simulate a successful Mubert generation response.
				return array(
					'body'     => wp_json_encode( array( 'url' => 'https://audio.example.com/track-123.mp3' ) ),
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'application/json' ),
				);
			}

			// Simulate the audio download response.
			return array(
				'body'     => 'FAKE_AUDIO_DATA',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'audio/mpeg' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'prompt'    => 'jazz piano trio',
				'duration'  => 30,
				'genre'     => 'jazz',
				'mood'      => 'relaxed',
				'file_name' => 'jazz-piece',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( 'music-api.mubert.com', $captured_request['url'] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'format', $result );
		$this->assertArrayHasKey( 'duration', $result );
		$this->assertArrayHasKey( 'prompt', $result );

		$this->assertSame( 'mp3', $result['format'] );
		$this->assertSame( 30, $result['duration'] );
		$this->assertSame( 'jazz piano trio', $result['prompt'] );
		$this->assertSame( 'jazz', $result['genre'] );
		$this->assertSame( 'relaxed', $result['mood'] );

		$attachment_id = $result['attachment_id'];
		$this->assertNotEmpty( $attachment_id );
		$this->assertSame( 'attachment', get_post_type( $attachment_id ) );
		$this->assertSame( 'audio/mpeg', get_post_mime_type( $attachment_id ) );

		$file_path = get_attached_file( $attachment_id );
		$this->assertFileExists( $file_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local attachment file for assertion.
		$this->assertSame( 'FAKE_AUDIO_DATA', file_get_contents( $file_path ) );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Tool handles API errors gracefully.
	 */
	public function test_execute_handles_api_errors() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['mubert_api_key'] = 'test-mubert-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Generate_Music();

		// Mock an API error.
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- pre_http_request filter signature requires all three parameters.
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
		$this->assertSame( 'wp_mcp_ai_mubert_api_error', $result->get_error_code() );
	}

	/**
	 * Tool sanitizes and validates all parameters.
	 */
	public function test_execute_sanitizes_parameters() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['mubert_api_key'] = 'test-mubert-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Generate_Music();
		$captured_payload = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_payload ) {
			if ( false !== strpos( $url, 'music-api.mubert.com' ) ) {
				$captured_payload = json_decode( $args['body'], true );

				return array(
					'body'     => wp_json_encode( array( 'url' => 'https://audio.example.com/track-sanitized.mp3' ) ),
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'application/json' ),
				);
			}

			// Simulate the audio download response.
			return array(
				'body'     => 'TEST',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'audio/mpeg' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'prompt'   => '  <script>alert("test")</script>  jazz  ',
				'duration' => 99999, // Over max, should be capped.
				'genre'    => '<b>jazz</b>', // Tags should be stripped.
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );

		// Check that sanitization occurred.
		$this->assertNotNull( $captured_payload );
		$this->assertIsArray( $captured_payload );

		// Script tags must be stripped from the prompt.
		$this->assertStringNotContainsString( '<script', $captured_payload['prompt'] );
		$this->assertStringContainsString( 'jazz', $captured_payload['prompt'] );

		// Duration should be capped at MAX_DURATION (1500).
		$this->assertSame( 1500, $captured_payload['duration'] );

		// Genre tags should be stripped.
		$this->assertSame( 'jazz', $captured_payload['genre'] );

		// Default format should be mp3.
		$this->assertSame( 'mp3', $captured_payload['format'] );

		// Clean up.
		if ( isset( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}
}
