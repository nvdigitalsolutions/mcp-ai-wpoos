<?php
/**
 * Tests for WooCommerce-powered tools.
 */
class WP_MCP_AI_Woo_Tool_Availability_Test extends WP_UnitTestCase {
	/**
	 * Ensure the WooCommerce products tool reports missing dependencies.
	 */
	public function test_products_tool_requires_woocommerce() {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is already loaded for subsequent integration tests.' );
		}

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Products();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_woo_missing', $result->get_error_code() );
	}

	/**
	 * Ensure the WooCommerce orders tool reports missing dependencies.
	 */
	public function test_orders_tool_requires_woocommerce() {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is already loaded for subsequent integration tests.' );
		}

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Orders();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_woo_missing', $result->get_error_code() );
	}
}

/**
 * Execution tests with stubbed WooCommerce helpers.
 */
class WP_MCP_AI_Woo_Tool_Execution_Test extends WP_UnitTestCase {
	/**
	 * Prepare WooCommerce stubs.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		require_once __DIR__ . '/helpers/woocommerce-stubs.php';

		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( 'manage_woocommerce' );
			$role->add_cap( 'view_woocommerce_reports' );
		}
	}

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
	}

	/**
	 * Ensure unauthenticated users cannot query WooCommerce orders.
	 */
	public function test_orders_tool_requires_login() {
		$tool   = new WP_MCP_AI_Tool_Get_Woo_Orders();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure unauthenticated users cannot query WooCommerce products.
	 */
	public function test_products_tool_requires_login() {
		$tool   = new WP_MCP_AI_Tool_Get_Woo_Products();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure the orders tool enforces capability checks.
	 */
	public function test_orders_tool_requires_permissions() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Orders();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure the products tool enforces capability checks.
	 */
	public function test_products_tool_requires_permissions() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Products();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure orders return structured data for administrators.
	 */
	public function test_orders_tool_returns_recent_orders() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Orders();
		$result = $tool->execute(
			array(
				'limit'  => 10,
				'status' => 'completed',
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'completed', $result[0]['status'] );

		global $wp_mcp_ai_wc_orders_args;
		$this->assertSame( 10, $wp_mcp_ai_wc_orders_args['limit'] );
		$this->assertSame( 'completed', $wp_mcp_ai_wc_orders_args['status'] );
	}

	/**
	 * Ensure the products tool honours filters and returns structured payloads.
	 */
	public function test_products_tool_returns_filtered_products() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Products();
		$result = $tool->execute(
			array(
				'limit'        => 25,
				'sku'          => ' SKU-ONE ',
				'status'       => 'Publish',
				'stock_status' => 'InStock',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'products', $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertArrayHasKey( 'count', $result );

		// When stock_status is used, variations should be automatically included.
		// So we expect variations instead of just the parent products.
		$products = $result['products'];
		$this->assertNotEmpty( $products );

		global $wp_mcp_ai_wc_products_args;
		$this->assertSame( 20, $wp_mcp_ai_wc_products_args['limit'] );
		$this->assertSame( 'SKU-ONE', $wp_mcp_ai_wc_products_args['sku'] );
		$this->assertSame( 'publish', $wp_mcp_ai_wc_products_args['status'] );
		$this->assertSame( 'instock', $wp_mcp_ai_wc_products_args['stock_status'] );
	}

	/**
	 * Test that variable products are expanded to variations when include_variations is true.
	 */
	public function test_products_tool_includes_variations_when_requested() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Products();
		$result = $tool->execute(
			array(
				'limit'              => 10,
				'include_variations' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'products', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$products = $result['products'];

		// Should have variations for the variable product (3 variations) plus 2 simple products = 5 total.
		$this->assertCount( 5, $products );

		// Check that variations have parent_id and parent_name.
		$variation_found = false;
		foreach ( $products as $product ) {
			if ( isset( $product['parent_id'] ) && isset( $product['parent_name'] ) ) {
				$variation_found = true;
				$this->assertSame( 503, $product['parent_id'] );
				$this->assertSame( 'Variable T-Shirt', $product['parent_name'] );
				$this->assertArrayHasKey( 'attributes', $product );
				$this->assertSame( 'variation', $product['type'] );
				break;
			}
		}

		$this->assertTrue( $variation_found, 'Should find at least one variation with parent context' );
	}

	/**
	 * Test that variations are included by default (accurate stock reporting).
	 */
	public function test_products_tool_includes_variations_by_default() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Products();
		$result = $tool->execute(
			array(
				'limit' => 10,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'products', $result );

		$products = $result['products'];

		// Should have 5 products (2 simple + 3 variations from variable product).
		$this->assertCount( 5, $products );

		// Check that at least one has parent_id (it's a variation).
		$has_variation = false;
		foreach ( $products as $product ) {
			if ( isset( $product['parent_id'] ) ) {
				$has_variation = true;
				break;
			}
		}

		$this->assertTrue( $has_variation, 'Should include variations by default for accurate stock reporting' );
	}

	/**
	 * Test that variations can be explicitly disabled.
	 */
	public function test_products_tool_can_exclude_variations() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Products();
		$result = $tool->execute(
			array(
				'limit'              => 10,
				'include_variations' => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'products', $result );

		$products = $result['products'];

		// Should have 3 products (2 simple + 1 variable parent, no variations).
		$this->assertCount( 3, $products );

		// Check that none have parent_id (they're not variations).
		foreach ( $products as $product ) {
			$this->assertArrayNotHasKey( 'parent_id', $product );
		}
	}

	/**
	 * Test that stock_status filter works correctly with variations enabled by default.
	 */
	public function test_products_tool_stock_filter_with_variations() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Products();
		$result = $tool->execute(
			array(
				'limit'        => 10,
				'stock_status' => 'instock',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'products', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$products = $result['products'];

		// Should include variations by default.
		// We expect: 1 simple instock product + 2 instock variations from variable product = 3 total.
		$this->assertGreaterThanOrEqual( 2, count( $products ) );

		// Verify at least one product is a variation with accurate stock.
		$has_variation_with_stock = false;
		foreach ( $products as $product ) {
			if ( isset( $product['parent_id'] ) && isset( $product['stock_quantity'] ) && $product['stock_quantity'] > 0 ) {
				$has_variation_with_stock = true;
				break;
			}
		}

		$this->assertTrue( $has_variation_with_stock, 'Should include variations with accurate stock quantities when filtering by stock_status' );
	}

	/**
	 * Test that variations show accurate stock quantities.
	 */
	public function test_variations_show_accurate_stock_quantities() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Products();
		$result = $tool->execute(
			array(
				'limit'              => 10,
				'include_variations' => true,
			)
		);

		$this->assertIsArray( $result );
		$products = $result['products'];

		// Find variations and check their stock quantities.
		$variation_stocks = array();
		foreach ( $products as $product ) {
			if ( isset( $product['parent_id'] ) && $product['parent_id'] === 503 ) {
				$variation_stocks[ $product['id'] ] = $product['stock_quantity'];
			}
		}

		// Should have 3 variations with different stock quantities.
		$this->assertCount( 3, $variation_stocks );
		$this->assertSame( 10, $variation_stocks[5031] ); // Small - 10 in stock.
		$this->assertSame( 5, $variation_stocks[5032] );  // Medium - 5 in stock.
		$this->assertSame( 0, $variation_stocks[5033] );  // Large - 0 in stock.
	}
}
