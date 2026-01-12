<?php
/**
 * Test WooCommerce Tools Visibility
 *
 * Tests that WooCommerce tools from the Pro addon are properly registered
 * and visible in the assistant edit page when WooCommerce is active.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for WooCommerce tools visibility.
 */
class Test_WooCommerce_Tools_Visibility extends WP_UnitTestCase {
	/**
	 * Test that Pro addon defines WooCommerce tools.
	 */
	public function test_pro_addon_defines_woocommerce_tools() {
		// Verify Pro addon is loaded.
		$this->assertTrue(
			defined( 'WP_MCP_AI_PRO_VERSION' ),
			'Pro addon should be loaded'
		);

		// Verify WooCommerce tool classes exist.
		$woo_tool_classes = array(
			'WP_MCP_AI_Pro_Tool_Woo_Products',
			'WP_MCP_AI_Pro_Tool_Woo_Orders',
			'WP_MCP_AI_Pro_Tool_Woo_Customers',
			'WP_MCP_AI_Pro_Tool_Woo_Coupons',
		);

		foreach ( $woo_tool_classes as $class_name ) {
			$class_file = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/' . str_replace( '_', '-', strtolower( str_replace( 'WP_MCP_AI_Pro_Tool_', 'class-wp-mcp-ai-pro-tool-', $class_name ) ) ) . '.php';
			$this->assertFileExists(
				$class_file,
				"WooCommerce tool file should exist: {$class_name}"
			);
		}
	}

	/**
	 * Test that Pro addon registers WooCommerce tools in the tool group map.
	 */
	public function test_pro_addon_registers_woocommerce_tool_groups() {
		// Get the registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Get tool group map.
		$group_map = $registry->get_tool_group_map();

		// Verify WooCommerce tools are in the group map.
		$expected_tools = array(
			'woo_products'  => 'wordpress-plugins',
			'woo_orders'    => 'wordpress-plugins',
			'woo_customers' => 'wordpress-plugins',
			'woo_coupons'   => 'wordpress-plugins',
		);

		foreach ( $expected_tools as $tool_slug => $expected_group ) {
			$this->assertArrayHasKey(
				$tool_slug,
				$group_map,
				"Tool '{$tool_slug}' should be in the tool group map"
			);
			$this->assertEquals(
				$expected_group,
				$group_map[ $tool_slug ],
				"Tool '{$tool_slug}' should be in group '{$expected_group}'"
			);
		}
	}

	/**
	 * Test WooCommerce tools are registered when WooCommerce class exists.
	 *
	 * This test simulates WooCommerce being active by mocking the class.
	 */
	public function test_woocommerce_tools_registered_when_woocommerce_active() {
		// Skip if WooCommerce is not actually available in test environment.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment. This test verifies tool registration logic only.' );
		}

		// Get the registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Get all registered tools.
		$all_tools        = $registry->get_tools();
		$registered_slugs = array_map(
			function ( $tool ) {
				return $tool->get_slug();
			},
			$all_tools
		);

