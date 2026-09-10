<?php
/**
 * Characterization tests for the Wave F4 law-firm research-analytics batch —
 * the eight ported research & analytics tools plus the tree-only blueprint
 * importer.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/law-firm/research-analytics/` + `examples/` are asserted in
 *   full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Law-firm research-analytics tests.
 */
class Test_Law_Firm_Research_Analytics extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-legal-research-assistant.php',
				'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-case-law-analyzer.php',
				'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-firm-performance-dashboard.php',
				'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-matter-analytics-generator.php',
				'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-revenue-forecaster.php',
				'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-attorney-utilization-tracker.php',
				'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-client-satisfaction-analyzer.php',
				'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-competitive-benchmarker.php',
				'tools/law-firm/examples/class-wp-mcp-ai-tool-import-law-firm-blueprint.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The nine ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_LF_Legal_Research_Assistant'   => 'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-legal-research-assistant.php',
			'WP_MCP_AI_Tool_LF_Case_Law_Analyzer'          => 'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-case-law-analyzer.php',
			'WP_MCP_AI_Tool_LF_Firm_Performance_Dashboard' => 'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-firm-performance-dashboard.php',
			'WP_MCP_AI_Tool_LF_Matter_Analytics_Generator' => 'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-matter-analytics-generator.php',
			'WP_MCP_AI_Tool_LF_Revenue_Forecaster'         => 'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-revenue-forecaster.php',
			'WP_MCP_AI_Tool_LF_Attorney_Utilization_Tracker' => 'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-attorney-utilization-tracker.php',
			'WP_MCP_AI_Tool_LF_Client_Satisfaction_Analyzer' => 'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-client-satisfaction-analyzer.php',
			'WP_MCP_AI_Tool_LF_Competitive_Benchmarker'    => 'tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-competitive-benchmarker.php',
			'WP_MCP_AI_Tool_Import_Law_Firm_Blueprint'     => 'tools/law-firm/examples/class-wp-mcp-ai-tool-import-law-firm-blueprint.php',
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
	 * The tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_LF_Legal_Research_Assistant'   => 'lf_legal_research_assistant',
			'WP_MCP_AI_Tool_LF_Case_Law_Analyzer'          => 'lf_case_law_analyzer',
			'WP_MCP_AI_Tool_LF_Firm_Performance_Dashboard' => 'lf_firm_performance_dashboard',
			'WP_MCP_AI_Tool_LF_Matter_Analytics_Generator' => 'lf_matter_analytics_generator',
			'WP_MCP_AI_Tool_LF_Revenue_Forecaster'         => 'lf_revenue_forecaster',
			'WP_MCP_AI_Tool_LF_Attorney_Utilization_Tracker' => 'lf_attorney_utilization_tracker',
			'WP_MCP_AI_Tool_LF_Client_Satisfaction_Analyzer' => 'lf_client_satisfaction_analyzer',
			'WP_MCP_AI_Tool_LF_Competitive_Benchmarker'    => 'lf_competitive_benchmarker',
			'WP_MCP_AI_Tool_Import_Law_Firm_Blueprint'     => 'import_law_firm_blueprint',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The settings-gated availability + missing-required first gate must be
	 * byte-identical.
	 */
	public function test_legal_research_gates(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_law_firm_toolkit' => true ) );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_LF_Legal_Research_Assistant();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_required', $result->get_error_code() );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * The tree-only import tool must expose the byte-identical blueprint
	 * contract and the four shipped blueprints must exist standalone.
	 */
	public function test_import_blueprint_contracts(): void {
		$tool = new WP_MCP_AI_Tool_Import_Law_Firm_Blueprint();
		$this->assertTrue( $tool->requires_base_pro() );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		$dir = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/examples';
		foreach ( array( 'managing-partner', 'compliance-officer', 'paralegal', 'litigation-associate' ) as $bp ) {
			$this->assertFileExists( $dir . '/' . $bp . '.json', $bp );
		}
	}
}
