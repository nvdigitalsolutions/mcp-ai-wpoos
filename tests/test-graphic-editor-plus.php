<?php
/**
 * Tests for Graphic Editor Plus tool.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-graphic-editor-plus.php';

/**
 * Test class for Graphic Editor Plus tool.
 */
class WP_MCP_AI_Graphic_Editor_Plus_Test extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Test image attachment ID.
	 *
	 * @var int
	 */
	protected $test_image_id;

	/**
	 * Test logo attachment ID.
	 *
	 * @var int
	 */
	protected $test_logo_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test user with upload capabilities.
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		// Create test images.
		$this->test_image_id = $this->create_test_image( 'test-image.png', 800, 600 );
		$this->test_logo_id  = $this->create_test_image( 'test-logo.png', 200, 100 );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clean up attachments.
		if ( $this->test_image_id ) {
			wp_delete_attachment( $this->test_image_id, true );
		}
		if ( $this->test_logo_id ) {
			wp_delete_attachment( $this->test_logo_id, true );
		}

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that tool has correct slug.
	 */
	public function test_get_slug() {
		$tool = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$this->assertSame( 'graphic_editor_plus', $tool->get_slug() );
	}

	/**
	 * Test that tool has correct name.
	 */
	public function test_get_name() {
		$tool = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$this->assertIsString( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test that tool has correct description.
	 */
	public function test_get_description() {
		$tool = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$this->assertIsString( $tool->get_description() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test that tool has valid parameters schema.
	 */
	public function test_get_parameters_schema() {
		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'operation', $schema['properties'] );
		$this->assertArrayHasKey( 'logo_attachment_id', $schema['properties'] );
		$this->assertArrayHasKey( 'target_width', $schema['properties'] );
		$this->assertArrayHasKey( 'expand_direction', $schema['properties'] );
	}

	/**
	 * Test that tool has correct capability flags.
	 */
	public function test_get_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'mixed-mode', $flags );
		$this->assertContains( 'pro-tool', $flags );
	}

	/**
	 * Test that unauthenticated users cannot execute tool.
	 */
	public function test_execute_requires_authentication() {
		wp_set_current_user( 0 );
		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$result = $tool->execute(
			array(
				'operation'     => 'add_logo',
				'attachment_id' => $this->test_image_id,
			),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that users without upload_files capability cannot execute tool.
	 */
	public function test_execute_requires_upload_files_capability() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$result = $tool->execute(
			array(
				'operation'     => 'add_logo',
				'attachment_id' => $this->test_image_id,
			),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		wp_delete_user( $subscriber_id );
	}

	/**
	 * Test that invalid operation returns error.
	 */
	public function test_execute_invalid_operation() {
		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$result = $tool->execute(
			array(
				'operation'     => 'invalid_operation',
				'attachment_id' => $this->test_image_id,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_operation', $result->get_error_code() );
	}

	/**
	 * Test add logo operation with valid parameters.
	 */
	public function test_execute_add_logo_success() {
		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$result = $tool->execute(
			array(
				'operation'          => 'add_logo',
				'attachment_id'      => $this->test_image_id,
				'logo_attachment_id' => $this->test_logo_id,
				'logo_position'      => 'bottom-left',
				'logo_scale'         => 0.2,
				'logo_margin'        => 20,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'operation', $result );
		$this->assertSame( 'add_logo', $result['operation'] );
		$this->assertArrayHasKey( 'logo_position', $result );
		$this->assertSame( 'bottom-left', $result['logo_position'] );
		$this->assertArrayHasKey( 'text', $result );

		// Clean up created attachment.
		if ( isset( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Test add logo operation without logo source returns error.
	 */
	public function test_execute_add_logo_missing_logo() {
		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$result = $tool->execute(
			array(
				'operation'     => 'add_logo',
				'attachment_id' => $this->test_image_id,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_logo', $result->get_error_code() );
	}

	/**
	 * Test resize graphic operation with valid parameters.
	 */
	public function test_execute_resize_graphic_success() {
		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$result = $tool->execute(
			array(
				'operation'      => 'resize_graphic',
				'attachment_id'  => $this->test_image_id,
				'target_width'   => 400,
				'target_height'  => 300,
				'output_format'  => 'png',
				'maintain_ratio' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'operation', $result );
		$this->assertSame( 'resize_graphic', $result['operation'] );
		$this->assertArrayHasKey( 'original_width', $result );
		$this->assertArrayHasKey( 'original_height', $result );
		$this->assertArrayHasKey( 'new_width', $result );
		$this->assertArrayHasKey( 'new_height', $result );
		$this->assertArrayHasKey( 'output_format', $result );
		$this->assertSame( 'png', $result['output_format'] );
		$this->assertArrayHasKey( 'text', $result );

		// Clean up created attachment.
		if ( isset( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Test resize graphic with different format conversion.
	 */
	public function test_execute_resize_graphic_format_conversion() {
		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$result = $tool->execute(
			array(
				'operation'      => 'resize_graphic',
				'attachment_id'  => $this->test_image_id,
				'target_width'   => 400,
				'output_format'  => 'jpg',
				'maintain_ratio' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'output_format', $result );
		// jpg should be converted to jpeg internally.
		$this->assertSame( 'jpeg', $result['output_format'] );

		// Clean up created attachment.
		if ( isset( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Test resize graphic without dimensions returns error.
	 */
	public function test_execute_resize_graphic_missing_dimensions() {
		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$result = $tool->execute(
			array(
				'operation'     => 'resize_graphic',
				'attachment_id' => $this->test_image_id,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_dimensions', $result->get_error_code() );
	}

	/**
	 * Test expand scene operation with valid parameters.
	 */
	public function test_execute_expand_scene_success() {
		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$result = $tool->execute(
			array(
				'operation'        => 'expand_scene',
				'attachment_id'    => $this->test_image_id,
				'expand_direction' => 'all',
				'expand_pixels'    => 50,
				'background_color' => 'transparent',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'operation', $result );
		$this->assertSame( 'expand_scene', $result['operation'] );
		$this->assertArrayHasKey( 'direction', $result );
		$this->assertSame( 'all', $result['direction'] );
		$this->assertArrayHasKey( 'pixels', $result );
		$this->assertSame( 50, $result['pixels'] );
		$this->assertArrayHasKey( 'original_width', $result );
		$this->assertArrayHasKey( 'original_height', $result );
		$this->assertArrayHasKey( 'new_width', $result );
		$this->assertArrayHasKey( 'new_height', $result );
		$this->assertSame( $result['original_width'] + 100, $result['new_width'] );
		$this->assertSame( $result['original_height'] + 100, $result['new_height'] );
		$this->assertArrayHasKey( 'text', $result );

		// Clean up created attachment.
		if ( isset( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Test expand scene operation with invalid direction returns error.
	 */
	public function test_execute_expand_scene_invalid_direction() {
		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$result = $tool->execute(
			array(
				'operation'        => 'expand_scene',
				'attachment_id'    => $this->test_image_id,
				'expand_direction' => 'diagonal',
				'expand_pixels'    => 50,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_direction', $result->get_error_code() );
	}

	/**
	 * Test position calculation for different positions.
	 */
	public function test_calculate_position() {
		$tool       = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$image_size = array(
			'width'  => 1000,
			'height' => 800,
		);
		$logo_size  = array(
			'width'  => 200,
			'height' => 100,
		);
		$margin     = 20;
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'calculate_position' );
		$method->setAccessible( true );

		// Test bottom-left.
		$coords = $method->invoke( $tool, $image_size, $logo_size, 'bottom-left', $margin );
		$this->assertSame( 20, $coords['x'] );
		$this->assertSame( 700, $coords['y'] );

		// Test top-right.
		$coords = $method->invoke( $tool, $image_size, $logo_size, 'top-right', $margin );
		$this->assertSame( 780, $coords['x'] );
		$this->assertSame( 20, $coords['y'] );

		// Test center.
		$coords = $method->invoke( $tool, $image_size, $logo_size, 'center', $margin );
		$this->assertSame( 400, $coords['x'] );
		$this->assertSame( 350, $coords['y'] );
	}

	/**
	 * Test expansion calculation for different directions.
	 */
	public function test_calculate_expansion() {
		$tool          = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$original_size = array(
			'width'  => 800,
			'height' => 600,
		);
		$pixels        = 50;
		$reflection    = new ReflectionClass( $tool );
		$method        = $reflection->getMethod( 'calculate_expansion' );
		$method->setAccessible( true );

		// Test 'all' direction.
		$expansion = $method->invoke( $tool, $original_size, 'all', $pixels );
		$this->assertSame( 900, $expansion['new_width'] );
		$this->assertSame( 700, $expansion['new_height'] );
		$this->assertSame( 50, $expansion['offset_x'] );
		$this->assertSame( 50, $expansion['offset_y'] );

		// Test 'left' direction.
		$expansion = $method->invoke( $tool, $original_size, 'left', $pixels );
		$this->assertSame( 850, $expansion['new_width'] );
		$this->assertSame( 600, $expansion['new_height'] );
		$this->assertSame( 50, $expansion['offset_x'] );
		$this->assertSame( 0, $expansion['offset_y'] );

		// Test 'horizontal' direction.
		$expansion = $method->invoke( $tool, $original_size, 'horizontal', $pixels );
		$this->assertSame( 900, $expansion['new_width'] );
		$this->assertSame( 600, $expansion['new_height'] );
		$this->assertSame( 50, $expansion['offset_x'] );
	}

	/**
	 * Test hex color parsing.
	 */
	public function test_parse_hex_color() {
		$tool       = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'parse_hex_color' );
		$method->setAccessible( true );

		// Test 6-digit hex.
		$color = $method->invoke( $tool, '#FF0000' );
		$this->assertSame( 255, $color['r'] );
		$this->assertSame( 0, $color['g'] );
		$this->assertSame( 0, $color['b'] );

		// Test 6-digit hex without #.
		$color = $method->invoke( $tool, '00FF00' );
		$this->assertSame( 0, $color['r'] );
		$this->assertSame( 255, $color['g'] );
		$this->assertSame( 0, $color['b'] );

		// Test 3-digit hex.
		$color = $method->invoke( $tool, '#F0F' );
		$this->assertSame( 255, $color['r'] );
		$this->assertSame( 0, $color['g'] );
		$this->assertSame( 255, $color['b'] );

		// Test invalid hex.
		$color = $method->invoke( $tool, 'ZZZZZZ' );
		$this->assertWPError( $color );
	}

	/**
	 * Test AI operations require prompt parameter.
	 */
	public function test_ai_operations_require_prompt() {
		$ai_operations = array( 'ai_enhance', 'ai_style', 'ai_background', 'ai_retouch' );

		foreach ( $ai_operations as $operation ) {
			$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
			$result = $tool->execute(
				array(
					'operation'     => $operation,
					'attachment_id' => $this->test_image_id,
				),
				array( 'user_id' => $this->user_id )
			);

			$this->assertWPError( $result, "Operation {$operation} should require prompt" );
			$this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
		}
	}

	/**
	 * Test AI enhance operation with mocked Gemini response.
	 */
	public function test_execute_ai_enhance_with_mock() {
		// Set up Gemini API key.
		$settings                   = array();
		$settings['gemini_api_key'] = 'test-api-key';
		update_option( 'wp_mcp_ai_settings', $settings );

		$png_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwsB9YwH0e0AAAAASUVORK5CYII=';

		// Mock HTTP response.
		$http_stub = function ( $preempt, $args, $url ) use ( $png_base64 ) {
			// Only intercept Gemini API calls.
			if ( false === strpos( $url, 'generativelanguage.googleapis.com' ) ) {
				return $preempt;
			}

			$payload = array(
				'candidates' => array(
					array(
						'content' => array(
							'parts' => array(
								array(
									'inlineData' => array(
										'data'     => $png_base64,
										'mimeType' => 'image/png',
									),
								),
							),
						),
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

		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$result = $tool->execute(
			array(
				'operation'     => 'ai_enhance',
				'attachment_id' => $this->test_image_id,
				'prompt'        => 'enhance brightness and contrast',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );
		delete_option( 'wp_mcp_ai_settings' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertArrayHasKey( 'operation', $result );
		$this->assertSame( 'ai_enhance', $result['operation'] );
		$this->assertArrayHasKey( 'prompt', $result );
		$this->assertArrayHasKey( 'text', $result );

		// Clean up.
		if ( isset( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Test AI style operation with mocked Gemini response.
	 */
	public function test_execute_ai_style_with_mock() {
		// Set up Gemini API key.
		$settings                   = array();
		$settings['gemini_api_key'] = 'test-api-key';
		update_option( 'wp_mcp_ai_settings', $settings );

		$png_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwsB9YwH0e0AAAAASUVORK5CYII=';

		// Mock HTTP response.
		$http_stub = function ( $preempt, $args, $url ) use ( $png_base64 ) {
			if ( false === strpos( $url, 'generativelanguage.googleapis.com' ) ) {
				return $preempt;
			}

			$payload = array(
				'candidates' => array(
					array(
						'content' => array(
							'parts' => array(
								array(
									'inlineData' => array(
										'data'     => $png_base64,
										'mimeType' => 'image/png',
									),
								),
							),
						),
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

		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$result = $tool->execute(
			array(
				'operation'     => 'ai_style',
				'attachment_id' => $this->test_image_id,
				'prompt'        => 'convert to watercolor painting',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );
		delete_option( 'wp_mcp_ai_settings' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'operation', $result );
		$this->assertSame( 'ai_style', $result['operation'] );

		// Clean up.
		if ( isset( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Test AI background operation requires prompt.
	 */
	public function test_execute_ai_background_requires_prompt() {
		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$result = $tool->execute(
			array(
				'operation'     => 'ai_background',
				'attachment_id' => $this->test_image_id,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * Test AI retouch operation requires prompt.
	 */
	public function test_execute_ai_retouch_requires_prompt() {
		$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
		$result = $tool->execute(
			array(
				'operation'     => 'ai_retouch',
				'attachment_id' => $this->test_image_id,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * Helper function to create a test image attachment.
	 *
	 * @param string $filename Filename.
	 * @param int    $width    Image width.
	 * @param int    $height   Image height.
	 * @return int Attachment ID.
	 */
	protected function create_test_image( $filename, $width, $height ) {
		// Create a simple PNG image.
		$image = imagecreatetruecolor( $width, $height );

		// Enable alpha channel.
		imagesavealpha( $image, true );
		imagealphablending( $image, false );

		// Fill with a color (red for main image, blue for logo).
		$color = ( false !== strpos( $filename, 'logo' ) )
			? imagecolorallocatealpha( $image, 0, 0, 255, 50 )  // Semi-transparent blue.
			: imagecolorallocate( $image, 255, 0, 0 );          // Red.

		imagefill( $image, 0, 0, $color );

		// Save to temp file.
		$upload_dir = wp_upload_dir();
		$temp_file  = $upload_dir['path'] . '/' . $filename;
		imagepng( $image, $temp_file );
		imagedestroy( $image );

		// Create attachment.
		$attachment = array(
			'guid'           => $upload_dir['url'] . '/' . $filename,
			'post_mime_type' => 'image/png',
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $temp_file );

		// Generate attachment metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attach_id, $temp_file );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		return $attach_id;
	}
}
