<?php
/**
 * tests/test-openai-transcription-tool.php
 *
 *
 * @package WP_MCP_AI
 */

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
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
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
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
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
				'body'     => wp_json_encode(
					array(
						'text'     => 'Bonjour le monde',
						'language' => 'fr',
					)
				),
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
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
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
	 * Attachments using the `audio/mp4` MIME type should be accepted.
	 */
	public function test_execute_accepts_audio_mp4_mime_type() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$attachment_id = $this->create_audio_attachment( $user_id, 'audio/mp4' );

		$tool             = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'body'     => wp_json_encode( array( 'text' => 'Audio MP4 supported' ) ),
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
		$this->assertSame( 'audio/mp4', $result['mime_type'] );
		$this->assertSame( 'Audio MP4 supported', $result['text'] );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Attachments using the `audio/flac` MIME type should be accepted.
	 */
	public function test_execute_accepts_audio_flac_mime_type() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$attachment_id = $this->create_audio_attachment( $user_id, 'audio/flac' );

		$tool             = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'body'     => wp_json_encode( array( 'text' => 'Audio FLAC supported' ) ),
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
		$this->assertSame( 'audio/flac', $result['mime_type'] );
		$this->assertSame( 'Audio FLAC supported', $result['text'] );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Attachments using unsupported MIME types like `audio/aac` should be rejected.
	 */
	public function test_execute_rejects_audio_aac_mime_type() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$attachment_id = $this->create_audio_attachment( $user_id, 'audio/aac' );

		$tool   = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$result = $tool->execute( array( 'attachment_id' => $attachment_id ), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_attachment_unsupported_mime', $result->get_error_code() );
		$this->assertSame( 'The attachment is not a supported audio format.', $result->get_error_message() );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Attachments using the `audio/opus` MIME type should be accepted.
	 */
	public function test_execute_accepts_audio_opus_mime_type() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$attachment_id = $this->create_audio_attachment( $user_id, 'audio/opus' );

		$tool             = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'body'     => wp_json_encode( array( 'text' => 'Audio Opus supported' ) ),
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
		$this->assertSame( 'audio/opus', $result['mime_type'] );
		$this->assertSame( 'Audio Opus supported', $result['text'] );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Attachments using the `audio/webm` MIME type should be accepted.
	 */
	public function test_execute_accepts_audio_webm_mime_type() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$attachment_id = $this->create_audio_attachment( $user_id, 'audio/webm' );

		$tool             = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'body'     => wp_json_encode( array( 'text' => 'Audio WebM supported' ) ),
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
		$this->assertSame( 'audio/webm', $result['mime_type'] );
		$this->assertSame( 'Audio WebM supported', $result['text'] );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Attachments using the `video/webm` MIME type should be accepted.
	 */
	public function test_execute_accepts_video_webm_mime_type() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$attachment_id = $this->create_audio_attachment( $user_id, 'video/webm' );

		$tool             = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'body'     => wp_json_encode( array( 'text' => 'Video WebM supported' ) ),
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
		$this->assertSame( 'video/webm', $result['mime_type'] );
		$this->assertSame( 'Video WebM supported', $result['text'] );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Attachments using the `audio/ogg` MIME type should be accepted.
	 */
	public function test_execute_accepts_audio_ogg_mime_type() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$attachment_id = $this->create_audio_attachment( $user_id, 'audio/ogg' );

		$tool             = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'body'     => wp_json_encode( array( 'text' => 'Audio OGG supported' ) ),
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
		$this->assertSame( 'audio/ogg', $result['mime_type'] );
		$this->assertSame( 'Audio OGG supported', $result['text'] );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Attachments using the `audio/m4a` MIME type should be accepted.
	 */
	public function test_execute_accepts_audio_m4a_mime_type() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$attachment_id = $this->create_audio_attachment( $user_id, 'audio/m4a' );

		$tool             = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'body'     => wp_json_encode( array( 'text' => 'Audio M4A supported' ) ),
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
		$this->assertSame( 'audio/m4a', $result['mime_type'] );
		$this->assertSame( 'Audio M4A supported', $result['text'] );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Unsupported file types should return an error with an appropriate HTTP status code.
	 */
	public function test_execute_rejects_unsupported_mime_with_status() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$attachment_id = $this->create_text_attachment( $user_id );

		$tool   = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$result = $tool->execute( array( 'attachment_id' => $attachment_id ), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_attachment_unsupported_mime', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( 415, $data['status'] );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Helper to create a dummy audio attachment for testing.
	 *
	 * @param int $author_id Optional author identifier.
	 * @return int Attachment ID.
	 */
	protected function create_audio_attachment( $author_id = 0, $mime_type_override = null ) {
		return $this->create_test_attachment( 'tool-audio.mp3', 'FAKEAUDIO', $author_id, $mime_type_override );
	}

	/**
	 * Helper to create a dummy text attachment for testing unsupported types.
	 *
	 * @param int $author_id Optional author identifier.
	 * @return int Attachment ID.
	 */
	protected function create_text_attachment( $author_id = 0 ) {
		return $this->create_test_attachment( 'notes.txt', 'Sample text content', $author_id );
	}

	/**
	 * Generic helper to create attachments backed by a file on disk.
	 *
	 * @param string      $filename           Base filename for the attachment.
	 * @param string      $contents           File contents to write.
	 * @param int         $author_id          Optional author identifier.
	 * @param string|null $mime_type_override Optional MIME override.
	 * @return int Attachment ID.
	 */
	protected function create_test_attachment( $filename, $contents, $author_id = 0, $mime_type_override = null ) {
		$upload_dir = wp_upload_dir();

		$tmp_file = wp_tempnam( $filename );
		file_put_contents( $tmp_file, $contents );

		$unique_name = wp_unique_filename( $upload_dir['path'], $filename );
		$destination = trailingslashit( $upload_dir['path'] ) . $unique_name;
		wp_mkdir_p( dirname( $destination ) );
		copy( $tmp_file, $destination );
		unlink( $tmp_file );

		$filetype = wp_check_filetype( $unique_name, null );

		$attachment = array(
			'post_mime_type' => null === $mime_type_override ? $filetype['type'] : $mime_type_override,
			'post_title'     => 'Sample Attachment',
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
