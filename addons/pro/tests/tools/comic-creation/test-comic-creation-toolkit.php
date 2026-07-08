<?php
/**
 * Tests for Comic Creation Pro Toolkit tools.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group comic-creation
 */

class Test_WP_MCP_AI_Comic_Creation_Toolkit extends WP_UnitTestCase {

	protected $user_id;

	public static $tool_classes = array(
		'WP_MCP_AI_Tool_Create_Comic_Layout',
		'WP_MCP_AI_Tool_Apply_Comic_Style',
		'WP_MCP_AI_Tool_Generate_Comic_Panel',
		'WP_MCP_AI_Tool_Generate_Comic_Script',
		'WP_MCP_AI_Tool_Generate_Character_Sheet',
		'WP_MCP_AI_Tool_Breakdown_Comic_Panels',
		'WP_MCP_AI_Tool_Ink_Comic_Panel',
		'WP_MCP_AI_Tool_Letter_Comic_Panel',
		'WP_MCP_AI_Tool_Colorize_Comic_Panel',
		'WP_MCP_AI_Tool_Upscale_Comic_Page',
		'WP_MCP_AI_Tool_Export_Comic_CBZ',
		'WP_MCP_AI_Tool_Add_Speech_Bubbles',
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
			$this->markTestSkipped( $class_name . ' file not found.' );
		}
		require_once $tool_file;
		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped();
		}
		$tool = new $class_name();
		$this->assertNotEmpty( $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
	}

	/** @dataProvider provide_all_classes */
	public function test_tool_parameter_schema( $class_name ) {
		$tool_file = $this->find_tool_file( $class_name );
		if ( ! $tool_file ) {
			$this->markTestSkipped();
		}
		require_once $tool_file;
		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped();
		}
		$tool   = new $class_name();
		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
	}

	protected function find_tool_file( $class_name ) {
		$kebab   = strtolower( str_replace( '_', '-', $class_name ) );
		$subdirs = array(
			WP_MCP_AI_PRO_PATH . 'includes/tools/comic-creation/',
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
