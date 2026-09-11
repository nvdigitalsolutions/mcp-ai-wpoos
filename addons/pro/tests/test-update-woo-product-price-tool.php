<?php
/**
 * Tests for the Update WooCommerce Product Price tool.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group   tools
 * @group   pro
 */

/**
 * Update Woo Product Price tool tests.
 */
class WP_MCP_AI_Update_Woo_Product_Price_Tool_Test extends WP_UnitTestCase {

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
	 * Load tool files and grant capabilities.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$tool_path = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-price.php'
			: dirname( __DIR__ ) . '/includes/tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-price.php';

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
		$tool = new WP_MCP_AI_Tool_Update_Woo_Product_Price();

		$this->assertSame( 'update_woo_product_price', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test parameters schema shape.
	 */
	public function test_parameters_schema() {
		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
		$schema = $tool->get_parameters_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'product_id', $schema['properties'] );
		$this->assertArrayHasKey( 'scope', $schema['properties'] );
		$this->assertArrayHasKey( 'regular_price', $schema['properties'] );
		$this->assertArrayHasKey( 'sale_price', $schema['properties'] );
		$this->assertArrayHasKey( 'sale_date_from', $schema['properties'] );
		$this->assertArrayHasKey( 'sale_date_to', $schema['properties'] );
		$this->assertArrayHasKey( 'clear_sale', $schema['properties'] );
		$this->assertContains( 'product_id', $schema['required'] );
		$this->assertContains( 'all', $schema['properties']['scope']['enum'] );
		$this->assertContains( 'variations', $schema['properties']['scope']['enum'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
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
		$tool    = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
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
		$this->assertFalse( WP_MCP_AI_Tool_Update_Woo_Product_Price::is_available() );

		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_ecommerce_toolkit' => true )
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$this->assertTrue( WP_MCP_AI_Tool_Update_Woo_Product_Price::is_available() );
		} else {
			// Without WooCommerce the tool stays unavailable regardless of the toolkit option.
			$this->assertFalse( WP_MCP_AI_Tool_Update_Woo_Product_Price::is_available() );
		}
	}

	// ----------------------------------------------------------------
	// Validation and permission tests (no WooCommerce data needed).
	// ----------------------------------------------------------------

	/**
	 * Test execute requires WooCommerce.
	 */
	public function test_execute_requires_woocommerce() {
		if ( $this->wc_exists() ) {
			$this->markTestSkipped( 'Real WooCommerce is loaded for integration tests.' );
		}

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
		$result = $tool->execute( array( 'product_id' => 123 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_not_active', $result->get_error_code() );
	}

	/**
	 * Test execute requires the product ID.
	 */
	public function test_execute_requires_product_id() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
		$result = $tool->execute( array(), array( 'user_id' => $this->admin_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_product_id', $result->get_error_code() );
	}

	/**
	 * Test execute requires at least one price field.
	 */
	public function test_execute_requires_price_field() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
		$result = $tool->execute( array( 'product_id' => $product_id ), array( 'user_id' => $this->admin_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_price_field', $result->get_error_code() );

		wp_delete_post( $product_id, true );
	}

	/**
	 * Test subscribers cannot update prices.
	 */
	public function test_execute_requires_permission() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
		$result = $tool->execute(
			array(
				'product_id'    => $product_id,
				'regular_price' => '99.99',
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
	 * Test updating a simple product's regular price.
	 */
	public function test_simple_price_update_roundtrip() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
		$result = $tool->execute(
			array(
				'product_id'    => $product_id,
				'regular_price' => '49.99',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( $product_id, $result['product_id'] );
		$this->assertSame( 'simple', $result['product_type'] );
		$this->assertCount( 1, $result['updated'] );

		$product = wc_get_product( $product_id );
		$this->assertSame( '49.99', $product->get_regular_price() );

		wp_delete_post( $product_id, true );
	}

	/**
	 * Test sale price validation rejects sale >= regular.
	 */
	public function test_sale_price_must_be_lower_than_regular() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
		$result = $tool->execute(
			array(
				'product_id'    => $product_id,
				'regular_price' => '10.00',
				'sale_price'    => '12.00',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_sale_price', $result->get_error_code() );

		wp_delete_post( $product_id, true );
	}

	/**
	 * Test sale dates validation.
	 */
	public function test_invalid_sale_dates_rejected() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
		$result = $tool->execute(
			array(
				'product_id'     => $product_id,
				'sale_price'     => '5.00',
				'sale_date_from' => '2026-12-31',
				'sale_date_to'   => '2026-01-01',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_sale_dates', $result->get_error_code() );

		wp_delete_post( $product_id, true );
	}

	/**
	 * Test clearing a sale price.
	 */
	public function test_clear_sale() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();
		$product    = wc_get_product( $product_id );
		$product->set_sale_price( '8.00' );
		$product->save();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
		$result = $tool->execute(
			array(
				'product_id' => $product_id,
				'clear_sale' => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );

		$product = wc_get_product( $product_id );
		$this->assertSame( '', $product->get_sale_price() );
		$this->assertFalse( $product->is_on_sale() );

		wp_delete_post( $product_id, true );
	}

	/**
	 * Test that a variable parent with scope=product is rejected with guidance.
	 */
	public function test_variable_parent_scope_product_rejected() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$ids = $this->create_variable_product();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
		$result = $tool->execute(
			array(
				'product_id'    => $ids['parent'],
				'scope'         => 'product',
				'regular_price' => '99.99',
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

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
		$result = $tool->execute(
			array(
				'product_id'    => $ids['parent'],
				'scope'         => 'all',
				'regular_price' => '39.99',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['updated'] );

		foreach ( $ids['variations'] as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			$this->assertSame( '39.99', $variation->get_regular_price() );
		}

		$this->cleanup_variable_product( $ids );
	}

	/**
	 * Test updating a variation directly by its ID.
	 */
	public function test_variation_id_direct_update() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$ids = $this->create_variable_product();
		$vid = $ids['variations'][0];

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
		$result = $tool->execute(
			array(
				'product_id'    => $vid,
				'regular_price' => '12.50',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( $vid, $result['product_id'] );
		$this->assertSame( 'variation', $result['product_type'] );

		$variation = wc_get_product( $vid );
		$this->assertSame( '12.50', $variation->get_regular_price() );

		$this->cleanup_variable_product( $ids );
	}

	/**
	 * Test grouped products update through their child products.
	 */
	public function test_grouped_scope_all_updates_children() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$child_a = $this->create_simple_product( '5.00' );
		$child_b = $this->create_simple_product( '6.00' );

		$grouped = new WC_Product_Grouped();
		$grouped->set_name( 'Grouped Test Product' );
		$grouped->set_children( array( $child_a, $child_b ) );
		$grouped->save();

		$tool   = new WP_MCP_AI_Tool_Update_Woo_Product_Price();
		$result = $tool->execute(
			array(
				'product_id'    => $grouped->get_id(),
				'scope'         => 'all',
				'regular_price' => '7.50',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['updated'] );

		foreach ( array( $child_a, $child_b ) as $child_id ) {
			$child = wc_get_product( $child_id );
			$this->assertSame( '7.50', $child->get_regular_price() );
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
