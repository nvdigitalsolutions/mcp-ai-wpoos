<?php
/**
 * Tests for Architectural Design Phase B tools (regional compliance + analysis depth).
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Phase B tools.
 */
class Test_Architectural_Tools_Phase_B extends WP_UnitTestCase {

	/**
	 * Editor user ID used for tool execution context.
	 *
	 * @var int
	 */
	protected $editor_id = 0;

	/**
	 * Set up — load Phase B tool files.
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

		$files = array(
			'regional-compliance/class-wp-mcp-ai-tool-calculate-wind-loads.php',
			'regional-compliance/class-wp-mcp-ai-tool-calculate-seismic-loads.php',
			'regional-compliance/class-wp-mcp-ai-tool-validate-setbacks-and-far.php',
			'regional-compliance/class-wp-mcp-ai-tool-check-uda-planning-compliance.php',
			'regional-compliance/class-wp-mcp-ai-tool-check-jnbc-hurricane-compliance.php',
			'regional-compliance/class-wp-mcp-ai-tool-check-us-ibc-irc-compliance.php',
			'regional-compliance/class-wp-mcp-ai-tool-generate-compliance-dossier.php',
			'analysis-compliance/class-wp-mcp-ai-tool-analyze-natural-ventilation.php',
			'analysis-compliance/class-wp-mcp-ai-tool-analyze-daylight-and-solar-gain.php',
			'sustainability/class-wp-mcp-ai-tool-simulate-thermal-comfort.php',
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
	 * Tear down — restore settings.
	 */
	public function tearDown(): void {
		update_option( 'wp_mcp_ai_settings', array() );
		parent::tearDown();
	}

	/**
	 * Provider: all 10 Phase B tool classes.
	 *
	 * @return array
	 */
	public static function tool_classes_provider() {
		return array(
			array( 'WP_MCP_AI_Tool_Calculate_Wind_Loads' ),
			array( 'WP_MCP_AI_Tool_Calculate_Seismic_Loads' ),
			array( 'WP_MCP_AI_Tool_Validate_Setbacks_And_Far' ),
			array( 'WP_MCP_AI_Tool_Check_UDA_Planning_Compliance' ),
			array( 'WP_MCP_AI_Tool_Check_JNBC_Hurricane_Compliance' ),
			array( 'WP_MCP_AI_Tool_Check_US_IBC_IRC_Compliance' ),
			array( 'WP_MCP_AI_Tool_Generate_Compliance_Dossier' ),
			array( 'WP_MCP_AI_Tool_Analyze_Natural_Ventilation' ),
			array( 'WP_MCP_AI_Tool_Analyze_Daylight_And_Solar_Gain' ),
			array( 'WP_MCP_AI_Tool_Simulate_Thermal_Comfort' ),
		);
	}

	/**
	 * Each Phase B tool class is loaded and exposes the standard interface.
	 *
	 * @dataProvider tool_classes_provider
	 *
	 * @param string $class Tool class name.
	 */
	public function test_tool_class_exists( $class ) {
		$this->assertTrue( class_exists( $class ), "Tool class $class should exist." );
		$this->assertTrue( method_exists( $class, 'is_available' ), "$class must implement is_available()." );
		$this->assertTrue( method_exists( $class, 'get_unavailable_reason' ), "$class must implement get_unavailable_reason()." );
		$this->assertTrue( method_exists( $class, 'get_slug' ), "$class must implement get_slug()." );
		$this->assertTrue( method_exists( $class, 'get_parameters_schema' ), "$class must implement get_parameters_schema()." );
		$this->assertTrue( method_exists( $class, 'execute' ), "$class must implement execute()." );
	}

	/**
	 * Each tool reports unavailable when toolkit setting off.
	 *
	 * @dataProvider tool_classes_provider
	 *
	 * @param string $class Tool class name.
	 */
	public function test_is_available_false_when_disabled( $class ) {
		update_option( 'wp_mcp_ai_settings', array() );
		$this->assertFalse( call_user_func( array( $class, 'is_available' ) ) );
		update_option( 'wp_mcp_ai_settings', array( 'enable_architectural_design_toolkit' => 1 ) );
	}

	// =================================================================
	// Wind loads.
	// =================================================================

