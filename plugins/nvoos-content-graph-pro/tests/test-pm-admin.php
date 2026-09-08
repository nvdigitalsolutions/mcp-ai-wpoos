<?php
/**
 * Characterization tests for the Wave F2 PM admin slice A — the ported
 * PM admin menu, command-center page, toolkit settings page, blueprints
 * page, research-add integration, the three metaboxes, admin columns,
 * AI actions, and bulk AI handlers.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical constants and hook
 *   wiring are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/admin/` + `src/research-add/` are asserted in full, including
 *   the file-gated init targets.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * PM admin slice A tests.
 */
class Test_Pm_Admin extends WP_UnitTestCase {

	/**
	 * Enable the PM toolkit for availability gates.
	 */
	public function setUp(): void {
		parent::setUp();
		$settings                              = get_option( 'wp_mcp_ai_settings', array() );
		$settings                              = is_array( $settings ) ? $settings : array();
		$settings['enable_project_management'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * The twelve ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_PM_Admin_Menu'                    => 'admin/class-wp-mcp-ai-pm-admin-menu.php',
			'WP_MCP_AI_PM_Command_Center_Page'           => 'admin/class-wp-mcp-ai-pm-command-center-page.php',
			'WP_MCP_AI_Project_Management_Toolkit_Settings_Page' => 'admin/class-wp-mcp-ai-project-management-toolkit-settings-page.php',
			'WP_MCP_AI_PM_Blueprints_Page'               => 'admin/class-wp-mcp-ai-pm-blueprints-page.php',
			'WP_MCP_AI_Research_Add_Base'                => 'admin/class-wp-mcp-ai-research-add-base.php',
			'WP_MCP_AI_Project_Management_Research_Add'  => 'research-add/class-wp-mcp-ai-project-management-research-add.php',
			'WP_MCP_AI_Project_Metabox'                  => 'admin/class-wp-mcp-ai-project-metabox.php',
			'WP_MCP_AI_Task_Metabox'                     => 'admin/class-wp-mcp-ai-task-metabox.php',
			'WP_MCP_AI_Event_Metabox'                    => 'admin/class-wp-mcp-ai-event-metabox.php',
			'WP_MCP_AI_Project_Management_Admin_Columns' => 'admin/class-wp-mcp-ai-project-management-admin-columns.php',
			'WP_MCP_AI_Project_Management_AI_Actions'    => 'admin/class-wp-mcp-ai-project-management-ai-actions.php',
			'WP_MCP_AI_Project_Management_Bulk_AI'       => 'admin/class-wp-mcp-ai-project-management-bulk-ai.php',
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
	 * The page constants must be byte-identical.
	 */
	public function test_page_constants(): void {
		$this->assertSame( 'nvoos-pm-dashboard', WP_MCP_AI_PM_Admin_Menu::PARENT_SLUG );
		$this->assertSame( 'nvoos-pm-command-center', WP_MCP_AI_PM_Command_Center_Page::PAGE_SLUG );
		$this->assertSame( 'wp_mcp_ai_pm_cc', WP_MCP_AI_PM_Command_Center_Page::NONCE_ACTION );
		$this->assertSame( 'nvoos-pm-blueprints', WP_MCP_AI_PM_Blueprints_Page::PAGE_SLUG );
	}

	/**
	 * init() must wire the admin_menu/AJAX hooks for each admin slice.
	 */
	public function test_init_hooks(): void {
		// The admin menu carries a self::$registered first-loader guard
		// (byte-identical) — assert the callbacks only on the true first
		// load, otherwise fall back to the weak hook presence.
		$menu_ref        = new ReflectionClass( 'WP_MCP_AI_PM_Admin_Menu' );
		$registered_prop = $menu_ref->getProperty( 'registered' );
		$registered_prop->setAccessible( true );
		if ( ! $registered_prop->getValue() ) {
			WP_MCP_AI_PM_Admin_Menu::init();
			$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_PM_Admin_Menu', 'register_parent_menu' ) ) );
			$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_PM_Admin_Menu', 'register_submenus' ) ) );
		} else {
			$this->assertNotFalse( has_action( 'admin_menu' ) );
		}

		WP_MCP_AI_PM_Command_Center_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_PM_Command_Center_Page', 'register_page' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_pm_cc_get_kpis' ) );

		WP_MCP_AI_PM_Blueprints_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_PM_Blueprints_Page', 'register_page' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_pm_install_blueprint' ) );

		WP_MCP_AI_Project_Metabox::init();
		WP_MCP_AI_Task_Metabox::init();
		WP_MCP_AI_Event_Metabox::init();
		$this->assertNotFalse( has_action( 'add_meta_boxes' ) );

		WP_MCP_AI_Project_Management_Admin_Columns::init();
		WP_MCP_AI_Project_Management_AI_Actions::init();
		WP_MCP_AI_Project_Management_Bulk_AI::init();
	}

	/**
	 * The blueprints page's installer seam must resolve the ported
	 * installer (the dir + installer file both exist standalone).
	 */
	public function test_blueprints_page_seams(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base paths serve the blueprints page.' );
		}

		$this->assertFileExists( WP_MCP_AI_PM_Blueprints_Page::BLUEPRINTS_DIR . '/project-manager.json' );
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php' );
	}

	/**
	 * Standalone only: the PM init's file-gated admin targets must now
	 * exist (the admin slice has landed).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base PM init wires the admin slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-pm-admin-menu.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-pm-command-center-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-project-management-toolkit-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-pm-blueprints-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/research-add/class-wp-mcp-ai-project-management-research-add.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		// The admin_init metabox loader's targets must also exist.
		foreach ( array( 'project-metabox', 'task-metabox', 'event-metabox', 'project-management-admin-columns', 'project-management-ai-actions', 'project-management-bulk-ai' ) as $metabox ) {
			$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-' . $metabox . '.php' );
		}
	}
}
