<?php
/**
 * Tests for Import Blueprint Pro tools.
 *
 * Covers all import-*-blueprint tools that install toolkit configurations.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group import-blueprints
 */

class Test_WP_MCP_AI_Import_Blueprint_Tools extends WP_UnitTestCase {

	protected $user_id;

	public static $tool_classes = array(
		'WP_MCP_AI_Tool_Import_AI_Tool_Builder_Blueprint',
		'WP_MCP_AI_Tool_Import_Analytics_Blueprint',
		'WP_MCP_AI_Tool_Import_Architectural_Design_Blueprint',
		'WP_MCP_AI_Tool_Import_Calendar_Booking_Blueprint',
		'WP_MCP_AI_Tool_Import_Chat_Channels_Blueprint',
		'WP_MCP_AI_Tool_Import_Comic_Creation_Blueprint',
		'WP_MCP_AI_Tool_Import_CRE_Debt_Blueprint',
		'WP_MCP_AI_Tool_Import_CRM_Blueprint',
		'WP_MCP_AI_Tool_Import_DJ_Management_Blueprint',
		'WP_MCP_AI_Tool_Import_Document_Generation_Blueprint',
		'WP_MCP_AI_Tool_Import_ECA_Management_Blueprint',
		'WP_MCP_AI_Tool_Import_Ecommerce_Blueprint',
		'WP_MCP_AI_Tool_Import_Email_Marketing_Blueprint',
		'WP_MCP_AI_Tool_Import_Extended_Cognition_Blueprint',
		'WP_MCP_AI_Tool_Import_Financial_Planning_Blueprint',
		'WP_MCP_AI_Tool_Import_Healthcare_Blueprint',
		'WP_MCP_AI_Tool_Import_Image_Production_Blueprint',
		'WP_MCP_AI_Tool_Import_Law_Firm_Blueprint',
		'WP_MCP_AI_Tool_Import_Media_Blueprint',
		'WP_MCP_AI_Tool_Import_Multilingual_Blueprint',
		'WP_MCP_AI_Tool_Import_Project_Management_Blueprint',
		'WP_MCP_AI_Tool_Import_Regulatory_Registration_Blueprint',
		'WP_MCP_AI_Tool_Import_Site_Creator_Blueprint',
		'WP_MCP_AI_Tool_Import_Social_Media_Blueprint',
		'WP_MCP_AI_Tool_Import_Video_Production_Blueprint',
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
		$kebab = strtolower( str_replace( '_', '-', $class_name ) );
		$filename = 'class-' . $kebab . '.php';

		// Recursive search across all pro tool subdirectories.
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				WP_MCP_AI_PRO_PATH . 'includes/tools/',
				RecursiveDirectoryIterator::SKIP_DOTS
			)
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getFilename() === $filename ) {
				return $file->getPathname();
			}
		}

		return false;
	}
}
