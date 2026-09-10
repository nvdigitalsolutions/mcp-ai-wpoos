<?php
/**
 * Characterization tests for the Wave F5 eca-management tool batch — the
 * thirty-five monolith-map tools plus the tree-only import-blueprint tool
 * ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/eca-management/` are asserted in full, including the
 *   standalone tool filter and ecosystem registration.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * ECA tools tests.
 */
class Test_ECA_Tools extends WP_UnitTestCase {

	/**
	 * The ported tool symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Create_ECA'          => 'tools/eca-management/class-wp-mcp-ai-tool-create-eca.php',
			'WP_MCP_AI_Tool_Research_ECA'        => 'tools/eca-management/class-wp-mcp-ai-tool-research-eca.php',
			'WP_MCP_AI_Tool_Mark_ECA_Attendance' => 'tools/eca-management/class-wp-mcp-ai-tool-mark-eca-attendance.php',
			'WP_MCP_AI_Tool_Import_ECA_Management_Blueprint' => 'tools/eca-management/examples/class-wp-mcp-ai-tool-import-eca-management-blueprint.php',
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
	 * The thirty-six tool surfaces must be byte-identical — uniform
	 * `edit_posts` capability and the monolith slug map plus the tree-only
	 * import tool.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Bulk_Enroll_Students'        => 'bulk_enroll_students',
			'WP_MCP_AI_Tool_Check_ECA_Conflicts'         => 'check_eca_conflicts',
			'WP_MCP_AI_Tool_Configure_ECA_Notifications' => 'configure_eca_notifications',
			'WP_MCP_AI_Tool_Create_ECA'                  => 'create_eca',
			'WP_MCP_AI_Tool_Create_ECA_Workflow_Rule'    => 'create_eca_workflow_rule',
			'WP_MCP_AI_Tool_Create_Student'              => 'create_student',
			'WP_MCP_AI_Tool_Delete_ECA'                  => 'delete_eca',
			'WP_MCP_AI_Tool_Delete_Student'              => 'delete_student',
			'WP_MCP_AI_Tool_Enroll_Student_ECA'          => 'enroll_student_eca',
			'WP_MCP_AI_Tool_Export_ECA_Data'             => 'export_eca_data',
			'WP_MCP_AI_Tool_Generate_ECA_Analytics'      => 'generate_eca_analytics',
			'WP_MCP_AI_Tool_Generate_ECA_Participation_Report' => 'generate_eca_participation_report',
			'WP_MCP_AI_Tool_Get_ECA'                     => 'get_eca',
			'WP_MCP_AI_Tool_Get_ECA_Attendance_Report'   => 'get_eca_attendance_report',
			'WP_MCP_AI_Tool_Get_ECA_Timetable'           => 'get_eca_timetable',
			'WP_MCP_AI_Tool_Get_Student'                 => 'get_student',
			'WP_MCP_AI_Tool_Get_Student_Participation_Summary' => 'get_student_participation_summary',
			'WP_MCP_AI_Tool_Import_ECAs_CSV'             => 'import_ecas_csv',
			'WP_MCP_AI_Tool_List_ECAs'                   => 'list_ecas',
			'WP_MCP_AI_Tool_List_Students'               => 'list_students',
			'WP_MCP_AI_Tool_Manage_ECA_Term'             => 'manage_eca_term',
			'WP_MCP_AI_Tool_Manage_ECA_Waitlist'         => 'manage_eca_waitlist',
			'WP_MCP_AI_Tool_Mark_ECA_Attendance'         => 'mark_eca_attendance',
			'WP_MCP_AI_Tool_Research_ECA'                => 'research_eca',
			'WP_MCP_AI_Tool_Send_ECA_Notification'       => 'send_eca_notification',
			'WP_MCP_AI_Tool_Send_ECA_Parent_Report'      => 'send_eca_parent_report',
			'WP_MCP_AI_Tool_Set_ECA_Schedule'            => 'set_eca_schedule',
			'WP_MCP_AI_Tool_Sync_ECA_Enrollments_From_ISAMS' => 'sync_eca_enrollments_from_isams',
			'WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS'        => 'sync_ecas_from_isams',
			'WP_MCP_AI_Tool_Sync_ECAs_From_SOCS'         => 'sync_ecas_from_socs',
			'WP_MCP_AI_Tool_Sync_ECAs_To_ISAMS'          => 'sync_ecas_to_isams',
			'WP_MCP_AI_Tool_Sync_Students_From_ISAMS'    => 'sync_students_from_isams',
			'WP_MCP_AI_Tool_Update_ECA'                  => 'update_eca',
			'WP_MCP_AI_Tool_Update_Student'              => 'update_student',
			'WP_MCP_AI_Tool_Withdraw_Student_ECA'        => 'withdraw_student_eca',
			'WP_MCP_AI_Tool_Import_ECA_Management_Blueprint' => 'import_eca_management_blueprint',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}

		$import = new WP_MCP_AI_Tool_Import_ECA_Management_Blueprint();
		$this->assertTrue( $import->requires_base_pro() );
	}

	/**
	 * The first argument gates must be byte-identical: the capability check
	 * passes for an author, then the missing-field WP_Error fires.
	 */
	public function test_gate_contracts(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$get_eca = new WP_MCP_AI_Tool_Get_ECA();
		$result  = $get_eca->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_id', $result->get_error_code() );

		$create_eca = new WP_MCP_AI_Tool_Create_ECA();
		$result     = $create_eca->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_name', $result->get_error_code() );

		$mark   = new WP_MCP_AI_Tool_Mark_ECA_Attendance();
		$result = $mark->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_id', $result->get_error_code() );
	}

	/**
	 * Standalone only: the init's tool filter must carry the full
	 * thirty-six-entry ECA map and the ecosystem registration helper must
	 * load.
	 */
	public function test_standalone_filter_and_registration(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base ECA init registers the tools at boot.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_eca_tools', 10 );
		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 36, $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Import_ECA_Management_Blueprint', $tools );
		$this->assertTrue( function_exists( 'wp_mcp_ai_pro_register_eca_ecosystem_tools' ) );

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/examples/isams-administrator.json',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/examples/school-eca-coordinator.json',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}
	}
}
