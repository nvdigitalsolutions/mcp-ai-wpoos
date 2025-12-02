<?php
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio.php';
/**
 * Tests for the OpenAI audio transcription tool.
 */
class WP_MCP_AI_OpenAI_Transcribe_Tool_Test extends WP_UnitTestCase {

	/**
	 * Test audio data for fake audio files.
	 */
	const TEST_AUDIO_DATA = 'FAKEAUDIODATA';

	/**
	 * Clean up global state after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Helper method to create a test audio attachment.
	 *
	 * Creates a fake audio file with test data and an associated WordPress
	 * attachment post. The caller is responsible for cleaning up both the
	 * attachment and the file using wp_delete_attachment() and unlink().
	 *
	 * @return array {
	 *     Array containing attachment and file information.
	 *
	 *     @type int    $attachment_id The WordPress attachment post ID.
	 *     @type string $file_path     Absolute path to the created test file.
	 * }
	 */
	protected function create_test_audio_attachment() {
		// Create a minimal test audio file.
		$upload_dir  = wp_upload_dir();
		$test_file   = $upload_dir['basedir'] . '/test-audio-' . time() . '.mp3';
		$file_handle = fopen( $test_file, 'w' );

		if ( false === $file_handle ) {
			$this->fail( 'Failed to create test audio file' );
		}

		if ( false === fwrite( $file_handle, self::TEST_AUDIO_DATA ) ) {
			fclose( $file_handle );
			$this->fail( 'Failed to write test audio data' );
		}

		fclose( $file_handle );

		// Create attachment pointing to this file.
		$attachment_id = self::factory()->attachment->create(
			array(
				'file'           => $test_file,
				'post_mime_type' => 'audio/mpeg',
			)
		);

		// Ensure the file path is set correctly.
		update_attached_file( $attachment_id, $test_file );

		return array(
			'attachment_id' => $attachment_id,
			'file_path'     => $test_file,
		);
	}

