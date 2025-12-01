<?php
/**
 * Test Pro Tools Loading in Combined Plugin
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Pro tools loading.
 */
class Test_Pro_Tools_Loading extends WP_UnitTestCase {
	/**
	 * Test that Pro addon is loaded when bundled in combined plugin.
	 */
	public function test_pro_addon_loaded_in_combined_plugin() {
		// Pro addon should be loaded.
		$this->assertTrue(
			defined( 'WP_MCP_AI_PRO_VERSION' ),
			'Pro addon constant should be defined when Pro addon is bundled'
		);

		// Pro addon file should exist.
		$pro_file = WP_MCP_AI_PATH . 'addons/pro/wp-mcp-ai-pro.php';
		$this->assertFileExists( $pro_file, 'Pro addon file should exist' );
	}

	/**
	 * Test that Pro tools are registered in tool registry.
	 * 
	 * Note: Individual Pro tools may not be registered if their dependencies
	 * (WooCommerce, JetEngine, Elementor, Crawl4AI, imagick/gd) are not available.
	 * This test verifies that Pro tools CAN be registered when dependencies are met.
	 */
	public function test_pro_tools_can_register() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		
		// Get all registered tools.
		$all_tools = $registry->get_tools();
		$registered_slugs = array_map( function( $tool ) {
			return $tool->get_slug();
		}, $all_tools );

		// Check if any Pro tools are registered.
		$pro_tool_slugs = array( 'product_actualization', 'lookup_product_price', 'woo_products', 'woo_orders', 'jetengine', 'elementor' );
		$registered_pro_tools = array_intersect( $pro_tool_slugs, $registered_slugs );

		// At least one Pro tool should be registered (or Pro addon is not working).
		// If none are registered, it could mean:
		// 1. Pro addon didn't load (BUG)
		// 2. All Pro tools require dependencies that aren't available (OK)
		// To distinguish, we check if the Pro addon is loaded.
		$pro_addon_loaded = defined( 'WP_MCP_AI_PRO_VERSION' );
		
