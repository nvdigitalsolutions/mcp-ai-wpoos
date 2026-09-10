<?php
/**
 * Characterization tests for the Wave F4 law-firm data layer — the five-CPT
 * registration class and the access/calculator helper classes ported from
 * the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/`
 *   are asserted in full, including the slim init's file-gate targets and
 *   the standalone-only tool wiring scaffolding.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Law-firm data layer tests.
 */
class Test_Law_Firm_Data_Layer extends WP_UnitTestCase {

	/**
	 * The three ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Law_Firm_CPT'        => 'class-wp-mcp-ai-law-firm-cpt.php',
			'WP_MCP_AI_Law_Firm_Access'     => 'tools/law-firm/class-wp-mcp-ai-law-firm-access.php',
			'WP_MCP_AI_Law_Firm_Calculator' => 'tools/law-firm/class-wp-mcp-ai-law-firm-calculator.php',
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
	 * The five post-type constants must be byte-identical.
	 */
	public function test_cpt_constants(): void {
		$this->assertSame( 'mcp_ai_lf_matter', WP_MCP_AI_Law_Firm_CPT::MATTER_POST_TYPE );
		$this->assertSame( 'mcp_ai_lf_client', WP_MCP_AI_Law_Firm_CPT::CLIENT_POST_TYPE );
		$this->assertSame( 'mcp_ai_lf_document', WP_MCP_AI_Law_Firm_CPT::DOCUMENT_POST_TYPE );
		$this->assertSame( 'mcp_ai_lf_time_entry', WP_MCP_AI_Law_Firm_CPT::TIME_ENTRY_POST_TYPE );
		$this->assertSame( 'mcp_ai_lf_trust_txn', WP_MCP_AI_Law_Firm_CPT::TRUST_TXN_POST_TYPE );
	}

	/**
	 * The CPT init must wire the registration hooks and register the five
	 * post types.
	 */
	public function test_cpt_init_and_registration(): void {
		WP_MCP_AI_Law_Firm_CPT::init();
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Law_Firm_CPT', 'register_post_types' ) ) );
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Law_Firm_CPT', 'register_taxonomies' ) ) );

		WP_MCP_AI_Law_Firm_CPT::register_post_types();
		$this->assertTrue( post_type_exists( 'mcp_ai_lf_matter' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_lf_client' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_lf_document' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_lf_time_entry' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_lf_trust_txn' ) );
	}

	/**
	 * The calculator contracts must be byte-identical.
	 */
	public function test_calculator_contracts(): void {
		$this->assertSame( '2026-06-18', WP_MCP_AI_Law_Firm_Calculator::add_business_days( '2026-06-15', 3 ) );
		$this->assertSame( 500.0, WP_MCP_AI_Law_Firm_Calculator::calculate_hourly_fee( 2.5, 200 ) );
		$this->assertSame( 4500.0, WP_MCP_AI_Law_Firm_Calculator::calculate_lodestar( 10, 300, 1.5 ) );
		$this->assertSame( 1000.0, WP_MCP_AI_Law_Firm_Calculator::calculate_present_value( 1100, 0.10, 1 ) );
		$this->assertTrue( WP_MCP_AI_Law_Firm_Calculator::is_business_day( '2026-06-15' ) );
	}

	/**
	 * The access helper must follow the byte-identical permission contract.
	 */
	public function test_access_contracts(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$matter_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_lf_matter',
				'post_author' => $author_id,
			)
		);

		$this->assertTrue( WP_MCP_AI_Law_Firm_Access::user_can_access_matter( $author_id, $matter_id ) );

		$other_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->assertFalse( WP_MCP_AI_Law_Firm_Access::user_can_access_matter( $other_id, $matter_id ) );
	}

	/**
	 * Standalone only: the slim init's file targets must exist, the tool
	 * filter must carry the twenty-eight matter-management + billing-trust +
	 * intake-management tools (the further law-firm tool batches land with the
	 * following sub-clusters), and the enqueue helper must load.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base law-firm init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-law-firm-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/class-wp-mcp-ai-law-firm-access.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/class-wp-mcp-ai-law-firm-calculator.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/init.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_law_firm_tools', 10 );
		$this->assertCount( 28, apply_filters( 'wp_mcp_ai_pro_tools', array() ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_pro_register_law_firm_ecosystem_tools' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_enqueue_law_firm_toolkit_admin_styles' ) );
	}
}
