<?php
/**
 * Characterization tests for the Wave F2 PM toolkit data layer — the
 * ported shared engine classes (engine/codes/pipeline-stages/capabilities/
 * workflow-engine), the Project/Task/Event CPTs, the notification manager,
 * and the slimmed standalone init.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical constants, maps,
 *   and CPT registrations are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/project-management/` + `src/` are asserted in full,
 *   including the enabled gate and the init wiring.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * PM data layer tests.
 */
class Test_Pm_Data_Layer extends WP_UnitTestCase {

	/**
	 * The nine ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_PM_Engine'               => 'tools/project-management/class-wp-mcp-ai-pm-engine.php',
			'WP_MCP_AI_PM_Codes'                => 'tools/project-management/class-wp-mcp-ai-pm-codes.php',
			'WP_MCP_AI_PM_Pipeline_Stages'      => 'tools/project-management/class-wp-mcp-ai-pm-pipeline-stages.php',
			'WP_MCP_AI_PM_Capabilities'         => 'tools/project-management/class-wp-mcp-ai-pm-capabilities.php',
			'WP_MCP_AI_PM_Workflow_Engine'      => 'tools/project-management/class-wp-mcp-ai-pm-workflow-engine.php',
			'WP_MCP_AI_Project_CPT'             => 'class-wp-mcp-ai-project-cpt.php',
			'WP_MCP_AI_Task_CPT'                => 'class-wp-mcp-ai-task-cpt.php',
			'WP_MCP_AI_Event_CPT'               => 'class-wp-mcp-ai-event-cpt.php',
			'WP_MCP_AI_PM_Notification_Manager' => 'class-wp-mcp-ai-pm-notification-manager.php',
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
	 * The engine/codes/pipeline/capabilities contracts must be byte-identical.
	 */
	public function test_engine_contracts(): void {
		$this->assertSame( 'wp_mcp_ai_pm_toolkit_settings', WP_MCP_AI_PM_Engine::SETTINGS_OPTION );

		$this->assertSame(
			array( 'idea', 'planning', 'active', 'at-risk', 'on-hold', 'completed', 'cancelled', 'archived' ),
			WP_MCP_AI_PM_Codes::PROJECT_STATUSES
		);
		$this->assertSame(
			array( 'backlog', 'todo', 'in-progress', 'review', 'blocked', 'completed', 'cancelled' ),
			WP_MCP_AI_PM_Codes::TASK_STATUSES
		);
		$this->assertSame(
			array( 'lowest', 'low', 'medium', 'high', 'highest', 'critical' ),
			WP_MCP_AI_PM_Codes::TASK_PRIORITIES
		);
		$this->assertSame( array( 'story_points', 'hours', 't_shirt' ), WP_MCP_AI_PM_Codes::ESTIMATION_METHODS );
		$this->assertSame( array( 'low', 'medium', 'high', 'critical' ), WP_MCP_AI_PM_Codes::RISK_LEVELS );

		$stages = WP_MCP_AI_PM_Pipeline_Stages::get_stages();
		$this->assertArrayHasKey( 'idea', $stages );
		$this->assertArrayHasKey( 'completed', $stages );
		$this->assertSame( 'planning', WP_MCP_AI_PM_Pipeline_Stages::default_stage() );
		$this->assertTrue( WP_MCP_AI_PM_Pipeline_Stages::is_valid( 'active' ) );
		$this->assertFalse( WP_MCP_AI_PM_Pipeline_Stages::is_valid( 'bogus' ) );

		$this->assertContains( 'project_manager', WP_MCP_AI_PM_Capabilities::ROLES );
		$this->assertNotEmpty( WP_MCP_AI_PM_Capabilities::get_role_map() );
		$this->assertIsArray( WP_MCP_AI_PM_Capabilities::get_role_capabilities( 'project_manager' ) );
	}

	/**
	 * The three main CPTs must register their byte-identical post types.
	 */
	public function test_cpt_registrations(): void {
		$this->assertSame( 'mcp_ai_project', WP_MCP_AI_Project_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_task', WP_MCP_AI_Task_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_event', WP_MCP_AI_Event_CPT::POST_TYPE );

		WP_MCP_AI_Project_CPT::register_post_type();
		WP_MCP_AI_Task_CPT::register_post_type();
		WP_MCP_AI_Event_CPT::register_post_type();

		$this->assertTrue( post_type_exists( 'mcp_ai_project' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_task' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_event' ) );
	}

	/**
	 * Standalone only: the init's enabled gate must load the engine files,
	 * register the sprint + PM workflow-rule CPTs inline, and wire the
	 * backward-compat CPT/taxonomy/notification hooks.
	 */
	public function test_init_gate_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base PM init runs at boot.' );
		}

		$settings                              = get_option( 'wp_mcp_ai_settings', array() );
		$settings                              = is_array( $settings ) ? $settings : array();
		$settings['enable_project_management'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );

		// First-loader-gated: if an earlier test already required the init,
		// the per-test hook backup/restore wiped its file-scope hooks, so
		// the hook assertions only run on the true first load.
		$pm_init_first_load = ! function_exists( 'wp_mcp_ai_register_project_management_post_types' );

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/init.php';

		// Inline sprint + PM workflow-rule CPTs register at require time
		// (post-type registrations are global, not hook-backed-up).
		$this->assertTrue( post_type_exists( 'mcp_ai_sprint' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_pm_wf_rule' ) );

		// Backward-compat hook wiring (first-load only).
		if ( $pm_init_first_load ) {
			$this->assertNotFalse( has_action( 'init', 'wp_mcp_ai_register_project_management_post_types' ) );
			$this->assertNotFalse( has_action( 'init', 'wp_mcp_ai_register_project_management_taxonomies' ) );
			$this->assertNotFalse( has_action( 'init', 'wp_mcp_ai_init_pm_notifications' ) );
			$this->assertNotFalse( has_action( 'admin_init', 'wp_mcp_ai_init_project_management_admin' ) );
			$this->assertNotFalse( has_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_project_management_admin_styles' ) );
		}

		// The auxiliary CPT registration stays enabled-gated.
		wp_mcp_ai_register_project_management_post_types();
		$this->assertTrue( post_type_exists( 'mcp_task_plan' ) );
		$this->assertTrue( post_type_exists( 'mcp_task_template' ) );
	}

	/**
	 * Standalone only: the registry must declare the PM toolkit module with
	 * the byte-identical enabled gate.
	 */
	public function test_registry_module_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base registry owns the module.' );
		}

		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();
		$modules = $registry->get_modules();
		$this->assertArrayHasKey( 'toolkit_project_management', $modules );
		$this->assertSame( 'Project Management Toolkit', $modules['toolkit_project_management']['label'] );
		$this->assertNotEmpty( $modules['toolkit_project_management']['files'] );
	}
}