	/** Test wind loads jamaica coastal.
	 */
	public function test_wind_loads_jamaica_coastal() {
		$tool = new WP_MCP_AI_Tool_Calculate_Wind_Loads();
		$res  = $tool->execute(
			array(
				'country_code'      => 'JM',
				'wind_zone'         => 'coastal',
				'exposure'          => 'C',
				'building_height_m' => 6.0,
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertTrue( $res['success'] );
		$this->assertGreaterThan( 50, $res['basic_wind_ms'] ); // Hurricane regime.
		$this->assertGreaterThan( 1500, $res['velocity_pressure_pa'] );
		$this->assertSame( 'JM', $res['country_code'] );
	}

	/** Test wind loads us inland.
	 */
	public function test_wind_loads_us_inland() {
		$tool = new WP_MCP_AI_Tool_Calculate_Wind_Loads();
		$res  = $tool->execute(
			array(
				'country_code' => 'US',
				'wind_zone'    => 'standard',
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertGreaterThan( 0, $res['design_pressure_pa'] );
	}

	/** Test wind loads unknown zone returns error.
	 */
	public function test_wind_loads_unknown_zone_returns_error() {
		$tool = new WP_MCP_AI_Tool_Calculate_Wind_Loads();
		$res  = $tool->execute(
			array(
				'country_code' => 'LK',
				'wind_zone'    => 'bogus',
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertInstanceOf( 'WP_Error', $res );
	}

	// =================================================================
	// Seismic loads.
	// =================================================================

	/** Test seismic loads lk zone3.
	 */
	public function test_seismic_loads_lk_zone3() {
		$tool = new WP_MCP_AI_Tool_Calculate_Seismic_Loads();
		$res  = $tool->execute(
			array(
				'country_code'       => 'LK',
				'seismic_zone'       => 'zone3',
				'building_weight_kn' => 3000.0,
				'num_storeys'        => 3,
				'r_factor'           => 5.0,
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertTrue( $res['success'] );
		$this->assertGreaterThan( 0, $res['cs'] );
		$this->assertGreaterThan( 0, $res['base_shear_kn'] );
		$this->assertCount( 3, $res['storey_forces'] );

		// Sum of storey forces ≈ base shear.
		$sum = 0;
		foreach ( $res['storey_forces'] as $f ) {
			$sum += $f['force_kn'];
		}
		$this->assertEqualsWithDelta( $res['base_shear_kn'], $sum, 0.5 );

		// Top storey gets more force than bottom storey (k=1 distribution).
		$first = $res['storey_forces'][0]['force_kn'];
		$last  = $res['storey_forces'][2]['force_kn'];
		$this->assertGreaterThan( $first, $last );
	}

	/** Test seismic loads validates inputs.
	 */
	public function test_seismic_loads_validates_inputs() {
		$tool = new WP_MCP_AI_Tool_Calculate_Seismic_Loads();
		$res  = $tool->execute(
			array(
				'country_code'       => 'US',
				'building_weight_kn' => 0,
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertInstanceOf( 'WP_Error', $res );
	}

	/** Test seismic sds override bypasses registry.
	 */
	public function test_seismic_sds_override_bypasses_registry() {
		$tool = new WP_MCP_AI_Tool_Calculate_Seismic_Loads();
		$res  = $tool->execute(
			array(
				'country_code'       => 'US',
				'building_weight_kn' => 1000.0,
				'sds_override'       => 1.0,
				'num_storeys'        => 1,
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertEquals( 1.0, $res['sds'] );
	}

	// =================================================================
	// Validate setbacks & FAR.
	// =================================================================

	/** Test validate setbacks lk residential pass.
	 */
	public function test_validate_setbacks_lk_residential_pass() {
		$tool = new WP_MCP_AI_Tool_Validate_Setbacks_And_Far();
		$res  = $tool->execute(
			array(
				'country_code'  => 'LK',
				'building_type' => 'residential',
				'lot'           => array( 'lot_perches' => 10.0 ), // ≈ 253 m².
				'building'      => array(
					'built_up_area_m2'  => 250.0,
					'footprint_area_m2' => 130.0,
					'setbacks_m'        => array(
						'front' => 3.0,
						'rear'  => 2.0,
						'left'  => 1.5,
						'right' => 1.5,
					),
				),
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertSame( 'pass', $res['overall_status'] );
	}

	/** Test validate setbacks lk far violation.
	 */
	public function test_validate_setbacks_lk_far_violation() {
		$tool = new WP_MCP_AI_Tool_Validate_Setbacks_And_Far();
		$res  = $tool->execute(
			array(
				'country_code'  => 'LK',
				'building_type' => 'residential',
				'lot'           => array( 'lot_perches' => 10.0 ),
				'building'      => array(
					'built_up_area_m2'  => 900.0, // Far above 2.0 FAR.
					'footprint_area_m2' => 130.0,
					'setbacks_m'        => array(
						'front' => 3.0,
						'rear'  => 2.0,
						'left'  => 1.5,
						'right' => 1.5,
					),
				),
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertSame( 'fail', $res['overall_status'] );
	}

	// =================================================================
	// UDA planning compliance.
	// =================================================================

	/** Test uda compliance full pass.
	 */
	public function test_uda_compliance_full_pass() {
		$tool = new WP_MCP_AI_Tool_Check_UDA_Planning_Compliance();
		$res  = $tool->execute(
			array(
				'gazette_vintage' => '2025',
				'lot'             => array( 'lot_perches' => 10.0 ),
				'building'        => array(
					'built_up_area_m2'   => 250.0,
					'footprint_area_m2'  => 130.0,
					'num_storeys'        => 2,
					'building_type'      => 'residential',
					'num_dwelling_units' => 1,
					'setbacks_m'         => array(
						'front' => 3.0,
						'rear'  => 2.0,
						'left'  => 1.5,
						'right' => 1.5,
					),
				),
				'site'            => array( 'nbro_landslide_zone' => 'none' ),
				'professional'    => array( 'slia_registered_architect' => true ),
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertNotEmpty( $res['checks'] );
		$this->assertSame( 'LK', $res['country_code'] );
		$this->assertSame( '2025', $res['gazette_vintage'] );
		$this->assertContains( $res['overall_status'], array( 'pass', 'conditional' ) );
	}

	/** Test uda compliance eia triggered for large project.
	 */
	public function test_uda_compliance_eia_triggered_for_large_project() {
		$tool = new WP_MCP_AI_Tool_Check_UDA_Planning_Compliance();
		$res  = $tool->execute(
			array(
				'lot'          => array( 'lot_perches' => 200.0 ),
				'building'     => array(
					'built_up_area_m2'   => 5000.0,
					'footprint_area_m2'  => 2500.0,
					'num_dwelling_units' => 80, // > 40 threshold.
					'setbacks_m'         => array(
						'front' => 3.0,
						'rear'  => 3.0,
						'left'  => 2.0,
						'right' => 2.0,
					),
				),
				'professional' => array( 'slia_registered_architect' => true ),
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$found_eia = false;
		foreach ( $res['checks'] as $c ) {
			if ( false !== strpos( $c['requirement'], 'EIA' ) && 'fail' === $c['status'] ) {
				$found_eia = true;
				break;
			}
		}
		$this->assertTrue( $found_eia, 'EIA threshold should trigger for > 40 housing units.' );
	}

	/** Test uda nbro landslide zone flag.
	 */
	public function test_uda_nbro_landslide_zone_flag() {
		$tool       = new WP_MCP_AI_Tool_Check_UDA_Planning_Compliance();
		$res        = $tool->execute(
			array(
				'lot'          => array( 'lot_perches' => 15.0 ),
				'building'     => array(
					'built_up_area_m2'  => 200.0,
					'footprint_area_m2' => 100.0,
					'setbacks_m'        => array(
						'front' => 3.0,
						'rear'  => 2.0,
						'left'  => 1.5,
						'right' => 1.5,
					),
				),
				'site'         => array(
					'nbro_landslide_zone' => 'high',
					'slope_deg'           => 25,
				),
				'professional' => array( 'slia_registered_architect' => true ),
			),
			array( 'user_id' => $this->editor_id )
		);
		$found_nbro = false;
		foreach ( $res['checks'] as $c ) {
			if ( false !== strpos( $c['requirement'], 'NBRO' ) ) {
				$found_nbro = true;
				$this->assertSame( 'fail', $c['status'] );
			}
		}
		$this->assertTrue( $found_nbro, 'NBRO landslide check expected when site is in hazard zone.' );
	}

	// =================================================================
	// JNBC hurricane.
	// =================================================================

	/** Test jnbc hurricane full resilience pass.
	 */
	public function test_jnbc_hurricane_full_resilience_pass() {
		$tool = new WP_MCP_AI_Tool_Check_JNBC_Hurricane_Compliance();
		$res  = $tool->execute(
			array(
				'wind_zone' => 'coastal',
				'parish'    => 'St. Thomas',
				'building'  => array(
					'building_height_m'    => 7.0,
					'occupancy_category'   => 'standard',
					'opening_protection'   => true,
					'continuous_load_path' => true,
					'roof_attachment'      => 'h2.5_clip',
					'roof_pitch_deg'       => 30,
				),
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertSame( 'JM', $res['country_code'] );
		$this->assertSame( 'pass', $res['overall_status'] );
	}

	/** Test jnbc hurricane failure when unprotected.
	 */
	public function test_jnbc_hurricane_failure_when_unprotected() {
		$tool = new WP_MCP_AI_Tool_Check_JNBC_Hurricane_Compliance();
		$res  = $tool->execute(
			array(
				'wind_zone' => 'standard',
				'building'  => array(
					'opening_protection'   => false,
					'continuous_load_path' => false,
					'roof_attachment'      => 'toenail',
					'roof_pitch_deg'       => 5,
				),
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertSame( 'fail', $res['overall_status'] );
	}

	// =================================================================
	// US IBC / IRC.
	// =================================================================

	/** Test us ibc irc auto picks irc for single family.
	 */
	public function test_us_ibc_irc_auto_picks_irc_for_single_family() {
		$tool = new WP_MCP_AI_Tool_Check_US_IBC_IRC_Compliance();
		$res  = $tool->execute(
			array(
				'code_path'    => 'auto',
				'jurisdiction' => 'FL',
				'climate_zone' => '2A',
				'building'     => array(
					'occupancy_classification' => 'R-3',
					'num_storeys'              => 2,
					'num_dwelling_units'       => 1,
					'smoke_alarms'             => true,
					'co_alarms'                => true,
				),
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertSame( 'irc', $res['code_path'] );
		$this->assertContains( 'us_irc_2024', $res['code_packs'] );
	}

	/** Test us ibc irc auto picks ibc for commercial.
	 */
	public function test_us_ibc_irc_auto_picks_ibc_for_commercial() {
		$tool = new WP_MCP_AI_Tool_Check_US_IBC_IRC_Compliance();
		$res  = $tool->execute(
			array(
				'code_path' => 'auto',
				'building'  => array(
					'occupancy_classification' => 'B',
					'num_storeys'              => 4,
					'corridor_width_m'         => 1.2,
					'stair_width_m'            => 1.2,
					'travel_distance_m'        => 60.0,
					'min_door_clear_width_mm'  => 815.0,
					'building_height_m'        => 14.0,
				),
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertSame( 'ibc', $res['code_path'] );
		$this->assertContains( 'us_ibc_2024', $res['code_packs'] );
		$this->assertContains( 'us_ada_2010', $res['code_packs'] );
	}

	// =================================================================
	// Compliance dossier.
	// =================================================================

	/** Test compliance dossier us aggregates status.
	 */
	public function test_compliance_dossier_us_aggregates_status() {
		$tool = new WP_MCP_AI_Tool_Generate_Compliance_Dossier();
		$res  = $tool->execute(
			array(
				'country_code' => 'US',
				'project'      => array(
					'title'   => 'Test residence',
					'address' => '123 Main St',
					'date'    => '2026-04-29',
				),
				'sections'     => array(
					'building_code' => array(
						'success'        => true,
						'overall_status' => 'pass',
					),
					'wind_loads'    => array( 'success' => true ),
					'seismic_loads' => array( 'overall_status' => 'conditional' ),
				),
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertSame( 'US', $res['country_code'] );
		$this->assertSame( 'conditional', $res['overall_status'] );
		$this->assertNotEmpty( $res['attachments'] );
		$this->assertNotEmpty( $res['submissions'] );
		$this->assertSame( 'Test residence', $res['project']['title'] );
		$this->assertGreaterThanOrEqual( 1, $res['totals']['sections_warning'] );
	}

	/** Test compliance dossier rejects invalid country.
	 */
	public function test_compliance_dossier_rejects_invalid_country() {
		$tool = new WP_MCP_AI_Tool_Generate_Compliance_Dossier();
		$res  = $tool->execute(
			array( 'country_code' => 'XX' ),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertInstanceOf( 'WP_Error', $res );
	}

	// =================================================================
	// Natural ventilation.
	// =================================================================

	/** Test natural ventilation cross flow meets target.
	 */
	public function test_natural_ventilation_cross_flow_meets_target() {
		$tool = new WP_MCP_AI_Tool_Analyze_Natural_Ventilation();
		$res  = $tool->execute(
			array(
				'country_code' => 'LK',
				'space'        => array(
					'occupants'  => 4,
					'area_sqm'   => 16.0,
					'height_m'   => 3.0,
					'space_type' => 'residential',
				),
				'openings'     => array(
					'inlet_area_sqm'  => 1.5,
					'outlet_area_sqm' => 1.5,
					'stack_height_m'  => 1.0,
					'strategy'        => 'cross_flow',
				),
				'wind'         => array(
					'mean_speed_ms'             => 2.5,
					'pressure_coefficient_diff' => 0.6,
				),
				'temperatures' => array(
					'indoor_c'  => 28,
					'outdoor_c' => 30,
				),
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertGreaterThan( 0, $res['achieved_ach'] );
		$this->assertGreaterThan( 0, $res['effective_opening_area_sqm'] );
		$this->assertSame( 'LK', $res['country_code'] );
	}

	/** Test natural ventilation invalid area.
	 */
	public function test_natural_ventilation_invalid_area() {
		$tool = new WP_MCP_AI_Tool_Analyze_Natural_Ventilation();
		$res  = $tool->execute(
			array( 'space' => array( 'area_sqm' => 0 ) ),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertInstanceOf( 'WP_Error', $res );
	}

	// =================================================================
	// Daylight & solar gain.
	// =================================================================

	/** Test daylight tropical west facade recommends overhang.
	 */
	public function test_daylight_tropical_west_facade_recommends_overhang() {
		$tool = new WP_MCP_AI_Tool_Analyze_Daylight_And_Solar_Gain();
		$res  = $tool->execute(
			array(
				'country_code'     => 'LK',
				'orientation'      => 'W',
				'overhang_depth_m' => 0.0,
				'window_height_m'  => 1.5,
				'space'            => array(
					'floor_area_sqm'        => 16.0,
					'window_area_sqm'       => 4.0,
					'visible_transmittance' => 0.6,
					'shgc'                  => 0.5,
				),
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertGreaterThan( 0, $res['estimated_solar_gain_w'] );
		$this->assertNotEmpty( $res['recommendations'] );
	}

	/** Test daylight validates inputs.
	 */
	public function test_daylight_validates_inputs() {
		$tool = new WP_MCP_AI_Tool_Analyze_Daylight_And_Solar_Gain();
		$res  = $tool->execute(
			array(
				'orientation' => 'S',
				'space'       => array(
					'floor_area_sqm'  => 0,
					'window_area_sqm' => 0,
				),
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertInstanceOf( 'WP_Error', $res );
	}

	// =================================================================
	// Thermal comfort.
	// =================================================================

	/** Test thermal comfort adaptive for tropical.
	 */
	public function test_thermal_comfort_adaptive_for_tropical() {
		$tool = new WP_MCP_AI_Tool_Simulate_Thermal_Comfort();
		$res  = $tool->execute(
			array(
				'country_code'           => 'LK',
				'air_temperature_c'      => 28.0,
				'relative_humidity_pct'  => 70.0,
				'air_speed_ms'           => 0.6,
				'outdoor_running_mean_c' => 28.0,
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertSame( 'adaptive', $res['model'] );
		$this->assertArrayHasKey( 'adaptive', $res );
		$this->assertArrayHasKey( 'comfort_temperature_c', $res['adaptive'] );
	}

	/** Test thermal comfort pmv for us office.
	 */
	public function test_thermal_comfort_pmv_for_us_office() {
		$tool = new WP_MCP_AI_Tool_Simulate_Thermal_Comfort();
		$res  = $tool->execute(
			array(
				'country_code'               => 'US',
				'air_temperature_c'          => 23.0,
				'mean_radiant_temperature_c' => 23.0,
				'relative_humidity_pct'      => 50.0,
				'air_speed_ms'               => 0.1,
				'metabolic_rate_met'         => 1.1,
				'clothing_insulation_clo'    => 0.5,
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotInstanceOf( 'WP_Error', $res );
		$this->assertSame( 'pmv', $res['model'] );
		$this->assertArrayHasKey( 'pmv', $res );
		$this->assertGreaterThanOrEqual( -3.5, $res['pmv']['pmv'] );
		$this->assertLessThanOrEqual( 3.5, $res['pmv']['pmv'] );
		$this->assertGreaterThan( 0, $res['pmv']['ppd_pct'] );
	}

	/** Test thermal comfort adaptive requires running mean.
	 */
	public function test_thermal_comfort_adaptive_requires_running_mean() {
		$tool = new WP_MCP_AI_Tool_Simulate_Thermal_Comfort();
		$res  = $tool->execute(
			array(
				'model'                 => 'adaptive',
				'air_temperature_c'     => 28.0,
				'relative_humidity_pct' => 70.0,
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertInstanceOf( 'WP_Error', $res );
	}

	// =================================================================
	// Capability enforcement.
	// =================================================================

	/** Test tools reject unauthenticated context.
	 */
	public function test_tools_reject_unauthenticated_context() {
		$tool = new WP_MCP_AI_Tool_Calculate_Wind_Loads();
		$res  = $tool->execute(
			array(
				'country_code' => 'JM',
				'wind_zone'    => 'coastal',
			),
			array() // no user_id.
		);
		$this->assertInstanceOf( 'WP_Error', $res );
		$this->assertSame( 'wp_mcp_ai_forbidden', $res->get_error_code() );
	}
}
