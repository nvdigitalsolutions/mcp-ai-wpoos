<?php
/**
 * Characterization tests for the Wave F2 PM templates + sprints tool batch —
 * the ported task-template create/list/instantiate tools and the sprint
 * create/plan/close tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surfaces and
 *   lifecycle contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/project-management/{templates,sprints}/` are asserted in
 *   full, including the filter and ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * PM templates + sprints tool batch tests.
 */
class Test_Pm_Templates_Sprints_Tools extends WP_UnitTestCase {

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
	 * The six ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Create_Task_Template'      => 'tools/project-management/templates/class-wp-mcp-ai-tool-create-task-template.php',
			'WP_MCP_AI_Tool_List_Task_Templates'       => 'tools/project-management/templates/class-wp-mcp-ai-tool-list-task-templates.php',
			'WP_MCP_AI_Tool_Instantiate_Task_Template' => 'tools/project-management/templates/class-wp-mcp-ai-tool-instantiate-task-template.php',
			'WP_MCP_AI_Tool_Create_Sprint'             => 'tools/project-management/sprints/class-wp-mcp-ai-tool-create-sprint.php',
			'WP_MCP_AI_Tool_Plan_Sprint'               => 'tools/project-management/sprints/class-wp-mcp-ai-tool-plan-sprint.php',
			'WP_MCP_AI_Tool_Close_Sprint'              => 'tools/project-management/sprints/class-wp-mcp-ai-tool-close-sprint.php',
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
	 * The six tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Create_Task_Template'      => 'create_task_template',
			'WP_MCP_AI_Tool_List_Task_Templates'       => 'list_task_templates',
			'WP_MCP_AI_Tool_Instantiate_Task_Template' => 'instantiate_task_template',
			'WP_MCP_AI_Tool_Create_Sprint'             => 'create_sprint',
			'WP_MCP_AI_Tool_Plan_Sprint'               => 'plan_sprint',
			'WP_MCP_AI_Tool_Close_Sprint'              => 'close_sprint',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The task-template flow must create, list, and instantiate templates.
	 */
	public function test_task_template_flow(): void {
		WP_MCP_AI_Project_CPT::register_post_type();
		$create_project = new WP_MCP_AI_Tool_Create_Project();
		$project        = $create_project->execute( array( 'name' => 'Template Home' ), array( 'user_id' => 1 ) );

		$tool = new WP_MCP_AI_Tool_Create_Task_Template();

		$missing = $tool->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_title', $missing->get_error_code() );

		$result = $tool->execute(
			array(
				'title'       => 'Onboarding Checklist',
				'description' => 'Standard onboarding tasks.',
				'tasks'       => array(
					array(
						'title'    => 'Set up account',
						'priority' => 'high',
					),
					array( 'title' => 'First review meeting' ),
				),
			),
			array( 'user_id' => 1 )
		);
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$template_id = $result['template_id'];
		$this->assertGreaterThan( 0, $template_id );
		$this->assertSame( 2, (int) get_post_meta( $template_id, '_template_task_count', true ) );

		$list   = new WP_MCP_AI_Tool_List_Task_Templates();
		$listed = $list->execute( array(), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $listed );
		$this->assertNotEmpty( $listed['templates'] ?? array() );

		$instantiate  = new WP_MCP_AI_Tool_Instantiate_Task_Template();
		$instantiated = $instantiate->execute(
			array(
				'template_id' => $template_id,
				'project_id'  => $project['project_id'],
			),
			array( 'user_id' => 1 )
		);
		$this->assertNotWPError( $instantiated );
		$this->assertTrue( $instantiated['success'] );
		$this->assertNotEmpty( $instantiated['task_ids'] ?? array() );
	}

	/**
	 * The sprint flow must create → plan → close with the status gates.
	 */
	public function test_sprint_flow(): void {
		WP_MCP_AI_Project_CPT::register_post_type();
		WP_MCP_AI_Task_CPT::register_post_type();
		$create_project = new WP_MCP_AI_Tool_Create_Project();
		$project        = $create_project->execute( array( 'name' => 'Sprint Home' ), array( 'user_id' => 1 ) );

		// A backlog task for the plan step's auto-selection (byte-identical:
		// the sprint only flips to active when tasks were actually planned).
		$create_task = new WP_MCP_AI_Tool_Create_Task();
		$task        = $create_task->execute(
			array(
				'title'      => 'Backlog item',
				'project_id' => $project['project_id'],
				'status'     => 'todo',
			),
			array( 'user_id' => 1 )
		);

		$tool = new WP_MCP_AI_Tool_Create_Sprint();

		$missing = $tool->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_title', $missing->get_error_code() );

		$result = $tool->execute(
			array(
				'title'      => 'Sprint 12',
				'project_id' => $project['project_id'],
				'start_date' => '2026-09-08',
				'end_date'   => '2026-09-22',
				'goal'       => 'Ship the alpha',
			),
			array( 'user_id' => 1 )
		);
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$sprint_id = $result['sprint_id'];
		$this->assertSame( 'planning', get_post_meta( $sprint_id, '_sprint_status', true ) );

		$plan    = new WP_MCP_AI_Tool_Plan_Sprint();
		$planned = $plan->execute( array( 'sprint_id' => $sprint_id ), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $planned );
		$this->assertTrue( $planned['success'] );
		$this->assertSame( 'active', get_post_meta( $sprint_id, '_sprint_status', true ) );

		$close  = new WP_MCP_AI_Tool_Close_Sprint();
		$closed = $close->execute( array( 'sprint_id' => $sprint_id ), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $closed );
		$this->assertTrue( $closed['success'] );
		$this->assertSame( 'completed', get_post_meta( $sprint_id, '_sprint_status', true ) );
	}

	/**
	 * Standalone only: the init's tool filter must carry the batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the PM tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_pm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Create_Task_Template', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Close_Sprint', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the batch
	 * into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/init.php';
		wp_mcp_ai_pro_register_pm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['create_task_template'] ?? null );
		$this->assertNotNull( $parent->all()['plan_sprint'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'instantiate_task_template' ) );
		$this->assertTrue( $core_tools->has( 'close_sprint' ) );
	}
}
