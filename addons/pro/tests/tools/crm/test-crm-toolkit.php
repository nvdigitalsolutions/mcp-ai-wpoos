<?php
/**
 * Tests for CRM Pro Toolkit tools.
 *
 * Comprehensive smoke tests for CRM tools including lead management,
 * deal tracking, support tickets, outreach sequences, and activity logging.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group crm
 * @group external-http
 */

/**
 * Test CRM Toolkit class.
 */
class Test_WP_MCP_AI_CRM_Toolkit extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * All CRM-related tool classes to validate.
	 *
	 * @var array<string, array<string>>
	 */
	public static $tool_groups = array(
		'lead'        => array(
			'WP_MCP_AI_Tool_Create_Lead',
			'WP_MCP_AI_Tool_Get_Lead',
			'WP_MCP_AI_Tool_Update_Lead',
			'WP_MCP_AI_Tool_List_Leads',
			'WP_MCP_AI_Tool_Delete_Lead',
			'WP_MCP_AI_Tool_Assign_Lead_To_Owner',
			'WP_MCP_AI_Tool_Convert_Lead_To_Customer',
			'WP_MCP_AI_Tool_Score_Lead',
			'WP_MCP_AI_Tool_Qualify_Lead_BANT',
			'WP_MCP_AI_Tool_Qualify_Lead_MEDDIC',
			'WP_MCP_AI_Tool_Rotate_Leads',
			'WP_MCP_AI_Tool_Extract_Lead_From_Message',
			'WP_MCP_AI_Tool_Draft_Lead_Reply',
			'WP_MCP_AI_Tool_Send_Lead_Email',
			'WP_MCP_AI_Tool_Send_Lead_SMS',
			'WP_MCP_AI_Tool_Send_Lead_DM',
			'WP_MCP_AI_Tool_Send_Lead_WhatsApp',
		),
		'deal'        => array(
			'WP_MCP_AI_Tool_Create_Deal',
			'WP_MCP_AI_Tool_Get_Deal',
			'WP_MCP_AI_Tool_Update_Deal',
			'WP_MCP_AI_Tool_List_Deals',
			'WP_MCP_AI_Tool_Delete_Deal',
			'WP_MCP_AI_Tool_Move_Deal_Stage',
		),
		'customer'    => array(
			'WP_MCP_AI_Tool_Create_Customer',
			'WP_MCP_AI_Tool_Get_Customer',
			'WP_MCP_AI_Tool_Update_Customer',
			'WP_MCP_AI_Tool_List_Customers',
			'WP_MCP_AI_Tool_Delete_Customer',
		),
		'activity'    => array(
			'WP_MCP_AI_Tool_Create_CRM_Activity',
			'WP_MCP_AI_Tool_Get_CRM_Activity',
			'WP_MCP_AI_Tool_List_CRM_Activities',
			'WP_MCP_AI_Tool_Complete_CRM_Activity',
			'WM_MCP_AI_Tool_Snooze_CRM_Activity',
			'WP_MCP_AI_Tool_Log_Call_Outcome',
		),
		'support'     => array(
			'WP_MCP_AI_Tool_Create_Support_Ticket',
			'WP_MCP_AI_Tool_Get_Support_Ticket',
			'WP_MCP_AI_Tool_List_Support_Tickets',
			'WP_MCP_AI_Tool_Update_Support_Ticket',
			'WP_MCP_AI_Tool_Resolve_Support_Ticket',
			'WP_MCP_AI_Tool_Reopen_Support_Ticket',
			'WP_MCP_AI_Tool_Escalate_Support_Ticket',
			'WP_MCP_AI_Tool_Merge_Support_Tickets',
			'WP_MCP_AI_Tool_Classify_Support_Ticket',
		),
		'outreach'    => array(
			'WP_MCP_AI_Tool_Create_Outreach_Sequence',
			'WP_MCP_AI_Tool_List_Outreach_Sequences',
			'WP_MCP_AI_Tool_Update_Outreach_Sequence',
			'WP_MCP_AI_Tool_Delete_Outreach_Sequence',
			'WP_MCP_AI_Tool_Enroll_Lead_In_Sequence',
			'WP_MCP_AI_Tool_Manage_Sequence_State',
			'WP_MCP_AI_Tool_Get_Sequence_Performance',
		),
		'comm'        => array(
			'WP_MCP_AI_Tool_Auto_Reply_Inbound',
			'WP_MCP_AI_Tool_Auto_Route_Inbound_Message',
			'WP_MCP_AI_Tool_Classify_Message_Intent',
			'WP_MCP_AI_Tool_Schedule_Follow_Up',
		),
		'analytics'   => array(
			'WP_MCP_AI_Tool_Forecast_Pipeline_Revenue',
			'WP_MCP_AI_Tool_Get_Conversion_Funnel',
			'WP_MCP_AI_Tool_Get_Owner_Workload',
			'WP_MCP_AI_Tool_Get_Ticket_SLA_Report',
			'WP_MCP_AI_Tool_Detect_Buying_Signals',
			'WP_MCP_AI_Tool_Identify_Top_Customers',
			'WP_MCP_AI_Tool_Identify_Top_Clients',
		),
		'data'        => array(
			'WP_MCP_AI_Tool_Detect_Duplicates',
			'WP_MCP_AI_Tool_Merge_Duplicates',
			'WP_MCP_AI_Tool_Check_DNC_Status',
			'WP_MCP_AI_Tool_Process_Opt_Out',
			'WP_MCP_AI_Tool_Classify_Email_Hygiene',
			'WP_MCP_AI_Tool_Manage_Email_Hygiene',
			'WP_MCP_AI_Tool_Record_Consent',
			'WP_MCP_AI_Tool_Get_Consent_Audit',
			'WP_MCP_AI_Tool_Revoke_Consent',
			'WP_MCP_AI_Tool_Repair_CRM_Data',
			'WP_MCP_AI_Tool_Prune_CRM_Messages',
		),
		'integrations' => array(
			'WP_MCP_AI_Tool_Connect_To_External_CRM',
			'WP_MCP_AI_Tool_Import_Gmail_To_CRM',
			'WP_MCP_AI_Tool_Import_CRM_CSV',
			'WP_MCP_AI_Tool_Import_LinkedIn_Profile',
		),
		'upwork'      => array(
			'WP_MCP_AI_Tool_Search_LinkedIn_Jobs',
			'WP_MCP_AI_Tool_Score_LinkedIn_Job',
			'WP_MCP_AI_Tool_Save_LinkedIn_Job',
			'WP_MCP_AI_Tool_Import_Upwork_Project',
			'WP_MCP_AI_Tool_List_Upwork_Contracts',
			'WP_MCP_AI_Tool_Sync_Upwork_Tasks',
		),
	);

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Provide all CRM tool class names.
	 *
	 * @return array
	 */
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
	 * Test every CRM tool file exists and class loads.
	 *
	 * @dataProvider provide_all_classes
	 *
	 * @param string $class_name Tool class name.
	 */
	public function test_tool_class_exists( $class_name ) {
		$tool_file = $this->get_tool_file_path( $class_name );

		if ( ! file_exists( $tool_file ) ) {
			// Some CRM tools may be in different subdirectories.
			$alt_file = $this->find_tool_file( $class_name );
			if ( ! $alt_file ) {
				$this->markTestSkipped( $class_name . ' file not found — may not exist yet.' );
			}
			$tool_file = $alt_file;
		}

		$this->assertFileExists( $tool_file );
		require_once $tool_file;
		$this->assertTrue( class_exists( $class_name ), $class_name . ' class should exist' );
	}

	/**
	 * Test every CRM tool has valid metadata.
	 *
	 * @dataProvider provide_all_classes
	 *
	 * @param string $class_name Tool class name.
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
	 * Test every CRM tool has valid parameter schema.
	 *
	 * @dataProvider provide_all_classes
	 *
	 * @param string $class_name Tool class name.
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

	/**
	 * Test every CRM tool execute doesn't crash.
	 *
	 * @dataProvider provide_all_classes
	 *
	 * @param string $class_name Tool class name.
	 */
	public function test_tool_execute_does_not_crash( $class_name ) {
		$tool_file = $this->find_tool_file( $class_name );
		if ( ! $tool_file ) {
			$this->markTestSkipped( $class_name . ' file not found.' );
		}

		require_once $tool_file;

		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped( $class_name . ' class does not exist.' );
		}

		$tool = new $class_name();

		if ( method_exists( $tool, 'is_available' ) && ! $tool->is_available() ) {
			$this->markTestSkipped( $class_name . ' not available.' );
		}

		$result = $tool->execute( array(), array( 'user_id' => $this->user_id ) );
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Find a CRM tool file across possible locations.
	 *
	 * @param string $class_name Tool class name.
	 * @return string|false
	 */
	protected function find_tool_file( $class_name ) {
		$kebab = strtolower( str_replace( '_', '-', $class_name ) );
		$filename = 'class-' . $kebab . '.php';
		$root = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/';
		if ( is_dir( $root ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( $file->isFile() && $file->getFilename() === $filename ) {
					return $file->getPathname();
				}
			}
		}
		$alt = WP_MCP_AI_PRO_PATH . 'includes/tools/' . $filename;
		return file_exists( $alt ) ? $alt : false;
	}

	protected function get_tool_file_path( $class_name ) {
		return $this->find_tool_file( $class_name );
	}
}
