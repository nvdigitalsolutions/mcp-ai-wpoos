<?php
/**
 * Tests for Cloudways Pro Toolkit tools.
 *
 * @group external-http
 *
 * Comprehensive smoke tests for all Cloudways infrastructure management tools.
 * Covers app lifecycle, server management, DNS, Git, SSH keys, and monitoring.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group cloudways
 * @group external-http
 */

/**
 * Test Cloudways Toolkit class.
 */
class Test_WP_MCP_AI_Cloudways_Toolkit extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * All Cloudways tool classes to validate.
	 *
	 * Organized by functional group.
	 *
	 * @var array<string, array<string>>
	 */
	protected $tool_classes = array(
		// App lifecycle.
		'app'       => array(
			'WP_MCP_AI_Tool_Cloudways_List_Apps',
			'WP_MCP_AI_Tool_Cloudways_Get_App',
			'WP_MCP_AI_Tool_Cloudways_App_Create',
			'WP_MCP_AI_Tool_Cloudways_App_Delete',
			'WP_MCP_AI_Tool_Cloudways_App_Clone',
			'WP_MCP_AI_Tool_Cloudways_App_Clone_To_Server',
			'WP_MCP_AI_Tool_Cloudways_App_Restore',
			'WP_MCP_AI_Tool_Cloudways_App_Restore_Rollback',
			'WP_MCP_AI_Tool_Cloudways_Update_App_Label',
			'WP_MCP_AI_Tool_Cloudways_App_CNAME_Update',
			'WP_MCP_AI_Tool_Cloudways_App_CORS_Headers_Update',
			'WP_MCP_AI_Tool_Cloudways_App_Credentials',
		),
		// Server management.
		'server'    => array(
			'WP_MCP_AI_Tool_Cloudways_List_Projects',
			'WP_MCP_AI_Tool_Cloudways_List_Servers',
			'WP_MCP_AI_Tool_Cloudways_Get_Server',
			'WP_MCP_AI_Tool_Cloudways_Server_Create',
			'WP_MCP_AI_Tool_Cloudways_Server_Delete',
			'WP_MCP_AI_Tool_Cloudways_Server_Clone',
			'WP_MCP_AI_Tool_Cloudways_Server_Start',
			'WP_MCP_AI_Tool_Cloudways_Server_Stop',
			'WP_MCP_AI_Tool_Cloudways_Server_Restart',
			'WP_MCP_AI_Tool_Cloudways_Server_Scale',
			'WP_MCP_AI_Tool_Cloudways_Server_Scale_Volume',
			'WP_MCP_AI_Tool_Cloudways_Update_Server_Label',
			'WP_MCP_AI_Tool_Cloudways_Server_Settings_Get',
		),
		// Git.
		'git'       => array(
			'WP_MCP_AI_Tool_Cloudways_Git_Branches_Get',
			'WP_MCP_AI_Tool_Cloudways_Git_Clone',
			'WP_MCP_AI_Tool_Cloudways_Git_Generate_Key',
			'WP_MCP_AI_Tool_Cloudways_Git_History_Get',
			'WP_MCP_AI_Tool_Cloudways_Git_Key_Get',
			'WP_MCP_AI_Tool_Cloudways_Git_Pull',
		),
		// DNS.
		'dns'       => array(
			'WP_MCP_AI_Tool_Cloudways_DNS_Add_Record',
			'WP_MCP_AI_Tool_Cloudways_DNS_Delete_Record',
			'WP_MCP_AI_Tool_Cloudways_DNS_List_Domains',
			'WP_MCP_AI_Tool_Cloudways_DNS_List_Records',
		),
		// SSH keys.
		'ssh'       => array(
			'WP_MCP_AI_Tool_Cloudways_SSH_Key_Create',
			'WP_MCP_AI_Tool_Cloudways_SSH_Key_Delete',
			'WP_MCP_AI_Tool_Cloudways_SSH_Key_List',
		),
		// Monitoring & utilities.
		'monitor'   => array(
			'WP_MCP_AI_Tool_Cloudways_App_Monitor_Summary',
			'WP_MCP_AI_Tool_Cloudways_Server_Monitor_Summary',
			'WP_MCP_AI_Tool_Cloudways_App_MySQL_Analytics',
			'WP_MCP_AI_Tool_Cloudways_App_PHP_Analytics',
			'WP_MCP_AI_Tool_Cloudways_App_Traffic_Analytics',
			'WP_MCP_AI_Tool_Cloudways_App_Vulnerabilities_List',
			'WP_MCP_AI_Tool_Cloudways_Copilot_Insights_List',
			'WP_MCP_AI_Tool_Cloudways_Restart_Service',
			'WP_MCP_AI_Tool_Cloudways_Service_Status',
			'WP_MCP_AI_Tool_Cloudways_Purge_App_Cache',
			'WP_MCP_AI_Tool_Cloudways_Create_App_Backup',
			'WP_MCP_AI_Tool_Cloudways_Create_Server_Backup',
			'WP_MCP_AI_Tool_Cloudways_Get_Operation_Status',
		),
		// FPM/Varnish config.
		'config'    => array(
			'WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Get',
			'WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Update',
			'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Get',
			'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Update',
		),
		// Cloudflare integration.
		'cloudflare' => array(
			'WP_MCP_AI_Tool_Cloudways_Cloudflare_Add_Domain',
			'WP_MCP_AI_Tool_Cloudways_Cloudflare_Details',
		),
		// Addons.
		'addons'    => array(
			'WP_MCP_AI_Tool_Cloudways_Addon_Activate',
			'WP_MCP_AI_Tool_Cloudways_Addon_List',
		),
		// App cron.
		'app_cron'  => array(
			'WP_MCP_AI_Tool_Cloudways_App_Cron_List_Get',
		),
	);

	/**
	 * Flattened list of all tool classes.
	 *
	 * @var array<string>
	 */
	protected $all_classes = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Skip if Pro addon is not loaded.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		$this->user_id = $this->factory->user->create(
			array( 'role' => 'administrator' )
		);

		wp_set_current_user( $this->user_id );

		// Flatten class list.
		foreach ( $this->tool_classes as $group => $classes ) {
			foreach ( $classes as $class ) {
				$this->all_classes[] = $class;
			}
		}
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// ============================================================================
	// Base class tests.
	// ============================================================================

	/**
	 * Test Cloudways base class exists.
	 */

	// ============================================================================
	// Tool existence and loading.
	// ============================================================================

	/**
	 * Test that every Cloudways tool file exists and class is loadable.
	 *
	 * @dataProvider provide_all_tool_classes
	 *
	 * @param string $class_name Tool class name.
	 */
	public function test_tool_class_exists( $class_name ) {
		$tool_file = $this->get_tool_file_path( $class_name );

		$this->assertFileExists(
			$tool_file,
			sprintf( 'Tool file should exist: %s', basename( $tool_file ) )
		);

		require_once $tool_file;

		$this->assertTrue(
			class_exists( $class_name ),
			sprintf( 'Tool class should exist: %s', $class_name )
		);
	}

	/**
	 * Data provider for all Cloudways tool classes.
	 *
	 * @return array
	 */
	public static function provide_all_tool_classes() {
		$data = array();
		$all  = array();

		// Flatten all groups.
		$groups = array(
			'app'       => array(
				'WP_MCP_AI_Tool_Cloudways_List_Apps',
				'WP_MCP_AI_Tool_Cloudways_Get_App',
				'WP_MCP_AI_Tool_Cloudways_App_Create',
				'WP_MCP_AI_Tool_Cloudways_App_Delete',
				'WP_MCP_AI_Tool_Cloudways_App_Clone',
				'WP_MCP_AI_Tool_Cloudways_App_Clone_To_Server',
				'WP_MCP_AI_Tool_Cloudways_App_Restore',
				'WP_MCP_AI_Tool_Cloudways_App_Restore_Rollback',
				'WP_MCP_AI_Tool_Cloudways_Update_App_Label',
				'WP_MCP_AI_Tool_Cloudways_App_CNAME_Update',
				'WP_MCP_AI_Tool_Cloudways_App_CORS_Headers_Update',
				'WP_MCP_AI_Tool_Cloudways_App_Credentials',
			),
			'server'    => array(
				'WP_MCP_AI_Tool_Cloudways_List_Projects',
				'WP_MCP_AI_Tool_Cloudways_List_Servers',
				'WP_MCP_AI_Tool_Cloudways_Get_Server',
				'WP_MCP_AI_Tool_Cloudways_Server_Create',
				'WP_MCP_AI_Tool_Cloudways_Server_Delete',
				'WP_MCP_AI_Tool_Cloudways_Server_Clone',
				'WP_MCP_AI_Tool_Cloudways_Server_Start',
				'WP_MCP_AI_Tool_Cloudways_Server_Stop',
				'WP_MCP_AI_Tool_Cloudways_Server_Restart',
				'WP_MCP_AI_Tool_Cloudways_Server_Scale',
				'WP_MCP_AI_Tool_Cloudways_Server_Scale_Volume',
				'WP_MCP_AI_Tool_Cloudways_Update_Server_Label',
				'WP_MCP_AI_Tool_Cloudways_Server_Settings_Get',
			),
			'git'       => array(
				'WP_MCP_AI_Tool_Cloudways_Git_Branches_Get',
				'WP_MCP_AI_Tool_Cloudways_Git_Clone',
				'WP_MCP_AI_Tool_Cloudways_Git_Generate_Key',
				'WP_MCP_AI_Tool_Cloudways_Git_History_Get',
				'WP_MCP_AI_Tool_Cloudways_Git_Key_Get',
				'WP_MCP_AI_Tool_Cloudways_Git_Pull',
			),
			'dns'       => array(
				'WP_MCP_AI_Tool_Cloudways_DNS_Add_Record',
				'WP_MCP_AI_Tool_Cloudways_DNS_Delete_Record',
				'WP_MCP_AI_Tool_Cloudways_DNS_List_Domains',
				'WP_MCP_AI_Tool_Cloudways_DNS_List_Records',
			),
			'ssh'       => array(
				'WP_MCP_AI_Tool_Cloudways_SSH_Key_Create',
				'WP_MCP_AI_Tool_Cloudways_SSH_Key_Delete',
				'WP_MCP_AI_Tool_Cloudways_SSH_Key_List',
			),
			'monitor'   => array(
				'WP_MCP_AI_Tool_Cloudways_App_Monitor_Summary',
				'WP_MCP_AI_Tool_Cloudways_Server_Monitor_Summary',
				'WP_MCP_AI_Tool_Cloudways_App_MySQL_Analytics',
				'WP_MCP_AI_Tool_Cloudways_App_PHP_Analytics',
				'WP_MCP_AI_Tool_Cloudways_App_Traffic_Analytics',
				'WP_MCP_AI_Tool_Cloudways_App_Vulnerabilities_List',
				'WP_MCP_AI_Tool_Cloudways_Copilot_Insights_List',
				'WP_MCP_AI_Tool_Cloudways_Restart_Service',
				'WP_MCP_AI_Tool_Cloudways_Service_Status',
				'WP_MCP_AI_Tool_Cloudways_Purge_App_Cache',
				'WP_MCP_AI_Tool_Cloudways_Create_App_Backup',
				'WP_MCP_AI_Tool_Cloudways_Create_Server_Backup',
				'WP_MCP_AI_Tool_Cloudways_Get_Operation_Status',
			),
			'config'    => array(
				'WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Get',
				'WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Update',
				'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Get',
				'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Update',
			),
			'cloudflare' => array(
				'WP_MCP_AI_Tool_Cloudways_Cloudflare_Add_Domain',
				'WP_MCP_AI_Tool_Cloudways_Cloudflare_Details',
			),
			'addons'    => array(
				'WP_MCP_AI_Tool_Cloudways_Addon_Activate',
				'WP_MCP_AI_Tool_Cloudways_Addon_List',
			),
			'app_cron'  => array(
				'WP_MCP_AI_Tool_Cloudways_App_Cron_List_Get',
			),
		);

		foreach ( $groups as $group_name => $classes ) {
			foreach ( $classes as $class ) {
				$data[ $class ] = array( $class );
			}
		}

		return $data;
	}

	// ============================================================================
	// Metadata and schema tests (data-driven).
	// ============================================================================

	/**
	 * Test that every Cloudways tool exposes valid metadata.
	 *
	 * @dataProvider provide_all_tool_classes
	 *
	 * @param string $class_name Tool class name.
	 */
	public function test_tool_metadata( $class_name ) {
		require_once $this->get_tool_file_path( $class_name );

		$tool = new $class_name();

		$this->assertNotEmpty( $tool->get_slug(), sprintf( '%s slug is not empty', $class_name ) );
		$this->assertNotEmpty( $tool->get_name(), sprintf( '%s name is not empty', $class_name ) );
		$this->assertNotEmpty( $tool->get_description(), sprintf( '%s description is not empty', $class_name ) );
		$this->assertSame( 'manage_options', $tool->get_required_capability() );
	}

	/**
	 * Test that every Cloudways tool has a valid parameter schema.
	 *
	 * @dataProvider provide_all_tool_classes
	 *
	 * @param string $class_name Tool class name.
	 */
	public function test_tool_parameter_schema( $class_name ) {
		require_once $this->get_tool_file_path( $class_name );

		$tool   = new $class_name();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema, sprintf( '%s schema is array', $class_name ) );
		$this->assertArrayHasKey( 'type', $schema, sprintf( '%s schema has type', $class_name ) );
		$this->assertSame( 'object', $schema['type'], sprintf( '%s schema type is object', $class_name ) );
		$this->assertArrayHasKey( 'properties', $schema, sprintf( '%s schema has properties', $class_name ) );
	}

	/**
	 * Test that every Cloudways tool returns capability flags.
	 *
	 * @dataProvider provide_all_tool_classes
	 *
	 * @param string $class_name Tool class name.
	 */
	public function test_tool_capability_flags( $class_name ) {
		require_once $this->get_tool_file_path( $class_name );

		$tool  = new $class_name();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags, sprintf( '%s capability flags is array', $class_name ) );
		$this->assertArrayHasKey( 'background-only', $flags );
	}

	/**
	 * Test that every Cloudways tool reports availability.
	 *
	 * @dataProvider provide_all_tool_classes
	 *
	 * @param string $class_name Tool class name.
	 */
	public function test_tool_is_available( $class_name ) {
		// Some tools check credentials, toolkit enabled, etc.
		// Just verify the method exists and returns boolean.
		$this->assertTrue(
			method_exists( $class_name, 'is_available' ),
			sprintf( '%s has is_available', $class_name )
		);

		$available = $class_name::is_available();
		$this->assertIsBool( $available, sprintf( '%s is_available returns bool', $class_name ) );
	}

	// ============================================================================
	// Execution safety tests.
	// ============================================================================

	/**
	 * Test that Cloudways tools return errors for missing required params.
	 *
	 * @dataProvider provide_all_tool_classes
	 *
	 * @param string $class_name Tool class name.
	 */
	public function test_tool_execute_without_params_does_not_crash( $class_name ) {
		require_once $this->get_tool_file_path( $class_name );

		$tool = new $class_name();

		// If the tool is not available, skip execution test.
		if ( method_exists( $tool, 'is_available' ) && ! $tool->is_available() ) {
			$this->markTestSkipped(
				sprintf( '%s is not available in current environment.', $class_name )
			);
		}

		$result = $tool->execute(
			array(),
			array( 'user_id' => $this->user_id )
		);

		// Should return either array or WP_Error, never crash.
		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			sprintf( '%s execute returns array or WP_Error', $class_name )
		);
	}

	// ============================================================================
	// Helpers.
	// ============================================================================

	/**
	 * Get the file path for a Cloudways tool class.
	 *
	 * @param string $class_name Tool class name.
	 * @return string
	 */
	protected function get_tool_file_path( $class_name ) {
		$kebab = strtolower( str_replace( '_', '-', $class_name ) );
		return WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-' . $kebab . '.php';
	}
}
