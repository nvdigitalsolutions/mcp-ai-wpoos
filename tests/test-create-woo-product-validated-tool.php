<?php
/**
 * Tests for the create_woo_product_validated tool.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-woo-product-validated.php';

/**
 * Test case for the Symfony Validator version of create_woo_product tool.
 */
class WP_MCP_AI_Create_Woo_Product_Validated_Tool_Test extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that the tool has correct metadata.
	 */
	public function test_tool_metadata() {
		$tool = new WP_MCP_AI_Tool_Create_Woo_Product_Validated();

		$this->assertSame( 'create_woo_product_validated', $tool->get_slug() );
		$this->assertSame( 'Create WooCommerce Product Draft (Validated)', $tool->get_name() );
		$this->assertStringContainsString( 'product', strtolower( $tool->get_description() ) );

		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'reference', $schema['properties'] );
		$this->assertArrayHasKey( 'product_type', $schema['properties'] );
	}

	/**
	 * Test tool execution with minimum valid data.
	 */
	public function test_execute_with_minimum_valid_data() {
		// Skip test if WooCommerce is not available.
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product_Validated();
		$result = $tool->execute(
			array(
				'reference' => 'TEST-SKU-123',
			),
			array( 'user_id' => $user_id )
		);

		// Either success or WooCommerce not available error is acceptable.
		if ( is_wp_error( $result ) ) {
			$this->assertSame( 'wp_mcp_ai_woo_missing', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'product_id', $result );
		}
	}

	/**
	 * Test tool rejects missing reference.
	 */
	public function test_execute_rejects_missing_reference() {
		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product_Validated();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool rejects empty reference.
	 */
	public function test_execute_rejects_empty_reference() {
		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product_Validated();
		$result = $tool->execute(
			array(
				'reference' => '',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool rejects invalid product type.
	 */
	public function test_execute_rejects_invalid_product_type() {
		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product_Validated();
		$result = $tool->execute(
			array(
				'reference'    => 'TEST-SKU-123',
				'product_type' => 'invalid',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool accepts valid product type.
	 */
	public function test_execute_accepts_valid_product_type() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product_Validated();
		$result = $tool->execute(
			array(
				'reference'    => 'TEST-SKU-123',
				'product_type' => 'simple',
			),
			array( 'user_id' => $user_id )
		);

		if ( is_wp_error( $result ) ) {
			$this->assertSame( 'wp_mcp_ai_woo_missing', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	/**
	 * Test tool rejects invalid brand page URL.
	 */
	public function test_execute_rejects_invalid_url() {
		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product_Validated();
		$result = $tool->execute(
			array(
				'reference'      => 'TEST-SKU-123',
				'brand_page_url' => 'not-a-valid-url',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool rejects too few image URLs.
	 */
	public function test_execute_rejects_too_few_image_urls() {
		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product_Validated();
		$result = $tool->execute(
			array(
				'reference'  => 'TEST-SKU-123',
				'image_urls' => array( 'https://example.com/image1.jpg' ),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool rejects too many image URLs.
	 */
	public function test_execute_rejects_too_many_image_urls() {
		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product_Validated();
		$result = $tool->execute(
			array(
				'reference'  => 'TEST-SKU-123',
				'image_urls' => array_fill( 0, 11, 'https://example.com/image.jpg' ),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool accepts valid image URLs count.
	 */
	public function test_execute_accepts_valid_image_urls_count() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product_Validated();
		$result = $tool->execute(
			array(
				'reference'  => 'TEST-SKU-123',
				'image_urls' => array(
					'https://example.com/image1.jpg',
					'https://example.com/image2.jpg',
				),
			),
			array( 'user_id' => $user_id )
		);

		if ( is_wp_error( $result ) ) {
			$this->assertSame( 'wp_mcp_ai_woo_missing', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	/**
	 * Test tool requires permission.
	 */
	public function test_execute_requires_permission() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product_Validated();
		$result = $tool->execute(
			array(
				'reference' => 'TEST-SKU-123',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		// Permission error occurs before WooCommerce check.
		$this->assertTrue(
			'wp_mcp_ai_forbidden' === $result->get_error_code() ||
			'wp_mcp_ai_woo_missing' === $result->get_error_code()
		);
	}

	/**
	 * Test capability flags are delegated.
	 */
	public function test_capability_flags() {
		$tool           = new WP_MCP_AI_Tool_Create_Woo_Product_Validated();
		$original_tool  = new WP_MCP_AI_Tool_Create_Woo_Product();
		$validated_flags = $tool->get_capability_flags();
		$original_flags  = $original_tool->get_capability_flags();

		$this->assertSame( $original_flags, $validated_flags );
	}
}
