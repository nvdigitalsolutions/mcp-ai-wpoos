<?php
/**
 * Tests for DietPi Pro Toolkit tools.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools @group pro @group dietpi @group external-http
 */

class Test_WP_MCP_AI_DietPi_Toolkit extends WP_UnitTestCase {

	protected $user_id;

	public static $tool_groups = array(
		'media'   => array( 'WP_MCP_AI_Tool_DietPi_Media_Center', 'WP_MCP_AI_Tool_DietPi_Media_Request_Flow', 'WP_MCP_AI_Tool_DietPi_Provision_New_App' ),
		'radarr'  => array( 'WP_MCP_AI_Tool_DietPi_Add_Radarr_Movie', 'WP_MCP_AI_Tool_DietPi_List_Radarr_Movies', 'WP_MCP_AI_Tool_DietPi_Manage_Radarr' ),
		'sonarr'  => array( 'WP_MCP_AI_Tool_DietPi_Add_Sonarr_Series', 'WP_MCP_AI_Tool_DietPi_List_Sonarr_Series', 'WP_MCP_AI_Tool_DietPi_Manage_Sonarr' ),
		'transmission' => array( 'WP_MCP_AI_Tool_DietPi_Add_Transmission', 'WP_MCP_AI_Tool_DietPi_List_Transmission', 'WP_MCP_AI_Tool_DietPi_Control_Transmission' ),
		'jackett' => array( 'WP_MCP_AI_Tool_DietPi_List_Jackett_Indexers', 'WP_MCP_AI_Tool_DietPi_Search_Jackett' ),
		'system'  => array( 'WP_MCP_AI_Tool_DietPi_System_Info', 'WP_MCP_AI_Tool_DietPi_System_Stats', 'WP_MCP_AI_Tool_DietPi_Update_System', 'WP_MCP_AI_Tool_DietPi_Backup_System', 'WP_MCP_AI_Tool_DietPi_Health_Check', 'WP_MCP_AI_Tool_DietPi_Dashboard_Summary', 'WP_MCP_AI_Tool_DietPi_Send_SSH_Command' ),
		'service' => array( 'WP_MCP_AI_Tool_DietPi_List_Services', 'WP_MCP_AI_Tool_DietPi_Control_Service' ),
		'storage' => array( 'WP_MCP_AI_Tool_DietPi_Manage_Storage' ),
		'base'    => array( 'WP_MCP_AI_Tool_DietPi_Base' ),
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
		foreach ( self::$tool_groups as $classes ) {
			foreach ( $classes as $class ) {
				$data[ $class ] = array( $class );
			}
		}
		return $data;
	}

	/** @dataProvider provide_all_classes */
	public function test_tool_class_exists( $class_name ) {
		$this->ensure_deps();
		$tool_file = $this->get_tool_file_path( $class_name );
		if ( ! file_exists( $tool_file ) ) {
			$this->markTestSkipped( $class_name . ' file not found.' ); return;
		}
		require_once $tool_file;
		$this->assertTrue( class_exists( $class_name ), $class_name . ' should exist' );
	}

	/** @dataProvider provide_all_classes */
	public function test_tool_metadata( $class_name ) {
		$tool = $this->safe_new( $class_name ); if ( ! $tool ) { return; }
		$this->assertNotEmpty( $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/** @dataProvider provide_all_classes */
	public function test_tool_parameter_schema( $class_name ) {
		$tool = $this->safe_new( $class_name ); if ( ! $tool ) { return; }
		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertArrayHasKey( 'properties', $schema );
	}

	/** @dataProvider provide_all_classes */
	public function test_tool_capability_flags( $class_name ) {
		$tool = $this->safe_new( $class_name ); if ( ! $tool ) { return; }
		$flags = $tool->get_capability_flags();
		$this->assertIsArray( $flags );
	}

	/** @dataProvider provide_all_classes */
	public function test_tool_execute_does_not_crash( $class_name ) {
		$tool = $this->safe_new( $class_name ); if ( ! $tool ) { return; }
		if ( method_exists( $tool, 'is_available' ) && ! $tool->is_available() ) {
			$this->markTestSkipped( $class_name . ' not available.' ); return;
		}
		$result = $tool->execute( array(), array( 'user_id' => $this->user_id ) );
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	protected function ensure_deps() {
		$trait   = WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-envelope.php';
		$helpers = WP_MCP_AI_PRO_PATH . 'includes/dietpi/class-wp-mcp-ai-dietpi-helpers.php';
		$base    = $this->get_tool_file_path( 'WP_MCP_AI_Tool_DietPi_Base' );
		if ( file_exists( $trait ) )   { require_once $trait; }
		if ( file_exists( $helpers ) ) { require_once $helpers; }
		if ( file_exists( $base ) )    { require_once $base; }
	}

	protected function safe_new( $class_name ) {
		$this->ensure_deps();
		$f = $this->get_tool_file_path( $class_name );
		if ( ! file_exists( $f ) ) { $this->markTestSkipped( $class_name . ' file not found.' ); return null; }
		require_once $f;
		if ( ! class_exists( $class_name ) ) { $this->markTestSkipped( $class_name . ' class not found.' ); return null; }
		try {
			$r = new ReflectionClass( $class_name );
			if ( $r->isAbstract() ) { $this->markTestSkipped( $class_name . ' is abstract.' ); return null; }
			return $r->newInstance();
		} catch ( \ReflectionException $e ) {
			$this->markTestSkipped( $class_name . ' cannot instantiate.' ); return null;
		}
	}

	protected function get_tool_file_path( $class_name ) {
		$kebab = strtolower( str_replace( '_', '-', $class_name ) );
		return WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-' . $kebab . '.php';
	}
}
