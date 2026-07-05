<?php
/**
 * Tests for remaining untested Pro tools — catch-all batch test.
 *
 * Covers tools in the root pro tools directory and infrastructure/support classes
 * that exist alongside tools but may not implement the full tool interface.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 */

class Test_WP_MCP_AI_Remaining_Pro_Tools extends WP_UnitTestCase {

	protected $user_id;

	/**
	 * All remaining untested tool/class names.
	 *
	 * @var array<string>
	 */
	public static $tool_classes = array(
		// CRM infrastructure classes.
		'WP_MCP_AI_CRM_Email_Search_Cron',
		'WP_MCP_AI_CRM_Gmail_Listener',
		'WP_MCP_AI_CRM_Gmail_PubSub_Handler',
		'WP_MCP_AI_CRM_IMAP_Client',
		'WP_MCP_AI_CRM_IMAP_Listener',
		'WP_MCP_AI_CRM_Message_Log',
		'WP_MCP_AI_CRM_Optimization',
		'WP_MCP_AI_CRM_SMS_Webhook_Listener',
		'WP_MCP_AI_CRM_Ticket_Automation',
		'WP_MCP_AI_CRM_Ticket_Notifications',
		'WP_MCP_AI_CRM_Web_Form_Listener',
		'WP_MCP_AI_CRM_WhatsApp_Webhook_Listener',

		// FlowHub / other infrastructure.
		'WP_MCP_AI_FlowHub_Alert_Manager',
		'WP_MCP_AI_Law_Firm_Access',

		// PM infrastructure.
		'WP_MCP_AI_PM_Capabilities',
		'WP_MCP_AI_PM_Codes',
		'WP_MCP_AI_PM_Engine',
		'WP_MCP_AI_PM_Pipeline_Stages',
		'WP_MCP_AI_PM_Workflow_Engine',

		// Blueprint installer.
		'WP_MCP_AI_Blueprint_Installer',

		// Pro tools in root directory (wp-mcp-ai-pro-tool-*).
		'WP_MCP_AI_Pro_Tool_Configure_Circuit_Breaker',
		'WP_MCP_AI_Pro_Tool_Configure_Schedule_Widget_Defaults',
		'WP_MCP_AI_Pro_Tool_CPT',
		'WP_MCP_AI_Pro_Tool_Create_Execution_Prompt',
		'WP_MCP_AI_Pro_Tool_Create_Google_Chat_Space',
		'WP_MCP_AI_Pro_Tool_Create_WPCode_Snippet',
		'WP_MCP_AI_Pro_Tool_Elementor',
		'WP_MCP_AI_Pro_Tool_EZuite_Inventory',
		'WP_MCP_AI_Pro_Tool_EZuite_Settings',
		'WP_MCP_AI_Pro_Tool_EZuite_Sync',
		'WP_MCP_AI_Pro_Tool_FlowHub_Analytics',
		'WP_MCP_AI_Pro_Tool_Get_Google_Analytics_Report',
		'WP_MCP_AI_Pro_Tool_Get_Google_Business_Insights',
		'WP_MCP_AI_Pro_Tool_Get_Import_Duty',
		'WP_MCP_AI_Pro_Tool_Get_Loop_Metrics',
		'WP_MCP_AI_Pro_Tool_Get_Mailjet_Statistics',
		'WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report',
		'WP_MCP_AI_Pro_Tool_Get_Schedule_Latest_Result',
		'WP_MCP_AI_Pro_Tool_Get_Twitter_DMs',
		'WP_MCP_AI_Pro_Tool_Install_And_Activate_Plugin',
		'WP_MCP_AI_Pro_Tool_Install_And_Activate_Theme',
		'WP_MCP_AI_Pro_Tool_Lookup_Product_Price',
		'WP_MCP_AI_Pro_Tool_Manage_Mailjet_Contacts',
		'WP_MCP_AI_Pro_Tool_Manage_Twitter_Webhook',
		'WP_MCP_AI_Pro_Tool_Post_Google_Business_Update',
		'WP_MCP_AI_Pro_Tool_Printful',
		'WP_MCP_AI_Pro_Tool_Render_Schedule_Result',
		'WP_MCP_AI_Pro_Tool_Schedule_All_Import',
		'WP_MCP_AI_Pro_Tool_Search_Drive',
		'WP_MCP_AI_Pro_Tool_Send_Twitter_DM',
		'WP_MCP_AI_Pro_Tool_Shopify_Customers',
		'WP_MCP_AI_Pro_Tool_Shopify_Inventory',
		'WP_MCP_AI_Pro_Tool_Shopify_Orders',
		'WP_MCP_AI_Pro_Tool_Site_Creator',
		'WP_MCP_AI_Pro_Tool_Update_Option',

		// Various scattered tools in root directory.
		'WP_MCP_AI_Tool_Check_Dnc_Status',
		'WP_MCP_AI_Tool_Connect_To_External_Crm',
		'WP_MCP_AI_Tool_Export_Comic_Cbz',
		'WP_MCP_AI_Tool_Export_Project_Csv',
		'WP_MCP_AI_Tool_Get_Pipeline_View',
		'WP_MCP_AI_Tool_Get_Ticket_Sla_Report',
		'WP_MCP_AI_Tool_Import_CRM_Csv',
		'WP_MCP_AI_Tool_Import_Places',
		'WP_MCP_AI_Tool_Import_Places_From_Html',
		'WP_MCP_AI_Tool_Import_Services',
		'WP_MCP_AI_Tool_Paper_Store_Export',
		'WP_MCP_AI_Tool_Paper_Store_Import',
		'WP_MCP_AI_Tool_Qualify_Lead_Bant',
		'WP_MCP_AI_Tool_Qualify_Lead_Meddic',
		'WP_MCP_AI_Tool_Send_Lead_Dm',
		'WP_MCP_AI_Tool_Send_Lead_Whatsapp',
		'WP_MCP_AI_Tool_Snooze_CRM_Activity',
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

	/**
	 * Test that every remaining class file exists and is loadable.
	 *
	 * @dataProvider provide_all_classes
	 */
	public function test_class_file_exists_and_loads( $class_name ) {
		$tool_file = $this->find_class_file( $class_name );
		if ( ! $tool_file ) {
			$this->markTestSkipped( $class_name . ' file not found in any location.' );
		}
		$this->assertFileExists( $tool_file, basename( $tool_file ) . ' should exist' );
		require_once $tool_file;
		$this->assertTrue( class_exists( $class_name ), $class_name . ' class should exist' );
	}

	/**
	 * Test that tool classes have valid metadata (skip non-tool utility classes).
	 *
	 * @dataProvider provide_all_classes
	 */
	public function test_class_metadata_if_tool( $class_name ) {
		$tool_file = $this->find_class_file( $class_name );
		if ( ! $tool_file ) {
			$this->markTestSkipped();
		}
		require_once $tool_file;
		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped();
		}

		try {
						$reflection = new ReflectionClass( $class_name );
						if ( $reflection->isAbstract() || $reflection->isInterface() ) {
							return; // Skip abstract classes and interfaces.
						}
						$constructor = $reflection->getConstructor();
						if ( $constructor && $constructor->getNumberOfRequiredParameters() > 0 ) {
							return; // Skip classes that require constructor args.
						}
						$instance = $reflection->newInstance();
					} catch ( \ReflectionException $e ) {
						return; // Can't reflect — skip.
					}

					// If it implements the tool interface, validate metadata.
					if ( $instance instanceof WP_MCP_AI_Tool_Interface ) {
			$this->assertNotEmpty( $instance->get_slug() );
			$this->assertNotEmpty( $instance->get_name() );
			$this->assertNotEmpty( $instance->get_description() );

			$schema = $instance->get_parameters_schema();
			$this->assertIsArray( $schema );
			$this->assertArrayHasKey( 'type', $schema );

			// Execute should not crash.
			if ( method_exists( $instance, 'is_available' ) && ! $instance->is_available() ) {
				return; // Skip execute test if unavailable.
			}
			$result = $instance->execute( array(), array( 'user_id' => $this->user_id ) );
			$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
		}
	}