	/**
	 * The tool requires an authenticated user or token context.
	 */
	public function test_execute_requires_authentication() {
		$tool   = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$result = $tool->execute( array( 'attachment_id' => 123 ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * The tool must receive an attachment source (attachment_id, file_id, or url).
	 */
	public function test_execute_requires_audio_source() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_audio_source', $result->get_error_code() );
	}

	/**
	 * By default (when translate argument is not specified), the tool should use transcription mode.
	 * This test verifies the fix for the bug where it was defaulting to translation mode.
	 */
	public function test_execute_defaults_to_transcription_mode() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$test_data     = $this->create_test_audio_attachment();
		$attachment_id = $test_data['attachment_id'];
		$test_file     = $test_data['file_path'];

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
						'text'     => 'This is a transcription',
						'language' => 'en',
						'duration' => 5.5,
					)
				),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				// Note: NOT specifying 'translate' argument - should default to false.
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request, 'HTTP request should have been made' );

		// The critical assertion: verify it used the transcriptions endpoint, not translations.
		$this->assertSame(
			WP_MCP_AI_OpenAI_Client::AUDIO_TRANSCRIPTIONS_ENDPOINT,
			$captured_request['url'],
			'Should use transcriptions endpoint by default, not translations endpoint'
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'text', $result );
		$this->assertSame( 'This is a transcription', $result['text'] );
		$this->assertArrayHasKey( 'translated', $result );
		$this->assertFalse( $result['translated'], 'Translated flag should be false when using transcription mode' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
		if ( file_exists( $test_file ) ) {
			unlink( $test_file );
		}
	}

	/**
	 * When translate is explicitly set to true, the tool should use translation mode.
	 */
	public function test_execute_uses_translation_mode_when_requested() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$test_data     = $this->create_test_audio_attachment();
		$attachment_id = $test_data['attachment_id'];
		$test_file     = $test_data['file_path'];

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
						'text'     => 'This is a translation',
						'language' => 'en',
						'duration' => 5.5,
					)
				),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'translate'     => true, // Explicitly request translation.
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request, 'HTTP request should have been made' );

		// Verify it used the translations endpoint.
		$this->assertSame(
			WP_MCP_AI_OpenAI_Client::AUDIO_TRANSLATIONS_ENDPOINT,
			$captured_request['url'],
			'Should use translations endpoint when translate is true'
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'text', $result );
		$this->assertSame( 'This is a translation', $result['text'] );
		$this->assertArrayHasKey( 'translated', $result );
		$this->assertTrue( $result['translated'], 'Translated flag should be true when using translation mode' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
		if ( file_exists( $test_file ) ) {
			unlink( $test_file );
		}
	}

	/**
	 * When translate is explicitly set to false, the tool should use transcription mode.
	 */
	public function test_execute_uses_transcription_mode_when_explicitly_false() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$test_data     = $this->create_test_audio_attachment();
		$attachment_id = $test_data['attachment_id'];
		$test_file     = $test_data['file_path'];

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
						'text'     => 'This is a transcription',
						'language' => 'en',
						'duration' => 5.5,
					)
				),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'translate'     => false, // Explicitly request transcription.
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request, 'HTTP request should have been made' );

		// Verify it used the transcriptions endpoint.
		$this->assertSame(
			WP_MCP_AI_OpenAI_Client::AUDIO_TRANSCRIPTIONS_ENDPOINT,
			$captured_request['url'],
			'Should use transcriptions endpoint when translate is false'
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'text', $result );
		$this->assertSame( 'This is a transcription', $result['text'] );
		$this->assertArrayHasKey( 'translated', $result );
		$this->assertFalse( $result['translated'], 'Translated flag should be false when using transcription mode' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
		if ( file_exists( $test_file ) ) {
			unlink( $test_file );
		}
	}

	/**
	 * When using translation mode, response format should be 'json' not 'verbose_json'.
	 * The translation endpoint doesn't support verbose_json format.
	 */
	public function test_translation_uses_json_response_format() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$test_data     = $this->create_test_audio_attachment();
		$attachment_id = $test_data['attachment_id'];
		$test_file     = $test_data['file_path'];

		$tool                    = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$captured_request        = null;
		$captured_request_fields = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, &$captured_request_fields ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			// Parse the multipart body to extract the response_format field.
			if ( isset( $args['body'] ) ) {
				// Extract response_format from multipart body.
				if ( preg_match( '/name="response_format"[^\r\n]*\r?\n\r?\n([^\r\n]+)/i', $args['body'], $matches ) ) {
					$captured_request_fields['response_format'] = trim( $matches[1] );
				}
			}

			return array(
				'body'     => wp_json_encode(
					array(
						'text'     => 'This is a translation',
						'language' => 'en',
						'duration' => 5.5,
					)
				),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'translate'     => true, // Request translation mode.
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request, 'HTTP request should have been made' );

		// Verify it used the translations endpoint.
		$this->assertSame(
			WP_MCP_AI_OpenAI_Client::AUDIO_TRANSLATIONS_ENDPOINT,
			$captured_request['url'],
			'Should use translations endpoint when translate is true'
		);

		// Verify response_format is 'json', not 'verbose_json'.
		$this->assertNotNull( $captured_request_fields, 'Should have captured request fields' );
		$this->assertArrayHasKey( 'response_format', $captured_request_fields, 'Request should include response_format field' );
		$this->assertSame( 'json', $captured_request_fields['response_format'], 'Translation endpoint should use json format, not verbose_json' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'text', $result );
		$this->assertArrayHasKey( 'translated', $result );
		$this->assertTrue( $result['translated'], 'Translated flag should be true' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
		if ( file_exists( $test_file ) ) {
			unlink( $test_file );
		}
	}

	/**
	 * When using transcription mode, response format should default to 'verbose_json'.
	 */
	public function test_transcription_uses_verbose_json_response_format() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$test_data     = $this->create_test_audio_attachment();
		$attachment_id = $test_data['attachment_id'];
		$test_file     = $test_data['file_path'];

		$tool                    = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$captured_request        = null;
		$captured_request_fields = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, &$captured_request_fields ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			// Parse the multipart body to extract the response_format field.
			if ( isset( $args['body'] ) ) {
				// Extract response_format from multipart body.
				if ( preg_match( '/name="response_format"[^\r\n]*\r?\n\r?\n([^\r\n]+)/i', $args['body'], $matches ) ) {
					$captured_request_fields['response_format'] = trim( $matches[1] );
				}
			}

			return array(
				'body'     => wp_json_encode(
					array(
						'text'     => 'This is a transcription',
						'language' => 'en',
						'duration' => 5.5,
					)
				),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'translate'     => false, // Request transcription mode.
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request, 'HTTP request should have been made' );

		// Verify it used the transcriptions endpoint.
		$this->assertSame(
			WP_MCP_AI_OpenAI_Client::AUDIO_TRANSCRIPTIONS_ENDPOINT,
			$captured_request['url'],
			'Should use transcriptions endpoint when translate is false'
		);

		// Verify response_format is 'verbose_json'.
		$this->assertNotNull( $captured_request_fields, 'Should have captured request fields' );
		$this->assertArrayHasKey( 'response_format', $captured_request_fields, 'Request should include response_format field' );
		$this->assertSame( 'verbose_json', $captured_request_fields['response_format'], 'Transcription endpoint should use verbose_json format by default' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'text', $result );
		$this->assertArrayHasKey( 'translated', $result );
		$this->assertFalse( $result['translated'], 'Translated flag should be false' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
		if ( file_exists( $test_file ) ) {
			unlink( $test_file );
		}
	}

	/**
	 * When using translation with verbose_json explicitly requested, it should be downgraded to json.
	 */
	public function test_translation_downgrades_verbose_json_to_json() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$test_data     = $this->create_test_audio_attachment();
		$attachment_id = $test_data['attachment_id'];
		$test_file     = $test_data['file_path'];

		$tool                    = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$captured_request        = null;
		$captured_request_fields = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, &$captured_request_fields ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			// Parse the multipart body to extract the response_format field.
			if ( isset( $args['body'] ) ) {
				// Extract response_format from multipart body.
				if ( preg_match( '/name="response_format"[^\r\n]*\r?\n\r?\n([^\r\n]+)/i', $args['body'], $matches ) ) {
					$captured_request_fields['response_format'] = trim( $matches[1] );
				}
			}

			return array(
				'body'     => wp_json_encode(
					array(
						'text'     => 'This is a translation',
						'language' => 'en',
						'duration' => 5.5,
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
				'translate'       => true, // Request translation mode.
				'response_format' => 'verbose_json', // Explicitly request verbose_json (should be downgraded).
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request, 'HTTP request should have been made' );

		// Verify response_format was downgraded from verbose_json to json.
		$this->assertNotNull( $captured_request_fields, 'Should have captured request fields' );
		$this->assertArrayHasKey( 'response_format', $captured_request_fields, 'Request should include response_format field' );
		$this->assertSame( 'json', $captured_request_fields['response_format'], 'Translation endpoint should downgrade verbose_json to json' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
		if ( file_exists( $test_file ) ) {
			unlink( $test_file );
		}
	}

	/**
	 * When using a model with '-api-' in the name (e.g., gpt-4o-mini-transcribe-api-ev3),
	 * verbose_json should be downgraded to json as these models don't support verbose_json.
	 */
	public function test_api_versioned_model_uses_json_response_format() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$test_data     = $this->create_test_audio_attachment();
		$attachment_id = $test_data['attachment_id'];
		$test_file     = $test_data['file_path'];

		$tool                    = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$captured_request        = null;
		$captured_request_fields = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, &$captured_request_fields ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			// Parse the multipart body to extract the response_format field.
			if ( isset( $args['body'] ) ) {
				// Extract response_format from multipart body.
				if ( preg_match( '/name="response_format"[^\r\n]*\r?\n\r?\n([^\r\n]+)/i', $args['body'], $matches ) ) {
					$captured_request_fields['response_format'] = trim( $matches[1] );
				}
				// Extract model from multipart body.
				if ( preg_match( '/name="model"[^\r\n]*\r?\n\r?\n([^\r\n]+)/i', $args['body'], $matches ) ) {
					$captured_request_fields['model'] = trim( $matches[1] );
				}
			}

			return array(
				'body'     => wp_json_encode(
					array(
						'text'     => 'This is a transcription',
						'language' => 'en',
						'duration' => 5.5,
					)
				),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'model'         => 'gpt-4o-mini-transcribe-api-ev3', // API-versioned model.
				// Not specifying response_format - should default to verbose_json but be downgraded to json.
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request, 'HTTP request should have been made' );

		// Verify it used the transcriptions endpoint (not translation).
		$this->assertSame(
			WP_MCP_AI_OpenAI_Client::AUDIO_TRANSCRIPTIONS_ENDPOINT,
			$captured_request['url'],
			'Should use transcriptions endpoint'
		);

		// Verify the model was set correctly.
		$this->assertNotNull( $captured_request_fields, 'Should have captured request fields' );
		$this->assertArrayHasKey( 'model', $captured_request_fields, 'Request should include model field' );
		$this->assertSame( 'gpt-4o-mini-transcribe-api-ev3', $captured_request_fields['model'], 'Model should be gpt-4o-mini-transcribe-api-ev3' );

		// Verify response_format was downgraded from verbose_json to json.
		$this->assertArrayHasKey( 'response_format', $captured_request_fields, 'Request should include response_format field' );
		$this->assertSame( 'json', $captured_request_fields['response_format'], 'API-versioned models should use json format, not verbose_json' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'text', $result );
		$this->assertArrayHasKey( 'translated', $result );
		$this->assertFalse( $result['translated'], 'Translated flag should be false for transcription mode' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
		if ( file_exists( $test_file ) ) {
			unlink( $test_file );
		}
	}

	/**
	 * When using a model with '-api-' in the name and explicitly requesting verbose_json,
	 * it should be downgraded to json to avoid API errors.
	 */
	public function test_api_versioned_model_downgrades_explicit_verbose_json() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$test_data     = $this->create_test_audio_attachment();
		$attachment_id = $test_data['attachment_id'];
		$test_file     = $test_data['file_path'];

		$tool                    = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
		$captured_request        = null;
		$captured_request_fields = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, &$captured_request_fields ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			// Parse the multipart body to extract the response_format field.
			if ( isset( $args['body'] ) ) {
				// Extract response_format from multipart body.
				if ( preg_match( '/name="response_format"[^\r\n]*\r?\n\r?\n([^\r\n]+)/i', $args['body'], $matches ) ) {
					$captured_request_fields['response_format'] = trim( $matches[1] );
				}
			}

			return array(
				'body'     => wp_json_encode(
					array(
						'text'     => 'This is a transcription',
						'language' => 'en',
						'duration' => 5.5,
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
				'model'           => 'gpt-4o-mini-transcribe-api-ev3',
				'response_format' => 'verbose_json', // Explicitly request verbose_json (should be downgraded).
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request, 'HTTP request should have been made' );

		// Verify response_format was downgraded from verbose_json to json.
		$this->assertNotNull( $captured_request_fields, 'Should have captured request fields' );
		$this->assertArrayHasKey( 'response_format', $captured_request_fields, 'Request should include response_format field' );
		$this->assertSame( 'json', $captured_request_fields['response_format'], 'API-versioned models should downgrade explicit verbose_json to json' );

		$this->assertIsArray( $result );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
		if ( file_exists( $test_file ) ) {
			unlink( $test_file );
		}
	}
}
