<?php
/**
 * Characterization tests for the Wave F2 e-commerce products tool batch —
 * the ported product-management tools (create-product-advanced,
 * bulk-update-products, update-woo-product-price/qty, import-products-csv,
 * export-products-report, sync-product-inventory).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surface and
 *   the deterministic execute() gates are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/ecommerce/` are asserted in full, including the ecosystem
 *   registrations and the registry module boot.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * E-commerce products tool batch tests.
 */
class Test_Ecommerce_Product_Tools extends WP_UnitTestCase {

	/**
	 * Snapshot of the shared settings option before mutation.
	 *
	 * @var mixed
	 */
	private $settings_snapshot = null;

	/**
	 * Snapshot the shared settings option.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->settings_snapshot = get_option( 'wp_mcp_ai_settings', null );
	}

	/**
	 * Restore the shared settings option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null === $this->settings_snapshot ) {
			delete_option( 'wp_mcp_ai_settings' );
		} else {
			update_option( 'wp_mcp_ai_settings', $this->settings_snapshot );
		}
		parent::tearDown();
	}

	/**
	 * The seven ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Create_Product_Advanced'  => 'tools/ecommerce/class-wp-mcp-ai-tool-create-product-advanced.php',
			'WP_MCP_AI_Tool_Bulk_Update_Products'     => 'tools/ecommerce/class-wp-mcp-ai-tool-bulk-update-products.php',
			'WP_MCP_AI_Tool_Update_Woo_Product_Price' => 'tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-price.php',
			'WP_MCP_AI_Tool_Update_Woo_Product_Qty'   => 'tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-qty.php',
			'WP_MCP_AI_Tool_Import_Products_CSV'      => 'tools/ecommerce/class-wp-mcp-ai-tool-import-products-csv.php',
			'WP_MCP_AI_Tool_Export_Products_Report'   => 'tools/ecommerce/class-wp-mcp-ai-tool-export-products-report.php',
			'WP_MCP_AI_Tool_Sync_Product_Inventory'   => 'tools/ecommerce/class-wp-mcp-ai-tool-sync-product-inventory.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The seven tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Create_Product_Advanced'  => 'create_product_advanced',
			'WP_MCP_AI_Tool_Bulk_Update_Products'     => 'bulk_update_products',
			'WP_MCP_AI_Tool_Update_Woo_Product_Price' => 'update_woo_product_price',
			'WP_MCP_AI_Tool_Update_Woo_Product_Qty'   => 'update_woo_product_qty',
			'WP_MCP_AI_Tool_Import_Products_CSV'      => 'import_products_csv',
			'WP_MCP_AI_Tool_Export_Products_Report'   => 'export_products_report',
			'WP_MCP_AI_Tool_Sync_Product_Inventory'   => 'sync_product_inventory',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The create-product execute permission gate must fire without a user.
	 */
	public function test_create_product_forbidden_gate(): void {
		$tool   = new WP_MCP_AI_Tool_Create_Product_Advanced();
		$result = $tool->execute( array(), array() );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Standalone only: the init's tool filter must carry the products batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the e-commerce tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_ecommerce_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Create_Product_Advanced', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Sync_Product_Inventory', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-export-products-report.php',
			$tools['WP_MCP_AI_Tool_Export_Products_Report']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the products
	 * batch into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/init.php';
		wp_mcp_ai_pro_register_ecommerce_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['create_product_advanced'] ?? null );
		$this->assertNotNull( $parent->all()['sync_product_inventory'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'create_product_advanced' ) );
		$this->assertTrue( $core_tools->has( 'bulk_update_products' ) );
		$this->assertTrue( $core_tools->has( 'update_woo_product_price' ) );
		$this->assertTrue( $core_tools->has( 'update_woo_product_qty' ) );
		$this->assertTrue( $core_tools->has( 'import_products_csv' ) );
		$this->assertTrue( $core_tools->has( 'export_products_report' ) );
		$this->assertTrue( $core_tools->has( 'sync_product_inventory' ) );
	}
}