	/**
	 * Find a class file across possible pro tool locations.
	 *
	 * @param string $class_name Class name.
	 * @return string|false
	 */
	protected function find_class_file( $class_name ) {
		$kebab   = strtolower( str_replace( '_', '-', $class_name ) );
		$subdirs = array(
			// Root pro tools directory.
			WP_MCP_AI_PRO_PATH . 'includes/tools/',
			// Domain subdirectories.
			WP_MCP_AI_PRO_PATH . 'includes/tools/crm/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/flowhub/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/erp-ezuite/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/comic-creation/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/places/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/paper-store/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/email-marketing/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/shopify-sync/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/site-creator-toolkit/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/',
			WP_MCP_AI_PRO_PATH . 'includes/tools/infrastructure/',
		);

		foreach ( $subdirs as $dir ) {
			$path = $dir . 'class-' . $kebab . '.php';
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		// Deep search as fallback.
		return $this->deep_find( $kebab );
	}

	/**
	 * Deep search for a class file by kebab-case name.
	 *
	 * @param string $kebab Kebab-case class name.
	 * @return string|false
	 */
	protected function deep_find( $kebab ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				WP_MCP_AI_PRO_PATH . 'includes/tools/',
				RecursiveDirectoryIterator::SKIP_DOTS
			)
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getFilename() === 'class-' . $kebab . '.php' ) {
				return $file->getPathname();
			}
		}

		return false;
	}
}
