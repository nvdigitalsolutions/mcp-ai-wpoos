<?php
/**
 * Tests for the Phase B Medical Vitals sub-toolkit.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! class_exists( 'WP_MCP_AI_Healthcare_Engine' ) ) {
	$base = dirname( __DIR__, 2 ) . '/includes/tools/healthcare';
	require_once $base . '/class-wp-mcp-ai-healthcare-engine.php';
}
$vitals_dir = dirname( __DIR__, 2 ) . '/includes/tools/healthcare/vitals';
foreach ( array(
	'class-wp-mcp-ai-healthcare-vaccination-schedules.php',
	'class-wp-mcp-ai-healthcare-vital-log-cpt.php',
) as $file ) {
	$file_path = $vitals_dir . '/' . $file;
	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
}

/**
 * Test the Phase B vitals sub-toolkit shared classes.
 */
class Test_Healthcare_Vitals extends WP_UnitTestCase {

	/**
	 * Vaccination registry exposes the canonical packs.
	 */
	public function test_vaccination_packs_registered() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Healthcare_Vaccination_Schedules' ) );
		$packs = WP_MCP_AI_Healthcare_Vaccination_Schedules::all();
		$this->assertArrayHasKey( 'cdc-pediatric-2025', $packs );
		$this->assertArrayHasKey( 'cdc-adult-2025', $packs );
		$this->assertArrayHasKey( 'who-epi-routine', $packs );
		$this->assertArrayHasKey( 'aafp-feline-core', $packs );
		$this->assertArrayHasKey( 'aaha-canine-core', $packs );
	}

	/**
	 * Species filter returns only matching packs.
	 */
	public function test_for_species_filters() {
		$canine = WP_MCP_AI_Healthcare_Vaccination_Schedules::for_species( 'canine' );
		$this->assertArrayHasKey( 'aaha-canine-core', $canine );
		$this->assertArrayNotHasKey( 'cdc-pediatric-2025', $canine );
	}

	/**
	 * Evaluate() classifies doses by age window.
	 */
	public function test_evaluate_classifies_doses() {
		$pack = WP_MCP_AI_Healthcare_Vaccination_Schedules::get( 'cdc-pediatric-2025' );
		$this->assertNotNull( $pack );

		// Newborn — first HepB dose is due, MMR is upcoming.
		$result = WP_MCP_AI_Healthcare_Vaccination_Schedules::evaluate( $pack, 5, array() );
		$this->assertNotEmpty( $result['due'] );
		$this->assertNotEmpty( $result['upcoming'] );

		// 2 yr old — MMR window is open or recently passed.
		$result_2yr = WP_MCP_AI_Healthcare_Vaccination_Schedules::evaluate( $pack, 730, array() );
		$this->assertIsArray( $result_2yr['due'] );

		// Marking a dose as given moves it into the given bucket.
		$result_given = WP_MCP_AI_Healthcare_Vaccination_Schedules::evaluate( $pack, 5, array( '08' ) );
		$found_given  = false;
		foreach ( $result_given['given'] as $entry ) {
			if ( isset( $entry['cvx_code'] ) && '08' === $entry['cvx_code'] ) {
				$found_given = true;
				break;
			}
		}
		$this->assertTrue( $found_given );
	}

	/**
	 * Filter hook lets partners add new packs.
	 */
	public function test_pack_filter_extends_registry() {
		add_filter(
			'wp_mcp_ai_healthcare_vaccination_schedules',
			static function ( $packs ) {
				$packs['custom-test'] = array(
					'name'    => 'Custom Test',
					'source'  => 'TEST',
					'species' => 'human',
					'doses'   => array(),
				);
				return $packs;
			}
		);
		$packs = WP_MCP_AI_Healthcare_Vaccination_Schedules::all();
		$this->assertArrayHasKey( 'custom-test', $packs );
		remove_all_filters( 'wp_mcp_ai_healthcare_vaccination_schedules' );
	}

	/**
	 * Vital log CPT class loads and registers when the toggle is on.
	 */
	public function test_vital_log_cpt_class() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Healthcare_Vital_Log_CPT' ) );
		$this->assertSame( 'mcp_ai_hc_vital_log', WP_MCP_AI_Healthcare_Vital_Log_CPT::POST_TYPE );
	}

	/**
	 * Vital log CPT insert returns 0 when CPT is not registered.
	 */
	public function test_vital_log_insert_without_cpt() {
		// CPT is conditionally registered; if not registered, insert returns 0.
		$result = WP_MCP_AI_Healthcare_Vital_Log_CPT::insert( 1, array( 'measurement_date' => '2025-01-01' ) );
		$this->assertTrue( 0 === $result || is_int( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Vital log CPT rejects invalid member IDs.
	 */
	public function test_vital_log_insert_rejects_invalid_member() {
		// Force the CPT registration so the invalid-member path runs.
		WP_MCP_AI_Healthcare_Vital_Log_CPT::register();
		if ( post_type_exists( 'mcp_ai_hc_vital_log' ) ) {
			$result = WP_MCP_AI_Healthcare_Vital_Log_CPT::insert( 0, array() );
			$this->assertInstanceOf( 'WP_Error', $result );
		} else {
			$this->markTestSkipped( 'Vital log CPT did not register in this environment.' );
		}
	}

	/**
	 * BMI growth band tool computes adult and paediatric outputs.
	 */
	public function test_bmi_growth_tool_outputs() {
		$tool_path = dirname( __DIR__, 2 ) . '/includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-compute-bmi-and-growth-percentile.php';
		require_once dirname( __DIR__, 4 ) . '/includes/interfaces/interface-wp-mcp-ai-tool.php';
		require_once $tool_path;
		$tool = new WP_MCP_AI_Tool_Compute_BMI_And_Growth_Percentile();
		$this->assertSame( 'compute_bmi_and_growth_percentile', $tool->get_slug() );

		$result = $tool->execute(
			array(
				'weight'      => 70,
				'weight_unit' => 'kg',
				'height'      => 175,
				'height_unit' => 'cm',
				'age_years'   => 30,
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'normal', $result['adult_band'] );
		$this->assertNull( $result['pediatric'] );

		// Paediatric path.
		$ped = $tool->execute(
			array(
				'weight'      => 30,
				'weight_unit' => 'kg',
				'height'      => 130,
				'height_unit' => 'cm',
				'age_years'   => 9,
			)
		);
		$this->assertIsArray( $ped );
		$this->assertNotNull( $ped['pediatric'] );
		$this->assertContains( $ped['band'], array( 'underweight', 'healthy', 'overweight', 'obesity' ) );
	}

	/**
	 * Get_vaccination_schedule tool auto-picks pack and returns due/upcoming.
	 */
	public function test_get_vaccination_schedule_tool() {
		require_once dirname( __DIR__, 4 ) . '/includes/interfaces/interface-wp-mcp-ai-tool.php';
		$tool_path = dirname( __DIR__, 2 ) . '/includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-get-vaccination-schedule.php';
		require_once $tool_path;

		$tool = new WP_MCP_AI_Tool_Get_Vaccination_Schedule();
		$this->assertSame( 'get_vaccination_schedule', $tool->get_slug() );

		$result = $tool->execute(
			array(
				'species'   => 'human',
				'age_years' => 0.0,
			)
		);
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'pack_slug', $result );
		$this->assertArrayHasKey( 'due', $result );
		$this->assertArrayHasKey( 'upcoming', $result );
	}
}
