<?php
/**
 * Tests for the Architectural Design shared engine.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! class_exists( 'WP_MCP_AI_Architectural_Engine' ) ) {
	$engine_path = dirname( __DIR__ ) . '/includes/tools/architectural-design/class-wp-mcp-ai-architectural-engine.php';
	if ( file_exists( $engine_path ) ) {
		require_once $engine_path;
	}
}

/**
 * Test case for WP_MCP_AI_Architectural_Engine.
 */
class Test_Architectural_Engine extends WP_UnitTestCase {

	/**
	 * Class is loadable.
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Architectural_Engine' ) );
	}

	/**
	 * Square-foot to square-metre round-trip.
	 */
	public function test_area_roundtrip() {
		$sqft = 1000.0;
		$sqm  = WP_MCP_AI_Architectural_Engine::sqft_to_sqm( $sqft );
		$back = WP_MCP_AI_Architectural_Engine::sqm_to_sqft( $sqm );
		$this->assertEqualsWithDelta( $sqft, $back, 0.001 );
		// 1000 sq ft ≈ 92.9 m^2.
		$this->assertEqualsWithDelta( 92.903, $sqm, 0.01 );
	}

	/**
	 * Sri Lankan perches to m^2.
	 */
	public function test_perches_to_sqm() {
		$this->assertEqualsWithDelta( 252.929, WP_MCP_AI_Architectural_Engine::perches_to_sqm( 10 ), 0.01 );
		$this->assertEqualsWithDelta( 10.0, WP_MCP_AI_Architectural_Engine::sqm_to_perches( 252.929 ), 0.001 );
	}

	/**
	 * Feet / metres round-trip.
	 */
	public function test_length_roundtrip() {
		$m = WP_MCP_AI_Architectural_Engine::ft_to_m( 10 );
		$this->assertEqualsWithDelta( 3.048, $m, 0.001 );
		$this->assertEqualsWithDelta( 10.0, WP_MCP_AI_Architectural_Engine::m_to_ft( $m ), 0.001 );
	}

	/**
	 * FAR calculation handles zero lot area.
	 */
	public function test_calculate_far() {
		$this->assertEqualsWithDelta( 2.0, WP_MCP_AI_Architectural_Engine::calculate_far( 200, 100 ), 0.0001 );
		$this->assertEquals( 0.0, WP_MCP_AI_Architectural_Engine::calculate_far( 200, 0 ) );
	}

	/**
	 * Site coverage percentage.
	 */
	public function test_site_coverage() {
		$this->assertEqualsWithDelta( 50.0, WP_MCP_AI_Architectural_Engine::calculate_site_coverage( 50, 100 ), 0.0001 );
	}

	/**
	 * Setback validator flags violations and reports compliant.
	 */
	public function test_validate_setbacks() {
		$proposed = array(
			'front' => 2.5,
			'rear'  => 1.0,
			'left'  => 1.0,
			'right' => 0.5,
		);
		$required = array(
			'front' => 2.0,
			'rear'  => 1.0,
			'left'  => 1.0,
			'right' => 1.0,
		);
		$result   = WP_MCP_AI_Architectural_Engine::validate_setbacks( $proposed, $required );
		$this->assertFalse( $result['compliant'] );
		$this->assertCount( 1, $result['violations'] );
		$this->assertSame( 'right', $result['violations'][0]['side'] );

		$ok = WP_MCP_AI_Architectural_Engine::validate_setbacks( $required, $required );
		$this->assertTrue( $ok['compliant'] );
		$this->assertEmpty( $ok['violations'] );
	}

	/**
	 * Occupancy load applies IBC-style factors and rounds up.
	 */
	public function test_occupancy_load() {
		// 100 m^2 business @ 9.3 m^2/person = 11 people (ceil).
		$this->assertSame( 11, WP_MCP_AI_Architectural_Engine::calculate_occupancy_load( 100, 'business' ) );
		// Unknown type defaults to business factor.
		$this->assertSame( 11, WP_MCP_AI_Architectural_Engine::calculate_occupancy_load( 100, 'unknown_type' ) );
	}

	/**
	 * Egress width factors.
	 */
	public function test_egress_width() {
		$this->assertEqualsWithDelta( 510.0, WP_MCP_AI_Architectural_Engine::calculate_egress_width( 100, 'level' ), 0.001 );
		$this->assertEqualsWithDelta( 760.0, WP_MCP_AI_Architectural_Engine::calculate_egress_width( 100, 'stair' ), 0.001 );
	}

	/**
	 * Velocity pressure: q_z @ 50 m/s with default factors.
	 *
	 * 0.613 * 1.0 * 1.0 * 0.85 * 50^2 = 1302.6 Pa.
	 */
	public function test_velocity_pressure() {
		$qz = WP_MCP_AI_Architectural_Engine::calculate_velocity_pressure( 50 );
		$this->assertEqualsWithDelta( 1302.625, $qz, 0.5 );
	}

