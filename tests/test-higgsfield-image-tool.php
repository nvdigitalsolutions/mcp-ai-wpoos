<?php
/**
 * Test suite for the Higgsfield image generation tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Tool_Generate_Higgsfield_Image.
 */
class Test_Higgsfield_Image_Tool extends WP_UnitTestCase {

	/**
	 * Zero out the polling delay so HTTP-mocked tests run instantly.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'wp_mcp_ai_higgsfield_poll_initial_delay', '__return_zero' );
	}

	/**
	 * Clean up HTTP mocks.
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
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Image();

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Generate_Higgsfield_Image', $tool );
	}

	/**
	 * Test tool slug.
	 */
	public function test_tool_slug() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Image();

		$this->assertEquals( 'generate_higgsfield_image', $tool->get_slug() );
	}

	/**
	 * Test parameters schema exposes the verified workflows.
	 */
	public function test_parameters_schema() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php';
		$tool   = new WP_MCP_AI_Tool_Generate_Higgsfield_Image();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'prompt', $schema['properties'] );
		$this->assertArrayHasKey( 'style_id', $schema['properties'] );
		$this->assertArrayHasKey( 'batch_size', $schema['properties'] );
		$this->assertContains( 'prompt', $schema['required'] );

		$this->assertEquals( array( 'soul-2', 'soul-cinema' ), $schema['properties']['model']['enum'] );
		$this->assertEquals( array( 1, 4 ), $schema['properties']['batch_size']['enum'] );
		$this->assertContains( '9:16', $schema['properties']['aspect_ratio']['enum'] );
		$this->assertContains( '2:3', $schema['properties']['aspect_ratio']['enum'] );
	}

	/**
	 * Test the model catalog endpoints are the documented ones.
	 */
	public function test_model_catalog_endpoints() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php';

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Generate_Higgsfield_Image' );
		$models     = $reflection->getConstant( 'IMAGE_MODELS' );

		$this->assertEquals( '/higgsfield-ai/soul/v2/standard', $models['soul-2']['endpoint'] );
		$this->assertEquals( '/higgsfield-ai/soul/cinema', $models['soul-cinema']['endpoint'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php';
		$tool  = new WP_MCP_AI_Tool_Generate_Higgsfield_Image();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'background-only', $flags );
	}

	/**
	 * Test model requirements.
	 */
	public function test_model_requirements() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php';
		$tool         = new WP_MCP_AI_Tool_Generate_Higgsfield_Image();
		$requirements = $tool->get_model_requirements();

		$this->assertContains( 'image-generation', $requirements );
	}

	/**
	 * Test execution without prompt returns error.
	 */
	public function test_execute_without_prompt() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Image();

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
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Image();

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $tool->execute(
			array( 'prompt' => 'Test image' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test the sync flow returns provider URLs when save_to_media is false.
	 */
	public function test_sync_flow_without_media_save() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Image();

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
								'images'     => array(
									array( 'url' => 'https://cdn.example.com/1.png' ),
								),
							)
						),
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
				'prompt'        => 'Editorial portrait in soft daylight',
				'model'         => 'soul-cinema',
				'batch_size'    => 1,
				'save_to_media' => false,
				'async'         => false,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'https://cdn.example.com/1.png', $result['urls'][0] );
		$this->assertSame( 'soul-cinema', $result['model'] );

		$submit_body = json_decode( $captured['body'][0], true );
		$this->assertStringContainsString( '/higgsfield-ai/soul/cinema', $captured['url'][0] );
		$this->assertSame( 'Editorial portrait in soft daylight', $submit_body['prompt'] );
	}

	/**
	 * Test style_id is forwarded for soul-2 but ignored for soul-cinema.
	 */
	public function test_style_id_model_gating() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Image();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'build_request_payload' );
		$method->setAccessible( true );

		$soul_2 = $method->invoke(
			$tool,
			array(
				'prompt'   => 'Test',
				'model'    => 'soul-2',
				'style_id' => '3db34ab5-3439-4317-9e03-08dc30852e69',
			)
		);
		$this->assertSame( '3db34ab5-3439-4317-9e03-08dc30852e69', $soul_2['payload']['style_id'] );

		$soul_cinema = $method->invoke(
			$tool,
			array(
				'prompt'   => 'Test',
				'model'    => 'soul-cinema',
				'style_id' => '3db34ab5-3439-4317-9e03-08dc30852e69',
			)
		);
		$this->assertArrayNotHasKey( 'style_id', $soul_cinema['payload'] );
	}

	/**
	 * Test LLM sanitizer keeps metadata and drops extras.
	 */
	public function test_sanitize_for_llm() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Image();

		$result = array(
			'success'        => true,
			'attachment_ids' => array( 10, 11 ),
			'urls'           => array( 'https://example.com/1.png' ),
			'model'          => 'soul-2',
			'provider'       => 'higgsfield',
			'request_id'     => 'req-1',
			'some_extra'     => 'data',
		);

		$sanitized = $tool->sanitize_for_llm( $result );

		$this->assertIsArray( $sanitized );
		$this->assertArrayHasKey( 'attachment_ids', $sanitized );
		$this->assertArrayHasKey( 'model', $sanitized );
		$this->assertArrayHasKey( 'request_id', $sanitized );
		$this->assertArrayNotHasKey( 'some_extra', $sanitized );
	}

	/**
	 * Test async pending metadata shape.
	 */
	public function test_async_pending_metadata() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php';
		$tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Image();

		$metadata = $tool->get_async_pending_metadata( 'async_img1', array(), array() );

		$this->assertIsArray( $metadata );
		$this->assertArrayHasKey( 'expected_url', $metadata );
		$this->assertArrayHasKey( 'expected_filename', $metadata );
		$this->assertStringContainsString( 'higgsfield-image-async_img1-1.jpg', $metadata['expected_filename'] );
	}
}
