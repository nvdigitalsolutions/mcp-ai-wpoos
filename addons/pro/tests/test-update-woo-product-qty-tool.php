<?php
/**
 * Tests for the Update WooCommerce Product Quantity tool.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group   tools
 * @group   pro
 */

/**
 * Update Woo Product Quantity tool tests.
 */
class WP_MCP_AI_Update_Woo_Product_Qty_Tool_Test extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Load tool files.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$tool_path = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-qty.php'
			: dirname( __DIR__ ) . '/includes/tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-qty.php';

		if ( file_exists( $tool_path ) ) {
			require_once $tool_path;
		}
	}

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->admin_id );

		// Grant admin the WooCommerce product-editing capability.
		$admin_user = get_user_by( 'id', $this->admin_id );
		$admin_user->add_cap( 'edit_products' );

		// Enable the e-commerce toolkit feature flag.
		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_ecommerce_toolkit' => true )
		);
	}

	/**
	 * Whether a real WooCommerce installation is available.
	 *
	 * @return bool
	 */
	protected function wc_exists() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	// ----------------------------------------------------------------
	// Metadata, schema, and gating.
	// ----------------------------------------------------------------

	/**
	 * Test tool slug, name, and description.
	 */
	public function test_tool_metadata() {
		$tool = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();

		$this->assertSame( 'update_woo_product_qty', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test parameters schema shape.
	 */
	public function test_parameters_schema() {
		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();
		$schema = $tool->get_parameters_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'product_id', $schema['properties'] );
		$this->assertArrayHasKey( 'quantity', $schema['properties'] );
		$this->assertArrayHasKey( 'operation', $schema['properties'] );
		$this->assertArrayHasKey( 'scope', $schema['properties'] );
		$this->assertArrayHasKey( 'manage_stock', $schema['properties'] );
		$this->assertContains( 'product_id', $schema['required'] );
		$this->assertContains( 'quantity', $schema['required'] );
		$this->assertContains( 'increase', $schema['properties']['operation']['enum'] );
		$this->assertContains( 'decrease', $schema['properties']['operation']['enum'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'requires-plugin', $flags );
		$this->assertContains( 'local-only', $flags );
	}

	/**
	 * Test safety profile is provided for destructive-op gating.
	 */
	public function test_safety_profile() {
		$tool    = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();
		$profile = $tool->get_safety_profile();

		$this->assertIsArray( $profile );
		$this->assertArrayHasKey( 'irreversibility_score', $profile );
		$this->assertArrayHasKey( 'minimum_necessity', $profile );
	}

	/**
	 * Test availability depends on the e-commerce toolkit option.
	 */
	public function test_is_available_requires_toolkit_option() {
		delete_option( 'wp_mcp_ai_settings' );
		$this->assertFalse( WP_MCP_AI_Tool_Update_Woo_Product_Qty::is_available() );

		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_ecommerce_toolkit' => true )
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$this->assertTrue( WP_MCP_AI_Tool_Update_Woo_Product_Qty::is_available() );
		} else {
			// Without WooCommerce the tool stays unavailable regardless of the toolkit option.
			$this->assertFalse( WP_MCP_AI_Tool_Update_Woo_Product_Qty::is_available() );
		}
	}

	// ----------------------------------------------------------------
	// Validation and permission tests.
	// ----------------------------------------------------------------

	/**
	 * Test execute requires WooCommerce.
	 */
	public function test_execute_requires_woocommerce() {
		if ( $this->wc_exists() ) {
			$this->markTestSkipped( 'Real WooCommerce is loaded for integration tests.' );
		}

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();
		$result = $tool->execute(
			array(
				'product_id' => 123,
				'quantity'   => 5,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_not_active', $result->get_error_code() );
	}

	/**
	 * Test execute requires quantity.
	 */
	public function test_execute_requires_quantity() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();
		$result = $tool->execute( array( 'product_id' => $product_id ), array( 'user_id' => $this->admin_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_quantity', $result->get_error_code() );

		wp_delete_post( $product_id, true );
	}

	/**
	 * Test negative quantities are rejected.
	 */
	public function test_negative_quantity_rejected() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();
		$result = $tool->execute(
			array(
				'product_id' => $product_id,
				'quantity'   => -5,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_quantity', $result->get_error_code() );

		wp_delete_post( $product_id, true );
	}

	/**
	 * Test subscribers cannot update quantities.
	 */
	public function test_execute_requires_permission() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();
		$result = $tool->execute(
			array(
				'product_id' => $product_id,
				'quantity'   => 10,
			),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'permission_denied', $result->get_error_code() );

		wp_delete_post( $product_id, true );
	}

	// ----------------------------------------------------------------
	// Integration tests (require a real WooCommerce).
	// ----------------------------------------------------------------

	/**
	 * Test the set operation round-trips a stock quantity.
	 */
	public function test_set_quantity_roundtrip() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();
		$result = $tool->execute(
			array(
				'product_id' => $product_id,
				'quantity'   => 42,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( $product_id, $result['product_id'] );
		$this->assertCount( 1, $result['updated'] );
		$this->assertSame( 42, $result['updated'][0]['after_qty'] );

		$product = wc_get_product( $product_id );
		$this->assertSame( 42, $product->get_stock_quantity() );
		$this->assertTrue( $product->get_manage_stock() );

		wp_delete_post( $product_id, true );
	}

	/**
	 * Test increase and decrease operations.
	 */
	public function test_increase_decrease_operations() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();
		$product    = wc_get_product( $product_id );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$product->save();

		$tool = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();

		$increase = $tool->execute(
			array(
				'product_id' => $product_id,
				'quantity'   => 5,
				'operation'  => 'increase',
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertIsArray( $increase );
		$this->assertSame( 15, $increase['updated'][0]['after_qty'] );

		$decrease = $tool->execute(
			array(
				'product_id' => $product_id,
				'quantity'   => 3,
				'operation'  => 'decrease',
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertIsArray( $decrease );
		$this->assertSame( 12, $decrease['updated'][0]['after_qty'] );

		$product = wc_get_product( $product_id );
		$this->assertSame( 12, $product->get_stock_quantity() );

		wp_delete_post( $product_id, true );
	}

	/**
	 * Test decrease clamps at zero.
	 */
	public function test_decrease_clamps_at_zero() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();
		$product    = wc_get_product( $product_id );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 2 );
		$product->save();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();
		$result = $tool->execute(
			array(
				'product_id' => $product_id,
				'quantity'   => 10,
				'operation'  => 'decrease',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['updated'][0]['after_qty'] );

		$product = wc_get_product( $product_id );
		$this->assertSame( 0, $product->get_stock_quantity() );
		$this->assertSame( 'outofstock', $product->get_stock_status() );

		wp_delete_post( $product_id, true );
	}

	/**
	 * Test setting zero quantity marks the product out of stock.
	 */
	public function test_zero_quantity_sets_outofstock() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();
		$product    = wc_get_product( $product_id );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 7 );
		$product->save();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();
		$result = $tool->execute(
			array(
				'product_id' => $product_id,
				'quantity'   => 0,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['updated'][0]['after_qty'] );

		$product = wc_get_product( $product_id );
		$this->assertSame( 'outofstock', $product->get_stock_status() );

		wp_delete_post( $product_id, true );
	}

	/**
	 * Test a variable parent with scope=product is rejected.
	 */
	public function test_variable_parent_scope_product_rejected() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$ids = $this->create_variable_product();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();
		$result = $tool->execute(
			array(
				'product_id' => $ids['parent'],
				'scope'      => 'product',
				'quantity'   => 20,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_scope', $result->get_error_code() );

		$this->cleanup_variable_product( $ids );
	}

	/**
	 * Test scope=all updates every variation of a variable product.
	 */
	public function test_variable_scope_all_updates_variations() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$ids = $this->create_variable_product();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();
		$result = $tool->execute(
			array(
				'product_id' => $ids['parent'],
				'scope'      => 'all',
				'quantity'   => 25,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['updated'] );

		foreach ( $ids['variations'] as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			$this->assertSame( 25, $variation->get_stock_quantity() );
		}

		$this->cleanup_variable_product( $ids );
	}

	/**
	 * Test external products are rejected (not stock-managed).
	 */
	public function test_external_product_rejected() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product = new WC_Product_External();
		$product->set_name( 'External Test Product' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();
		$result = $tool->execute(
			array(
				'product_id' => $product->get_id(),
				'quantity'   => 10,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsupported_type', $result->get_error_code() );

		wp_delete_post( $product->get_id(), true );
	}

	/**
	 * Test grouped products update through their child products.
	 */
	public function test_grouped_scope_all_updates_children() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$child_a = $this->create_simple_product();
		$child_b = $this->create_simple_product();

		$grouped = new WC_Product_Grouped();
		$grouped->set_name( 'Grouped Test Product' );
		$grouped->set_children( array( $child_a, $child_b ) );
		$grouped->save();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Qty();
		$result = $tool->execute(
			array(
				'product_id' => $grouped->get_id(),
				'scope'      => 'all',
				'quantity'   => 8,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['updated'] );

		foreach ( array( $child_a, $child_b ) as $child_id ) {
			$child = wc_get_product( $child_id );
			$this->assertSame( 8, $child->get_stock_quantity() );
		}

		wp_delete_post( $grouped->get_id(), true );
		wp_delete_post( $child_a, true );
		wp_delete_post( $child_b, true );
	}

	// ----------------------------------------------------------------
	// Fixtures.
	// ----------------------------------------------------------------

	/**
	 * Create a simple product.
	 *
	 * @param string $price Regular price.
	 * @return int Product ID.
	 */
	protected function create_simple_product( $price = '10.00' ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Simple Test Product' );
		$product->set_regular_price( $price );
		$product->save();

		return $product->get_id();
	}

	/**
	 * Create a variable product with two variations.
	 *
	 * @return array{parent:int,variations:int[]} Parent ID and variation IDs.
	 */
	protected function create_variable_product() {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'Small', 'Large' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Variable Test Product' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();

		$variations = array();
		foreach ( array( 'Small', 'Large' ) as $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'Size' => $size ) );
			$variation->set_regular_price( '20.00' );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 5 );
			$variation->save();
			$variations[] = $variation->get_id();
		}

		return array(
			'parent'     => $parent->get_id(),
			'variations' => $variations,
		);
	}

	/**
	 * Clean up a variable product and its variations.
	 *
	 * @param array $ids Parent/variations IDs from create_variable_product().
	 * @return void
	 */
	protected function cleanup_variable_product( $ids ) {
		foreach ( $ids['variations'] as $variation_id ) {
			wp_delete_post( $variation_id, true );
		}
		wp_delete_post( $ids['parent'], true );
	}
}
