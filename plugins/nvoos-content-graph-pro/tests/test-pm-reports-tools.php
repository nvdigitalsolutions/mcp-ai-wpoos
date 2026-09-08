<?php
/**
 * Characterization tests for the Wave F2 PM reports + blueprint-import
 * batch — the ported status-report/CSV-export tools, the PM blueprint
 * import tool, its six JSON assets, and the shared blueprint installer.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and
 *   contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/project-management/{reports,examples}/` + `src/tools/orchestration/`
 *   are asserted in full, including the filter and ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * PM reports + blueprint-import tool batch tests.
 */
class Test_Pm_Reports_Tools extends WP_UnitTestCase {

	/**
	 * Enable the PM toolkit for availability gates.
	 */
	public function setUp(): void {
		parent::setUp();
		$settings                              = get_option( 'wp_mcp_ai_settings', array() );
		$settings                              = is_array( $settings ) ? $settings : array();
		$settings['enable_project_management'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * The four ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Generate_Status_Report' => 'tools/project-management/reports/class-wp-mcp-ai-tool-generate-status-report.php',
			'WP_MCP_AI_Tool_Export_Project_CSV'     => 'tools/project-management/reports/class-wp-mcp-ai-tool-export-project-csv.php',
			'WP_MCP_AI_Tool_Import_Project_Management_Blueprint' => 'tools/project-management/examples/class-wp-mcp-ai-tool-import-project-management-blueprint.php',
			'WP_MCP_AI_Blueprint_Installer'         => 'tools/orchestration/class-wp-mcp-ai-blueprint-installer.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The three tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Generate_Status_Report' => 'generate_status_report',
			'WP_MCP_AI_Tool_Export_Project_CSV'     => 'export_project_csv',
			'WP_MCP_AI_Tool_Import_Project_Management_Blueprint' => 'import_project_management_blueprint',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The blueprint installer must load/list/label the copied JSON assets.
	 */
	public function test_installer_contracts(): void {
		$dir = defined( 'WP_MCP_AI_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/examples'
			: NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/examples';

		$loaded = WP_MCP_AI_Blueprint_Installer::load_blueprint( $dir, 'project-manager' );
		$this->assertNotWPError( $loaded );
		$this->assertNotEmpty( $loaded['name'] ?? array() );

		$list = WP_MCP_AI_Blueprint_Installer::list_blueprints( $dir );
		$this->assertContains( 'project-manager', $list );
		$this->assertContains( 'scrum-master', $list );

		$this->assertSame( 'Project Manager', WP_MCP_AI_Blueprint_Installer::slug_to_label( 'project-manager' ) );
	}

	/**
	 * The status-report and CSV-export tools must gate and produce output.
	 */
	public function test_report_tools_execute(): void {
		WP_MCP_AI_Project_CPT::register_post_type();
		$create  = new WP_MCP_AI_Tool_Create_Project();
		$project = $create->execute( array( 'name' => 'Report Home' ), array( 'user_id' => 1 ) );

		$report  = new WP_MCP_AI_Tool_Generate_Status_Report();
		$missing = $report->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_project_id', $missing->get_error_code() );

		$generated = $report->execute( array( 'project_id' => $project['project_id'] ), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $generated );
		$this->assertTrue( $generated['success'] );
		$this->assertNotEmpty( $generated['report'] ?? array() );

		$csv      = new WP_MCP_AI_Tool_Export_Project_CSV();
		$exported = $csv->execute( array( 'project_id' => $project['project_id'] ), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $exported );
		$this->assertTrue( $exported['success'] );
		$this->assertNotEmpty( $exported['csv'] ?? array() );
	}

	/**
	 * The blueprint-import tool must install the copied asset as an
	 * mcp_ai_assistant post.
	 */
	public function test_blueprint_import_execute(): void {
		$tool   = new WP_MCP_AI_Tool_Import_Project_Management_Blueprint();
		$result = $tool->execute( array( 'blueprint' => 'project-manager' ), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertGreaterThan( 0, $result['assistant_id'] );
		$this->assertSame( 'mcp_ai_assistant', get_post( $result['assistant_id'] )->post_type );
	}

	/**
	 * Standalone only: the init's tool filter must carry the batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the PM tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_pm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Generate_Status_Report', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Import_Project_Management_Blueprint', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the batch
	 * into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/init.php';
		wp_mcp_ai_pro_register_pm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['generate_status_report'] ?? null );
		$this->assertNotNull( $parent->all()['import_project_management_blueprint'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'export_project_csv' ) );
		$this->assertTrue( $core_tools->has( 'import_project_management_blueprint' ) );
	}
}
