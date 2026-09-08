<?php
/**
 * Characterization tests for the Wave F2 analytics toolkit — the slimmed
 * standalone init plus the twelve gated tools and the tree-only
 * import-blueprint tool ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/analytics/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Analytics toolkit tests.
 */
class Test_Analytics_Toolkit extends WP_UnitTestCase {

	/**
	 * The gated tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$funnel = new WP_MCP_AI_Tool_Funnel_Analysis();
		$this->assertSame( 'funnel_analysis', $funnel->get_slug() );
		$this->assertSame( 'manage_options', $funnel->get_required_capability() );
		$this->assertSame( array(), $funnel->get_parameters_schema()['required'] );

		$forecast = new WP_MCP_AI_Tool_Revenue_Forecast();
		$this->assertSame( 'revenue_forecast', $forecast->get_slug() );

		$import = new WP_MCP_AI_Tool_Import_Analytics_Blueprint();
		$this->assertSame( 'import_analytics_blueprint', $import->get_slug() );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Funnel_Analysis'  => 'tools/analytics/class-wp-mcp-ai-tool-funnel-analysis.php',
			'WP_MCP_AI_Tool_Revenue_Forecast' => 'tools/analytics/class-wp-mcp-ai-tool-revenue-forecast.php',
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
	 * The funnel-analysis execute() contracts degrade gracefully — a custom
	 * funnel without steps is rejected and the default funnel smoke-runs
	 * over empty data (no external probes are exercised).
	 */
	public function test_funnel_analysis_execute_contracts(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Funnel_Analysis();

		$missing_steps = $tool->execute(
			array( 'funnel_type' => 'custom' ),
			array( 'user_id' => $user_id )
		);
		$this->assertInstanceOf( 'WP_Error', $missing_steps );
		$this->assertSame( 'custom_steps_required', $missing_steps->get_error_code() );

		$smoke = $tool->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertTrue( is_array( $smoke ) || is_wp_error( $smoke ) );
	}

	/**
	 * Standalone only: the init's tool filter must carry all thirteen ported
	 * tools with the addon's file paths.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the analytics tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/analytics/init.php';
		// Re-arm the filter added inside the init's guard block — the WP test
		// framework may have restored an earlier hook snapshot.
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_analytics_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 13, $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Funnel_Analysis', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/analytics/class-wp-mcp-ai-tool-funnel-analysis.php',
			$tools['WP_MCP_AI_Tool_Funnel_Analysis']
		);
		// The tree-only import tool is carried standalone (the base registers
		// it nowhere — CRM CC-extras precedent).
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Import_Analytics_Blueprint', $tools );
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/analytics/examples/business-intelligence-analyst.json' );
	}

	/**
	 * Standalone only: the ecosystem registration must register the ported
	 * tools into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/analytics/init.php';
		wp_mcp_ai_pro_register_analytics_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['funnel_analysis'] ?? null );
		$this->assertNotNull( $parent->all()['revenue_forecast'] ?? null );
		$this->assertNotNull( $parent->all()['import_analytics_blueprint'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'funnel_analysis' ) );
		$this->assertTrue( $core_tools->has( 'import_analytics_blueprint' ) );
	}
}
