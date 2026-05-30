<?php
/**
 * Tests for WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 */

/**
 * Test class for the Validate Image for Vehicle tool.
 */
class Test_Pro_Tool_Validate_Image_For_Vehicle extends WP_UnitTestCase {

	/**
	 * Test that the tool class file exists.
	 */
	public function test_class_file_exists() {
		$file = dirname( __DIR__ ) . '/addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		$this->assertFileExists( $file, 'Tool class file should exist.' );
	}

	/**
	 * Test that the class implements required interfaces.
	 */
	public function test_class_exists_and_implements_interfaces() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}

		require_once $file;

		$this->assertTrue( class_exists( 'WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle' ) );

		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle' );
		$this->assertTrue( $reflection->implementsInterface( 'WP_MCP_AI_Tool_Interface' ) );
		$this->assertTrue( $reflection->implementsInterface( 'WP_MCP_AI_Tool_Capability_Flags_Interface' ) );
		$this->assertTrue( $reflection->implementsInterface( 'WP_MCP_AI_Tool_Model_Requirements_Interface' ) );
	}

	/**
	 * Test tool slug.
	 */
	public function test_get_slug() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$tool = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle();
		$this->assertSame( 'validate_image_for_vehicle', $tool->get_slug() );
	}

	/**
	 * Test tool name is not empty.
	 */
	public function test_get_name() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$tool = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle();
		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test tool description mentions vehicle.
	 */
	public function test_get_description() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$tool = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle();
		$desc = $tool->get_description();
		$this->assertNotEmpty( $desc );
		$this->assertStringContainsString( 'vehicle', strtolower( $desc ) );
	}

	/**
	 * Test parameters schema structure.
	 */
	public function test_get_parameters_schema() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$tool   = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'image_attachment_ids', $schema['properties'] );
		$this->assertArrayHasKey( 'estimate_type', $schema['properties'] );
		$this->assertArrayHasKey( 'strict_mode', $schema['properties'] );

		// Estimate type enum.
		$estimate_prop = $schema['properties']['estimate_type'];
		$this->assertContains( 'cleaning', $estimate_prop['enum'] );
		$this->assertContains( 'repair', $estimate_prop['enum'] );

		// Required fields.
		$this->assertContains( 'image_attachment_ids', $schema['required'] );
		$this->assertContains( 'estimate_type', $schema['required'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_get_capability_flags() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$tool  = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'requires-vision-model', $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'external-api', $flags );
	}

	/**
	 * Test model requirements include vision.
	 */
	public function test_get_model_requirements() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$tool = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle();
		$this->assertContains( 'vision', $tool->get_model_requirements() );
	}

	/**
	 * Test execute requires authentication.
	 */
	public function test_execute_requires_authentication() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$tool   = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle();
		$result = $tool->execute(
			array(
				'image_attachment_ids' => array( 1 ),
				'estimate_type'        => 'cleaning',
			),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execute requires images.
	 */
	public function test_execute_requires_images() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$tool   = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle();
		$result = $tool->execute(
			array(
				'image_attachment_ids' => array(),
				'estimate_type'        => 'cleaning',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_images', $result->get_error_code() );
	}

	/**
	 * Test required repair views are defined.
	 */
	public function test_required_repair_views_defined() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$views = WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle::REQUIRED_REPAIR_VIEWS;

		$this->assertArrayHasKey( 'front', $views );
		$this->assertArrayHasKey( 'rear', $views );
		$this->assertArrayHasKey( 'left_side', $views );
		$this->assertArrayHasKey( 'right_side', $views );
	}

	/**
	 * Test cleaning scoring weights sum to 100.
	 */
	public function test_cleaning_weights_sum_to_100() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$this->assertSame( 100, array_sum( WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle::CLEANING_WEIGHTS ) );
	}

	/**
	 * Test repair scoring weights sum to 100.
	 */
	public function test_repair_weights_sum_to_100() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$this->assertSame( 100, array_sum( WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle::REPAIR_WEIGHTS ) );
	}

	/**
	 * Test rating thresholds are properly ordered (descending).
	 */
	public function test_rating_thresholds_ordered() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PATH not defined.' );
		}

		$file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Tool file not found.' );
		}
		require_once $file;

		$thresholds = WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle::RATING_THRESHOLDS;
		$prev       = 101;
		foreach ( $thresholds as $grade => $threshold ) {
			$this->assertLessThan( $prev, $threshold, "Grade {$grade} threshold should be less than previous." );
			$prev = $threshold;
		}
	}

	/**
	 * Test tool is registered in the pro plugin file.
	 */
	public function test_tool_registered_in_pro_plugin() {
		$pro_file = dirname( __DIR__ ) . '/addons/pro/mcp-ai-wpoos-pro.php';
		if ( ! file_exists( $pro_file ) ) {
			$this->markTestSkipped( 'Pro plugin file not found.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read in test.
		$contents = file_get_contents( $pro_file );
		$this->assertStringContainsString(
			'WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle',
			$contents,
			'Tool class should be registered in the pro plugin file.'
		);
		$this->assertStringContainsString(
			"'validate_image_for_vehicle'",
			$contents,
			'Tool slug should be mapped in the pro plugin file.'
		);
	}
}
