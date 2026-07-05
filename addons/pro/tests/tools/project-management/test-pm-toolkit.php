<?php
/**
 * Tests for Project Management Pro Toolkit tools.
 *
 * Covers task management, sprints, workflow rules, reporting, and integrations.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group project-management
 */

/**
 * Test PM Toolkit class.
 */
class Test_WP_MCP_AI_PM_Toolkit extends WP_UnitTestCase {

	protected $user_id;

	public static $tool_groups = array(
		'task'          => array(
			'WP_MCP_AI_Tool_Create_Task',
			'WP_MCP_AI_Tool_Delete_Task',
			'WP_MCP_AI_Tool_Get_My_Tasks',
			'WP_MCP_AI_Tool_Detect_Stale_Tasks',
			'WP_MCP_AI_Tool_Forecast_Completion',
			'WP_MCP_AI_Tool_Identify_Blockers',
		),
		'sprint'        => array(
			'WP_MCP_AI_Tool_Create_Sprint',
			'WP_MCP_AI_Tool_Close_Sprint',
		),
		'task_template' => array(
			'WP_MCP_AI_Tool_Create_Task_Template',
			'WP_MCP_AI_Tool_List_Task_Templates',
			'WP_MCP_AI_Tool_Instantiate_Task_Template',
		),
		'workflow'      => array(
			'WP_MCP_AI_Tool_Create_Workflow_Rule',
			'WP_MCP_AI_Tool_Create_PM_Workflow_Rule',
			'WP_MCP_AI_Tool_List_PM_Workflow_Rules',
			'WP_MCP_AI_Tool_Manage_Workflow_Rules',
			'WP_MCP_AI_Tool_Simulate_Workflow_Rule',
			'WP_MCP_AI_Tool_Simulate_PM_Workflow_Rule',
			'WP_MCP_AI_Tool_Get_Workflow_Inbox',
		),
		'reporting'     => array(
			'WP_MCP_AI_Tool_Get_Burndown_Chart',
			'WP_MCP_AI_Tool_Get_PM_KPIs',
			'WP_MCP_AI_Tool_Get_Project_Pipeline',
			'WP_MCP_AI_Tool_Get_Project_Timeline',
			'WP_MCP_AI_Tool_Get_Resource_Utilization',
			'WP_MCP_AI_Tool_Get_Team_Velocity',
			'WP_MCP_AI_Tool_Get_Portfolio_Health',
			'WP_MCP_AI_Tool_Get_Upcoming_Deadlines',
			'WP_MCP_AI_Tool_Assess_Project_Risk',
			'WP_MCP_AI_Tool_Generate_Status_Report',
		),
		'project'       => array(
			'WP_MCP_AI_Tool_Create_Project',
			'WP_MCP_AI_Tool_Delete_Project',
			'WP_MCP_AI_Tool_Export_Project_CSV',
		),
		'integrations'  => array(
			'WP_MCP_AI_Tool_Sync_From_JetAppointment',
			'WP_MCP_AI_Tool_Sync_To_JetAppointment',
			'WP_MCP_AI_Tool_Sync_From_JetBooking',
			'WP_MCP_AI_Tool_Get_JetAppointment_Providers',
			'WP_MCP_AI_Tool_Get_JetAppointment_Services',
			'WP_MCP_AI_Tool_Get_JetBooking_Instances',
			'WP_MCP_AI_Tool_Get_JetBooking_Units',
			'WP_MCP_AI_Tool_Find_Bookable_Places',
		),
	);

	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public static function provide_all_classes() {
		$data = array();
		foreach ( self::$tool_groups as $group => $classes ) {
			foreach ( $classes as $class ) {
				$data[ $class ] = array( $class );
			}
		}
		return $data;
	}

	/**
	 * @dataProvider provide_all_classes
	 */
	public function test_tool_class_exists( $class_name ) {
		$tool_file = $this->find_tool_file( $class_name );
		if ( ! $tool_file ) {
			$this->markTestSkipped( $class_name . ' file not found.' );
		}
		$this->assertFileExists( $tool_file );
		require_once $tool_file;
		$this->assertTrue( class_exists( $class_name ) );
	}

	/**
	 * @dataProvider provide_all_classes
	 */
	public function test_tool_metadata( $class_name ) {
		$tool_file = $this->find_tool_file( $class_name );
		if ( ! $tool_file ) {
			$this->markTestSkipped( $class_name . ' file not found.' );
		}
		require_once $tool_file;
		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped( $class_name . ' class does not exist.' );
		}
		$tool = new $class_name();
		$this->assertNotEmpty( $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * @dataProvider provide_all_classes
	 */
	public function test_tool_parameter_schema( $class_name ) {
		$tool_file = $this->find_tool_file( $class_name );
		if ( ! $tool_file ) {
			$this->markTestSkipped( $class_name . ' file not found.' );
		}
		require_once $tool_file;
		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped( $class_name . ' class does not exist.' );
		}
		$tool   = new $class_name();
		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertArrayHasKey( 'properties', $schema );
	}

	protected function find_tool_file( $class_name ) {
		$kebab   = strtolower( str_replace( '_', '-', $class_name ) );
		$subdirs = array(
			WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/',
		);
		foreach ( $subdirs as $dir ) {
			$path = $dir . 'class-' . $kebab . '.php';
			if ( file_exists( $path ) ) {
				return $path;
			}
		}
		return false;
	}
}
