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
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
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
				'model'   => 'gpt-image-test',
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
				'prompt'          => 'A friendly robot painting a portrait',
				'model'           => 'gpt-image-test',
				'size'            => '1024x1792',
				'quality'         => 'high',
				'format'          => 'png',
				'response_format' => 'b64_json',
				'file_name'       => 'robot-art',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::IMAGES_ENDPOINT, $captured_request['url'] );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'response_format', $payload );
		$this->assertSame( 'b64_json', $payload['response_format'] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertNotEmpty( $result['url'] );
		$this->assertStringContainsString( 'wp-content/uploads/', $result['url'] );
		$this->assertSame( 'png', $result['format'] );
		$this->assertSame( '1024x1792', $result['size'] );
		$this->assertSame( 'high', $result['quality'] );
		$this->assertSame( 'gpt-image-test', $result['model'] );
		$this->assertSame( 'b64_json', $result['response_format'] );
		$this->assertSame( 'A friendlier robot', $result['revised_prompt'] );
		$this->assertSame( 456, $result['created'] );

		// Verify text field includes descriptive information.
		$this->assertArrayHasKey( 'text', $result );
		$this->assertStringContainsString( 'Successfully generated image', $result['text'] );
		$this->assertStringContainsString( 'Revised prompt: A friendlier robot', $result['text'] );
		$this->assertStringContainsString( '1024x1792', $result['text'] );
		$this->assertStringContainsString( 'high', $result['text'] );

		$attachment_id = $result['attachment_id'];
		$this->assertNotEmpty( $attachment_id );
		$this->assertSame( 'attachment', get_post_type( $attachment_id ) );
		$this->assertSame( 'image/png', get_post_mime_type( $attachment_id ) );

		$file_path = get_attached_file( $attachment_id );
		$this->assertFileExists( $file_path );
		$this->assertSame( $png_binary, file_get_contents( $file_path ) );

		$this->assertGreaterThan( 0, (int) $result['bytes'] );

		// Verify OpenAI metadata is saved to attachment.
		$openai_meta = get_post_meta( $attachment_id, '_wp_mcp_ai_openai_image_meta', true );
		$this->assertIsArray( $openai_meta );
		$this->assertSame( 'openai', $openai_meta['source'] );
		$this->assertSame( 'A friendly robot painting a portrait', $openai_meta['original_prompt'] );
		$this->assertSame( 'gpt-image-test', $openai_meta['model'] );
		$this->assertSame( 'A friendlier robot', $openai_meta['revised_prompt'] );
		$this->assertSame( 456, $openai_meta['created'] );
		$this->assertSame( 'png', $openai_meta['format'] );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * The tool should fall back to the configured image defaults when optional arguments are omitted.
	 * Note: 'hd' quality will be sanitized to 'medium' as per the quality mapping patch.
	 */
	public function test_execute_uses_configured_defaults_when_arguments_missing() {
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']               = 'sk-test';
		$settings['openai_image_size']            = '1792x1024';
		$settings['openai_image_quality']         = 'high';
		$settings['openai_image_response_format'] = 'url';
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
				'model'   => 'gpt-image-1',
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
		$this->assertSame( '1792x1024', $payload['size'] );
		$this->assertSame( 'high', $payload['quality'] );
		$this->assertArrayHasKey( 'response_format', $payload );
		$this->assertSame( 'url', $payload['response_format'] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertSame( 'url', $result['response_format'] );

		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Models that support response formats should send the configured value to OpenAI.
	 */
	public function test_execute_respects_response_format_for_supported_models() {
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']               = 'sk-test';
		$settings['openai_image_model']           = 'dall-e-3';
		$settings['openai_image_response_format'] = 'url';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$captured_request = null;
		$png_base64       = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';
		$png_binary       = base64_decode( $png_base64 );
		$image_url        = 'https://example.com/generated.png';

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, $png_binary, $image_url ) {
			if ( WP_MCP_AI_OpenAI_Client::IMAGES_ENDPOINT === $url ) {
				$captured_request = array(
					'args' => $args,
					'url'  => $url,
				);

				$payload = array(
					'created' => 321,
					'model'   => 'dall-e-3',
					'data'    => array(
						array(
							'url'            => $image_url,
							'revised_prompt' => 'A helpful assistant robot',
						),
					),
				);

				return array(
					'body'     => wp_json_encode( $payload ),
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'application/json' ),
				);
			}

			if ( $image_url === $url ) {
				return array(
					'body'     => $png_binary,
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'image/png' ),
				);
			}

			return $preempt;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'prompt' => 'A robotic assistant drafting schematics',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::IMAGES_ENDPOINT, $captured_request['url'] );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'response_format', $payload );
		$this->assertSame( 'url', $payload['response_format'] );

		$this->assertIsArray( $result );
		$this->assertSame( 'dall-e-3', $result['model'] );
		$this->assertSame( 'url', $result['response_format'] );
		$this->assertSame( 'A helpful assistant robot', $result['revised_prompt'] );

		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * The tool should clamp custom timeouts to the documented schema maximum.
	 */
	public function test_execute_clamps_timeout_to_schema_maximum() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
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
				'created' => 123,
				'model'   => 'gpt-image-1',
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
				'prompt'  => 'An image with a custom timeout',
				'timeout' => 999,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertSame( 300, $captured_request['args']['timeout'] );

		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * The tool should use the model returned by OpenAI if it differs from the requested model.
	 */
	public function test_execute_uses_model_from_api_response() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
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

			// Simulate OpenAI returning a different model (e.g., due to fallback or upgrade)
			$payload = array(
				'created' => 789,
				'model'   => 'dall-e-3',
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
				'prompt' => 'A test image',
				'model'  => 'gpt-image-1',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertSame( 'gpt-image-1', $payload['model'] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'model', $result );
		// The result should reflect the model actually returned by OpenAI.
		$this->assertSame( 'dall-e-3', $result['model'] );

		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * The tool should include inline base64 content for immediate rendering.
	 */
	/**
	 * Test that execute() does NOT include inline content payload by default.
	 *
	 * Inline content (base64 encoded image data) should not be included in the
	 * default response to prevent bloating tool results sent to chat clients and LLMs.
	 */
	public function test_execute_does_not_include_inline_content_by_default() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool       = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$png_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';
		$png_binary = base64_decode( $png_base64 );

		$http_stub = function ( $preempt, $args, $url ) use ( $png_base64 ) {
			$payload = array(
				'created' => time(),
				'model'   => 'gpt-image-1',
				'data'    => array(
					array(
						'b64_json'       => $png_base64,
						'revised_prompt' => 'A test image',
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
			array( 'prompt' => 'A test image' ),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		// Verify that inline content is NOT included by default to prevent bloating responses.
		$this->assertArrayNotHasKey( 'content', $result, 'Inline base64 content should not be included by default' );
		// Verify that essential metadata is still present.
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'file_name', $result );

		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Test that gpt-image-1 model accepts 'medium' quality (its default).
	 */
	public function test_gpt_image_1_accepts_medium_quality() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
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
				'created' => 123,
				'model'   => 'gpt-image-1',
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
				'prompt'  => 'A test image',
				'model'   => 'gpt-image-1',
				'quality' => 'medium',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertSame( 'medium', $payload['quality'] );
		$this->assertSame( 'medium', $result['quality'] );

		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Test that gpt-image-1 model accepts 'high' quality.
	 */
	public function test_gpt_image_1_accepts_high_quality() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
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
				'created' => 123,
				'model'   => 'gpt-image-1',
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
				'prompt'  => 'A high quality test image',
				'model'   => 'gpt-image-1',
				'quality' => 'high',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertSame( 'high', $payload['quality'] );
		$this->assertSame( 'high', $result['quality'] );

		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Test that invalid quality for gpt-image-1 falls back to model default (medium).
	 */
	public function test_gpt_image_1_invalid_quality_falls_back_to_default() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
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
				'created' => 123,
				'model'   => 'gpt-image-1',
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

		// Try to use 'standard' (valid for DALL-E) with gpt-image-1 (should fall back to 'medium').
		$result = $tool->execute(
			array(
				'prompt'  => 'A test image with invalid quality',
				'model'   => 'gpt-image-1',
				'quality' => 'standard',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$payload = json_decode( $captured_request['args']['body'], true );
		// Should have fallen back to 'medium' (gpt-image-1's default).
		$this->assertSame( 'medium', $payload['quality'] );
		$this->assertSame( 'medium', $result['quality'] );

		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Test that DALL-E 3 'hd' quality is sanitized to 'medium' (quality mapping patch).
	 */
	public function test_dalle_3_hd_quality_sanitized_to_medium() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
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
				'created' => 123,
				'model'   => 'dall-e-3',
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

		// Try to use 'hd' - it should be sanitized to 'medium'.
		$result = $tool->execute(
			array(
				'prompt'  => 'A high-def test image',
				'model'   => 'dall-e-3',
				'quality' => 'hd',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$payload = json_decode( $captured_request['args']['body'], true );
		// 'hd' should be sanitized to 'medium'.
		$this->assertSame( 'medium', $payload['quality'] );
		$this->assertSame( 'medium', $result['quality'] );

		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Test that quality sanitization guards against invalid values.
	 */
	public function test_quality_sanitization_guards_against_invalid_values() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool       = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$png_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';

		$invalid_qualities = array( 'standard', 'hd', 'ultra', 'best', '', null, 'invalid' );

		foreach ( $invalid_qualities as $invalid_quality ) {
			$captured_request = null;

			$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64 ) {
				$captured_request = array(
					'args' => $args,
					'url'  => $url,
				);

				$payload = array(
					'created' => time(),
					'model'   => 'gpt-image-1',
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
					'prompt'  => 'Test image with invalid quality',
					'quality' => $invalid_quality,
				),
				array( 'user_id' => $user_id )
			);

			remove_filter( 'pre_http_request', $http_stub, 10 );

			$this->assertNotNull( $captured_request, 'Request not captured for quality: ' . var_export( $invalid_quality, true ) );
			$payload = json_decode( $captured_request['args']['body'], true );
			// All invalid qualities should fall back to 'medium'.
			$this->assertSame( 'medium', $payload['quality'], "Quality should be 'medium' for invalid value: " . var_export( $invalid_quality, true ) );

			if ( ! empty( $result['attachment_id'] ) ) {
				wp_delete_attachment( $result['attachment_id'], true );
			}
		}
	}

	/**
	 * Test that the tool returns estimated usage and cost data for UI display.
	 */
	public function test_execute_returns_usage_and_cost_data() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool       = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$png_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';

		$http_stub = function ( $preempt, $args, $url ) use ( $png_base64 ) {
			$payload = array(
				'created' => 123,
				'model'   => 'gpt-image-1',
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
				'prompt'  => 'A test image',
				'model'   => 'gpt-image-1',
				'size'    => '1024x1024',
				'quality' => 'medium',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );

		// Verify usage data is present and estimated.
		$this->assertArrayHasKey( 'usage', $result, 'Result should include usage data' );
		$this->assertIsArray( $result['usage'] );
		$this->assertArrayHasKey( 'prompt_tokens', $result['usage'] );
		$this->assertArrayHasKey( 'completion_tokens', $result['usage'] );
		$this->assertArrayHasKey( 'total_tokens', $result['usage'] );
		$this->assertArrayHasKey( 'is_estimated', $result['usage'] );
		$this->assertTrue( $result['usage']['is_estimated'], 'Usage should be marked as estimated' );
		$this->assertGreaterThan( 0, $result['usage']['total_tokens'], 'Total tokens should be greater than 0' );

		// Verify cost data is present and estimated.
		$this->assertArrayHasKey( 'cost', $result, 'Result should include cost data' );
		$this->assertIsArray( $result['cost'] );
		$this->assertArrayHasKey( 'cost_usd', $result['cost'] );
		$this->assertArrayHasKey( 'is_estimated', $result['cost'] );
		$this->assertArrayHasKey( 'provider', $result['cost'] );
		$this->assertArrayHasKey( 'model', $result['cost'] );
		$this->assertTrue( $result['cost']['is_estimated'], 'Cost should be marked as estimated' );
		$this->assertGreaterThan( 0, $result['cost']['cost_usd'], 'Cost should be greater than 0' );
		$this->assertSame( 'openai', $result['cost']['provider'] );
		$this->assertSame( 'gpt-image-1', $result['cost']['model'] );

		// Verify provider is set at top level for normalization functions.
		$this->assertArrayHasKey( 'provider', $result );
		$this->assertSame( 'openai', $result['provider'] );

		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}
}
