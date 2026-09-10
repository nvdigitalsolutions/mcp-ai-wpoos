<?php
/**
 * Characterization tests for the Wave F4 cre-debt asset-management batch —
 * the twelve ported asset-management tools plus the tree-only blueprint
 * importer.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/cre-debt/asset-management/` + `examples/` are asserted in
 *   full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRE Debt asset-management tests.
 */
class Test_CRE_Debt_Asset_Management extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-asset-disposition-analyzer.php',
				'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-capex-reserve-planner.php',
				'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-hold-sell-analyzer.php',
				'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-lease-expiration-manager.php',
				'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-loan-modification-calculator.php',
				'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-loan-surveillance-dashboard.php',
				'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-property-budget-manager.php',
				'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-property-performance-tracker.php',
				'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-servicing-fee-calculator.php',
				'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-tenant-credit-analyzer.php',
				'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-watchlist-manager.php',
				'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-workout-scenario-modeler.php',
				'tools/cre-debt/examples/class-wp-mcp-ai-tool-import-cre-debt-blueprint.php',
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
			'WP_MCP_AI_Tool_CRE_Asset_Disposition_Analyzer' => 'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-asset-disposition-analyzer.php',
			'WP_MCP_AI_Tool_CRE_Capex_Reserve_Planner'    => 'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-capex-reserve-planner.php',
			'WP_MCP_AI_Tool_CRE_Hold_Sell_Analyzer'       => 'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-hold-sell-analyzer.php',
			'WP_MCP_AI_Tool_CRE_Lease_Expiration_Manager' => 'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-lease-expiration-manager.php',
			'WP_MCP_AI_Tool_CRE_Loan_Modification_Calculator' => 'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-loan-modification-calculator.php',
			'WP_MCP_AI_Tool_CRE_Loan_Surveillance_Dashboard' => 'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-loan-surveillance-dashboard.php',
			'WP_MCP_AI_Tool_CRE_Property_Budget_Manager'  => 'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-property-budget-manager.php',
			'WP_MCP_AI_Tool_CRE_Property_Performance_Tracker' => 'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-property-performance-tracker.php',
			'WP_MCP_AI_Tool_CRE_Servicing_Fee_Calculator' => 'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-servicing-fee-calculator.php',
			'WP_MCP_AI_Tool_CRE_Tenant_Credit_Analyzer'   => 'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-tenant-credit-analyzer.php',
			'WP_MCP_AI_Tool_CRE_Watchlist_Manager'        => 'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-watchlist-manager.php',
			'WP_MCP_AI_Tool_CRE_Workout_Scenario_Modeler' => 'tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-workout-scenario-modeler.php',
			'WP_MCP_AI_Tool_Import_CRE_Debt_Blueprint'    => 'tools/cre-debt/examples/class-wp-mcp-ai-tool-import-cre-debt-blueprint.php',
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
			'WP_MCP_AI_Tool_CRE_Asset_Disposition_Analyzer' => 'cre_asset_disposition_analyzer',
			'WP_MCP_AI_Tool_CRE_Capex_Reserve_Planner'    => 'cre_capex_reserve_planner',
			'WP_MCP_AI_Tool_CRE_Hold_Sell_Analyzer'       => 'cre_hold_sell_analyzer',
			'WP_MCP_AI_Tool_CRE_Lease_Expiration_Manager' => 'cre_lease_expiration_manager',
			'WP_MCP_AI_Tool_CRE_Loan_Modification_Calculator' => 'cre_loan_modification_calculator',
			'WP_MCP_AI_Tool_CRE_Loan_Surveillance_Dashboard' => 'cre_loan_surveillance_dashboard',
			'WP_MCP_AI_Tool_CRE_Property_Budget_Manager'  => 'cre_property_budget_manager',
			'WP_MCP_AI_Tool_CRE_Property_Performance_Tracker' => 'cre_property_performance_tracker',
			'WP_MCP_AI_Tool_CRE_Servicing_Fee_Calculator' => 'cre_servicing_fee_calculator',
			'WP_MCP_AI_Tool_CRE_Tenant_Credit_Analyzer'   => 'cre_tenant_credit_analyzer',
			'WP_MCP_AI_Tool_CRE_Watchlist_Manager'        => 'cre_watchlist_manager',
			'WP_MCP_AI_Tool_CRE_Workout_Scenario_Modeler' => 'cre_workout_scenario_modeler',
			'WP_MCP_AI_Tool_Import_CRE_Debt_Blueprint'    => 'import_cre_debt_blueprint',
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
	public function test_capex_planner_gates(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_cre_debt_toolkit' => true ) );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_CRE_Capex_Reserve_Planner();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_input', $result->get_error_code() );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * The tree-only import tool must expose the byte-identical blueprint
	 * contract and the three shipped blueprints must exist standalone.
	 */
	public function test_import_blueprint_contracts(): void {
		$tool = new WP_MCP_AI_Tool_Import_CRE_Debt_Blueprint();
		$this->assertTrue( $tool->requires_base_pro() );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		$dir = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/examples';
		foreach ( array( 'loan-originator', 'cmbs-analyst', 'fund-manager' ) as $bp ) {
			$this->assertFileExists( $dir . '/' . $bp . '.json', $bp );
		}
	}
}
