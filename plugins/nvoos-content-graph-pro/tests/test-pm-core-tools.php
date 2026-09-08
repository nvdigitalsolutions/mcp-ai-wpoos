<?php
/**
 * Characterization tests for the Wave F2 PM core CRUD + dependency tool
 * batch — the ported create/update/delete/list project + task tools and
 * the three task-dependency tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surface and
 *   execute contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/project-management/` are asserted in full, including the
 *   filter and ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * PM core tool batch tests.
 */
class Test_Pm_Core_Tools extends WP_UnitTestCase {

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
	 * The eleven ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Create_Project'         => 'tools/project-management/class-wp-mcp-ai-tool-create-project.php',
			'WP_MCP_AI_Tool_Update_Project'         => 'tools/project-management/class-wp-mcp-ai-tool-update-project.php',
			'WP_MCP_AI_Tool_Delete_Project'         => 'tools/project-management/class-wp-mcp-ai-tool-delete-project.php',
			'WP_MCP_AI_Tool_List_Projects'          => 'tools/project-management/class-wp-mcp-ai-tool-list-projects.php',
			'WP_MCP_AI_Tool_Create_Task'            => 'tools/project-management/class-wp-mcp-ai-tool-create-task.php',
			'WP_MCP_AI_Tool_Update_Task'            => 'tools/project-management/class-wp-mcp-ai-tool-update-task.php',
			'WP_MCP_AI_Tool_Delete_Task'            => 'tools/project-management/class-wp-mcp-ai-tool-delete-task.php',
			'WP_MCP_AI_Tool_List_Tasks'             => 'tools/project-management/class-wp-mcp-ai-tool-list-tasks.php',
			'WP_MCP_AI_Tool_Add_Task_Dependency'    => 'tools/project-management/class-wp-mcp-ai-tool-add-task-dependency.php',
			'WP_MCP_AI_Tool_Remove_Task_Dependency' => 'tools/project-management/class-wp-mcp-ai-tool-remove-task-dependency.php',
			'WP_MCP_AI_Tool_Get_Task_Dependencies'  => 'tools/project-management/class-wp-mcp-ai-tool-get-task-dependencies.php',
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
	 * The eleven tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Create_Project'         => 'create_project',
			'WP_MCP_AI_Tool_Update_Project'         => 'update_project',
			'WP_MCP_AI_Tool_Delete_Project'         => 'delete_project',
			'WP_MCP_AI_Tool_List_Projects'          => 'list_projects',
			'WP_MCP_AI_Tool_Create_Task'            => 'create_task',
			'WP_MCP_AI_Tool_Update_Task'            => 'update_task',
			'WP_MCP_AI_Tool_Delete_Task'            => 'delete_task',
			'WP_MCP_AI_Tool_List_Tasks'             => 'list_tasks',
			'WP_MCP_AI_Tool_Add_Task_Dependency'    => 'add_task_dependency',
			'WP_MCP_AI_Tool_Remove_Task_Dependency' => 'remove_task_dependency',
			'WP_MCP_AI_Tool_Get_Task_Dependencies'  => 'get_task_dependencies',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * create-project must enforce the name gate and stamp the project meta.
	 */
	public function test_create_project_execute(): void {
		WP_MCP_AI_Project_CPT::register_post_type();
		$tool = new WP_MCP_AI_Tool_Create_Project();

		$missing = $tool->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_name', $missing->get_error_code() );

		$result = $tool->execute(
			array(
				'name'        => 'Alpha Launch',
				'description' => '<p>Ship the alpha.</p>',
				'status'      => 'active',
				'start_date'  => '2026-09-01',
				'end_date'    => '2026-10-01',
			),
			array( 'user_id' => 1 )
		);
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$project_id = $result['project_id'];
		$this->assertGreaterThan( 0, $project_id );
		$this->assertSame( 'mcp_ai_project', get_post( $project_id )->post_type );
		$this->assertSame( 'active', get_post_meta( $project_id, '_project_status', true ) );
		$this->assertSame( '2026-09-01', get_post_meta( $project_id, '_project_start_date', true ) );

		// Update path (same tool, byte-identical base behavior).
		$updated = $tool->execute(
			array(
				'project_id' => $project_id,
				'name'       => 'Alpha Launch v2',
				'status'     => 'on-hold',
			),
			array( 'user_id' => 1 )
		);
		$this->assertNotWPError( $updated );
		$this->assertTrue( $updated['updated'] );
		$this->assertSame( 'Alpha Launch v2', get_the_title( $project_id ) );
	}

