<?php
/**
 * Base+pro regression pins for the Project Management toolkit init gate.
 *
 * Same contract as the CRM pins: with base+pro, requiring the PM init with
 * the toolkit enabled must load the shared engine and run its require-time
 * wiring — including the inline Sprint and PM Workflow Rule CPT
 * registrations that only execute when the gate passed.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once __DIR__ . '/helpers/trait-basepro-test-case.php';

/**
 * PM toolkit base+pro init-gate regression tests.
 */
class Test_BasePro_PM_Init extends WP_UnitTestCase {
	use WP_MCP_AI_BasePro_Test_Case;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_mcp_ai_basepro_require_matrix();
		$this->wp_mcp_ai_basepro_enable_toolkits( array( 'enable_project_management' ) );
	}
	/**
	 * The enabled gate must let the PM init run its boot-time wiring.
	 *
	 * The matrix bootstrap seeds enable_project_management before the plugin
	 * boots, so the Pro module registry loads this init at boot through the
	 * base+pro gate. The Sprint and PM Workflow Rule CPTs are registered
	 * inline inside that gate — post_type_exists() flips from false to true
	 * only when the gate passed.
	 *
	 * @return void
	 */
	public function test_pm_init_gate_passes_with_pro_active(): void {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/init.php';

		// Shared engine classes (autoloadable, so smoke pins only).
		$this->assertTrue( class_exists( 'WP_MCP_AI_PM_Engine' ), 'Shared PM engine must load in base+pro.' );

		// Regression pins: the Sprint and PM Workflow Rule CPTs are
		// registered inline inside the gate — post_type_exists() flips from
		// false to true only when the gate passed.
		$this->assertTrue( post_type_exists( 'mcp_ai_sprint' ), 'Sprint CPT must be registered inside the gate.' );
		$this->assertTrue( post_type_exists( 'mcp_ai_pm_wf_rule' ), 'PM Workflow Rule CPT must be registered inside the gate.' );
	}

	/**
	 * The project, task, and event CPTs must register in base+pro.
	 *
	 * @return void
	 */
	public function test_pm_cpts_register(): void {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/init.php';

		WP_MCP_AI_Project_CPT::register_post_type();
		WP_MCP_AI_Task_CPT::register_post_type();
		WP_MCP_AI_Event_CPT::register_post_type();

		foreach ( array( 'mcp_ai_project', 'mcp_ai_task', 'mcp_ai_event' ) as $slug ) {
			$this->assertTrue( post_type_exists( $slug ), "PM CPT {$slug} should be registered in base+pro." );
		}
	}

	/**
	 * The top-level "NV PM" admin menu must register in base+pro.
	 *
	 * @return void
	 */
	public function test_pm_admin_menu_hooks(): void {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/init.php';
		if ( ! class_exists( 'WP_MCP_AI_PM_Admin_Menu', false ) ) {
			$this->markTestSkipped( 'PM admin menu class did not load — the gate test covers that regression.' );
		}
		// Re-arm the menu hooks. Two test-framework behaviours get in the way:
		// the per-test hook snapshot restore may have removed a registration
		// made during an earlier test's first init load, and the class's
		// static first-loader guard would then refuse to re-register. Reset
		// the guard before re-arming.
		$registered = new ReflectionProperty( 'WP_MCP_AI_PM_Admin_Menu', 'registered' );
		$registered->setValue( null, false );
		WP_MCP_AI_PM_Admin_Menu::init();

		$this->assertSame( 'nvoos-pm-dashboard', WP_MCP_AI_PM_Admin_Menu::PARENT_SLUG );
		$this->assertSame( 25, has_action( 'admin_menu', array( 'WP_MCP_AI_PM_Admin_Menu', 'register_parent_menu' ) ), 'NV PM parent menu must hook at priority 25.' );
		$this->assertSame( 28, has_action( 'admin_menu', array( 'WP_MCP_AI_PM_Admin_Menu', 'register_submenus' ) ), 'NV PM submenus must hook at priority 28.' );
	}
}
