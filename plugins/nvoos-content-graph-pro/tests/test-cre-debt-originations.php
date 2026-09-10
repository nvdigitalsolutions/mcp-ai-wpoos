<?php
/**
 * Characterization tests for the Wave F4 cre-debt originations batch — the
 * eleven ported origination tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/cre-debt/originations/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRE Debt originations tests.
 */
class Test_CRE_Debt_Originations extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the eleven gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-deal-pipeline-manager.php',
				'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-borrower-profile-analyzer.php',
				'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-loan-quote-generator.php',
				'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-market-comp-analyzer.php',
				'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-deal-screening-calculator.php',
				'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-origination-volume-tracker.php',
				'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-rate-lock-manager.php',
				'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-broker-relationship-tracker.php',
				'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-term-sheet-comparator.php',
				'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-execution-strategy-advisor.php',
				'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-closing-checklist-manager.php',
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
			'WP_MCP_AI_Tool_CRE_Deal_Pipeline_Manager'     => 'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-deal-pipeline-manager.php',
			'WP_MCP_AI_Tool_CRE_Borrower_Profile_Analyzer' => 'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-borrower-profile-analyzer.php',
			'WP_MCP_AI_Tool_CRE_Loan_Quote_Generator'      => 'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-loan-quote-generator.php',
			'WP_MCP_AI_Tool_CRE_Market_Comp_Analyzer'      => 'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-market-comp-analyzer.php',
			'WP_MCP_AI_Tool_CRE_Deal_Screening_Calculator' => 'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-deal-screening-calculator.php',
			'WP_MCP_AI_Tool_CRE_Origination_Volume_Tracker' => 'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-origination-volume-tracker.php',
			'WP_MCP_AI_Tool_CRE_Rate_Lock_Manager'         => 'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-rate-lock-manager.php',
			'WP_MCP_AI_Tool_CRE_Broker_Relationship_Tracker' => 'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-broker-relationship-tracker.php',
			'WP_MCP_AI_Tool_CRE_Term_Sheet_Comparator'     => 'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-term-sheet-comparator.php',
			'WP_MCP_AI_Tool_CRE_Execution_Strategy_Advisor' => 'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-execution-strategy-advisor.php',
			'WP_MCP_AI_Tool_CRE_Closing_Checklist_Manager' => 'tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-closing-checklist-manager.php',
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
			'WP_MCP_AI_Tool_CRE_Deal_Pipeline_Manager'     => 'cre_deal_pipeline_manager',
			'WP_MCP_AI_Tool_CRE_Borrower_Profile_Analyzer' => 'cre_borrower_profile_analyzer',
			'WP_MCP_AI_Tool_CRE_Loan_Quote_Generator'      => 'cre_loan_quote_generator',
			'WP_MCP_AI_Tool_CRE_Market_Comp_Analyzer'      => 'cre_market_comp_analyzer',
			'WP_MCP_AI_Tool_CRE_Deal_Screening_Calculator' => 'cre_deal_screening_calculator',
			'WP_MCP_AI_Tool_CRE_Origination_Volume_Tracker' => 'cre_origination_volume_tracker',
			'WP_MCP_AI_Tool_CRE_Rate_Lock_Manager'         => 'cre_rate_lock_manager',
			'WP_MCP_AI_Tool_CRE_Broker_Relationship_Tracker' => 'cre_broker_relationship_tracker',
			'WP_MCP_AI_Tool_CRE_Term_Sheet_Comparator'     => 'cre_term_sheet_comparator',
			'WP_MCP_AI_Tool_CRE_Execution_Strategy_Advisor' => 'cre_execution_strategy_advisor',
			'WP_MCP_AI_Tool_CRE_Closing_Checklist_Manager' => 'cre_closing_checklist_manager',
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
	public function test_borrower_profile_gates(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_cre_debt_toolkit' => true ) );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_CRE_Borrower_Profile_Analyzer();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_input', $result->get_error_code() );

		delete_option( 'wp_mcp_ai_settings' );
	}
}
