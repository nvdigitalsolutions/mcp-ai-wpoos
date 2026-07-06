<?php
/**
 * Tests for Cloudways Pro Toolkit tools.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools @group pro @group cloudways @group external-http
 */

class Test_WP_MCP_AI_Cloudways_Toolkit extends WP_UnitTestCase {

	protected $user_id;

	public static $tool_classes = array(
		'app'        => array( 'WP_MCP_AI_Tool_Cloudways_List_Apps', 'WP_MCP_AI_Tool_Cloudways_Get_App', 'WP_MCP_AI_Tool_Cloudways_App_Create', 'WP_MCP_AI_Tool_Cloudways_App_Delete', 'WP_MCP_AI_Tool_Cloudways_App_Clone', 'WP_MCP_AI_Tool_Cloudways_App_Clone_To_Server', 'WP_MCP_AI_Tool_Cloudways_App_Restore', 'WP_MCP_AI_Tool_Cloudways_App_Restore_Rollback', 'WP_MCP_AI_Tool_Cloudways_Update_App_Label', 'WP_MCP_AI_Tool_Cloudways_App_CNAME_Update', 'WP_MCP_AI_Tool_Cloudways_App_CORS_Headers_Update', 'WP_MCP_AI_Tool_Cloudways_App_Credentials' ),
		'server'     => array( 'WP_MCP_AI_Tool_Cloudways_List_Projects', 'WP_MCP_AI_Tool_Cloudways_List_Servers', 'WP_MCP_AI_Tool_Cloudways_Get_Server', 'WP_MCP_AI_Tool_Cloudways_Server_Create', 'WP_MCP_AI_Tool_Cloudways_Server_Delete', 'WP_MCP_AI_Tool_Cloudways_Server_Clone', 'WP_MCP_AI_Tool_Cloudways_Server_Start', 'WP_MCP_AI_Tool_Cloudways_Server_Stop', 'WP_MCP_AI_Tool_Cloudways_Server_Restart', 'WP_MCP_AI_Tool_Cloudways_Server_Scale', 'WP_MCP_AI_Tool_Cloudways_Server_Scale_Volume', 'WP_MCP_AI_Tool_Cloudways_Update_Server_Label', 'WP_MCP_AI_Tool_Cloudways_Server_Settings_Get' ),
		'git'        => array( 'WP_MCP_AI_Tool_Cloudways_Git_Branches_Get', 'WP_MCP_AI_Tool_Cloudways_Git_Clone', 'WP_MCP_AI_Tool_Cloudways_Git_Generate_Key', 'WP_MCP_AI_Tool_Cloudways_Git_History_Get', 'WP_MCP_AI_Tool_Cloudways_Git_Key_Get', 'WP_MCP_AI_Tool_Cloudways_Git_Pull' ),
		'dns'        => array( 'WP_MCP_AI_Tool_Cloudways_DNS_Add_Record', 'WP_MCP_AI_Tool_Cloudways_DNS_Delete_Record', 'WP_MCP_AI_Tool_Cloudways_DNS_List_Domains', 'WP_MCP_AI_Tool_Cloudways_DNS_List_Records' ),
		'ssh'        => array( 'WP_MCP_AI_Tool_Cloudways_SSH_Key_Create', 'WP_MCP_AI_Tool_Cloudways_SSH_Key_Delete', 'WP_MCP_AI_Tool_Cloudways_SSH_Key_List' ),
		'monitor'    => array( 'WP_MCP_AI_Tool_Cloudways_App_Monitor_Summary', 'WP_MCP_AI_Tool_Cloudways_Server_Monitor_Summary', 'WP_MCP_AI_Tool_Cloudways_App_MySQL_Analytics', 'WP_MCP_AI_Tool_Cloudways_App_PHP_Analytics', 'WP_MCP_AI_Tool_Cloudways_App_Traffic_Analytics', 'WP_MCP_AI_Tool_Cloudways_App_Vulnerabilities_List', 'WP_MCP_AI_Tool_Cloudways_Copilot_Insights_List', 'WP_MCP_AI_Tool_Cloudways_Restart_Service', 'WP_MCP_AI_Tool_Cloudways_Service_Status', 'WP_MCP_AI_Tool_Cloudways_Purge_App_Cache', 'WP_MCP_AI_Tool_Cloudways_Create_App_Backup', 'WP_MCP_AI_Tool_Cloudways_Create_Server_Backup', 'WP_MCP_AI_Tool_Cloudways_Get_Operation_Status' ),
		'config'     => array( 'WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Get', 'WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Update', 'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Get', 'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Update' ),
		'cloudflare' => array( 'WP_MCP_AI_Tool_Cloudways_Cloudflare_Add_Domain', 'WP_MCP_AI_Tool_Cloudways_Cloudflare_Details' ),
		'addons'     => array( 'WP_MCP_AI_Tool_Cloudways_Addon_Activate', 'WP_MCP_AI_Tool_Cloudways_Addon_List' ),
		'app_cron'   => array( 'WP_MCP_AI_Tool_Cloudways_App_Cron_List_Get' ),
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

	public static function provide_all_tool_classes() {
		$data   = array();
		$groups = array(
			'app'        => array( 'WP_MCP_AI_Tool_Cloudways_List_Apps', 'WP_MCP_AI_Tool_Cloudways_Get_App', 'WP_MCP_AI_Tool_Cloudways_App_Create', 'WP_MCP_AI_Tool_Cloudways_App_Delete', 'WP_MCP_AI_Tool_Cloudways_App_Clone', 'WP_MCP_AI_Tool_Cloudways_App_Clone_To_Server', 'WP_MCP_AI_Tool_Cloudways_App_Restore', 'WP_MCP_AI_Tool_Cloudways_App_Restore_Rollback', 'WP_MCP_AI_Tool_Cloudways_Update_App_Label', 'WP_MCP_AI_Tool_Cloudways_App_CNAME_Update', 'WP_MCP_AI_Tool_Cloudways_App_CORS_Headers_Update', 'WP_MCP_AI_Tool_Cloudways_App_Credentials' ),
			'server'     => array( 'WP_MCP_AI_Tool_Cloudways_List_Projects', 'WP_MCP_AI_Tool_Cloudways_List_Servers', 'WP_MCP_AI_Tool_Cloudways_Get_Server', 'WP_MCP_AI_Tool_Cloudways_Server_Create', 'WP_MCP_AI_Tool_Cloudways_Server_Delete', 'WP_MCP_AI_Tool_Cloudways_Server_Clone', 'WP_MCP_AI_Tool_Cloudways_Server_Start', 'WP_MCP_AI_Tool_Cloudways_Server_Stop', 'WP_MCP_AI_Tool_Cloudways_Server_Restart', 'WP_MCP_AI_Tool_Cloudways_Server_Scale', 'WP_MCP_AI_Tool_Cloudways_Server_Scale_Volume', 'WP_MCP_AI_Tool_Cloudways_Update_Server_Label', 'WP_MCP_AI_Tool_Cloudways_Server_Settings_Get' ),
			'git'        => array( 'WP_MCP_AI_Tool_Cloudways_Git_Branches_Get', 'WP_MCP_AI_Tool_Cloudways_Git_Clone', 'WP_MCP_AI_Tool_Cloudways_Git_Generate_Key', 'WP_MCP_AI_Tool_Cloudways_Git_History_Get', 'WP_MCP_AI_Tool_Cloudways_Git_Key_Get', 'WP_MCP_AI_Tool_Cloudways_Git_Pull' ),
			'dns'        => array( 'WP_MCP_AI_Tool_Cloudways_DNS_Add_Record', 'WP_MCP_AI_Tool_Cloudways_DNS_Delete_Record', 'WP_MCP_AI_Tool_Cloudways_DNS_List_Domains', 'WP_MCP_AI_Tool_Cloudways_DNS_List_Records' ),
			'ssh'        => array( 'WP_MCP_AI_Tool_Cloudways_SSH_Key_Create', 'WP_MCP_AI_Tool_Cloudways_SSH_Key_Delete', 'WP_MCP_AI_Tool_Cloudways_SSH_Key_List' ),
			'monitor'    => array( 'WP_MCP_AI_Tool_Cloudways_App_Monitor_Summary', 'WP_MCP_AI_Tool_Cloudways_Server_Monitor_Summary', 'WP_MCP_AI_Tool_Cloudways_App_MySQL_Analytics', 'WP_MCP_AI_Tool_Cloudways_App_PHP_Analytics', 'WP_MCP_AI_Tool_Cloudways_App_Traffic_Analytics', 'WP_MCP_AI_Tool_Cloudways_App_Vulnerabilities_List', 'WP_MCP_AI_Tool_Cloudways_Copilot_Insights_List', 'WP_MCP_AI_Tool_Cloudways_Restart_Service', 'WP_MCP_AI_Tool_Cloudways_Service_Status', 'WP_MCP_AI_Tool_Cloudways_Purge_App_Cache', 'WP_MCP_AI_Tool_Cloudways_Create_App_Backup', 'WP_MCP_AI_Tool_Cloudways_Create_Server_Backup', 'WP_MCP_AI_Tool_Cloudways_Get_Operation_Status' ),
			'config'     => array( 'WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Get', 'WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Update', 'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Get', 'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Update' ),
			'cloudflare' => array( 'WP_MCP_AI_Tool_Cloudways_Cloudflare_Add_Domain', 'WP_MCP_AI_Tool_Cloudways_Cloudflare_Details' ),
			'addons'     => array( 'WP_MCP_AI_Tool_Cloudways_Addon_Activate', 'WP_MCP_AI_Tool_Cloudways_Addon_List' ),
			'app_cron'   => array( 'WP_MCP_AI_Tool_Cloudways_App_Cron_List_Get' ),
		);
		foreach ( $groups as $classes ) {
			foreach ( $classes as $class ) {
				$data[ $class ] = array( $class );
			}
		}
		return $data;
	}

	/** @dataProvider provide_all_tool_classes */
	public function test_tool_class_exists( $class_name ) {
		$this->ensure_deps();
		$f = $this->get_tool_file_path( $class_name );
		if ( ! file_exists( $f ) ) {
			$this->markTestSkipped( $class_name . ' file not found.' );
			return; }
		require_once $f;
		$this->assertTrue( class_exists( $class_name ), $class_name . ' should exist' );
	}

	/** @dataProvider provide_all_tool_classes */
	public function test_tool_metadata( $class_name ) {
		$t = $this->safe_new( $class_name );
		if ( ! $t ) {
			return; }
		$this->assertNotEmpty( $t->get_slug() );
		$this->assertNotEmpty( $t->get_name() );
		$this->assertNotEmpty( $t->get_description() );
		$this->assertSame( 'manage_options', $t->get_required_capability() );
	}

	/** @dataProvider provide_all_tool_classes */
	public function test_tool_parameter_schema( $class_name ) {
		$t = $this->safe_new( $class_name );
		if ( ! $t ) {
			return; }
		$s = $t->get_parameters_schema();
		$this->assertIsArray( $s );
		$this->assertArrayHasKey( 'type', $s );
		$this->assertArrayHasKey( 'properties', $s );
	}

	/** @dataProvider provide_all_tool_classes */
	public function test_tool_capability_flags( $class_name ) {
		$t = $this->safe_new( $class_name );
		if ( ! $t ) {
			return; }
		$this->assertIsArray( $t->get_capability_flags() );
	}

	/** @dataProvider provide_all_tool_classes */
	public function test_tool_is_available( $class_name ) {
		if ( ! method_exists( $class_name, 'is_available' ) ) {
			$this->markTestSkipped( $class_name . ' has no is_available.' );
			return;
		}
		$this->assertIsBool( $class_name::is_available() );
	}

	/** @dataProvider provide_all_tool_classes */
	public function test_tool_execute_without_params_does_not_crash( $class_name ) {
		$t = $this->safe_new( $class_name );
		if ( ! $t ) {
			return; }
		if ( method_exists( $t, 'is_available' ) && ! $t->is_available() ) {
			$this->markTestSkipped( $class_name . ' not available.' );
			return;
		}
		$r = $t->execute( array(), array( 'user_id' => $this->user_id ) );
		$this->assertTrue( is_array( $r ) || is_wp_error( $r ) );
	}

	protected function ensure_deps() {
		$trait = WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-envelope.php';
		$base  = $this->get_tool_file_path( 'WP_MCP_AI_Tool_Cloudways_Base' );
		if ( file_exists( $trait ) ) {
			require_once $trait; }
		if ( file_exists( $base ) ) {
			require_once $base; }
	}

	protected function safe_new( $class_name ) {
		$this->ensure_deps();
		$f = $this->get_tool_file_path( $class_name );
		if ( ! file_exists( $f ) ) {
			$this->markTestSkipped( $class_name . ' file not found.' );
			return null; }
		require_once $f;
		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped( $class_name . ' class not found.' );
			return null; }
		try {
			$r = new ReflectionClass( $class_name );
			if ( $r->isAbstract() ) {
				$this->markTestSkipped( $class_name . ' is abstract.' );
				return null; }
			return $r->newInstance();
		} catch ( \ReflectionException $e ) {
			$this->markTestSkipped( $class_name . ' cannot instantiate.' );
			return null;
		}
	}

	protected function get_tool_file_path( $class_name ) {
		$kebab = strtolower( str_replace( '_', '-', $class_name ) );
		return WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-' . $kebab . '.php';
	}
}
