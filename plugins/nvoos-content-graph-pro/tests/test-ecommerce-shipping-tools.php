<?php
/**
 * Characterization tests for the Wave F2 e-commerce shipping + invoice tool
 * batch — the ported tools (shipping-box-packer, shipping-rate-estimator,
 * generate-woocommerce-order-invoice-pdf).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surface is
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/ecommerce/` are asserted in full, including the ecosystem
 *   registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * E-commerce shipping + invoice tool batch tests.
 */
class Test_Ecommerce_Shipping_Tools extends WP_UnitTestCase {

	/**
	 * The three ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Shipping_Box_Packer'     => 'tools/ecommerce/class-wp-mcp-ai-tool-shipping-box-packer.php',
			'WP_MCP_AI_Tool_Shipping_Rate_Estimator' => 'tools/ecommerce/class-wp-mcp-ai-tool-shipping-rate-estimator.php',
			'WP_MCP_AI_Tool_Generate_WooCommerce_Order_Invoice_PDF' => 'tools/ecommerce/class-wp-mcp-ai-tool-generate-woocommerce-order-invoice-pdf.php',
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
	 * The three tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Shipping_Box_Packer'     => 'shipping_box_packer',
			'WP_MCP_AI_Tool_Shipping_Rate_Estimator' => 'shipping_rate_estimator',
			'WP_MCP_AI_Tool_Generate_WooCommerce_Order_Invoice_PDF' => 'generate_invoice_pdf',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * Standalone only: the init's tool filter must carry the batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the e-commerce tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_ecommerce_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Shipping_Box_Packer', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Generate_WooCommerce_Order_Invoice_PDF', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the batch
	 * into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/init.php';
		wp_mcp_ai_pro_register_ecommerce_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['shipping_box_packer'] ?? null );
		$this->assertNotNull( $parent->all()['generate_invoice_pdf'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'shipping_box_packer' ) );
		$this->assertTrue( $core_tools->has( 'shipping_rate_estimator' ) );
		$this->assertTrue( $core_tools->has( 'generate_invoice_pdf' ) );
	}
}
