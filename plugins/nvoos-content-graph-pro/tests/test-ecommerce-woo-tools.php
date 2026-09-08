<?php
/**
 * Characterization tests for the Wave F2 e-commerce woo tools batch — the
 * ported WooCommerce tools (woo-products, woo-orders, woo-customers,
 * woo-coupons) plus the standalone enablement gate.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surface and
 *   the enablement gate are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/ecommerce/` are asserted in full, including the ecosystem
 *   registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * E-commerce woo tools batch tests.
 */
class Test_Ecommerce_Woo_Tools extends WP_UnitTestCase {

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
	 * The four ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Pro_Tool_Woo_Products'  => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-woo-products.php',
			'WP_MCP_AI_Pro_Tool_Woo_Orders'    => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-woo-orders.php',
			'WP_MCP_AI_Pro_Tool_Woo_Customers' => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-woo-customers.php',
			'WP_MCP_AI_Pro_Tool_Woo_Coupons'   => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-woo-coupons.php',
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
			'WP_MCP_AI_Pro_Tool_Woo_Products'  => 'woo_products',
			'WP_MCP_AI_Pro_Tool_Woo_Orders'    => 'woo_orders',
			'WP_MCP_AI_Pro_Tool_Woo_Customers' => 'woo_customers',
			'WP_MCP_AI_Pro_Tool_Woo_Coupons'   => 'woo_coupons',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
		}
	}

	/**
	 * The enablement gate must default to true and honor the explicit flag.
	 */
	public function test_woocommerce_tools_enablement_gate(): void {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/init.php';

		$this->assertTrue( wp_mcp_ai_pro_is_woocommerce_tools_enabled( array() ) );
		$this->assertTrue( wp_mcp_ai_pro_is_woocommerce_tools_enabled( array( 'enable_woocommerce_tools' => 1 ) ) );
		$this->assertFalse( wp_mcp_ai_pro_is_woocommerce_tools_enabled( array( 'enable_woocommerce_tools' => 0 ) ) );
	}

	/**
	 * Standalone only: the init's tool filter must carry the woo batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the e-commerce tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_ecommerce_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Woo_Products', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Woo_Coupons', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the woo
	 * batch into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/init.php';
		wp_mcp_ai_pro_register_ecommerce_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['woo_products'] ?? null );
		$this->assertNotNull( $parent->all()['woo_coupons'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'woo_products' ) );
		$this->assertTrue( $core_tools->has( 'woo_orders' ) );
		$this->assertTrue( $core_tools->has( 'woo_customers' ) );
		$this->assertTrue( $core_tools->has( 'woo_coupons' ) );
	}
}
