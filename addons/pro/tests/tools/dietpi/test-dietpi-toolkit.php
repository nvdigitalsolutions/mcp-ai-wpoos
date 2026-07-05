<?php
/**
 * Tests for DietPi Pro Toolkit tools.
 *
 * Comprehensive smoke tests for all DietPi media server management tools.
 * Covers Radarr, Sonarr, Transmission, Jackett, system management, and storage.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group dietpi
 * @group external-http
 */

/**
 * Test DietPi Toolkit class.
 */
class Test_WP_MCP_AI_DietPi_Toolkit extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * All DietPi tool classes to validate, organized by functional group.
	 *
	 * @var array<string, array<string>>
	 */
	protected $tool_groups = array(
		'media'   => array(
			'WP_MCP_AI_Tool_DietPi_Media_Center',
			'WP_MCP_AI_Tool_DietPi_Media_Request_Flow',
			'WP_MCP_AI_Tool_DietPi_Provision_New_App',
		),
		'radarr'  => array(
			'WP_MCP_AI_Tool_DietPi_Add_Radarr_Movie',
			'WP_MCP_AI_Tool_DietPi_List_Radarr_Movies',
			'WP_MCP_AI_Tool_DietPi_Manage_Radarr',
		),
		'sonarr'  => array(
			'WP_MCP_AI_Tool_DietPi_Add_Sonarr_Series',
			'WP_MCP_AI_Tool_DietPi_List_Sonarr_Series',
			'WP_MCP_AI_Tool_DietPi_Manage_Sonarr',
		),
		'transmission' => array(
			'WP_MCP_AI_Tool_DietPi_Add_Transmission',
			'WP_MCP_AI_Tool_DietPi_List_Transmission',
			'WP_MCP_AI_Tool_DietPi_Control_Transmission',
		),
		'jackett' => array(
			'WP_MCP_AI_Tool_DietPi_List_Jackett_Indexers',
			'WP_MCP_AI_Tool_DietPi_Search_Jackett',
		),
		'system'  => array(
			'WP_MCP_AI_Tool_DietPi_System_Info',
			'WP_MCP_AI_Tool_DietPi_System_Stats',
			'WP_MCP_AI_Tool_DietPi_Update_System',
			'WP_MCP_AI_Tool_DietPi_Backup_System',
			'WP_MCP_AI_Tool_DietPi_Health_Check',
			'WP_MCP_AI_Tool_DietPi_Dashboard_Summary',
			'WP_MCP_AI_Tool_DietPi_Send_SSH_Command',
		),
		'service' => array(
			'WP_MCP_AI_Tool_DietPi_List_Services',
			'WP_MCP_AI_Tool_DietPi_Control_Service',
		),
		'storage' => array(
			'WP_MCP_AI_Tool_DietPi_Manage_Storage',
		),
		'base'    => array(
			'WP_MCP_AI_Tool_DietPi_Base',
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
	 * Provide all DietPi tool class names.
	 *
	 * @return array
	 */
	public function provide_all_classes() {
		$data = array();
		foreach ( $this->tool_groups as $group => $classes ) {
			foreach ( $classes as $class ) {
				$data[ $class ] = array( $class );
			}
		}
		return $data;
	}

	/**
	 * Test every DietPi tool file exists and class loads.
	 *
	 * @dataProvider provide_all_classes
	 *
	 * @param string $class_name Tool class name.
	 */
	public function test_tool_class_exists( $class_name ) {
		$tool_file = $this->get_tool_file_path( $class_name );
		$this->assertFileExists( $tool_file, basename( $tool_file ) . ' should exist' );

		require_once $tool_file;
		$this->assertTrue( class_exists( $class_name ), $class_name . ' should exist' );
	}

	/**
	 * Test every DietPi tool has valid metadata.
	 *
	 * @dataProvider provide_all_classes
	 *
	 * @param string $class_name Tool class name.
	 */
	public function test_tool_metadata( $class_name ) {
		require_once $this->get_tool_file_path( $class_name );

		$tool = new $class_name();

		$this->assertNotEmpty( $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test every DietPi tool has valid parameter schema.
	 *
	 * @dataProvider provide_all_classes
	 *
	 * @param string $class_name Tool class name.
	 */
	public function test_tool_parameter_schema( $class_name ) {
		require_once $this->get_tool_file_path( $class_name );

		$tool   = new $class_name();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertArrayHasKey( 'properties', $schema );
	}

	/**
	 * Test every DietPi tool returns capability flags.
	 *
	 * @dataProvider provide_all_classes
	 *
	 * @param string $class_name Tool class name.
	 */
	public function test_tool_capability_flags( $class_name ) {
		require_once $this->get_tool_file_path( $class_name );

		$tool  = new $class_name();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
	}

	/**
	 * Test every DietPi tool execute doesn't crash.
	 *
	 * @dataProvider provide_all_classes
	 *
	 * @param string $class_name Tool class name.
	 */
	public function test_tool_execute_does_not_crash( $class_name ) {
		require_once $this->get_tool_file_path( $class_name );

		$tool = new $class_name();

		if ( method_exists( $tool, 'is_available' ) && ! $tool->is_available() ) {
			$this->markTestSkipped( $class_name . ' not available.' );
		}

		$result = $tool->execute( array(), array( 'user_id' => $this->user_id ) );
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Get file path for a DietPi tool class.
	 *
	 * @param string $class_name Tool class name.
	 * @return string
	 */
	protected function get_tool_file_path( $class_name ) {
		$kebab = strtolower( str_replace( '_', '-', $class_name ) );
		return WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-' . $kebab . '.php';
	}
}
