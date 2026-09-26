<?php
/**
 * Test suite for Higgsfield video generation tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Tool_Generate_Higgsfield_Video.
 */
class Test_Higgsfield_Video_Tool extends WP_UnitTestCase {
	/**
	 * Zero out the polling delay so HTTP-mocked tests run instantly.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'wp_mcp_ai_higgsfield_poll_initial_delay', '__return_zero' );
	}

	/**
	 * Remove any HTTP mocks registered by tests in this suite.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_higgsfield_poll_initial_delay' );
		parent::tear_down();
	}

	/**
	 * Test tool instantiation.
	 */
	public function test_tool_instantiation() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Generate_Higgsfield_Video', $tool );
	}

	/**
	 * Test tool slug.
	 */
	public function test_tool_slug() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();

		$this->assertEquals( 'generate_higgsfield_video', $tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_tool_name() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();

		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test tool description.
	 */
	public function test_tool_description() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();

		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test parameters schema.
	 */
	public function test_parameters_schema() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';
		$tool   = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'prompt', $schema['properties'] );
		$this->assertArrayHasKey( 'model', $schema['properties'] );
		$this->assertArrayHasKey( 'duration', $schema['properties'] );
		$this->assertArrayHasKey( 'resolution', $schema['properties'] );
		$this->assertArrayHasKey( 'aspect_ratio', $schema['properties'] );
		$this->assertArrayHasKey( 'generate_audio', $schema['properties'] );
		$this->assertArrayHasKey( 'reference_video_url', $schema['properties'] );
		$this->assertArrayHasKey( 'reference_audio_url', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'prompt', $schema['required'] );

		// The model catalog must expose the five verified models.
		$this->assertEquals(
			array( 'cinema-studio-4.0', 'seedance-2.5', 'seedance-2.0', 'wan-3.0', 'kling-3.0' ),
			$schema['properties']['model']['enum']
		);

		// Union enums span the whole catalog; per-model clamping happens at runtime.
		$this->assertContains( '1080p', $schema['properties']['resolution']['enum'] );
		$this->assertContains( '4k', $schema['properties']['resolution']['enum'] );
		$this->assertContains( 'adaptive', $schema['properties']['aspect_ratio']['enum'] );
		$this->assertContains( '21:9', $schema['properties']['aspect_ratio']['enum'] );
		$this->assertContains( 'helicopter-shot', $schema['properties']['camera_movement']['enum'] );
	}

	/**
	 * Test the model catalog endpoints are the documented ones.
	 */
	public function test_model_catalog_endpoints() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Generate_Higgsfield_Video' );
		$models     = $reflection->getConstant( 'VIDEO_MODELS' );

		$this->assertEquals( '/higgsfield/cinema-studio/4.0', $models['cinema-studio-4.0']['endpoint_t2v'] );
		$this->assertEquals( '/bytedance/seedance-2.5/text-to-video', $models['seedance-2.5']['endpoint_t2v'] );
		$this->assertEquals( '/bytedance/seedance-2.5/reference-to-video', $models['seedance-2.5']['endpoint_ref'] );
		$this->assertEquals( '/bytedance/seedance-2.0/text-to-video', $models['seedance-2.0']['endpoint_t2v'] );
		$this->assertEquals( '/alibaba/wan-3.0/text-to-video', $models['wan-3.0']['endpoint_t2v'] );
		$this->assertEquals( '/kling-video/v3.0/std/text-to-video', $models['kling-3.0']['endpoint_t2v'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';
		$tool  = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'async', $flags );
		$this->assertContains( 'long-running', $flags );
		$this->assertContains( 'background-only', $flags );
	}

	/**
	 * Test model requirements.
	 */
	public function test_model_requirements() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';
		$tool         = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();
		$requirements = $tool->get_model_requirements();

		$this->assertIsArray( $requirements );
		$this->assertContains( 'video-generation', $requirements );
	}

	/**
	 * Test execution without prompt returns error.
	 */
	public function test_execute_without_prompt() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * Test execution without upload_files capability returns error.
	 */
	public function test_execute_without_upload_capability() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $tool->execute(
			array( 'prompt' => 'Test video' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test reference media with an unsupported model (kling-3.0) returns an error.
	 */
	public function test_references_with_unsupported_model() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = $tool->execute(
			array(
				'prompt'              => 'Test video',
				'model'               => 'kling-3.0',
				'reference_image_url' => 'https://example.com/input.jpg',
				'async'               => false,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_higgsfield_refs_unsupported', $result->get_error_code() );
	}

	/**
	 * Test the wan-3.0 payload mapping (resolution kept, seed forwarded, refs routed).
	 */
	public function test_wan_model_payload_mapping() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		update_option(
			'wp_mcp_ai_settings',
			array(
				'higgsfield_api_key_id'     => 'key-1',
				'higgsfield_api_key_secret' => 'secret-1',
			)
		);
		if ( method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		$captured = array();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured ) {
				$captured['url'][]  = $url;
				$captured['body'][] = isset( $args['body'] ) ? $args['body'] : null;

				if ( false !== strpos( $url, '/requests/' ) && false !== strpos( $url, '/status' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'status'     => 'completed',
								'request_id' => 'req-1',
								'video'      => array( 'url' => 'https://example.com/video.mp4' ),
							)
						),
					);
				}

				if ( false !== strpos( $url, 'example.com/video.mp4' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => 'FAKEVIDEOBINARYDATA',
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'status'     => 'queued',
							'request_id' => 'req-1',
							'status_url' => 'https://api.higgsfield.ai/requests/req-1/status',
							'cancel_url' => 'https://api.higgsfield.ai/requests/req-1/cancel',
						)
					),
				);
			},
			10,
			3
		);

		$result = $tool->execute(
			array(
				'prompt'         => 'Ocean waves at sunset',
				'model'          => 'wan-3.0',
				'duration'       => 25,
				'resolution'     => '1080p',
				'seed'           => 42,
				'save_to_media'  => false,
				'async'          => false,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$submit_url  = $captured['url'][0];
		$submit_body = json_decode( $captured['body'][0], true );

		$this->assertStringContainsString( '/alibaba/wan-3.0/text-to-video', $submit_url );
		$this->assertSame( 'Ocean waves at sunset', $submit_body['prompt'] );
		$this->assertSame( 25, $submit_body['duration'] );
		$this->assertSame( '1080p', $submit_body['resolution'] );
		$this->assertSame( 42, $submit_body['seed'] );
	}

	/**
	 * Test the kling-3.0 payload mapping (sound on/off, no resolution field).
	 */
	public function test_kling_model_payload_mapping() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		update_option(
			'wp_mcp_ai_settings',
			array(
				'higgsfield_api_key_id'     => 'key-1',
				'higgsfield_api_key_secret' => 'secret-1',
			)
		);
		if ( method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		$captured = array();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured ) {
				$captured['url'][]  = $url;
				$captured['body'][] = isset( $args['body'] ) ? $args['body'] : null;

				if ( false !== strpos( $url, '/requests/' ) && false !== strpos( $url, '/status' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'status'     => 'completed',
								'request_id' => 'req-1',
								'video'      => array( 'url' => 'https://example.com/video.mp4' ),
							)
						),
					);
				}

				if ( false !== strpos( $url, 'example.com/video.mp4' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => 'FAKEVIDEOBINARYDATA',
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'status'     => 'queued',
							'request_id' => 'req-1',
							'status_url' => 'https://api.higgsfield.ai/requests/req-1/status',
							'cancel_url' => 'https://api.higgsfield.ai/requests/req-1/cancel',
						)
					),
				);
			},
			10,
			3
		);

		$result = $tool->execute(
			array(
				'prompt'         => 'Coastal road',
				'model'          => 'kling-3.0',
				'duration'       => 12,
				'generate_audio' => false,
				'save_to_media'  => false,
				'async'          => false,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$submit_url  = $captured['url'][0];
		$submit_body = json_decode( $captured['body'][0], true );

		$this->assertStringContainsString( '/kling-video/v3.0/std/text-to-video', $submit_url );
		$this->assertSame( 'off', $submit_body['sound'] );
		$this->assertArrayNotHasKey( 'resolution', $submit_body );
		$this->assertArrayNotHasKey( 'generate_audio', $submit_body );
	}

	/**
	 * Test LLM sanitizer strips video data.
	 */
	public function test_sanitize_for_llm() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();

		$result = array(
			'success'    => true,
			'video_url'  => 'data:video/mp4;base64,dGVzdA==',
			'prompt'     => 'Test prompt',
			'model'      => 'cinema-studio-4.0',
			'provider'   => 'higgsfield',
			'request_id' => 'd7e6c0f3-6699-4f6c-bb45-2ad7fd9158ff',
			'some_extra' => 'data',
		);

		$sanitized = $tool->sanitize_for_llm( $result );

		$this->assertIsArray( $sanitized );
		$this->assertArrayNotHasKey( 'video_url', $sanitized );
		$this->assertArrayHasKey( 'video_data_stripped', $sanitized );
		$this->assertTrue( $sanitized['video_data_stripped'] );
		$this->assertArrayHasKey( 'success', $sanitized );
		$this->assertArrayHasKey( 'prompt', $sanitized );
		$this->assertArrayHasKey( 'model', $sanitized );
		$this->assertArrayHasKey( 'provider', $sanitized );
		$this->assertArrayHasKey( 'request_id', $sanitized );
	}

	/**
	 * Test async pending metadata shape.
	 */
	public function test_async_pending_metadata() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();

		$metadata = $tool->get_async_pending_metadata( 'async_test123', array(), array() );

		$this->assertIsArray( $metadata );
		$this->assertArrayHasKey( 'expected_url', $metadata );
		$this->assertArrayHasKey( 'expected_filename', $metadata );
		$this->assertArrayHasKey( 'message', $metadata );
		$this->assertStringContainsString( 'higgsfield-video-async_test123.mp4', $metadata['expected_filename'] );
	}
}
