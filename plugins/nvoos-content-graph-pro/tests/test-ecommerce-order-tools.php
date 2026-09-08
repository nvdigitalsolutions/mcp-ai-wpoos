<?php
/**
 * Characterization tests for the Wave F2 e-commerce orders tool batch —
 * the ported order-management tools (bulk-order-status-update,
 * get-order-analytics, process-order-workflow, refund-order-advanced).
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
 * E-commerce orders tool batch tests.
 */
class Test_Ecommerce_Order_Tools extends WP_UnitTestCase {

	/**
	 * The four ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Bulk_Order_Status_Update' => 'tools/ecommerce/class-wp-mcp-ai-tool-bulk-order-status-update.php',
			'WP_MCP_AI_Tool_Get_Order_Analytics'      => 'tools/ecommerce/class-wp-mcp-ai-tool-get-order-analytics.php',
			'WP_MCP_AI_Tool_Process_Order_Workflow'   => 'tools/ecommerce/class-wp-mcp-ai-tool-process-order-workflow.php',
			'WP_MCP_AI_Tool_Refund_Order_Advanced'    => 'tools/ecommerce/class-wp-mcp-ai-tool-refund-order-advanced.php',
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
	 * The four tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Bulk_Order_Status_Update' => 'bulk_order_status_update',
			'WP_MCP_AI_Tool_Get_Order_Analytics'      => 'get_order_analytics',
			'WP_MCP_AI_Tool_Process_Order_Workflow'   => 'process_order_workflow',
			'WP_MCP_AI_Tool_Refund_Order_Advanced'    => 'refund_order_advanced',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * Standalone only: the init's tool filter must carry the orders batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the e-commerce tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_ecommerce_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Bulk_Order_Status_Update', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Order_Analytics', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Process_Order_Workflow', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Refund_Order_Advanced', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the orders
	 * batch into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/init.php';
		wp_mcp_ai_pro_register_ecommerce_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['bulk_order_status_update'] ?? null );
		$this->assertNotNull( $parent->all()['refund_order_advanced'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'bulk_order_status_update' ) );
		$this->assertTrue( $core_tools->has( 'get_order_analytics' ) );
		$this->assertTrue( $core_tools->has( 'process_order_workflow' ) );
		$this->assertTrue( $core_tools->has( 'refund_order_advanced' ) );
	}
}