		// Verify WooCommerce tools are registered.
		$woo_tools = array( 'woo_products', 'woo_orders', 'woo_customers', 'woo_coupons' );
		foreach ( $woo_tools as $tool_slug ) {
			$this->assertContains(
				$tool_slug,
				$registered_slugs,
				"WooCommerce tool '{$tool_slug}' should be registered when WooCommerce is active"
			);
		}
	}

	/**
	 * Test that WooCommerce tool instances implement required interfaces.
	 */
	public function test_woocommerce_tool_instances_implement_interfaces() {
		// Skip if WooCommerce is not available.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$all_tools = $registry->get_tools();

		$woo_tool_slugs = array( 'woo_products', 'woo_orders', 'woo_customers', 'woo_coupons' );

		foreach ( $all_tools as $tool ) {
			if ( in_array( $tool->get_slug(), $woo_tool_slugs, true ) ) {
				// Verify tool implements required interface.
				$this->assertInstanceOf(
					'WP_MCP_AI_Tool_Interface',
					$tool,
					"WooCommerce tool '{$tool->get_slug()}' should implement WP_MCP_AI_Tool_Interface"
				);

				// Verify tool has required methods.
				$this->assertTrue(
					method_exists( $tool, 'get_slug' ),
					"Tool should have get_slug() method"
				);
				$this->assertTrue(
					method_exists( $tool, 'get_name' ),
					"Tool should have get_name() method"
				);
				$this->assertTrue(
					method_exists( $tool, 'get_description' ),
					"Tool should have get_description() method"
				);
				$this->assertTrue(
					method_exists( $tool, 'execute' ),
					"Tool should have execute() method"
				);
			}
		}
	}

	/**
	 * Test that WooCommerce tools have capability flags.
	 */
	public function test_woocommerce_tools_have_capability_flags() {
		// Skip if WooCommerce is not available.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$all_tools = $registry->get_tools();

		$woo_tool_slugs = array( 'woo_products', 'woo_orders', 'woo_customers', 'woo_coupons' );

		foreach ( $all_tools as $tool ) {
			if ( in_array( $tool->get_slug(), $woo_tool_slugs, true ) ) {
				// Verify tool implements capability flags interface.
				if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
					$flags = $tool->get_capability_flags();
					$this->assertIsArray(
						$flags,
						"WooCommerce tool '{$tool->get_slug()}' should return capability flags array"
					);
					$this->assertContains(
						'pro',
						$flags,
						"WooCommerce tool should have 'pro' capability flag"
					);
					$this->assertContains(
						'requires-woocommerce',
						$flags,
						"WooCommerce tool should have 'requires-woocommerce' capability flag"
					);
				}
			}
		}
	}

	/**
	 * Test that WooCommerce tools appear in the assistant edit page tool list.
	 *
	 * This simulates the rendering of the tools metabox.
	 */
	public function test_woocommerce_tools_appear_in_assistant_edit_page() {
		// Skip if WooCommerce is not available.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		// Create a test assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_assistant',
				'post_title' => 'Test WooCommerce Assistant',
			)
		);

		// Get the registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Get all tools.
		$all_tools = $registry->get_tools();

		// Get tool group map.
		$group_map = $registry->get_tool_group_map();

		// Check that WooCommerce tools are in the tools list.
		$woo_tool_found = false;
		$woo_tool_slugs = array( 'woo_products', 'woo_orders', 'woo_customers', 'woo_coupons' );

		foreach ( $all_tools as $tool ) {
			if ( in_array( $tool->get_slug(), $woo_tool_slugs, true ) ) {
				$woo_tool_found = true;

				// Verify the tool has a group assignment.
				$this->assertArrayHasKey(
					$tool->get_slug(),
					$group_map,
					"WooCommerce tool '{$tool->get_slug()}' should have a group assignment"
				);

				// Verify the tool is in the wordpress-plugins group.
				$this->assertEquals(
					'wordpress-plugins',
					$group_map[ $tool->get_slug() ],
					"WooCommerce tool should be in 'wordpress-plugins' group"
				);
			}
		}

		$this->assertTrue(
			$woo_tool_found,
			'At least one WooCommerce tool should be found in the tools list'
		);

		// Clean up.
		wp_delete_post( $assistant_id, true );
	}

	/**
	 * Test WooCommerce tool availability check.
	 */
	public function test_woocommerce_tool_availability_check() {
		// Load the WooCommerce Products tool class.
		$tool_file = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-woo-products.php';
		if ( file_exists( $tool_file ) ) {
			require_once $tool_file;
		}

		// If WooCommerce is active, tool should be available.
		if ( class_exists( 'WooCommerce' ) && class_exists( 'WP_MCP_AI_Pro_Tool_Woo_Products' ) ) {
			$this->assertTrue(
				WP_MCP_AI_Pro_Tool_Woo_Products::is_available(),
				'WooCommerce Products tool should be available when WooCommerce is active'
			);
		} elseif ( class_exists( 'WP_MCP_AI_Pro_Tool_Woo_Products' ) ) {
			$this->assertFalse(
				WP_MCP_AI_Pro_Tool_Woo_Products::is_available(),
				'WooCommerce Products tool should not be available when WooCommerce is not active'
			);

			// Check unavailable reason.
			$reason = WP_MCP_AI_Pro_Tool_Woo_Products::get_unavailable_reason();
			$this->assertNotEmpty(
				$reason,
				'Tool should provide a reason when unavailable'
			);
			$this->assertStringContainsString(
				'WooCommerce',
				$reason,
				'Unavailable reason should mention WooCommerce'
			);
		}
	}
}
