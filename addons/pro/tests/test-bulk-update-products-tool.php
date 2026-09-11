<?php
/**
 * Tests for the Bulk Update Products tool (variable-scope expansion).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group   tools
 * @group   pro
 */

/**
 * Bulk Update Products tool tests.
 */
class WP_MCP_AI_Bulk_Update_Products_Tool_Test extends WP_UnitTestCase {

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
			? WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-bulk-update-products.php'
			: dirname( __DIR__ ) . '/includes/tools/ecommerce/class-wp-mcp-ai-tool-bulk-update-products.php';

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

		// Grant admin the WooCommerce product-management capabilities.
		$admin_user = get_user_by( 'id', $this->admin_id );
		$admin_user->add_cap( 'manage_woocommerce' );
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
		$tool = new WP_MCP_AI_Tool_Bulk_Update_Products();

		$this->assertSame( 'bulk_update_products', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test parameters schema shape, including the scope argument.
	 */
	public function test_parameters_schema() {
		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$schema = $tool->get_parameters_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'product_ids', $schema['properties'] );
		$this->assertArrayHasKey( 'filter', $schema['properties'] );
		$this->assertArrayHasKey( 'updates', $schema['properties'] );
		$this->assertArrayHasKey( 'dry_run', $schema['properties'] );
		$this->assertArrayHasKey( 'scope', $schema['properties'] );
		$this->assertContains( 'updates', $schema['required'] );
		$this->assertContains( 'all', $schema['properties']['scope']['enum'] );
		$this->assertContains( 'product', $schema['properties']['scope']['enum'] );
		$this->assertSame( 'all', $schema['properties']['scope']['default'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'database-write', $flags );
		$this->assertContains( 'requires-plugin', $flags );
	}

	// ----------------------------------------------------------------
	// Validation and permission tests.
	// ----------------------------------------------------------------

	/**
	 * Test subscribers cannot bulk-update products.
	 */
	public function test_execute_requires_permission() {
		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array(
				'product_ids' => array( 1 ),
				'updates'     => array( 'regular_price' => 5 ),
			),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test the updates object is required.
	 */
	public function test_execute_requires_updates() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array( 'product_ids' => array( 1 ) ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_updates', $result->get_error_code() );
	}

	/**
	 * Test a product selector (IDs or filter) is required.
	 */
	public function test_execute_requires_selector() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array( 'updates' => array( 'regular_price' => 5 ) ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_products', $result->get_error_code() );
	}

	// ----------------------------------------------------------------
	// Integration tests (require a real WooCommerce).
	// ----------------------------------------------------------------

	/**
	 * Test a simple-product price update round-trips through the targets shape.
	 */
	public function test_simple_product_price_roundtrip() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();

		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array(
				'product_ids' => array( $product_id ),
				'updates'     => array( 'regular_price' => '25.50' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame( 1, $result['updated_targets'] );
		$this->assertCount( 1, $result['updated_products'] );

		$entry = $result['updated_products'][0];
		$this->assertSame( $product_id, $entry['product_id'] );
		$this->assertSame( 'simple', $entry['type'] );
		$this->assertCount( 1, $entry['targets'] );
		$this->assertSame( $product_id, $entry['targets'][0]['id'] );
		$this->assertSame( '25.50', $entry['targets'][0]['changes']['regular_price'] );

		$product = wc_get_product( $product_id );
		$this->assertSame( '25.50', $product->get_regular_price() );

		wp_delete_post( $product_id, true );
	}

	/**
	 * Test scope=all expands a variable parent to its variations and re-syncs
	 * the parent.
	 */
	public function test_variable_parent_scope_all_expands_to_variations() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$ids = $this->create_variable_product();

		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array(
				'product_ids' => array( $ids['parent'] ),
				'updates'     => array( 'regular_price' => '30.00' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'all', $result['scope'] );
		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 2, $result['updated_targets'] );

		$entry = $result['updated_products'][0];
		$this->assertSame( $ids['parent'], $entry['product_id'] );
		$this->assertSame( 'variable', $entry['type'] );
		$this->assertCount( 2, $entry['targets'] );

		foreach ( $entry['targets'] as $target ) {
			$this->assertSame( 'variation', $target['type'] );
			$this->assertSame( '30.00', $target['changes']['regular_price'] );
		}

		foreach ( $ids['variations'] as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			$this->assertSame( '30.00', $variation->get_regular_price() );
		}

		// The parent was re-synced with WC_Product_Variable::sync().
		$parent = wc_get_product( $ids['parent'] );
		$this->assertSame( '30.00', $parent->get_variation_price() );

		$this->cleanup_variable_product( $ids );
	}

	/**
	 * Test scope=all expands a grouped parent to its children.
	 */
	public function test_grouped_parent_scope_all_expands_to_children() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$child_a = $this->create_simple_product();
		$child_b = $this->create_simple_product();

		$grouped = new WC_Product_Grouped();
		$grouped->set_name( 'Grouped Test Product' );
		$grouped->set_children( array( $child_a, $child_b ) );
		$grouped->save();

		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array(
				'product_ids' => array( $grouped->get_id() ),
				'updates'     => array( 'stock_quantity' => 7 ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 2, $result['updated_targets'] );

		$entry = $result['updated_products'][0];
		$this->assertSame( 'grouped', $entry['type'] );
		$this->assertCount( 2, $entry['targets'] );

		foreach ( array( $child_a, $child_b ) as $child_id ) {
			$child = wc_get_product( $child_id );
			$this->assertSame( 7, $child->get_stock_quantity() );
		}

		wp_delete_post( $grouped->get_id(), true );
		wp_delete_post( $child_a, true );
		wp_delete_post( $child_b, true );
	}

	/**
	 * Test scope=product keeps legacy exact-ID semantics on a variable parent.
	 */
	public function test_variable_parent_scope_product_keeps_exact_ids() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$ids = $this->create_variable_product();

		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array(
				'product_ids' => array( $ids['parent'] ),
				'scope'       => 'product',
				'updates'     => array( 'regular_price' => '50.00' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'product', $result['scope'] );
		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 1, $result['updated_targets'] );

		$entry = $result['updated_products'][0];
		$this->assertCount( 1, $entry['targets'] );
		$this->assertSame( $ids['parent'], $entry['targets'][0]['id'] );
		$this->assertSame( 'variable', $entry['targets'][0]['type'] );

		// Variations are untouched under scope=product.
		foreach ( $ids['variations'] as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			$this->assertSame( '20.00', $variation->get_regular_price() );
		}

		$this->cleanup_variable_product( $ids );
	}

	/**
	 * Test updates without price/stock fields do not expand a variable parent.
	 */
	public function test_non_price_stock_updates_do_not_expand() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$ids = $this->create_variable_product();

		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array(
				'product_ids' => array( $ids['parent'] ),
				'updates'     => array( 'featured' => true ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 1, $result['updated_targets'] );

		$entry = $result['updated_products'][0];
		$this->assertSame( array( 'featured' => true ), $entry['changes'] );
		$this->assertCount( 1, $entry['targets'] );
		$this->assertSame( $ids['parent'], $entry['targets'][0]['id'] );

		$parent = wc_get_product( $ids['parent'] );
		$this->assertTrue( $parent->get_featured() );

		$this->cleanup_variable_product( $ids );
	}

	/**
	 * Test status updates always apply to the selected product, not targets.
	 */
	public function test_status_applies_to_selected_product() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$ids = $this->create_variable_product();

		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array(
				'product_ids' => array( $ids['parent'] ),
				'updates'     => array(
					'regular_price' => '40.00',
					'status'        => 'draft',
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['updated_targets'] );

		$entry = $result['updated_products'][0];
		$this->assertSame( 'draft', $entry['changes']['status'] );

		$parent = wc_get_product( $ids['parent'] );
		$this->assertSame( 'draft', $parent->get_status() );

		foreach ( $ids['variations'] as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			$this->assertSame( '40.00', $variation->get_regular_price() );
		}

		$this->cleanup_variable_product( $ids );
	}

	/**
	 * Test a stock quantity of zero marks a simple product out of stock.
	 */
	public function test_zero_stock_quantity_sets_outofstock() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product();
		$product    = wc_get_product( $product_id );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 9 );
		$product->save();

		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array(
				'product_ids' => array( $product_id ),
				'updates'     => array( 'stock_quantity' => 0 ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 0, $result['updated_products'][0]['targets'][0]['changes']['stock_quantity'] );

		$product = wc_get_product( $product_id );
		$this->assertSame( 0, $product->get_stock_quantity() );
		$this->assertSame( 'outofstock', $product->get_stock_status() );

		wp_delete_post( $product_id, true );
	}

