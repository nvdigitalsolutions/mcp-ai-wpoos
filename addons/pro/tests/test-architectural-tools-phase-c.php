<?php
/**
 * Tests for Architectural Design Phase C tools.
 *
 * Phase C — Sustainability scoring (EDGE + LEED v4) and costing depth
 * (BoQ generation in POMI / SMM7 / CSI MasterFormat, value-engineering
 * options).
 *
 * Fixtures cover:
 *   - LK 3-storey residential aiming at EDGE Certified.
 *   - US LEED Silver office submission with all prerequisites met.
 *   - LK BoQ in POMI format, JM BoQ in SMM7 format, US BoQ in CSI 2020.
 *   - Country-aware VE option filtering and ranking.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Phase C tools.
 */
class Test_Architectural_Tools_Phase_C extends WP_UnitTestCase {

	/**
	 * Editor user ID used for tool execution context.
	 *
	 * @var int
	 */
	protected $editor_id = 0;

	/**
	 * Set up — load Phase C tool files and toolkit engine.
	 */
	public function setUp(): void {
		parent::setUp();

		$pro_path = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH
			: dirname( __DIR__ ) . '/';

		$base = $pro_path . 'includes/tools/architectural-design/';
		if ( ! file_exists( $base ) ) {
			$this->markTestSkipped( 'Architectural Design toolkit not present.' );
		}

		require_once $base . 'class-wp-mcp-ai-architectural-engine.php';
		require_once $base . 'class-wp-mcp-ai-architectural-codes.php';
		require_once $base . 'class-wp-mcp-ai-architectural-sustainability.php';

		$files = array(
			'sustainability/class-wp-mcp-ai-tool-score-edge-certification.php',
			'sustainability/class-wp-mcp-ai-tool-score-leed-v4-certification.php',
			'estimation-scheduling/class-wp-mcp-ai-tool-generate-bill-of-quantities.php',
			'estimation-scheduling/class-wp-mcp-ai-tool-propose-value-engineering-options.php',
			'analysis-compliance/class-wp-mcp-ai-tool-calculate-sustainability-metrics.php',
		);
		foreach ( $files as $f ) {
			$abs = $base . $f;
			if ( file_exists( $abs ) ) {
				require_once $abs;
			}
		}

		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
		update_option( 'wp_mcp_ai_settings', array( 'enable_architectural_design_toolkit' => 1 ) );
	}

	/**
	 * Helper to build a context array.
	 *
	 * @return array
	 */
	protected function ctx() {
		return array( 'user_id' => $this->editor_id );
	}