		if ( $pro_addon_loaded ) {
			// Pro addon is loaded. Check if product_actualization can register.
			if ( extension_loaded( 'imagick' ) || extension_loaded( 'gd' ) ) {
				$this->assertContains(
					'product_actualization',
					$registered_slugs,
					'product_actualization should be registered when imagick or gd is available'
				);
			}

			// If Crawl4AI tool exists, lookup_product_price might be available.
			if ( class_exists( 'WP_MCP_AI_Tool_Run_Crawl4AI_Job' ) ) {
				// Only check if Crawl4AI itself is available.
				if ( method_exists( 'WP_MCP_AI_Tool_Run_Crawl4AI_Job', 'is_available' ) ) {
					$crawl4ai_available = WP_MCP_AI_Tool_Run_Crawl4AI_Job::is_available();
					if ( $crawl4ai_available ) {
						$this->assertContains(
							'lookup_product_price',
							$registered_slugs,
							'lookup_product_price should be registered when Crawl4AI is available'
						);
					}
				}
			}

			// WooCommerce-dependent Pro tools.
			if ( class_exists( 'WooCommerce' ) ) {
				$this->assertContains(
					'woo_products',
					$registered_slugs,
					'woo_products should be registered when WooCommerce is active'
				);
				$this->assertContains(
					'woo_orders',
					$registered_slugs,
					'woo_orders should be registered when WooCommerce is active'
				);
			}

			// JetEngine-dependent Pro tools.
			if ( class_exists( 'Jet_Engine' ) ) {
				$this->assertContains(
					'jetengine',
					$registered_slugs,
					'jetengine should be registered when JetEngine is active'
				);
			}

			// Elementor-dependent Pro tools.
			if ( did_action( 'elementor/loaded' ) ) {
				$this->assertContains(
					'elementor',
					$registered_slugs,
					'elementor should be registered when Elementor is active'
				);
			}
		} else {
			$this->fail( 'Pro addon should be loaded when bundled in combined plugin' );
		}
	}

	/**
	 * Test that Pro tools appear in tool group map.
	 */
	public function test_pro_tools_in_group_map() {
		$registry   = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map  = $registry->get_tool_group_map();

		// Check that Pro tools are in the group map (regardless of availability).
		$this->assertArrayHasKey(
			'product_actualization',
			$group_map,
			'product_actualization should be in tool group map'
		);

		$this->assertArrayHasKey(
			'lookup_product_price',
			$group_map,
			'lookup_product_price should be in tool group map'
		);

		$this->assertArrayHasKey(
			'woo_products',
			$group_map,
			'woo_products should be in tool group map'
		);

		$this->assertArrayHasKey(
			'woo_orders',
			$group_map,
			'woo_orders should be in tool group map'
		);

		// Verify they're in the correct groups.
		$this->assertEquals(
			'external-tools',
			$group_map['product_actualization'],
			'product_actualization should be in external-tools group'
		);

		$this->assertEquals(
			'external-tools',
			$group_map['lookup_product_price'],
			'lookup_product_price should be in external-tools group'
		);

		$this->assertEquals(
			'wordpress-plugins',
			$group_map['woo_products'],
			'woo_products should be in wordpress-plugins group'
		);

		$this->assertEquals(
			'wordpress-plugins',
			$group_map['woo_orders'],
			'woo_orders should be in wordpress-plugins group'
		);
	}

	/**
	 * Test that base version flag is NOT set (full version mode).
	 */
	public function test_full_version_mode_active() {
		// WP_MCP_AI_BASE_VERSION should not be defined or should be false.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
			$this->assertFalse(
				WP_MCP_AI_BASE_VERSION,
				'WP_MCP_AI_BASE_VERSION should be false for full version mode'
			);
		}

		// wp_mcp_ai_is_base_version() should return false.
		$this->assertFalse(
			wp_mcp_ai_is_base_version(),
			'wp_mcp_ai_is_base_version() should return false when no flags are set'
		);
	}

	/**
	 * Test that all tools (base + extended + pro) are loaded.
	 */
	public function test_all_tools_loaded_in_full_version() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$all_tools = $registry->get_tools();

		// Count should include base tools + extended tools + pro tools.
		// Base tools: ~60, Extended tools: ~30, Pro tools available: depends on dependencies.
		// At minimum, we should have base tools (60+) + lookup_product_price (1 Pro tool).
		$tool_count = count( $all_tools );
		$this->assertGreaterThan(
			60,
			$tool_count,
			'Full version should have more than 60 tools (at least base tools + some Pro tools)'
		);

		// Verify at least one Pro tool is loaded.
		$has_pro_tool = false;
		foreach ( $all_tools as $tool ) {
			$slug = $tool->get_slug();
			if ( in_array( $slug, array( 'product_actualization', 'lookup_product_price', 'woo_products', 'woo_orders', 'jetengine', 'elementor' ), true ) ) {
				$has_pro_tool = true;
				break;
			}
		}
		$this->assertTrue(
			$has_pro_tool,
			'At least one Pro tool should be loaded in full version mode'
		);
	}

	/**
	 * Test Pro addon initialization function exists.
	 */
	public function test_pro_init_function_exists() {
		$this->assertTrue(
			function_exists( 'wp_mcp_ai_pro_init' ),
			'wp_mcp_ai_pro_init() function should exist'
		);

		$this->assertTrue(
			function_exists( 'wp_mcp_ai_pro_register_tools' ),
			'wp_mcp_ai_pro_register_tools() function should exist'
		);
	}

	/**
	 * Test that extended tools (WooCommerce, JetEngine, etc.) are also loaded.
	 */
	public function test_extended_tools_loaded() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Extended tools from the base plugin (not pro).
		$extended_tools = array(
			'get_woo_recent_orders',
			'get_elementor_templates',
			'search_gmail',
		);

		foreach ( $extended_tools as $tool_slug ) {
			$this->assertTrue(
				$registry->is_tool_registered( $tool_slug ),
				"Extended tool '{$tool_slug}' should be registered in full version mode"
			);
		}
	}
}
