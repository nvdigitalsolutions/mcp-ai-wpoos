<?php
/**
 * Characterization tests for the Wave F2 e-commerce always-on extras batch —
 * the ported tools (import duty, QuickBooks report/desktop sync, printful,
 * product actualization, lookup product price, validate-image for
 * product/vehicle) and their standalone degradation guards.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surface and
 *   capabilities are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/ecommerce/` are asserted in full, including the ecosystem
 *   registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * E-commerce extras tool batch tests.
 */
class Test_Ecommerce_Extras extends WP_UnitTestCase {

	/**
	 * The eight ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Pro_Tool_Get_Import_Duty'         => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-get-import-duty.php',
			'WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report'   => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-get-quickbooks-report.php',
			'WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync' => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-quickbooks-desktop-sync.php',
			'WP_MCP_AI_Pro_Tool_Printful'                => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-printful.php',
			'WP_MCP_AI_Pro_Tool_Product_Actualization'   => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-product-actualization.php',
			'WP_MCP_AI_Pro_Tool_Lookup_Product_Price'    => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-lookup-product-price.php',
			'WP_MCP_AI_Pro_Tool_Validate_Image_For_Product' => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php',
			'WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle' => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php',
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
	 * The eight tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Pro_Tool_Get_Import_Duty'         => 'get_import_duty',
			'WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report'   => 'quickbooks_report',
			'WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync' => 'quickbooks_desktop_sync',
			'WP_MCP_AI_Pro_Tool_Printful'                => 'printful',
			'WP_MCP_AI_Pro_Tool_Product_Actualization'   => 'product_actualization',
			'WP_MCP_AI_Pro_Tool_Lookup_Product_Price'    => 'lookup_product_price',
			'WP_MCP_AI_Pro_Tool_Validate_Image_For_Product' => 'validate_image_for_product',
			'WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle' => 'validate_image_for_vehicle',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
		}
	}

	/**
	 * Every tool must declare the edit_posts capability gate.
	 */
	public function test_capabilities(): void {
		$classes = array(
			'WP_MCP_AI_Pro_Tool_Get_Import_Duty',
			'WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report',
			'WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync',
			'WP_MCP_AI_Pro_Tool_Printful',
			'WP_MCP_AI_Pro_Tool_Product_Actualization',
			'WP_MCP_AI_Pro_Tool_Lookup_Product_Price',
			'WP_MCP_AI_Pro_Tool_Validate_Image_For_Product',
			'WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle',
		);

		foreach ( $classes as $class ) {
			$tool = new $class();
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The pro-tier tools must flag themselves appropriately; the two
	 * validate-image tools flag vision-model/credential requirements.
	 */
	public function test_capability_flags(): void {
		$pro_flags = array(
			'WP_MCP_AI_Pro_Tool_Get_Import_Duty',
			'WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report',
			'WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync',
			'WP_MCP_AI_Pro_Tool_Product_Actualization',
			'WP_MCP_AI_Pro_Tool_Lookup_Product_Price',
		);

		foreach ( $pro_flags as $class ) {
			$tool  = new $class();
			$flags = $tool->get_capability_flags();
			$this->assertContains( 'pro', $flags, $class );
		}

		$vision_flags = array(
			'WP_MCP_AI_Pro_Tool_Validate_Image_For_Product',
			'WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle',
		);

		foreach ( $vision_flags as $class ) {
			$tool  = new $class();
			$flags = $tool->get_capability_flags();
			$this->assertContains( 'requires-credentials', $flags, $class );
			$this->assertContains( 'requires-vision-model', $flags, $class );
		}
	}

	/**
	 * Standalone only: the init's tool filter must carry the extras batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the e-commerce tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_ecommerce_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Get_Import_Duty', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Printful', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Product_Actualization', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Lookup_Product_Price', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Validate_Image_For_Product', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the extras
	 * batch into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/init.php';
		wp_mcp_ai_pro_register_ecommerce_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['get_import_duty'] ?? null );
		$this->assertNotNull( $parent->all()['printful'] ?? null );
		$this->assertNotNull( $parent->all()['validate_image_for_product'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'get_import_duty' ) );
		$this->assertTrue( $core_tools->has( 'quickbooks_report' ) );
		$this->assertTrue( $core_tools->has( 'quickbooks_desktop_sync' ) );
		$this->assertTrue( $core_tools->has( 'printful' ) );
		$this->assertTrue( $core_tools->has( 'product_actualization' ) );
		$this->assertTrue( $core_tools->has( 'lookup_product_price' ) );
		$this->assertTrue( $core_tools->has( 'validate_image_for_product' ) );
		$this->assertTrue( $core_tools->has( 'validate_image_for_vehicle' ) );
	}
}
