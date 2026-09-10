<?php
/**
 * Characterization tests for the Wave F4 cre-debt data layer — the two-CPT
 * registration class and the calculator helper class ported from the base
 * Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/` +
 *   `src/tools/cre-debt/` are asserted in full, including the slim init's
 *   file-gate targets and the standalone-only tool wiring scaffolding.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRE Debt data layer tests.
 */
class Test_CRE_Debt_Data_Layer extends WP_UnitTestCase {

	/**
	 * The two ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_CRE_Debt_CPT'        => 'class-wp-mcp-ai-cre-debt-cpt.php',
			'WP_MCP_AI_CRE_Debt_Calculator' => 'tools/cre-debt/class-wp-mcp-ai-cre-debt-calculator.php',
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
	 * The two post-type constants must be byte-identical.
	 */
	public function test_cpt_constants(): void {
		$this->assertSame( 'mcp_ai_cre_loan', WP_MCP_AI_CRE_Debt_CPT::LOAN_POST_TYPE );
		$this->assertSame( 'mcp_ai_cre_property', WP_MCP_AI_CRE_Debt_CPT::PROPERTY_POST_TYPE );
	}

	/**
	 * The CPT init must wire the registration hooks and register the two
	 * post types.
	 */
	public function test_cpt_init_and_registration(): void {
		WP_MCP_AI_CRE_Debt_CPT::init();
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_CRE_Debt_CPT', 'register_post_types' ) ) );

		WP_MCP_AI_CRE_Debt_CPT::register_post_types();
		$this->assertTrue( post_type_exists( 'mcp_ai_cre_loan' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_cre_property' ) );
	}

	/**
	 * The calculator contracts must be byte-identical.
	 */
	public function test_calculator_contracts(): void {
		$this->assertEqualsWithDelta( 599.55, WP_MCP_AI_CRE_Debt_Calculator::calculate_monthly_payment( 100000.0, 0.06, 360 ), 0.01 );
		$this->assertSame( 500.0, WP_MCP_AI_CRE_Debt_Calculator::calculate_io_payment( 100000.0, 0.06 ) );
		$this->assertSame( 2.0, WP_MCP_AI_CRE_Debt_Calculator::calculate_dscr( 100.0, 50.0 ) );
		$this->assertSame( 0.8, WP_MCP_AI_CRE_Debt_Calculator::calculate_ltv( 80.0, 100.0 ) );
		$this->assertSame( 0.1, WP_MCP_AI_CRE_Debt_Calculator::calculate_debt_yield( 100.0, 1000.0 ) );
		$this->assertSame( 1250.0, WP_MCP_AI_CRE_Debt_Calculator::calculate_value_direct_cap( 100.0, 0.08 ) );

		$noi = WP_MCP_AI_CRE_Debt_Calculator::calculate_noi( 1000.0, 0.05, 10.0, 20.0, 300.0 );
		$this->assertSame( 50.0, $noi['vacancy_loss'] );
		$this->assertSame( 960.0, $noi['egi'] );
		$this->assertSame( 660.0, $noi['noi'] );
	}

	/**
	 * Standalone only: the slim init's file targets must exist, the tool
	 * filter must carry the forty-five originations + underwriting + cmbs +
	 * debt-fund tools (the further cre-debt tool batches land with the
	 * following sub-clusters), and the enqueue helper must load.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base cre-debt init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-cre-debt-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/class-wp-mcp-ai-cre-debt-calculator.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/init.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-cre-debt-toolkit.css',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_cre_debt_tools', 10 );
		$this->assertCount( 45, apply_filters( 'wp_mcp_ai_pro_tools', array() ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_pro_register_cre_debt_ecosystem_tools' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_enqueue_cre_debt_toolkit_admin_styles' ) );
	}
}
