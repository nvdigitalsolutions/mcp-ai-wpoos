<?php
/**
 * Base+pro regression pins for the Food & Beverage toolkit init gate.
 *
 * Regression for the base+pro load-gate surface: with the base version
 * constant true AND the Pro addon active, requiring the F&B init with the
 * toolkit enabled must load the toolkit services and the admin settings page
 * (the "Food & Beverage" submenu under NV oOS). The old build had no module
 * registry entry for fnb, so the init never loaded and no admin menu ever
 * appeared — even with the toggle enabled.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once __DIR__ . '/helpers/trait-basepro-test-case.php';

/**
 * F&B toolkit base+pro init-gate regression tests.
 */
class Test_BasePro_Fnb_Init extends WP_UnitTestCase {
	use WP_MCP_AI_BasePro_Test_Case;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_mcp_ai_basepro_require_matrix();
		$this->wp_mcp_ai_basepro_enable_toolkits( array( 'enable_fnb_toolkit' ) );
	}

	/**
	 * The enabled gate must let the F&B init run its boot-time wiring.
	 *
	 * @return void
	 */
	public function test_fnb_init_gate_passes_with_pro_active(): void {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/init.php';

		// Shared service classes (gate-only loads).
		$this->assertTrue( class_exists( 'WP_MCP_AI_Fnb_Settings' ), 'F&B settings wrapper must load in base+pro.' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Fnb_Data_Source' ), 'F&B data source must load in base+pro.' );

		// Regression pins: the admin surface that only exists when the gate
		// passed — the settings page class and its admin_menu hook.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Fnb_Settings_Page' ), 'F&B settings page must load in base+pro admin.' );
		$this->assertNotFalse(
			has_action( 'admin_menu', array( 'WP_MCP_AI_Fnb_Settings_Page', 'add_menu' ) ),
			'F&B admin menu hook must be registered in base+pro.'
		);
	}
}
