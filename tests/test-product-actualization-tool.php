<?php
/**
 * Tests for Product Actualization tool.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test Product Actualization tool availability and basic functionality.
 */
class WP_MCP_AI_Product_Actualization_Tool_Test extends WP_UnitTestCase {

	/**
	 * Test tool availability check.
	 */
	public function test_tool_requires_image_extension() {
		// Check if tool is available based on extensions.
		$has_imagick = extension_loaded( 'imagick' );
		$has_gd      = extension_loaded( 'gd' );

		$available = WP_MCP_AI_Pro_Tool_Product_Actualization::is_available();

		if ( $has_imagick || $has_gd ) {
			$this->assertTrue( $available, 'Tool should be available when Imagick or GD is loaded' );
		} else {
			$this->assertFalse( $available, 'Tool should not be available without Imagick or GD' );
		}
	}

	/**
	 * Test tool slug.
	 */
	public function test_tool_slug() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$this->assertSame( 'product_actualization', $tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_tool_name() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test tool description.
	 */
	public function test_tool_description() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test parameters schema structure.
	 */
	public function test_parameters_schema() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );

		// Check required parameters.
		$this->assertContains( 'product_attachment_id', $schema['required'] );
		$this->assertContains( 'scene_prompt', $schema['required'] );

		// Check optional parameters exist.
		$properties = $schema['properties'];
		$this->assertArrayHasKey( 'mode', $properties );
		$this->assertArrayHasKey( 'aspect_ratio', $properties );
		$this->assertArrayHasKey( 'background_mode', $properties );
		$this->assertArrayHasKey( 'placement_hint', $properties );
		$this->assertArrayHasKey( 'scale_factor', $properties );
	}

	/**
	 * Test execution requires authentication.
	 */
	public function test_execute_requires_authentication() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$result = $tool->execute( array(), array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execution requires upload_files capability.
	 */
	public function test_execute_requires_capability() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$context = array( 'user_id' => $user_id );
		$result  = $tool->execute( array(), $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execution with missing product_attachment_id.
	 */
	public function test_execute_requires_product_id() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$context = array( 'user_id' => $user_id );
		$args    = array( 'scene_prompt' => 'Test scene' );
		$result  = $tool->execute( $args, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_product', $result->get_error_code() );
	}

	/**
	 * Test execution with missing scene_prompt.
	 */
	public function test_execute_requires_scene_prompt() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$context = array( 'user_id' => $user_id );
		$args    = array( 'product_attachment_id' => 123 );
		$result  = $tool->execute( $args, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * Test execution with invalid product attachment.
	 */
	public function test_execute_validates_product_attachment() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$context = array( 'user_id' => $user_id );
		$args    = array(
			'product_attachment_id' => 99999,
			'scene_prompt'          => 'Beautiful sunset beach scene',
		);
		$result  = $tool->execute( $args, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_product', $result->get_error_code() );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool  = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'async', $flags );
		$this->assertContains( 'consumes-tokens', $flags );
		$this->assertContains( 'external-api', $flags );
	}

	/**
	 * Test model requirements.
	 */
	public function test_model_requirements() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool         = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$requirements = $tool->get_model_requirements();

		$this->assertIsArray( $requirements );
		$this->assertContains( 'image-generation', $requirements );
	}

	/**
	 * Test tool rules structure.
	 */
	public function test_tool_rules() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool  = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$rules = $tool->get_tool_rules();

		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( 'model_requirements', $rules );
		$this->assertArrayHasKey( 'parameter_constraints', $rules );
		$this->assertArrayHasKey( 'rate_limits', $rules );
		$this->assertArrayHasKey( 'timeout_constraints', $rules );
		$this->assertArrayHasKey( 'dependencies', $rules );
		$this->assertArrayHasKey( 'orchestration_hints', $rules );
	}

	/**
	 * Test video mode returns not implemented error.
	 */
	public function test_video_mode_not_implemented() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		// Create a test image attachment.
		$upload_dir = wp_upload_dir();
		$test_image = trailingslashit( $upload_dir['path'] ) . 'test-product.png';

		// Create a simple 100x100 PNG.
		$image = imagecreatetruecolor( 100, 100 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 255, 255, 255 ) );
		imagepng( $image, $test_image );
		imagedestroy( $image );

		$attachment_id = self::factory()->attachment->create_upload_object( $test_image );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$context = array( 'user_id' => $user_id );
		$args    = array(
			'product_attachment_id' => $attachment_id,
			'scene_prompt'          => 'Beautiful sunset beach scene',
			'mode'                  => 'video',
		);

		$result = $tool->execute( $args, $context );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_not_implemented', $result->get_error_code() );
	}

	/**
	 * Test scale factor validation.
	 */
	public function test_scale_factor_validation() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'scale_factor', $schema['properties'] );
		$this->assertSame( 0.1, $schema['properties']['scale_factor']['minimum'] );
		$this->assertSame( 2.0, $schema['properties']['scale_factor']['maximum'] );
		$this->assertSame( 1.0, $schema['properties']['scale_factor']['default'] );
	}

	/**
	 * Test aspect ratio options.
	 */
	public function test_aspect_ratio_options() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'aspect_ratio', $schema['properties'] );
		$this->assertArrayHasKey( 'enum', $schema['properties']['aspect_ratio'] );

		$ratios = $schema['properties']['aspect_ratio']['enum'];
		$this->assertContains( '1:1', $ratios );
		$this->assertContains( '4:5', $ratios );
		$this->assertContains( '16:9', $ratios );
		$this->assertContains( '9:16', $ratios );
		$this->assertContains( 'auto', $ratios );
	}

	/**
	 * Test background mode options.
	 */
	public function test_background_mode_options() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'background_mode', $schema['properties'] );
		$this->assertArrayHasKey( 'enum', $schema['properties']['background_mode'] );

		$modes = $schema['properties']['background_mode']['enum'];
		$this->assertContains( 'auto', $modes );
		$this->assertContains( 'remove', $modes );
		$this->assertContains( 'preserve', $modes );
	}
}
