<?php
/**
 * Tests for Infrastructure Pro Toolkit tools.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group infrastructure
 */

class Test_WP_MCP_AI_Infrastructure_Toolkit extends WP_UnitTestCase {

	protected $user_id;

	protected $tool_classes = array(
		'WP_MCP_AI_Tool_Check_WP_CLI',
		'WP_MCP_AI_Tool_Execute_Shell_Command',
		'WP_MCP_AI_Tool_Capture_Webpage_Screenshot',
		'WP_MCP_AI_Tool_Install_And_Activate_Plugin',
		'WP_MCP_AI_Tool_Install_And_Activate_Theme',
		'WP_MCP_AI_Tool_Benchmark_Tool_Performance',
		'WP_MCP_AI_Tool_Collect_Custom_Metrics',
		'WP_MCP_AI_Tool_Configure_Circuit_Breaker',
		'WP_MCP_AI_Tool_Get_Loop_Metrics',
		'WP_MCP_AI_Tool_Update_Option',
		'WP_MCP_AI_Tool_Format_Code_Prettier',
		'WP_MCP_AI_Tool_Automate_Development_Workflow',
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

	public function provide_all_classes() {
		$data = array();
		foreach ( $this->tool_classes as $class ) {
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
	}

	protected function find_tool_file( $class_name ) {
		$kebab   = strtolower( str_replace( '_', '-', $class_name ) );
		$subdirs = array(
			WP_MCP_AI_PRO_PATH . 'includes/tools/infrastructure/',
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
