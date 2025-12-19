<?php
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php';
/**
 * Tests for the OpenAI speech generation tool.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_OpenAI_Speech_Tool_Test extends WP_UnitTestCase {

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
		$tool   = new WP_MCP_AI_Tool_Generate_OpenAI_Speech();
		$result = $tool->execute( array( 'text' => 'Hello world' ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * The tool must receive the text argument before contacting OpenAI.
	 */
	public function test_execute_requires_text_argument() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_Generate_OpenAI_Speech();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_text', $result->get_error_code() );
	}

	/**
	 * Successful execution stores the generated audio as an attachment and returns metadata.
	 */
	public function test_execute_generates_attachment_and_returns_metadata() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Generate_OpenAI_Speech();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'body'     => 'FAKEAUDIO',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'audio/mpeg' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'text'      => 'Hello there friend',
				'voice'     => 'verse',
				'format'    => 'mp3',
				'model'     => 'gpt-test-tts',
				'file_name' => 'greeting-audio',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::AUDIO_SPEECH_ENDPOINT, $captured_request['url'] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertSame( 'mp3', $result['format'] );
		$this->assertSame( 'verse', $result['voice'] );
		$this->assertSame( 'gpt-test-tts', $result['model'] );

		$attachment_id = $result['attachment_id'];
		$this->assertNotEmpty( $attachment_id );
		$this->assertSame( 'attachment', get_post_type( $attachment_id ) );
		$this->assertSame( 'audio/mpeg', get_post_mime_type( $attachment_id ) );

		$file_path = get_attached_file( $attachment_id );
		$this->assertFileExists( $file_path );
		$this->assertSame( 'FAKEAUDIO', file_get_contents( $file_path ) );

		$this->assertGreaterThan( 0, (int) $result['bytes'] );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * When arguments are omitted, the tool should honour the configured defaults.
	 */
	public function test_execute_uses_configured_defaults_when_arguments_omitted() {
		$settings                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']       = 'sk-test';
		$settings['openai_speech_model']  = 'gpt-custom-tts';
		$settings['openai_speech_voice']  = 'aria';
		$settings['openai_speech_format'] = 'wav';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Generate_OpenAI_Speech();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'body'     => 'FAKEAUDIO',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'audio/wav' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'text' => 'Hello defaults',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'gpt-custom-tts', $result['model'] );
		$this->assertSame( 'aria', $result['voice'] );
		$this->assertSame( 'wav', $result['format'] );

		$this->assertNotNull( $captured_request );
		$this->assertArrayHasKey( 'body', $captured_request['args'] );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertSame( 'gpt-custom-tts', $payload['model'] );
		$this->assertSame( 'aria', $payload['voice'] );
		$this->assertSame( 'wav', $payload['format'] );

		$attachment_id = $result['attachment_id'];
		$this->assertNotEmpty( $attachment_id );
		wp_delete_attachment( $attachment_id, true );
	}
}
