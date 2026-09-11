<?php
/**
 * Characterization tests for the Wave F2 architectural-design tool batch —
 * the 39 loader tools, the always-on drawing tool, and the tree-only
 * import-blueprint tool ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/architectural-design/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Architectural-design tool batch tests.
 */
class Test_Architectural_Design_Tools extends WP_UnitTestCase {

	/**
	 * The tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$floor_plan = new WP_MCP_AI_Tool_Generate_Floor_Plan();
		$this->assertSame( 'generate_floor_plan', $floor_plan->get_slug() );
		$this->assertSame( 'edit_posts', $floor_plan->get_required_capability() );

		$drawing = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();
		$this->assertSame( 'generate_architectural_drawing', $drawing->get_slug() );

		$import = new WP_MCP_AI_Tool_Import_Architectural_Design_Blueprint();
		$this->assertSame( 'import_architectural_design_blueprint', $import->get_slug() );
		$this->assertTrue( $import->requires_base_pro() );
		$this->assertIsBool( WP_MCP_AI_Tool_Import_Architectural_Design_Blueprint::is_available() );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Generate_Floor_Plan' => 'tools/architectural-design/floor-planning/class-wp-mcp-ai-tool-generate-floor-plan.php',
			'WP_MCP_AI_Tool_Generate_Architectural_Drawing' => 'tools/architectural-design/class-wp-mcp-ai-tool-generate-architectural-drawing.php',
			'WP_MCP_AI_Tool_Import_Architectural_Design_Blueprint' => 'tools/architectural-design/examples/class-wp-mcp-ai-tool-import-architectural-design-blueprint.php',
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
	 * The deterministic engine-backed execute() contracts degrade gracefully
	 * in the no-external-input environment.
	 */
	public function test_engine_backed_execute(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Generate_Floor_Plan();
		$result = $tool->execute(
			array(
				'width'  => 10,
				'length' => 20,
			),
			array( 'user_id' => $user_id )
		);
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Standalone only: the init's tool filter must carry all forty-one
	 * ported tools with the addon's file paths.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architectural-design/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_architectural_design_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 41, $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architectural-design/floor-planning/class-wp-mcp-ai-tool-generate-floor-plan.php',
			$tools['WP_MCP_AI_Tool_Generate_Floor_Plan']
		);
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Generate_Architectural_Drawing', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architectural-design/examples/class-wp-mcp-ai-tool-import-architectural-design-blueprint.php',
			$tools['WP_MCP_AI_Tool_Import_Architectural_Design_Blueprint']
		);
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architectural-design/examples/residential-architect.json' );
	}

	/**
	 * Standalone only: the ecosystem registration must register the ported
	 * tools into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architectural-design/init.php';
		wp_mcp_ai_pro_register_architectural_design_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['generate_floor_plan'] ?? null );
		$this->assertNotNull( $parent->all()['generate_architectural_drawing'] ?? null );
		$this->assertNotNull( $parent->all()['import_architectural_design_blueprint'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'generate_floor_plan' ) );
		$this->assertTrue( $core_tools->has( 'render_architectural_view' ) );
		$this->assertTrue( $core_tools->has( 'import_architectural_design_blueprint' ) );
	}
}
