<?php
/**
 * Tests for E-commerce Toolkit admin menu registration priority.
 *
 * Validates that e-commerce toolkit admin pages register at correct priorities
 * to avoid conflicts with WooCommerce's admin menu structure.
 *
 * @package WP_MCP_AI
 */

/**
 * Test E-commerce admin menu priorities.
 */
class WP_MCP_AI_Ecommerce_Admin_Menu_Priority_Test extends WP_UnitTestCase {

	/**
	 * Test that toolkit settings page registers at reasonable priority.
	 */
	public function test_toolkit_settings_page_registers_at_reasonable_priority() {
		global $wp_filter;

		// Skip if WooCommerce is not available or we're in base version mode.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available' );
		}

		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			$this->markTestSkipped( 'Running in base version mode' );
		}

		// Ensure toolkit is enabled.
		delete_option( 'wp_mcp_ai_settings' );

		// Check if admin_menu hooks are registered.
		$this->assertArrayHasKey(
			'admin_menu',
			$wp_filter,
			'admin_menu hooks should be registered'
		);

		// Get all admin_menu hooks and their priorities.
		$admin_menu_hooks = $wp_filter['admin_menu']->callbacks ?? array();

		// Find the toolkit settings page hook.
		$settings_page_priority = null;
		foreach ( $admin_menu_hooks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				// Check if this is the Ecommerce Settings Page callback.
				if ( isset( $callback['function'] ) && is_array( $callback['function'] ) ) {
					$obj = $callback['function'][0] ?? null;
					if ( is_object( $obj ) && class_exists( 'WP_MCP_AI_Ecommerce_Settings_Page' ) && $obj instanceof WP_MCP_AI_Ecommerce_Settings_Page ) {
						$settings_page_priority = $priority;
						break 2;
					}
				}
			}
		}

		// Verify settings page is registered.
		$this->assertNotNull(
			$settings_page_priority,
			'E-commerce Settings Page should be registered on admin_menu hook'
		);

		// Verify priority is less than 100 (should be 30 after fix).
		$this->assertLessThan(
			100,
			$settings_page_priority,
			'E-commerce Settings Page should register before priority 100 to avoid WooCommerce menu conflicts'
		);

		// Ideally should be at priority 30 or less.
		$this->assertLessThanOrEqual(
			30,
			$settings_page_priority,
			'E-commerce Settings Page should register at priority 30 or less for optimal compatibility'
		);
	}

	/**
	 * Test that research and consolidate pages register before settings page.
	 */
	public function test_research_and_consolidate_pages_register_before_settings() {
		global $wp_filter;

		// Skip if WooCommerce is not available or we're in base version mode.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available' );
		}

		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			$this->markTestSkipped( 'Running in base version mode' );
		}

		// Ensure toolkit is enabled.
		delete_option( 'wp_mcp_ai_settings' );

		// Check if admin_menu hooks are registered.
		$this->assertArrayHasKey(
			'admin_menu',
			$wp_filter,
			'admin_menu hooks should be registered'
		);

		// Get all admin_menu hooks and their priorities.
		$admin_menu_hooks = $wp_filter['admin_menu']->callbacks ?? array();

		// Find priorities for each page.
		$research_priority    = null;
		$consolidate_priority = null;
		$settings_priority    = null;

		foreach ( $admin_menu_hooks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( isset( $callback['function'] ) && is_array( $callback['function'] ) ) {
					$class_or_obj = $callback['function'][0] ?? null;
					$method       = $callback['function'][1] ?? null;

					// Check for Product Research Page (static callback).
					if ( is_string( $class_or_obj ) && 'WP_MCP_AI_Product_Research_Page' === $class_or_obj && 'add_menu_page' === $method ) {
						$research_priority = $priority;
					}

					// Check for Product Consolidate Page (static callback).
					if ( is_string( $class_or_obj ) && 'WP_MCP_AI_Product_Consolidate_Page' === $class_or_obj && 'add_menu_page' === $method ) {
						$consolidate_priority = $priority;
					}

					// Check for Ecommerce Settings Page (object callback).
					if ( is_object( $class_or_obj ) && class_exists( 'WP_MCP_AI_Ecommerce_Settings_Page' ) && $class_or_obj instanceof WP_MCP_AI_Ecommerce_Settings_Page ) {
						$settings_priority = $priority;
					}
				}
			}
		}

		// Verify all pages are registered.
		$this->assertNotNull( $research_priority, 'Research page should be registered' );
		$this->assertNotNull( $consolidate_priority, 'Consolidate page should be registered' );
		$this->assertNotNull( $settings_priority, 'Settings page should be registered' );

		// Verify research page is at priority 30 (after top-level menu at priority 25).
		$this->assertEquals( 30, $research_priority, 'Research page should be at priority 30' );

		// Verify consolidate page is at priority 25.
		$this->assertEquals( 25, $consolidate_priority, 'Consolidate page should be at priority 25' );

		// Verify settings page is at reasonable priority (30).
		$this->assertEquals( 30, $settings_priority, 'Settings page should be at priority 30' );

		// Verify consolidate page registers before settings page.
		$this->assertLessThanOrEqual(
			$settings_priority,
			$consolidate_priority,
			'Consolidate page should register at or before Settings page'
		);
	}

	/**
	 * Test that all pages can successfully add submenus under E-Commerce Toolkit menu.
	 */
	public function test_all_pages_can_add_submenus_to_ecommerce_toolkit() {
		global $menu, $submenu;

		// Skip if WooCommerce is not available or we're in base version mode.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available' );
		}

		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			$this->markTestSkipped( 'Running in base version mode' );
		}

		// Ensure toolkit is enabled.
		delete_option( 'wp_mcp_ai_settings' );

		// Trigger admin_menu to populate $submenu global.
		do_action( 'admin_menu' );

		// Check if E-Commerce Toolkit menu exists.
		$toolkit_menu_slug = 'wp-mcp-ai-ecommerce-toolkit';
		
		// Verify top-level menu exists.
		$found_top_level_menu = false;
		foreach ( $menu as $menu_item ) {
			if ( isset( $menu_item[2] ) && $toolkit_menu_slug === $menu_item[2] ) {
				$found_top_level_menu = true;
				break;
			}
		}
		$this->assertTrue( $found_top_level_menu, 'E-Commerce Toolkit top-level menu should exist' );

		// Check if E-Commerce Toolkit menu has submenus.
		$this->assertArrayHasKey(
			$toolkit_menu_slug,
			$submenu,
			'E-Commerce Toolkit menu should have submenus in $submenu global'
		);

		// Find our custom pages in the submenu.
		$found_research    = false;
		$found_consolidate = false;
		$found_settings    = false;

		foreach ( $submenu[ $toolkit_menu_slug ] as $item ) {
			$page_slug = $item[2] ?? '';

			if ( 'research-product' === $page_slug ) {
				$found_research = true;
			}

			if ( 'product-consolidate' === $page_slug ) {
				$found_consolidate = true;
			}

			if ( 'wp-mcp-ai-ecommerce-toolkit-settings' === $page_slug ) {
				$found_settings = true;
			}
		}

		// Verify all pages were added to the submenu.
		$this->assertTrue( $found_research, 'Research & Add page should be in E-Commerce Toolkit submenu' );
		$this->assertTrue( $found_consolidate, 'Consolidate & Add page should be in E-Commerce Toolkit submenu' );
		$this->assertTrue( $found_settings, 'Toolkit Settings page should be in E-Commerce Toolkit submenu' );
	}
}
