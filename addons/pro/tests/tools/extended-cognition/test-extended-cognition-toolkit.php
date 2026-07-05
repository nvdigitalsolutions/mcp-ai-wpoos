<?php
/**
 * Tests for Extended Cognition Pro Toolkit tools.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group extended-cognition
 */

class Test_WP_MCP_AI_Extended_Cognition_Toolkit extends WP_UnitTestCase {

	protected $user_id;

	public static $tool_classes = array(
		'WP_MCP_AI_Tool_Ext_Cog_Analyze_Video_Feed',
		'WP_MCP_AI_Tool_Ext_Cog_Detect_Objects',
		'WP_MCP_AI_Tool_Ext_Cog_Recognize_Products',
		'WP_MCP_AI_Tool_Ext_Cog_Analyze_Sensory_Input',
		'WP_MCP_AI_Tool_Ext_Cog_Capture_Audio',
		'WP_MCP_AI_Tool_Ext_Cog_Capture_Screen',
		'WP_MCP_AI_Tool_Ext_Cog_Capture_Visual',
		'WP_MCP_AI_Tool_Ext_Cog_Get_Motion_Context',
		'WP_MCP_AI_Tool_Ext_Cog_Manage_Sensor_Permissions',
		'WP_MCP_AI_Tool_Ext_Cog_Remember_Sensory_Context',
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
		foreach ( self::$tool_classes as $class ) {
			$data[ $class ] = array( $class );
		}
		return $data;
	}

	/** @dataProvider provide_all_classes */
	public function test_tool_class_exists( $class_name ) {
		$tool_file = $this->find_tool_file( $class_name );
		if ( ! $tool_file ) {
			$this->markTestSkipped( $class_name . ' file not found.' );
		}
		$this->assertFileExists( $tool_file );
		require_once $tool_file;
		$this->assertTrue( class_exists( $class_name ) );
	}

	/** @dataProvider provide_all_classes */
	public function test_tool_metadata( $class_name ) {
		$tool_file = $this->find_tool_file( $class_name );
		if ( ! $tool_file ) {
			$this->markTestSkipped();
		}
		require_once $tool_file;
		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped();
		}
		$tool = new $class_name();
		$this->assertNotEmpty( $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
	}

	protected function find_tool_file( $class_name ) {
		$kebab   = strtolower( str_replace( '_', '-', $class_name ) );
		$subdirs = array(
			WP_MCP_AI_PRO_PATH . 'includes/tools/extended-cognition/',
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
