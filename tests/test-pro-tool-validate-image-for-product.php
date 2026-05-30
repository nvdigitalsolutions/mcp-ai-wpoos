<?php
/**
 * Tests for WP_MCP_AI_Pro_Tool_Validate_Image_For_Product.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 */

/**
 * Test class for the Validate Image for Product tool.
 */
class Test_Pro_Tool_Validate_Image_For_Product extends WP_UnitTestCase {

	/**
	 * Test that the tool class file exists and can be loaded.
	 */
	public function test_class_file_exists() {
		$file = dirname( __DIR__ ) . '/addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php';
		$this->assertFileExists( $file, 'Tool class file should exist.' );
	}

	/**
	 * Test that the class can be loaded and implements the required interface.
	 */
	public function test_class_exists_and_implements_interface() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found at expected path.' );
		}

		require_once $file;

		$this->assertTrue( class_exists( 'WP_MCP_AI_Pro_Tool_Validate_Image_For_Product' ), 'Tool class should exist.' );

		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Tool_Validate_Image_For_Product' );

		$this->assertTrue(
			$reflection->implementsInterface( 'WP_MCP_AI_Tool_Interface' ),
			'Tool should implement WP_MCP_AI_Tool_Interface.'
		);
		$this->assertTrue(
			$reflection->implementsInterface( 'WP_MCP_AI_Tool_Capability_Flags_Interface' ),
			'Tool should implement WP_MCP_AI_Tool_Capability_Flags_Interface.'
		);
		$this->assertTrue(
			$reflection->implementsInterface( 'WP_MCP_AI_Tool_Model_Requirements_Interface' ),
			'Tool should implement WP_MCP_AI_Tool_Model_Requirements_Interface.'
		);
	}

	/**
	 * Test tool slug.
	 */
	public function test_get_slug() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$tool = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Product();
		$this->assertSame( 'validate_image_for_product', $tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_get_name() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$tool = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Product();
		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test tool description.
	 */
	public function test_get_description() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$tool = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Product();
		$desc = $tool->get_description();
		$this->assertNotEmpty( $desc );
		$this->assertStringContainsString( 'product', strtolower( $desc ) );
	}

	/**
	 * Test parameters schema structure.
	 */
	public function test_get_parameters_schema() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$tool   = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Product();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'product_type', $schema['properties'] );
		$this->assertArrayHasKey( 'attachment_id', $schema['properties'] );
		$this->assertArrayHasKey( 'strict_mode', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );

		// Product type should have enum with all supported types.
		$product_type_prop = $schema['properties']['product_type'];
		$this->assertContains( 'watch', $product_type_prop['enum'] );
		$this->assertContains( 'ring', $product_type_prop['enum'] );
		$this->assertContains( 'earring', $product_type_prop['enum'] );
		$this->assertContains( 'necklace', $product_type_prop['enum'] );
		$this->assertContains( 'glasses', $product_type_prop['enum'] );
		$this->assertContains( 'hat', $product_type_prop['enum'] );
		$this->assertContains( 'bag', $product_type_prop['enum'] );
		$this->assertContains( 'bracelet', $product_type_prop['enum'] );
		$this->assertContains( 'general', $product_type_prop['enum'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_get_capability_flags() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$tool  = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Product();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'requires-vision-model', $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'external-api', $flags );
	}

	/**
	 * Test model requirements.
	 */
	public function test_get_model_requirements() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$tool = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Product();
		$reqs = $tool->get_model_requirements();

		$this->assertIsArray( $reqs );
		$this->assertContains( 'vision', $reqs );
	}

	/**
	 * Test execute requires authentication.
	 */
	public function test_execute_requires_authentication() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$tool   = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Product();
		$result = $tool->execute(
			array( 'product_type' => 'watch' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execute requires an image.
	 */
	public function test_execute_requires_image() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		// Create a user with upload_files capability.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$tool   = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Product();
		$result = $tool->execute(
			array( 'product_type' => 'watch' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_image', $result->get_error_code() );
	}

	/**
	 * Test product type requirements constants are properly defined.
	 */
	public function test_product_type_requirements_defined() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$reqs = WP_MCP_AI_Pro_Tool_Validate_Image_For_Product::PRODUCT_TYPE_REQUIREMENTS;

		$expected_types = array( 'watch', 'bracelet', 'ring', 'earring', 'necklace', 'glasses', 'hat', 'bag', 'general' );
		foreach ( $expected_types as $type ) {
			$this->assertArrayHasKey( $type, $reqs, "Product type '{$type}' should be defined." );
			$this->assertArrayHasKey( 'label', $reqs[ $type ] );
			$this->assertArrayHasKey( 'required_parts', $reqs[ $type ] );
			$this->assertArrayHasKey( 'optional_parts', $reqs[ $type ] );
			$this->assertArrayHasKey( 'preferred_pose', $reqs[ $type ] );
			$this->assertArrayHasKey( 'min_dimension', $reqs[ $type ] );
			$this->assertNotEmpty( $reqs[ $type ]['required_parts'], "Product type '{$type}' should have required parts." );
		}
	}

	/**
	 * Test rating thresholds are properly ordered.
	 */
	public function test_rating_thresholds_ordered() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$thresholds = WP_MCP_AI_Pro_Tool_Validate_Image_For_Product::RATING_THRESHOLDS;

		// Thresholds should be descending.
		$prev = 101;
		foreach ( $thresholds as $grade => $threshold ) {
			$this->assertLessThan( $prev, $threshold, "Grade {$grade} threshold should be less than previous." );
			$prev = $threshold;
		}
	}

	/**
	 * Test scoring weights sum to 100.
	 */
	public function test_scoring_weights_sum_to_100() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$weights = WP_MCP_AI_Pro_Tool_Validate_Image_For_Product::SCORING_WEIGHTS;
		$this->assertSame( 100, array_sum( $weights ), 'Scoring weights must sum to exactly 100.' );
	}

	/**
	 * Test that the pro plugin file registers the tool class.
	 */
	public function test_tool_registered_in_pro_plugin() {
		$pro_file = dirname( __DIR__ ) . '/addons/pro/mcp-ai-wpoos-pro.php';
		if ( ! file_exists( $pro_file ) ) {
			$this->markTestSkipped( 'Pro plugin file not found.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read in test.
		$contents = file_get_contents( $pro_file );
		$this->assertStringContainsString(
			'WP_MCP_AI_Pro_Tool_Validate_Image_For_Product',
			$contents,
			'Tool class should be registered in the pro plugin file.'
		);
		$this->assertStringContainsString(
			"'validate_image_for_product'",
			$contents,
			'Tool slug should be mapped in the pro plugin file.'
		);
	}
}