	/**
	 * Test dry runs report expansion targets without writing.
	 */
	public function test_dry_run_reports_targets_without_writing() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$ids = $this->create_variable_product();

		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array(
				'product_ids' => array( $ids['parent'] ),
				'dry_run'     => true,
				'updates'     => array( 'regular_price' => '30.00' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 2, $result['updated_targets'] );
		$this->assertCount( 2, $result['updated_products'][0]['targets'] );

		// Nothing was written.
		foreach ( $ids['variations'] as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			$this->assertSame( '20.00', $variation->get_regular_price() );
		}

		$this->cleanup_variable_product( $ids );
	}

	/**
	 * Test a childless variable parent with price fields fails per-ID.
	 */
	public function test_childless_variable_parent_fails_per_id() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Childless Variable Product' );
		$parent->save();

		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array(
				'product_ids' => array( $parent->get_id() ),
				'updates'     => array( 'regular_price' => '30.00' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame( $parent->get_id(), $result['errors'][0]['product_id'] );

		wp_delete_post( $parent->get_id(), true );
	}

	/**
	 * Test unknown product IDs land in the errors list.
	 */
	public function test_unknown_product_id_reported_in_errors() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array(
				'product_ids' => array( 999999999 ),
				'updates'     => array( 'regular_price' => '30.00' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 999999999, $result['errors'][0]['product_id'] );
	}

	/**
	 * Test an invalid scope value falls back to "all".
	 */
	public function test_invalid_scope_falls_back_to_all() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$ids = $this->create_variable_product();

		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array(
				'product_ids' => array( $ids['parent'] ),
				'scope'       => 'bogus',
				'updates'     => array( 'regular_price' => '30.00' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'all', $result['scope'] );
		$this->assertSame( 2, $result['updated_targets'] );

		$this->cleanup_variable_product( $ids );
	}

	/**
	 * Test price adjustment applies per target (variations adjust from their
	 * own prices).
	 */
	public function test_price_adjustment_applies_per_target() {
		if ( ! $this->wc_exists() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$product_id = $this->create_simple_product( '10.00' );

		$tool   = new WP_MCP_AI_Tool_Bulk_Update_Products();
		$result = $tool->execute(
			array(
				'product_ids' => array( $product_id ),
				'updates'     => array(
					'price_adjustment' => array(
						'type'   => 'percentage',
						'value'  => 10,
						'action' => 'increase',
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 11.0, $result['updated_products'][0]['targets'][0]['changes']['regular_price'] );

		$product = wc_get_product( $product_id );
		$this->assertSame( 11.0, (float) $product->get_regular_price() );

		wp_delete_post( $product_id, true );
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
