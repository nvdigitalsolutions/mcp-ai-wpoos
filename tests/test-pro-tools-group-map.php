<?php
/**
 * Tests for Pro tools group map integration.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test Pro tools are properly registered in the tool group map.
 */
class WP_MCP_AI_Pro_Tools_Group_Map_Test extends WP_UnitTestCase {

	/**
	 * Test that Pro tool group map filter is registered.
	 *
	 * Verifies that the wp_mcp_ai_pro_tool_group_map function is hooked
	 * to the wp_mcp_ai_tool_group_map filter.
	 */
	public function test_pro_tool_group_map_filter_registered() {
		$this->assertTrue(
			has_filter( 'wp_mcp_ai_tool_group_map', 'wp_mcp_ai_pro_tool_group_map' ),
			'Pro tool group map filter should be registered'
		);
	}

	/**
	 * Test that product_actualization tool is in the group map.
	 *
	 * Confirms that the product_actualization tool from the Pro addon
	 * is correctly mapped to the 'external-tools' group.
	 */
	public function test_product_actualization_in_group_map() {
		if ( ! function_exists( 'wp_mcp_ai_pro_tool_group_map' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded' );
		}

		$core_map = array(
			'search_content' => 'wordpress-core',
		);

		$result = wp_mcp_ai_pro_tool_group_map( $core_map );

		$this->assertArrayHasKey( 'product_actualization', $result );
		$this->assertSame( 'external-tools', $result['product_actualization'] );
	}

	/**
	 * Test that all Pro tools are in the group map.
	 *
	 * Validates that all 6 Pro tools (product_actualization, lookup_product_price,
	 * woo_products, woo_orders, jetengine, elementor) are present in the group map
	 * with correct group assignments.
	 */
	public function test_all_pro_tools_in_group_map() {
		if ( ! function_exists( 'wp_mcp_ai_pro_tool_group_map' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded' );
		}

		$core_map = array();
		$result   = wp_mcp_ai_pro_tool_group_map( $core_map );

		$expected_tools = array(
			'product_actualization' => 'external-tools',
			'lookup_product_price'  => 'external-tools',
			'woo_products'          => 'wordpress-plugins',
			'woo_orders'            => 'wordpress-plugins',
			'jetengine'             => 'wordpress-plugins',
			'elementor'             => 'wordpress-plugins',
		);

		foreach ( $expected_tools as $tool_slug => $expected_group ) {
			$this->assertArrayHasKey(
				$tool_slug,
				$result,
				"Tool '{$tool_slug}' should be in the group map"
			);
			$this->assertSame(
				$expected_group,
				$result[ $tool_slug ],
				"Tool '{$tool_slug}' should be in group '{$expected_group}'"
			);
		}
	}

	/**
	 * Test that Pro tools don't override core tools.
	 *
	 * Ensures that when Pro tools are merged into the group map, existing
	 * core tools remain unchanged and both sets of tools coexist.
	 */
	public function test_pro_tools_dont_override_core_tools() {
		if ( ! function_exists( 'wp_mcp_ai_pro_tool_group_map' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded' );
		}

		$core_map = array(
			'search_content' => 'wordpress-core',
			'save_post'      => 'wordpress-core',
		);

		$result = wp_mcp_ai_pro_tool_group_map( $core_map );

		// Core tools should still be present.
		$this->assertArrayHasKey( 'search_content', $result );
		$this->assertSame( 'wordpress-core', $result['search_content'] );
		$this->assertArrayHasKey( 'save_post', $result );
		$this->assertSame( 'wordpress-core', $result['save_post'] );

		// Pro tools should also be present.
		$this->assertArrayHasKey( 'product_actualization', $result );
		$this->assertArrayHasKey( 'woo_products', $result );
	}

	/**
	 * Test that the tool group map filter can be further extended.
	 *
	 * Confirms that the wp_mcp_ai_pro_tool_groups filter allows third parties
	 * to add custom Pro tools to the group map, maintaining extensibility.
	 */
	public function test_pro_tool_groups_filter_is_filterable() {
		if ( ! function_exists( 'wp_mcp_ai_pro_tool_group_map' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded' );
		}

		// Add a filter to extend Pro tools.
		add_filter(
			'wp_mcp_ai_pro_tool_groups',
			function ( $pro_tools ) {
				$pro_tools['custom_pro_tool'] = 'other';
				return $pro_tools;
			}
		);

		$result = wp_mcp_ai_pro_tool_group_map( array() );

		$this->assertArrayHasKey( 'custom_pro_tool', $result );
		$this->assertSame( 'other', $result['custom_pro_tool'] );

		// Clean up filter.
		remove_all_filters( 'wp_mcp_ai_pro_tool_groups' );
	}
}
