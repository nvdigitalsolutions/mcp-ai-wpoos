<?php
/**
 * Characterization tests for the Wave F2 e-commerce customers + inventory
 * tool batch — the ported tools (segment-customers, customer-lifetime-value,
 * export-customer-data, track-inventory-movement, low-stock-alert-automation,
 * inventory-forecast).
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
 * E-commerce customers + inventory tool batch tests.
 */
class Test_Ecommerce_Customer_Inventory extends WP_UnitTestCase {

	/**
	 * The six ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Segment_Customers'          => 'tools/ecommerce/class-wp-mcp-ai-tool-segment-customers.php',
			'WP_MCP_AI_Tool_Customer_Lifetime_Value'    => 'tools/ecommerce/class-wp-mcp-ai-tool-customer-lifetime-value.php',
			'WP_MCP_AI_Tool_Export_Customer_Data'       => 'tools/ecommerce/class-wp-mcp-ai-tool-export-customer-data.php',
			'WP_MCP_AI_Tool_Track_Inventory_Movement'   => 'tools/ecommerce/class-wp-mcp-ai-tool-track-inventory-movement.php',
			'WP_MCP_AI_Tool_Low_Stock_Alert_Automation' => 'tools/ecommerce/class-wp-mcp-ai-tool-low-stock-alert-automation.php',
			'WP_MCP_AI_Tool_Inventory_Forecast'         => 'tools/ecommerce/class-wp-mcp-ai-tool-inventory-forecast.php',
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
			'WP_MCP_AI_Tool_Segment_Customers'          => 'segment_customers',
			'WP_MCP_AI_Tool_Customer_Lifetime_Value'    => 'customer_lifetime_value',
			'WP_MCP_AI_Tool_Export_Customer_Data'       => 'export_customer_data',
			'WP_MCP_AI_Tool_Track_Inventory_Movement'   => 'track_inventory_movement',
			'WP_MCP_AI_Tool_Low_Stock_Alert_Automation' => 'low_stock_alert_automation',
			'WP_MCP_AI_Tool_Inventory_Forecast'         => 'inventory_forecast',
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
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Segment_Customers', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Inventory_Forecast', $tools );
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
		$this->assertNotNull( $parent->all()['segment_customers'] ?? null );
		$this->assertNotNull( $parent->all()['inventory_forecast'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'segment_customers' ) );
		$this->assertTrue( $core_tools->has( 'customer_lifetime_value' ) );
		$this->assertTrue( $core_tools->has( 'export_customer_data' ) );
		$this->assertTrue( $core_tools->has( 'track_inventory_movement' ) );
		$this->assertTrue( $core_tools->has( 'low_stock_alert_automation' ) );
		$this->assertTrue( $core_tools->has( 'inventory_forecast' ) );
	}
}
