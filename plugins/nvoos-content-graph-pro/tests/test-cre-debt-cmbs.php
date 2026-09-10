<?php
/**
 * Characterization tests for the Wave F4 cre-debt cmbs batch — the ten
 * ported CMBS / securitization tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/cre-debt/cmbs/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRE Debt cmbs tests.
 */
class Test_CRE_Debt_Cmbs extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the ten gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-bond-cash-flow-modeler.php',
				'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-deal-structurer.php',
				'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-defeasance-calculator.php',
				'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-investor-reporting-generator.php',
				'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-maturity-risk-analyzer.php',
				'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-pool-analyzer.php',
				'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-rating-agency-analyzer.php',
				'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-special-servicing-tracker.php',
				'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-surveillance-monitor.php',
				'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cre-clo-modeler.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The ten ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_CMBS_Bond_Cash_Flow_Modeler' => 'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-bond-cash-flow-modeler.php',
			'WP_MCP_AI_Tool_CMBS_Deal_Structurer'        => 'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-deal-structurer.php',
			'WP_MCP_AI_Tool_CMBS_Defeasance_Calculator'  => 'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-defeasance-calculator.php',
			'WP_MCP_AI_Tool_CMBS_Investor_Reporting_Generator' => 'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-investor-reporting-generator.php',
			'WP_MCP_AI_Tool_CMBS_Maturity_Risk_Analyzer' => 'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-maturity-risk-analyzer.php',
			'WP_MCP_AI_Tool_CMBS_Pool_Analyzer'          => 'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-pool-analyzer.php',
			'WP_MCP_AI_Tool_CMBS_Rating_Agency_Analyzer' => 'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-rating-agency-analyzer.php',
			'WP_MCP_AI_Tool_CMBS_Special_Servicing_Tracker' => 'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-special-servicing-tracker.php',
			'WP_MCP_AI_Tool_CMBS_Surveillance_Monitor'   => 'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-surveillance-monitor.php',
			'WP_MCP_AI_Tool_CRE_CLO_Modeler'             => 'tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cre-clo-modeler.php',
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
			'WP_MCP_AI_Tool_CMBS_Bond_Cash_Flow_Modeler' => 'cmbs_bond_cash_flow_modeler',
			'WP_MCP_AI_Tool_CMBS_Deal_Structurer'        => 'cmbs_deal_structurer',
			'WP_MCP_AI_Tool_CMBS_Defeasance_Calculator'  => 'cmbs_defeasance_calculator',
			'WP_MCP_AI_Tool_CMBS_Investor_Reporting_Generator' => 'cmbs_investor_reporting_generator',
			'WP_MCP_AI_Tool_CMBS_Maturity_Risk_Analyzer' => 'cmbs_maturity_risk_analyzer',
			'WP_MCP_AI_Tool_CMBS_Pool_Analyzer'          => 'cmbs_pool_analyzer',
			'WP_MCP_AI_Tool_CMBS_Rating_Agency_Analyzer' => 'cmbs_rating_agency_analyzer',
			'WP_MCP_AI_Tool_CMBS_Special_Servicing_Tracker' => 'cmbs_special_servicing_tracker',
			'WP_MCP_AI_Tool_CMBS_Surveillance_Monitor'   => 'cmbs_surveillance_monitor',
			'WP_MCP_AI_Tool_CRE_CLO_Modeler'             => 'cre_clo_modeler',
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
	public function test_defeasance_gates(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_cre_debt_toolkit' => true ) );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_CMBS_Defeasance_Calculator();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_input', $result->get_error_code() );

		delete_option( 'wp_mcp_ai_settings' );
	}
}
