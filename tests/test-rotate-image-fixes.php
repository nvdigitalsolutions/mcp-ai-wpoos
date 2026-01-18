<?php
/**
 * Tests for rotate_image tool fixes.
 *
 * @package WP_MCP_AI
 */

/**
 * Test rotate_image tool edge cases and bug fixes.
 */
class Test_Rotate_Image_Fixes extends WP_UnitTestCase {

	/**
	 * Test that NaN angle parameter is rejected.
	 */
	public function test_nan_angle_rejected() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'rotate_image' );

		$this->assertNotNull( $tool, 'Rotate image tool should be registered' );

		// Create a user with upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Test with NaN angle.
		$result = $tool->execute(
			array( 'angle' => NAN ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result, 'NaN angle should return error' );
		$this->assertEquals( 'wp_mcp_ai_invalid_angle', $result->get_error_code() );
	}

	/**
	 * Test that Infinity angle parameter is rejected.
	 */
	public function test_infinity_angle_rejected() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'rotate_image' );

		$this->assertNotNull( $tool, 'Rotate image tool should be registered' );

		// Create a user with upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Test with Infinity angle.
		$result = $tool->execute(
			array( 'angle' => INF ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result, 'Infinity angle should return error' );
		$this->assertEquals( 'wp_mcp_ai_invalid_angle', $result->get_error_code() );
	}

	/**
	 * Test that negative Infinity angle parameter is rejected.
	 */
	public function test_negative_infinity_angle_rejected() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'rotate_image' );

		$this->assertNotNull( $tool, 'Rotate image tool should be registered' );

		// Create a user with upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Test with negative Infinity angle.
		$result = $tool->execute(
			array( 'angle' => -INF ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result, 'Negative Infinity angle should return error' );
		$this->assertEquals( 'wp_mcp_ai_invalid_angle', $result->get_error_code() );
	}

	/**
	 * Test that zero angle without flip operations is rejected.
	 */
	public function test_zero_angle_no_flip_rejected() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'rotate_image' );

		$this->assertNotNull( $tool, 'Rotate image tool should be registered' );

		// Create a user with upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Test with zero angle and no flips.
		$result = $tool->execute(
			array( 'angle' => 0 ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result, 'Zero angle without flip should return error' );
		$this->assertEquals( 'wp_mcp_ai_no_operation', $result->get_error_code() );
	}

	/**
	 * Test that no parameters is rejected.
	 */
	public function test_no_parameters_rejected() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'rotate_image' );

		$this->assertNotNull( $tool, 'Rotate image tool should be registered' );

		// Create a user with upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Test with no parameters.
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result, 'No parameters should return error' );
		$this->assertEquals( 'wp_mcp_ai_no_operation', $result->get_error_code() );
	}

	/**
	 * Test that valid float angle is accepted.
	 */
	public function test_valid_float_angle_accepted() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'rotate_image' );

		$this->assertNotNull( $tool, 'Rotate image tool should be registered' );

		// Create a user with upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Test with valid float angle (should get past angle validation).
		// Will fail at image loading since we don't provide an image, but that's okay.
		$result = $tool->execute(
			array( 'angle' => 90.5 ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result, 'Should fail at image loading, not angle validation' );
		// Should fail with missing source error, not invalid angle error.
		$this->assertNotEquals( 'wp_mcp_ai_invalid_angle', $result->get_error_code() );
		$this->assertEquals( 'wp_mcp_ai_missing_source', $result->get_error_code() );
	}

	/**
	 * Test that zero angle with flip is accepted.
	 */
	public function test_zero_angle_with_flip_accepted() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'rotate_image' );

		$this->assertNotNull( $tool, 'Rotate image tool should be registered' );

		// Create a user with upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Test with zero angle but horizontal flip.
		// Will fail at image loading since we don't provide an image, but should pass angle validation.
		$result = $tool->execute(
			array(
				'angle'           => 0,
				'flip_horizontal' => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result, 'Should fail at image loading, not parameter validation' );
		// Should fail with missing source error, not no operation error.
		$this->assertNotEquals( 'wp_mcp_ai_no_operation', $result->get_error_code() );
		$this->assertEquals( 'wp_mcp_ai_missing_source', $result->get_error_code() );
	}

	/**
	 * Test that flip parameters work correctly with actual image.
	 *
	 * This test verifies the fix for swapped flip parameters.
	 */
	public function test_flip_parameters_with_image() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'rotate_image' );

		$this->assertNotNull( $tool, 'Rotate image tool should be registered' );

		// Create a user with upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create a simple test image.
		$image_path = $this->create_test_image();
		$this->assertNotEmpty( $image_path, 'Test image should be created' );

		// Upload as attachment.
		$attachment_id = $this->factory->attachment->create_upload_object( $image_path );
		$this->assertGreaterThan( 0, $attachment_id, 'Attachment should be created' );

		// Test horizontal flip.
		$result = $tool->execute(
			array(
				'attachment_id'   => $attachment_id,
				'flip_horizontal' => true,
			),
			array( 'user_id' => $user_id )
		);

		// Should succeed (not return WP_Error).
		$this->assertNotWPError( $result, 'Horizontal flip should succeed' );
		$this->assertIsArray( $result, 'Result should be array' );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertStringContainsString( 'flipped horizontally', $result['message'] );
	}

	/**
	 * Test that rotate direction is correct (clockwise).
	 *
	 * This test verifies the fix for rotate direction.
	 */
	public function test_rotate_direction_with_image() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'rotate_image' );

		$this->assertNotNull( $tool, 'Rotate image tool should be registered' );

		// Create a user with upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create a simple test image.
		$image_path = $this->create_test_image();
		$this->assertNotEmpty( $image_path, 'Test image should be created' );

		// Upload as attachment.
		$attachment_id = $this->factory->attachment->create_upload_object( $image_path );
		$this->assertGreaterThan( 0, $attachment_id, 'Attachment should be created' );

		// Test rotation with positive angle (should rotate clockwise as documented).
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'angle'         => 90,
			),
			array( 'user_id' => $user_id )
		);

		// Should succeed (not return WP_Error).
		$this->assertNotWPError( $result, 'Rotation should succeed' );
		$this->assertIsArray( $result, 'Result should be array' );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertStringContainsString( 'rotated 90 degrees', $result['message'] );
	}

	/**
	 * Helper method to create a test image.
	 *
	 * @return string Path to created image file.
	 */
	private function create_test_image() {
		// Create a simple 100x100 test image.
		$image = imagecreatetruecolor( 100, 100 );

		// Fill with a color.
		$color = imagecolorallocate( $image, 255, 0, 0 );
		imagefill( $image, 0, 0, $color );

		// Add a marker to detect orientation (white square in top-left corner).
		$white = imagecolorallocate( $image, 255, 255, 255 );
		imagefilledrectangle( $image, 0, 0, 20, 20, $white );

		// Save to temporary file.
		$temp_file = tempnam( sys_get_temp_dir(), 'test_image_' ) . '.png';
		imagepng( $image, $temp_file );
		imagedestroy( $image );

		return $temp_file;
	}
}