	/**
	 * Wind tables include LK / JM / US, with the JM standard zone close to
	 * 141 mph (~63 m/s).
	 */
	public function test_wind_design_pressure() {
		$jm = WP_MCP_AI_Architectural_Engine::get_wind_design_pressure( 'JM', 'standard' );
		$this->assertNotEmpty( $jm['standard'] );
		$this->assertEqualsWithDelta( 63.0, $jm['basic_wind_ms'], 0.01 );
		$this->assertEqualsWithDelta( 140.93, $jm['basic_wind_mph'], 0.5 );
		$this->assertGreaterThan( 0, $jm['velocity_pressure_pa'] );

		$lk = WP_MCP_AI_Architectural_Engine::get_wind_design_pressure( 'LK', 'zone3' );
		$this->assertEqualsWithDelta( 49.0, $lk['basic_wind_ms'], 0.01 );

		$unknown = WP_MCP_AI_Architectural_Engine::get_wind_design_pressure( 'XX', 'foo' );
		$this->assertSame( 0.0, $unknown['basic_wind_ms'] );
	}

	/**
	 * Seismic base shear: V = Cs * W.
	 */
	public function test_seismic_base_shear() {
		// SDS 0.30, R 8, Ie 1 -> Cs = 0.0375, W = 1000 -> V = 37.5.
		$result = WP_MCP_AI_Architectural_Engine::calculate_seismic_base_shear( 1000, 0.30, 8, 1.0 );
		$this->assertEqualsWithDelta( 0.0375, $result['cs'], 0.0001 );
		$this->assertEqualsWithDelta( 37.5, $result['base_shear_kn'], 0.001 );
	}

	/**
	 * Seismic table dispatch.
	 */
	public function test_seismic_design_parameters() {
		$lk = WP_MCP_AI_Architectural_Engine::get_seismic_design_parameters( 'LK', 'zone2' );
		$this->assertEqualsWithDelta( 0.10, $lk['sds'], 0.0001 );
		$us = WP_MCP_AI_Architectural_Engine::get_seismic_design_parameters( 'US', 'd' );
		$this->assertEqualsWithDelta( 0.50, $us['sds'], 0.0001 );
	}

	/**
	 * Ventilation airflow: ASHRAE rate vs ACH-based, with max returned.
	 */
	public function test_ventilation_airflow() {
		// 4 occupants, 50 m^2, 2.7 m, 8 ACH.
		$r = WP_MCP_AI_Architectural_Engine::calculate_ventilation_airflow( 4, 50, 2.7, 8 );
		// ASHRAE: 4*7.5 + 50*0.3 = 30 + 15 = 45 L/s.
		$this->assertEqualsWithDelta( 45.0, $r['ashrae_lps'], 0.01 );
		// ACH: 50 * 2.7 * 1000 * 8 / 3600 ≈ 300.0.
		$this->assertEqualsWithDelta( 300.0, $r['ach_lps'], 0.5 );
		$this->assertEquals( $r['ach_lps'], $r['required_lps'] );
	}

	/**
	 * Cost rate dispatch returns LKR for LK and applies multiplier.
	 */
	public function test_cost_rate_dispatch() {
		$lk = WP_MCP_AI_Architectural_Engine::get_cost_rate( 'LK', 'standard', 'masonry' );
		$this->assertSame( 'LKR', $lk['currency'] );
		$this->assertEqualsWithDelta( 145000.0, $lk['rate_per_sqm'], 0.01 );

		// Steel = 1.18 multiplier.
		$lk_steel = WP_MCP_AI_Architectural_Engine::get_cost_rate( 'LK', 'standard', 'steel' );
		$this->assertEqualsWithDelta( 145000.0 * 1.18, $lk_steel['rate_per_sqm'], 0.01 );

		$us = WP_MCP_AI_Architectural_Engine::get_cost_rate( 'US', 'luxury', 'masonry' );
		$this->assertSame( 'USD', $us['currency'] );
		$this->assertGreaterThan( 0, $us['rate_per_sqft'] );

		$missing = WP_MCP_AI_Architectural_Engine::get_cost_rate( 'XX', 'standard', 'masonry' );
		$this->assertSame( 0.0, $missing['rate_per_sqm'] );
	}

	/**
	 * Currency conversion via USD pivot.
	 */
	public function test_convert_currency() {
		// Override rate table for deterministic test.
		add_filter(
			'wp_mcp_ai_arch_currency_rates',
			function () {
				return array(
					'USD' => 1.0,
					'LKR' => 300.0,
					'JMD' => 150.0,
				);
			}
		);

		// 30000 LKR -> 100 USD -> 15000 JMD.
		$out = WP_MCP_AI_Architectural_Engine::convert_currency( 30000, 'LKR', 'JMD' );
		$this->assertEqualsWithDelta( 15000.0, $out, 0.001 );

		$null = WP_MCP_AI_Architectural_Engine::convert_currency( 100, 'XYZ', 'USD' );
		$this->assertNull( $null );
	}

	/**
	 * Toolkit settings produce defaults.
	 */
	public function test_get_toolkit_settings_defaults() {
		delete_option( 'wp_mcp_ai_arch_design_settings' );
		$s = WP_MCP_AI_Architectural_Engine::get_toolkit_settings();
		$this->assertSame( 'LK', $s['default_country'] );
		$this->assertSame( 'metric', $s['default_unit_system'] );
		$this->assertSame( 'LKR', $s['default_currency'] );
		$this->assertSame( '4.3', $s['ifc_export_version'] );
	}
}
