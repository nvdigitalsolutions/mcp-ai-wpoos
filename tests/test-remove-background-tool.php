<?php
/**
 * Tests for Remove Background tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Remove Background tool functionality.
 */
class Test_Remove_Background_Tool extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Remove_Background
	 */
	protected $tool;

	/**
	 * Test image attachment ID.
	 *
	 * @var int
	 */
	protected $test_image_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the tool class.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-remove-background.php';
		$this->tool = new WP_MCP_AI_Tool_Remove_Background();

		// Create a test image.
		$this->test_image_id = $this->create_test_image();
	}

	/**
	 * Clean up test fixtures.
	 */
	public function tearDown(): void {
		// Clean up test image.
		if ( $this->test_image_id ) {
			wp_delete_attachment( $this->test_image_id, true );
		}

		parent::tearDown();
	}

	/**
	 * Create a test image attachment.
	 *
	 * @return int Attachment ID.
	 */
	protected function create_test_image() {
		// Create a simple test image.
		$upload_dir = wp_upload_dir();
		$filename   = $upload_dir['path'] . '/test-image-' . time() . '.png';

		// Create a simple 100x100 PNG image.
		$image = imagecreate( 100, 100 );
		if ( ! $image ) {
			$this->fail( 'Failed to create test image' );
		}

		// Set background color (red).
		$red = imagecolorallocate( $image, 255, 0, 0 );
		imagefill( $image, 0, 0, $red );

		// Save image.
		imagepng( $image, $filename );
		imagedestroy( $image );

		// Create attachment.
		$attachment = array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Test Image',
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $filename );

		// Generate metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		return $attach_id;
	}

	/**
	 * Test tool registration.
	 */
	public function test_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->assertTrue( $registry->is_tool_registered( 'remove_background' ) );
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'remove_background', $this->tool->get_slug() );
		$this->assertSame( 'Remove Background', $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Test parameter schema.
	 */
	public function test_parameter_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'method', $schema['properties'] );
		$this->assertArrayHasKey( 'attachment_id', $schema['properties'] );
		$this->assertArrayHasKey( 'image_url', $schema['properties'] );

		// Check method enum.
		$method_schema = $schema['properties']['method'];
		$this->assertArrayHasKey( 'enum', $method_schema );
		$this->assertContains( 'auto', $method_schema['enum'] );
		$this->assertContains( 'free', $method_schema['enum'] );
		$this->assertContains( 'paid', $method_schema['enum'] );
	}

	/**
	 * Test execution without authentication.
	 */
	public function test_execute_requires_authentication() {
		$context = array();
		$result  = $this->tool->execute( array(), $context );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execution without upload permission.
	 */
	public function test_execute_requires_upload_permission() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$context = array( 'user_id' => $user_id );

		$result = $this->tool->execute( array(), $context );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execution with missing source.
	 */
	public function test_execute_requires_source() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$context = array( 'user_id' => $user_id );
		$result  = $this->tool->execute( array(), $context );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'attachment_id', $result->get_error_message() );
	}

	/**
	 * Test execution with invalid attachment.
	 */
	public function test_execute_with_invalid_attachment() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$arguments = array( 'attachment_id' => 99999 );
		$context   = array( 'user_id' => $user_id );

		$result = $this->tool->execute( $arguments, $context );

		$this->assertWPError( $result );
	}

	/**
	 * Test settings integration.
	 */
	public function test_removebg_api_key_setting_exists() {
		$defaults = WP_MCP_AI_Admin_Settings_Base::get_default_settings();

		$this->assertArrayHasKey( 'removebg_api_key', $defaults );
		$this->assertSame( '', $defaults['removebg_api_key'] );
	}

	/**
	 * Test tool can detect Python command.
	 */
	public function test_find_python_command() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'find_python_command' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tool );

		// Should either find Python or return an error.
		$this->assertTrue( is_string( $result ) || is_wp_error( $result ) );

		if ( is_string( $result ) ) {
			$this->assertContains( $result, array( 'python', 'python3' ) );
		}
	}

	/**
	 * Test method parameter validation.
	 */
	public function test_method_parameter_validation() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Test with invalid method - should default to 'auto'.
		$arguments = array(
			'attachment_id' => $this->test_image_id,
			'method'        => 'invalid',
		);
		$context   = array( 'user_id' => $user_id );

		// This will fail due to missing rembg/API key, but method should be sanitized.
		$result = $this->tool->execute( $arguments, $context );

		// Should be an error, but not about invalid method.
		$this->assertWPError( $result );
		$this->assertNotSame( 'wp_mcp_ai_invalid_method', $result->get_error_code() );
	}

	/**
	 * Test free method error when rembg is not installed.
	 */
	public function test_free_method_requires_rembg() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$arguments = array(
			'attachment_id' => $this->test_image_id,
			'method'        => 'free',
		);
		$context   = array( 'user_id' => $user_id );

		$result = $this->tool->execute( $arguments, $context );

		// Should fail because rembg is likely not installed in test environment.
		$this->assertWPError( $result );
	}

	/**
	 * Test paid method error when API key is missing.
	 */
	public function test_paid_method_requires_api_key() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Ensure API key is not set.
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings['removebg_api_key'] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		$arguments = array(
			'attachment_id' => $this->test_image_id,
			'method'        => 'paid',
		);
		$context   = array( 'user_id' => $user_id );

		$result = $this->tool->execute( $arguments, $context );

		// Should fail because API key is not configured.
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'API key', $result->get_error_message() );
	}

	/**
	 * Test sanitize_for_llm with success result.
	 */
	public function test_sanitize_for_llm_success() {
		$input = array(
			'attachment_id' => 123,
			'url'           => 'http://example.com/image.png',
			'width'         => 800,
			'height'        => 600,
		);

		$result = $this->tool->sanitize_for_llm( $input );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 123, $result['attachment_id'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * Test sanitize_for_llm with error result.
	 */
	public function test_sanitize_for_llm_error() {
		$error  = new WP_Error( 'test_error', 'Test error message' );
		$result = $this->tool->sanitize_for_llm( $error );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 'test_error', $result['error']['code'] );
		$this->assertSame( 'Test error message', $result['error']['message'] );
	}

	/**
	 * Test tool is in correct group.
	 */
	public function test_tool_in_correct_group() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map = $registry->get_tool_group_map();

		$this->assertArrayHasKey( 'remove_background', $group_map );
		$this->assertSame( 'wordpress-core', $group_map['remove_background'] );
	}

	/**
	 * Test helper function still works.
	 */
	public function test_helper_function_exists() {
		$this->assertTrue( function_exists( 'wp_mcp_ai_remove_image_background' ) );
	}
}
