<?php
/**
 * Characterization tests for the Wave F4 cre-debt debt-fund batch — the
 * eleven ported debt-fund tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/cre-debt/debt-fund/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRE Debt debt-fund tests.
 */
class Test_CRE_Debt_Debt_Fund extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the eleven gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-concentration-limit-monitor.php',
				'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-covenant-compliance-checker.php',
				'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-credit-risk-scorer.php',
				'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-debt-waterfall-modeler.php',
				'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-capital-call-calculator.php',
				'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-liquidity-analyzer.php',
				'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-portfolio-dashboard.php',
				'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-return-calculator.php',
				'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-scenario-modeler.php',
				'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-lp-report-generator.php',
				'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-warehouse-line-manager.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The eleven ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_CRE_Concentration_Limit_Monitor' => 'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-concentration-limit-monitor.php',
			'WP_MCP_AI_Tool_CRE_Covenant_Compliance_Checker' => 'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-covenant-compliance-checker.php',
			'WP_MCP_AI_Tool_CRE_Credit_Risk_Scorer'       => 'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-credit-risk-scorer.php',
			'WP_MCP_AI_Tool_CRE_Debt_Waterfall_Modeler'   => 'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-debt-waterfall-modeler.php',
			'WP_MCP_AI_Tool_CRE_Fund_Capital_Call_Calculator' => 'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-capital-call-calculator.php',
			'WP_MCP_AI_Tool_CRE_Fund_Liquidity_Analyzer'  => 'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-liquidity-analyzer.php',
			'WP_MCP_AI_Tool_CRE_Fund_Portfolio_Dashboard' => 'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-portfolio-dashboard.php',
			'WP_MCP_AI_Tool_CRE_Fund_Return_Calculator'   => 'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-return-calculator.php',
			'WP_MCP_AI_Tool_CRE_Fund_Scenario_Modeler'    => 'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-scenario-modeler.php',
			'WP_MCP_AI_Tool_CRE_LP_Report_Generator'      => 'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-lp-report-generator.php',
			'WP_MCP_AI_Tool_CRE_Warehouse_Line_Manager'   => 'tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-warehouse-line-manager.php',
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
			'WP_MCP_AI_Tool_CRE_Concentration_Limit_Monitor' => 'cre_concentration_limit_monitor',
			'WP_MCP_AI_Tool_CRE_Covenant_Compliance_Checker' => 'cre_covenant_compliance_checker',
			'WP_MCP_AI_Tool_CRE_Credit_Risk_Scorer'       => 'cre_credit_risk_scorer',
			'WP_MCP_AI_Tool_CRE_Debt_Waterfall_Modeler'   => 'cre_debt_waterfall_modeler',
			'WP_MCP_AI_Tool_CRE_Fund_Capital_Call_Calculator' => 'cre_fund_capital_call_calculator',
			'WP_MCP_AI_Tool_CRE_Fund_Liquidity_Analyzer'  => 'cre_fund_liquidity_analyzer',
			'WP_MCP_AI_Tool_CRE_Fund_Portfolio_Dashboard' => 'cre_fund_portfolio_dashboard',
			'WP_MCP_AI_Tool_CRE_Fund_Return_Calculator'   => 'cre_fund_return_calculator',
			'WP_MCP_AI_Tool_CRE_Fund_Scenario_Modeler'    => 'cre_fund_scenario_modeler',
			'WP_MCP_AI_Tool_CRE_LP_Report_Generator'      => 'cre_lp_report_generator',
			'WP_MCP_AI_Tool_CRE_Warehouse_Line_Manager'   => 'cre_warehouse_line_manager',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The settings-gated availability + invalid-input first gate must be
	 * byte-identical.
	 */
	public function test_waterfall_gates(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_cre_debt_toolkit' => true ) );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_CRE_Debt_Waterfall_Modeler();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_input', $result->get_error_code() );

		delete_option( 'wp_mcp_ai_settings' );
	}
}
