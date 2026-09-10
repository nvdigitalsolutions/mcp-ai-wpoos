<?php
/**
 * Characterization tests for the Wave F4 cre-debt underwriting batch — the
 * thirteen ported underwriting tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/cre-debt/underwriting/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRE Debt underwriting tests.
 */
class Test_CRE_Debt_Underwriting extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the thirteen gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-dcf-modeler.php',
				'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-noi-calculator.php',
				'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-loan-sizer.php',
				'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-amortization-scheduler.php',
				'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-debt-yield-analyzer.php',
				'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-cap-rate-sensitivity.php',
				'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-rent-roll-analyzer.php',
				'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-operating-expense-benchmarker.php',
				'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-stress-test-modeler.php',
				'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-leverage-return-analyzer.php',
				'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-property-valuation-engine.php',
				'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-environmental-risk-scorer.php',
				'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-underwriting-memo-generator.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The thirteen ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_CRE_DCF_Modeler'               => 'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-dcf-modeler.php',
			'WP_MCP_AI_Tool_CRE_NOI_Calculator'            => 'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-noi-calculator.php',
			'WP_MCP_AI_Tool_CRE_Loan_Sizer'                => 'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-loan-sizer.php',
			'WP_MCP_AI_Tool_CRE_Amortization_Scheduler'    => 'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-amortization-scheduler.php',
			'WP_MCP_AI_Tool_CRE_Debt_Yield_Analyzer'       => 'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-debt-yield-analyzer.php',
			'WP_MCP_AI_Tool_CRE_Cap_Rate_Sensitivity'      => 'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-cap-rate-sensitivity.php',
			'WP_MCP_AI_Tool_CRE_Rent_Roll_Analyzer'        => 'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-rent-roll-analyzer.php',
			'WP_MCP_AI_Tool_CRE_Operating_Expense_Benchmarker' => 'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-operating-expense-benchmarker.php',
			'WP_MCP_AI_Tool_CRE_Stress_Test_Modeler'       => 'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-stress-test-modeler.php',
			'WP_MCP_AI_Tool_CRE_Leverage_Return_Analyzer'  => 'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-leverage-return-analyzer.php',
			'WP_MCP_AI_Tool_CRE_Property_Valuation_Engine' => 'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-property-valuation-engine.php',
			'WP_MCP_AI_Tool_CRE_Environmental_Risk_Scorer' => 'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-environmental-risk-scorer.php',
			'WP_MCP_AI_Tool_CRE_Underwriting_Memo_Generator' => 'tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-underwriting-memo-generator.php',
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
			'WP_MCP_AI_Tool_CRE_DCF_Modeler'               => 'cre_dcf_modeler',
			'WP_MCP_AI_Tool_CRE_NOI_Calculator'            => 'cre_noi_calculator',
			'WP_MCP_AI_Tool_CRE_Loan_Sizer'                => 'cre_loan_sizer',
			'WP_MCP_AI_Tool_CRE_Amortization_Scheduler'    => 'cre_amortization_scheduler',
			'WP_MCP_AI_Tool_CRE_Debt_Yield_Analyzer'       => 'cre_debt_yield_analyzer',
			'WP_MCP_AI_Tool_CRE_Cap_Rate_Sensitivity'      => 'cre_cap_rate_sensitivity',
			'WP_MCP_AI_Tool_CRE_Rent_Roll_Analyzer'        => 'cre_rent_roll_analyzer',
			'WP_MCP_AI_Tool_CRE_Operating_Expense_Benchmarker' => 'cre_operating_expense_benchmarker',
			'WP_MCP_AI_Tool_CRE_Stress_Test_Modeler'       => 'cre_stress_test_modeler',
			'WP_MCP_AI_Tool_CRE_Leverage_Return_Analyzer'  => 'cre_leverage_return_analyzer',
			'WP_MCP_AI_Tool_CRE_Property_Valuation_Engine' => 'cre_property_valuation_engine',
			'WP_MCP_AI_Tool_CRE_Environmental_Risk_Scorer' => 'cre_environmental_risk_scorer',
			'WP_MCP_AI_Tool_CRE_Underwriting_Memo_Generator' => 'cre_underwriting_memo_generator',
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
	public function test_loan_sizer_gates(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_cre_debt_toolkit' => true ) );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_CRE_Loan_Sizer();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_input', $result->get_error_code() );

		delete_option( 'wp_mcp_ai_settings' );
	}
}
