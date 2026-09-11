<?php
/**
 * Characterization tests for the Wave F2 e-commerce shopify tool batch —
 * the ported Shopify tools (products/orders/customers/inventory/catalog),
 * the `WP_MCP_AI_Shopify_Client`, and the D8-compat product-card trait.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surface and
 *   the Shopify client constants are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/ecommerce/` + `src/` are asserted in full, including the
 *   ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * E-commerce shopify tool batch tests.
 */
class Test_Ecommerce_Shopify_Tools extends WP_UnitTestCase {

	/**
	 * The seven ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Pro_Tool_Shopify_Products'  => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-products.php',
			'WP_MCP_AI_Pro_Tool_Shopify_Orders'    => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-orders.php',
			'WP_MCP_AI_Pro_Tool_Shopify_Customers' => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-customers.php',
			'WP_MCP_AI_Pro_Tool_Shopify_Inventory' => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-inventory.php',
			'WP_MCP_AI_Pro_Tool_Shopify_Catalog'   => 'tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-catalog.php',
			'WP_MCP_AI_Shopify_Client'             => 'class-wp-mcp-ai-shopify-client.php',
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

		// The product-card trait is base-owned (includes/tools/) — served by
		// the base plugin monolith, by the addon's D8-compat copy standalone.
		$trait_reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Product_Card' );
		$trait_path       = str_replace( '\\', '/', (string) $trait_reflection->getFileName() );
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'includes/tools/trait-wp-mcp-ai-tool-product-card.php', $trait_path );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/tools/trait-wp-mcp-ai-tool-product-card.php', $trait_path );
		}
	}

	/**
	 * The five tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Pro_Tool_Shopify_Products'  => 'shopify_products',
			'WP_MCP_AI_Pro_Tool_Shopify_Orders'    => 'shopify_orders',
			'WP_MCP_AI_Pro_Tool_Shopify_Customers' => 'shopify_customers',
			'WP_MCP_AI_Pro_Tool_Shopify_Inventory' => 'shopify_inventory',
			'WP_MCP_AI_Pro_Tool_Shopify_Catalog'   => 'shopify_catalog',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
		}
	}

	/**
	 * The Shopify client must resolve its base URL constants byte-identically.
	 */
	public function test_shopify_client_surface(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Shopify_Client' );
		$constants  = $reflection->getConstants();
		$this->assertSame( '2025-01', $constants['DEFAULT_API_VERSION'] );
		$this->assertSame( '2025-04', $constants['LATEST_KNOWN_VERSION'] );
		$this->assertSame( 5242880, $constants['MAX_RESPONSE_SIZE'] );
		$this->assertSame( 30, $constants['DEFAULT_TIMEOUT'] );
		$this->assertSame( 'https://discover.shopifyapps.com', $constants['CATALOG_BASE_URL'] );
	}

	/**
	 * Standalone only: the init's tool filter must carry the shopify batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the e-commerce tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_ecommerce_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Shopify_Products', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Shopify_Catalog', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the shopify
	 * batch into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/init.php';
		wp_mcp_ai_pro_register_ecommerce_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['shopify_products'] ?? null );
		$this->assertNotNull( $parent->all()['shopify_catalog'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'shopify_products' ) );
		$this->assertTrue( $core_tools->has( 'shopify_orders' ) );
		$this->assertTrue( $core_tools->has( 'shopify_customers' ) );
		$this->assertTrue( $core_tools->has( 'shopify_inventory' ) );
		$this->assertTrue( $core_tools->has( 'shopify_catalog' ) );
	}
}