	/**
	 * EDGE certification scoring.
	 */
	public function test_edge_lk_residential_certified() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Score_Edge_Certification' ) ) {
			$this->markTestSkipped( 'EDGE tool unavailable.' );
		}
		$tool   = new WP_MCP_AI_Tool_Score_Edge_Certification();
		$result = $tool->execute(
			array(
				'country_code'             => 'LK',
				'building_use'             => 'residential',
				'energy_savings_pct'       => 25.0,
				'water_savings_pct'        => 22.0,
				'embodied_co2_savings_pct' => 21.0,
			),
			$this->ctx()
		);
		$this->assertNotWPError( $result );
		$this->assertSame( 'edge_certified', $result['awarded_tier'] );
		$this->assertSame( 'LK', $result['country_code'] );
	}

	/** Test edge jm fails when water below threshold.
	 */
	public function test_edge_jm_fails_when_water_below_threshold() {
		$tool   = new WP_MCP_AI_Tool_Score_Edge_Certification();
		$result = $tool->execute(
			array(
				'country_code'             => 'JM',
				'energy_savings_pct'       => 30.0,
				'water_savings_pct'        => 10.0,
				'embodied_co2_savings_pct' => 25.0,
			),
			$this->ctx()
		);
		$this->assertNotWPError( $result );
		$this->assertSame( '', $result['awarded_tier'] );
	}

	/** Test edge advanced when energy savings above 40.
	 */
	public function test_edge_advanced_when_energy_savings_above_40() {
		$tool   = new WP_MCP_AI_Tool_Score_Edge_Certification();
		$result = $tool->execute(
			array(
				'country_code'             => 'US',
				'building_use'             => 'commercial',
				'energy_savings_pct'       => 45.0,
				'water_savings_pct'        => 25.0,
				'embodied_co2_savings_pct' => 22.0,
			),
			$this->ctx()
		);
		$this->assertNotWPError( $result );
		$this->assertSame( 'edge_advanced', $result['awarded_tier'] );
	}

	/** Test edge uses absolute eui against baseline.
	 */
	public function test_edge_uses_absolute_eui_against_baseline() {
		$tool = new WP_MCP_AI_Tool_Score_Edge_Certification();
		// LK residential baseline EUI = 60. Proposed 30 -> 50 % savings.
		$result = $tool->execute(
			array(
				'country_code'           => 'LK',
				'eui_kwh_m2_year'        => 30.0,
				'water_l_person_day'     => 100.0,
				'embodied_co2_kgco2e_m2' => 350.0,
			),
			$this->ctx()
		);
		$this->assertNotWPError( $result );
		$this->assertEqualsWithDelta( 50.0, $result['energy_savings_pct'], 0.1 );
		$this->assertEqualsWithDelta( 50.0, $result['water_savings_pct'], 0.1 );
		$this->assertEqualsWithDelta( 30.0, $result['embodied_co2_savings_pct'], 0.1 );
		$this->assertSame( 'edge_advanced', $result['awarded_tier'] );
	}

	/** Test edge requires country code.
	 */
	public function test_edge_requires_country_code() {
		$tool   = new WP_MCP_AI_Tool_Score_Edge_Certification();
		$result = $tool->execute( array(), $this->ctx() );
		$this->assertWPError( $result );
	}

	/**
	 * LEED v4 BD+C scoring.
	 */
	public function test_leed_silver_with_50_points_and_all_prereqs() {
		$tool   = new WP_MCP_AI_Tool_Score_Leed_V4_Certification();
		$result = $tool->execute(
			array(
				'awarded_credits'   => array(
					'EA_c2' => 18,
					'EA_c5' => 5,
					'WE_c2' => 6,
					'EQ_c7' => 3,
					'LT_c5' => 5,
					'SS_c4' => 3,
					'MR_c1' => 5,
					'IN_c1' => 5,
				),
				'met_prerequisites' => array(
					'SS_p1' => true,
					'WE_p1' => true,
					'WE_p2' => true,
					'WE_p3' => true,
					'EA_p1' => true,
					'EA_p2' => true,
					'EA_p3' => true,
					'EA_p4' => true,
					'MR_p1' => true,
					'MR_p2' => true,
					'EQ_p1' => true,
					'EQ_p2' => true,
				),
			),
			$this->ctx()
		);
		$this->assertNotWPError( $result );
		$this->assertSame( 50, $result['total_points'] );
		$this->assertSame( 'silver', $result['awarded_level'] );
		$this->assertEmpty( $result['missing_prerequisites'] );
	}

	/** Test leed does not certify when prereq missing.
	 */
	public function test_leed_does_not_certify_when_prereq_missing() {
		$tool   = new WP_MCP_AI_Tool_Score_Leed_V4_Certification();
		$result = $tool->execute(
			array( 'awarded_credits' => array( 'EA_c2' => 18 ) ),
			$this->ctx()
		);
		$this->assertNotWPError( $result );
		$this->assertSame( '', $result['awarded_level'] );
		$this->assertNotEmpty( $result['missing_prerequisites'] );
	}

	/** Test leed clamps points above credit max.
	 */
	public function test_leed_clamps_points_above_credit_max() {
		$tool   = new WP_MCP_AI_Tool_Score_Leed_V4_Certification();
		$result = $tool->execute(
			array(
				'awarded_credits'   => array( 'EA_c2' => 99 ),
				'met_prerequisites' => array(
					'SS_p1' => true,
					'WE_p1' => true,
					'WE_p2' => true,
					'WE_p3' => true,
					'EA_p1' => true,
					'EA_p2' => true,
					'EA_p3' => true,
					'EA_p4' => true,
					'MR_p1' => true,
					'MR_p2' => true,
					'EQ_p1' => true,
					'EQ_p2' => true,
				),
			),
			$this->ctx()
		);
		$this->assertSame( 18, $result['total_points'] );
		$this->assertCount( 1, $result['over_max_credits'] );
	}

	/** Test leed detects invalid credit id.
	 */
	public function test_leed_detects_invalid_credit_id() {
		$tool   = new WP_MCP_AI_Tool_Score_Leed_V4_Certification();
		$result = $tool->execute(
			array( 'awarded_credits' => array( 'XX_c1' => 1 ) ),
			$this->ctx()
		);
		$this->assertContains( 'XX_c1', $result['invalid_credit_ids'] );
	}

	/** Test leed requires awarded credits.
	 */
	public function test_leed_requires_awarded_credits() {
		$tool   = new WP_MCP_AI_Tool_Score_Leed_V4_Certification();
		$result = $tool->execute( array(), $this->ctx() );
		$this->assertWPError( $result );
	}

	/**
	 * Bill of Quantities.
	 */
	public function test_boq_lk_picks_pomi_with_lkr() {
		$tool   = new WP_MCP_AI_Tool_Generate_Bill_Of_Quantities();
		$result = $tool->execute(
			array(
				'country_code' => 'LK',
				'project_name' => 'Colombo House',
				'line_items'   => array(
					array(
						'section'     => 'D',
						'description' => 'Reinforced concrete grade 25',
						'quantity'    => 12,
						'unit'        => 'm3',
						'rate'        => 65000,
					),
					array(
						'section'     => 'E',
						'description' => '225mm cement-block walls',
						'quantity'    => 220,
						'unit'        => 'm2',
						'rate'        => 6500,
					),
				),
			),
			$this->ctx()
		);
		$this->assertNotWPError( $result );
		$this->assertSame( 'pomi', $result['format'] );
		$this->assertSame( 'LKR', $result['currency'] );
		// Subtotal = 12*65000 + 220*6500 = 780000 + 1430000 = 2210000.
		$this->assertEqualsWithDelta( 2210000.0, $result['subtotal'], 0.5 );
		// Default contingency 10% + OP 15% => grand total = 2210000 * 1.25 = 2762500.
		$this->assertEqualsWithDelta( 2762500.0, $result['grand_total'], 0.5 );
	}

	/** Test boq jm picks smm7.
	 */
	public function test_boq_jm_picks_smm7() {
		$tool   = new WP_MCP_AI_Tool_Generate_Bill_Of_Quantities();
		$result = $tool->execute(
			array(
				'country_code' => 'JM',
				'line_items'   => array(
					array(
						'section'     => 'F',
						'description' => 'Concrete block masonry',
						'quantity'    => 100,
						'unit'        => 'm2',
						'rate'        => 3000,
					),
				),
			),
			$this->ctx()
		);
		$this->assertSame( 'smm7', $result['format'] );
		$this->assertSame( 'JMD', $result['currency'] );
	}

	/** Test boq us picks csi 2020.
	 */
	public function test_boq_us_picks_csi_2020() {
		$tool   = new WP_MCP_AI_Tool_Generate_Bill_Of_Quantities();
		$result = $tool->execute(
			array(
				'country_code' => 'US',
				'line_items'   => array(
					array(
						'section'     => '03',
						'description' => 'Cast-in-place concrete',
						'quantity'    => 50,
						'unit'        => 'cy',
						'rate'        => 220,
					),
				),
			),
			$this->ctx()
		);
		$this->assertSame( 'csi_masterformat_2020', $result['format'] );
		$this->assertSame( 'USD', $result['currency'] );
	}

	/** Test boq unknown section is reported.
	 */
	public function test_boq_unknown_section_is_reported() {
		$tool   = new WP_MCP_AI_Tool_Generate_Bill_Of_Quantities();
		$result = $tool->execute(
			array(
				'country_code' => 'LK',
				'line_items'   => array(
					array(
						'section'     => 'ZZ',
						'description' => 'Bogus',
						'quantity'    => 1,
						'unit'        => 'no',
						'rate'        => 100,
					),
				),
			),
			$this->ctx()
		);
		$this->assertNotEmpty( $result['unknown_sections'] );
	}

	/** Test boq explicit format overrides country.
	 */
	public function test_boq_explicit_format_overrides_country() {
		$tool   = new WP_MCP_AI_Tool_Generate_Bill_Of_Quantities();
		$result = $tool->execute(
			array(
				'country_code' => 'LK',
				'format'       => 'csi_masterformat_2020',
			),
			$this->ctx()
		);
		$this->assertSame( 'csi_masterformat_2020', $result['format'] );
	}

	/**
	 * Value-engineering options.
	 */
	public function test_ve_options_filtered_by_country() {
		$tool   = new WP_MCP_AI_Tool_Propose_Value_Engineering_Options();
		$result = $tool->execute(
			array(
				'country_code'  => 'US',
				'baseline_cost' => 1000000,
			),
			$this->ctx()
		);
		$this->assertNotWPError( $result );
		// US should never see the local-red-brick option.
		foreach ( $result['options'] as $opt ) {
			$this->assertNotSame( 've_use_local_red_brick', $opt['id'] );
		}
	}

	/** Test ve options ranked by savings amount.
	 */
	public function test_ve_options_ranked_by_savings_amount() {
		$tool   = new WP_MCP_AI_Tool_Propose_Value_Engineering_Options();
		$result = $tool->execute(
			array(
				'country_code'  => 'LK',
				'baseline_cost' => 5000000,
				'top_n'         => 5,
			),
			$this->ctx()
		);
		$this->assertNotWPError( $result );
		$this->assertLessThanOrEqual( 5, count( $result['options'] ) );
		// Verify descending order of mid savings.
		$prev = PHP_INT_MAX;
		foreach ( $result['options'] as $opt ) {
			$mid = $opt['savings_amount_mid'];
			$this->assertLessThanOrEqual( $prev, $mid );
			$prev = $mid;
		}
	}

	/** Test ve category filter applies.
	 */
	public function test_ve_category_filter_applies() {
		$tool   = new WP_MCP_AI_Tool_Propose_Value_Engineering_Options();
		$result = $tool->execute(
			array(
				'country_code' => 'LK',
				'categories'   => array( 'mep' ),
			),
			$this->ctx()
		);
		foreach ( $result['options'] as $opt ) {
			$this->assertSame( 'mep', $opt['category'] );
		}
	}

	/** Test ve aggregate capped at 60 pct.
	 */
	public function test_ve_aggregate_capped_at_60_pct() {
		$tool   = new WP_MCP_AI_Tool_Propose_Value_Engineering_Options();
		$result = $tool->execute(
			array( 'country_code' => 'LK' ),
			$this->ctx()
		);
		$this->assertLessThanOrEqual( 60.0, $result['aggregate_savings_pct']['max'] );
	}

	/**
	 * Refactored calculate_sustainability_metrics now uses EDGE engine.
	 */
	public function test_calculate_sustainability_metrics_returns_edge_block_for_lk() {
		$tool   = new WP_MCP_AI_Tool_Calculate_Sustainability_Metrics();
		$result = $tool->execute(
			array(
				'floor_plan'        => array( 'rooms' => array() ),
				'total_area'        => 200.0,
				'window_wall_ratio' => 0.30,
				'hvac_system'       => 'high_efficiency',
				'renewable_energy'  => array( 'solar_pv_kw' => 5.0 ),
				'country_code'      => 'LK',
				'building_use'      => 'residential',
			),
			$this->ctx()
		);
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'metrics', $result );
		$this->assertArrayHasKey( 'edge', $result['metrics'] );
		$this->assertSame( 'LK', $result['metrics']['country_code'] );
		$this->assertNotEmpty( $result['metrics']['recommendations'] );
	}

	/** Test calculate sustainability metrics us recommendations differ.
	 */
	public function test_calculate_sustainability_metrics_us_recommendations_differ() {
		$tool   = new WP_MCP_AI_Tool_Calculate_Sustainability_Metrics();
		$result = $tool->execute(
			array(
				'floor_plan'        => array( 'rooms' => array() ),
				'total_area'        => 500.0,
				'window_wall_ratio' => 0.45,
				'country_code'      => 'US',
				'building_use'      => 'commercial',
			),
			$this->ctx()
		);
		$this->assertNotWPError( $result );
		$this->assertSame( 'US', $result['metrics']['country_code'] );
		// US recommendations should mention insulation R-30.
		$joined = implode( ' ', $result['metrics']['recommendations'] );
		$this->assertStringContainsString( 'R-30', $joined );
	}
}
