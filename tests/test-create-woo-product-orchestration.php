<?php
/**
 * Tests for the create_woo_product tool with orchestration mode.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-woo-product.php';

/**
 * Test case for multi-step orchestration in create_woo_product tool.
 */
class WP_MCP_AI_Create_Woo_Product_Orchestration_Test extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that orchestration mode is disabled by default.
	 */
	public function test_orchestration_mode_disabled_by_default() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$result = $tool->execute(
			array(
				'reference' => 'TEST-ORCH-001',
				'title'     => 'Test Product',
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			$this->assertIsArray( $result );
			// Legacy mode should not include orchestration metadata.
			$this->assertArrayNotHasKey( 'orchestration', $result );
			$this->assertArrayNotHasKey( 'execution_id', $result );
		}
	}

	/**
	 * Test that orchestration mode can be enabled.
	 */
	public function test_orchestration_mode_can_be_enabled() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$result = $tool->execute(
			array(
				'reference'          => 'TEST-ORCH-002',
				'title'              => 'Test Orchestrated Product',
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			$this->assertIsArray( $result );
			// Orchestration mode should include metadata.
			$this->assertArrayHasKey( 'orchestration', $result );
			$this->assertArrayHasKey( 'execution_id', $result );
			$this->assertTrue( $result['orchestration']['enabled'] );
			$this->assertArrayHasKey( 'steps', $result['orchestration'] );
		}
	}

	/**
	 * Test data validation step.
	 */
	public function test_validation_step_rejects_duplicate_sku() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Create_Woo_Product();

		// Create first product.
		$result1 = $tool->execute(
			array(
				'reference'          => 'DUPLICATE-SKU',
				'title'              => 'First Product',
				'orchestration_mode' => false, // Use legacy mode for first product.
			),
			array( 'user_id' => $user_id )
		);

		if ( is_wp_error( $result1 ) ) {
			$this->markTestSkipped( 'Cannot create first product: ' . $result1->get_error_message() );
		}

		// Try to create second product with same SKU (should fail in orchestration mode).
		$result2 = $tool->execute(
			array(
				'reference'          => 'DUPLICATE-SKU',
				'title'              => 'Second Product',
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result2 );
		$this->assertStringContainsString( 'orchestration_failed', $result2->get_error_code() );
	}

	/**
	 * Test validation step rejects invalid price.
	 */
	public function test_validation_step_rejects_invalid_price() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$result = $tool->execute(
			array(
				'reference'          => 'TEST-PRICE-001',
				'title'              => 'Test Product',
				'local_price'        => -10.99, // Invalid negative price.
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'orchestration_failed', $result->get_error_code() );
	}

	/**
	 * Test validation step rejects variable product without attributes.
	 */
	public function test_validation_step_rejects_variable_without_attributes() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$result = $tool->execute(
			array(
				'reference'          => 'TEST-VAR-001',
				'title'              => 'Test Variable Product',
				'product_type'       => 'variable',
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'orchestration_failed', $result->get_error_code() );
	}

	/**
	 * Test that orchestration includes proper step logging.
	 */
	public function test_orchestration_includes_step_logging() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$result = $tool->execute(
			array(
				'reference'          => 'TEST-LOG-001',
				'title'              => 'Test Logging Product',
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			$this->assertArrayHasKey( 'execution_id', $result );
			$execution_id = $result['execution_id'];

			// Check that steps were logged.
			$this->assertArrayHasKey( 'orchestration', $result );
			$this->assertArrayHasKey( 'steps', $result['orchestration'] );
			$steps = $result['orchestration']['steps'];

			$this->assertIsArray( $steps );
			$this->assertGreaterThan( 0, count( $steps ) );

			// Verify expected steps are present.
			$step_names = array_column( $steps, 'name' );
			$this->assertContains( 'started', $step_names );
			$this->assertContains( 'validate', $step_names );
			$this->assertContains( 'create', $step_names );
			$this->assertContains( 'completed', $step_names );
		}
	}

	/**
	 * Test content enhancement step (if AI is available).
	 */
	public function test_content_enhancement_step() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Streaming' ) ) {
			$this->markTestSkipped( 'AI streaming not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$result = $tool->execute(
			array(
				'reference'          => 'TEST-AI-001',
				'title'              => 'Amazing Product',
				'orchestration_mode' => true,
				'enhance_content'    => true, // Enable content enhancement.
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			// If enhancement succeeded, steps should include 'enhance' and 'enhancement_completed'.
			$steps = $result['orchestration']['steps'];
			$step_names = array_column( $steps, 'name' );
			
			// Enhancement may be skipped or completed, but should be logged.
			$this->assertTrue(
				in_array( 'enhance', $step_names, true ) ||
				in_array( 'enhancement_skipped', $step_names, true ) ||
				in_array( 'enhancement_completed', $step_names, true )
			);
		}
	}

	/**
	 * Test auto-research step (if research_product tool is available).
	 */
	public function test_auto_research_step() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		// Check if research_product tool is available.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->markTestSkipped( 'Tool registry not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$result = $tool->execute(
			array(
				'reference'          => 'TEST-RESEARCH-001',
				'title'              => 'Product to Research',
				'orchestration_mode' => true,
				'auto_research'      => true, // Enable auto-research.
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			// Research step should be in the logs.
			$steps = $result['orchestration']['steps'];
			$step_names = array_column( $steps, 'name' );
			
			// Research may be completed, failed, or skipped.
			$this->assertTrue(
				in_array( 'research', $step_names, true ) ||
				in_array( 'research_completed', $step_names, true ) ||
				in_array( 'research_failed', $step_names, true )
			);
		}
	}

	/**
	 * Test backward compatibility: legacy mode still works.
	 */
	public function test_backward_compatibility_legacy_mode() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Create_Woo_Product();

		// Test with orchestration_mode explicitly false.
		$result1 = $tool->execute(
			array(
				'reference'          => 'LEGACY-001',
				'title'              => 'Legacy Product 1',
				'orchestration_mode' => false,
			),
			array( 'user_id' => $user_id )
		);

		// Test with orchestration_mode not set (default).
		$result2 = $tool->execute(
			array(
				'reference' => 'LEGACY-002',
				'title'     => 'Legacy Product 2',
			),
			array( 'user_id' => $user_id )
		);

		// Both should work in legacy mode.
		if ( ! is_wp_error( $result1 ) ) {
			$this->assertArrayNotHasKey( 'orchestration', $result1 );
		}

		if ( ! is_wp_error( $result2 ) ) {
			$this->assertArrayNotHasKey( 'orchestration', $result2 );
		}
	}

	/**
	 * Test parameter schema includes orchestration parameters.
	 */
	public function test_parameter_schema_includes_orchestration_params() {
		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'orchestration_mode', $schema['properties'] );
		$this->assertArrayHasKey( 'auto_research', $schema['properties'] );
		$this->assertArrayHasKey( 'enhance_content', $schema['properties'] );
		$this->assertArrayHasKey( 'optimize', $schema['properties'] );
	}

	/**
	 * Test tool description mentions orchestration.
	 */
	public function test_tool_description_mentions_orchestration() {
		$tool        = new WP_MCP_AI_Tool_Create_Woo_Product();
		$description = $tool->get_description();

		$this->assertStringContainsString( 'orchestration', strtolower( $description ) );
	}
}
