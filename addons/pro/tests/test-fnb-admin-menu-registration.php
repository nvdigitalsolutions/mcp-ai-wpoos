<?php
/**
 * Tests that the F&B Management top-level admin menu registers with the five
 * native-storage CPT screens and a Settings link.
 *
 * Mirrors the CRM/PM admin-menu pattern for CPT-heavy toolkits.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

/**
 * Class Test_Fnb_Admin_Menu_Registration
 */
class Test_Fnb_Admin_Menu_Registration extends WP_UnitTestCase {

	/**
	 * Set up admin context and the native-storage CPTs.
	 */
	public function setUp(): void {
		parent::setUp();

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		set_current_screen( 'dashboard' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_fnb_settings' );
		set_current_screen( 'front' );
		parent::tearDown();
	}

	/**
	 * Helper: load the admin menu class (skips without Pro).
	 *
	 * @return string|null
	 */
	protected function load_menu_class() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return null;
		}
		$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-fnb-admin-menu.php';
		if ( ! file_exists( $file ) ) {
			return null;
		}
		require_once $file;

		return class_exists( 'WP_MCP_AI_Fnb_Admin_Menu' ) ? 'WP_MCP_AI_Fnb_Admin_Menu' : null;
	}

	/**
	 * Helper: register the native-storage CPTs.
	 *
	 * @return void
	 */
	protected function register_native_cpts() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return;
		}
		update_option(
			'wp_mcp_ai_fnb_settings',
			array( 'enable_fnb_native_storage' => true )
		);

		$base = WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/';
		require_once $base . 'class-wp-mcp-ai-fnb-settings.php';
		require_once $base . 'class-wp-mcp-ai-fnb-data-source.php';
		require_once $base . 'trait-wp-mcp-ai-fnb-native-cpt-storage.php';
		require_once $base . 'class-wp-mcp-ai-fnb-sale-cpt.php';
		require_once $base . 'class-wp-mcp-ai-fnb-purchase-cpt.php';
		require_once $base . 'class-wp-mcp-ai-fnb-stock-count-cpt.php';
		require_once $base . 'class-wp-mcp-ai-fnb-waste-cpt.php';
		require_once $base . 'class-wp-mcp-ai-fnb-booking-cpt.php';

		WP_MCP_AI_Fnb_Sale_CPT::register();
		WP_MCP_AI_Fnb_Purchase_CPT::register();
		WP_MCP_AI_Fnb_Stock_Count_CPT::register();
		WP_MCP_AI_Fnb_Waste_CPT::register();
		WP_MCP_AI_Fnb_Booking_CPT::register();
	}

	/**
	 * The top-level F&B Management menu registers with its landing page.
	 */
	public function test_parent_menu_registered() {
		global $menu;

		$class = $this->load_menu_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or menu file missing.' );
		}

		$menu = array();
		call_user_func( array( $class, 'register_parent_menu' ) );

		$slugs = wp_list_pluck( $menu, 2 );
		$this->assertContains( 'nvoos-fnb-dashboard', $slugs, 'F&B Management top-level menu registered' );
	}

	/**
	 * Each native-storage CPT gets a submenu link under F&B Management.
	 */
	public function test_cpt_submenus_registered() {
		global $submenu;

		$class = $this->load_menu_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or menu file missing.' );
		}

		$this->register_native_cpts();

		$submenu = array();
		call_user_func( array( $class, 'register_submenus' ) );

		$this->assertArrayHasKey( 'nvoos-fnb-dashboard', $submenu, 'F&B submenu group exists' );

		$slugs = wp_list_pluck( $submenu['nvoos-fnb-dashboard'], 2 );

		foreach ( array( 'mcp_ai_fnb_sale', 'mcp_ai_fnb_purchase', 'mcp_ai_fnb_stock', 'mcp_ai_fnb_waste', 'mcp_ai_fnb_booking' ) as $post_type ) {
			$this->assertContains(
				'edit.php?post_type=' . $post_type,
				$slugs,
				'F&B CPT screen linked under F&B Management: ' . $post_type
			);
		}

		$this->assertContains( 'admin.php?page=wp-mcp-ai-fnb', $slugs, 'F&B settings linked from the F&B menu' );
	}
}