	/**
	 * list-projects must return the created project.
	 */
	public function test_list_projects_execute(): void {
		WP_MCP_AI_Project_CPT::register_post_type();
		$create = new WP_MCP_AI_Tool_Create_Project();
		$create->execute( array( 'name' => 'Listed Project' ), array( 'user_id' => 1 ) );

		$list   = new WP_MCP_AI_Tool_List_Projects();
		$result = $list->execute( array(), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );
		$this->assertNotEmpty( $result['projects'] ?? array() );
	}

	/**
	 * delete-project must hard-delete and report the id.
	 */
	public function test_delete_project_execute(): void {
		WP_MCP_AI_Project_CPT::register_post_type();
		$create  = new WP_MCP_AI_Tool_Create_Project();
		$created = $create->execute( array( 'name' => 'Doomed Project' ), array( 'user_id' => 1 ) );

		$delete = new WP_MCP_AI_Tool_Delete_Project();
		$result = $delete->execute( array( 'project_id' => $created['project_id'] ), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertNull( get_post( $created['project_id'] ) );

		$missing = $delete->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_id', $missing->get_error_code() );
	}

	/**
	 * create-task must enforce the title gate and link to its project.
	 */
	public function test_create_task_execute(): void {
		WP_MCP_AI_Project_CPT::register_post_type();
		WP_MCP_AI_Task_CPT::register_post_type();

		$create  = new WP_MCP_AI_Tool_Create_Project();
		$project = $create->execute( array( 'name' => 'Task Home' ), array( 'user_id' => 1 ) );

		$tool = new WP_MCP_AI_Tool_Create_Task();

		$missing = $tool->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_title', $missing->get_error_code() );

		$result = $tool->execute(
			array(
				'title'      => 'Write tests',
				'project_id' => $project['project_id'],
				'priority'   => 'high',
				'status'     => 'todo',
			),
			array( 'user_id' => 1 )
		);
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$task_id = $result['task_id'];
		$this->assertSame( 'mcp_ai_task', get_post( $task_id )->post_type );
		$this->assertSame( 'high', get_post_meta( $task_id, '_task_priority', true ) );
	}

	/**
	 * The task-dependency flow must add, list, and remove links.
	 */
	public function test_task_dependency_flow(): void {
		WP_MCP_AI_Project_CPT::register_post_type();
		WP_MCP_AI_Task_CPT::register_post_type();

		$task_tool = new WP_MCP_AI_Tool_Create_Task();
		$task_a    = $task_tool->execute( array( 'title' => 'Task A' ), array( 'user_id' => 1 ) );
		$task_b    = $task_tool->execute( array( 'title' => 'Task B' ), array( 'user_id' => 1 ) );

		$add = new WP_MCP_AI_Tool_Add_Task_Dependency();

		$missing = $add->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_ids', $missing->get_error_code() );

		$added = $add->execute(
			array(
				'blocking_task_id' => $task_a['task_id'],
				'blocked_task_id'  => $task_b['task_id'],
			),
			array( 'user_id' => 1 )
		);
		$this->assertNotWPError( $added );
		$this->assertTrue( $added['success'] );

		$get  = new WP_MCP_AI_Tool_Get_Task_Dependencies();
		$deps = $get->execute( array( 'task_id' => $task_b['task_id'] ), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $deps );
		$this->assertNotEmpty( $deps['depends_on'] ?? array() );

		$remove  = new WP_MCP_AI_Tool_Remove_Task_Dependency();
		$removed = $remove->execute(
			array(
				'blocking_task_id' => $task_a['task_id'],
				'blocked_task_id'  => $task_b['task_id'],
			),
			array( 'user_id' => 1 )
		);
		$this->assertNotWPError( $removed );
	}

	/**
	 * Standalone only: the init's tool filter must carry the core batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the PM tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_pm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Create_Project', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Task_Dependencies', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the core
	 * batch into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/init.php';
		wp_mcp_ai_pro_register_pm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['create_project'] ?? null );
		$this->assertNotNull( $parent->all()['list_tasks'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'create_project' ) );
		$this->assertTrue( $core_tools->has( 'add_task_dependency' ) );
	}
}
