<?php
/**
 * Characterization tests for the Wave F2 e-commerce marketing tool batch —
 * the ported tools (create-discount-campaign, abandoned-cart-recovery,
 * get-abandoned-carts, send-cart-recovery-email, upsell-recommendations,
 * sales-performance-dashboard).
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
 * E-commerce marketing tool batch tests.
 */
class Test_Ecommerce_Marketing_Tools extends WP_UnitTestCase {

	/**
	 * The six ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Create_Discount_Campaign'    => 'tools/ecommerce/class-wp-mcp-ai-tool-create-discount-campaign.php',
			'WP_MCP_AI_Tool_Abandoned_Cart_Recovery'     => 'tools/ecommerce/class-wp-mcp-ai-tool-abandoned-cart-recovery.php',
			'WP_MCP_AI_Tool_Get_Abandoned_Carts'         => 'tools/ecommerce/class-wp-mcp-ai-tool-get-abandoned-carts.php',
			'WP_MCP_AI_Tool_Send_Cart_Recovery_Email'    => 'tools/ecommerce/class-wp-mcp-ai-tool-send-cart-recovery-email.php',
			'WP_MCP_AI_Tool_Upsell_Recommendations'      => 'tools/ecommerce/class-wp-mcp-ai-tool-upsell-recommendations.php',
			'WP_MCP_AI_Tool_Sales_Performance_Dashboard' => 'tools/ecommerce/class-wp-mcp-ai-tool-sales-performance-dashboard.php',
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
	 * The six tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Create_Discount_Campaign'    => 'create_discount_campaign',
			'WP_MCP_AI_Tool_Abandoned_Cart_Recovery'     => 'abandoned_cart_recovery',
			'WP_MCP_AI_Tool_Get_Abandoned_Carts'         => 'get_abandoned_carts',
			'WP_MCP_AI_Tool_Send_Cart_Recovery_Email'    => 'send_cart_recovery_email',
			'WP_MCP_AI_Tool_Upsell_Recommendations'      => 'upsell_recommendations',
			'WP_MCP_AI_Tool_Sales_Performance_Dashboard' => 'sales_performance_dashboard',
		);

		$manage_woocommerce = array(
			'WP_MCP_AI_Tool_Get_Abandoned_Carts',
			'WP_MCP_AI_Tool_Send_Cart_Recovery_Email',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$expected_cap = in_array( $class, $manage_woocommerce, true ) ? 'manage_woocommerce' : 'edit_posts';
			$this->assertSame( $expected_cap, $tool->get_required_capability(), $class );
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
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Create_Discount_Campaign', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Sales_Performance_Dashboard', $tools );
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
		$this->assertNotNull( $parent->all()['create_discount_campaign'] ?? null );
		$this->assertNotNull( $parent->all()['sales_performance_dashboard'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'create_discount_campaign' ) );
		$this->assertTrue( $core_tools->has( 'abandoned_cart_recovery' ) );
		$this->assertTrue( $core_tools->has( 'get_abandoned_carts' ) );
		$this->assertTrue( $core_tools->has( 'send_cart_recovery_email' ) );
		$this->assertTrue( $core_tools->has( 'upsell_recommendations' ) );
		$this->assertTrue( $core_tools->has( 'sales_performance_dashboard' ) );
	}
}
