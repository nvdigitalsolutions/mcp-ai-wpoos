<?php
/**
 * Tests for the unified Healthcare Toolkit shared engine.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! class_exists( 'WP_MCP_AI_Healthcare_Engine' ) ) {
	$engine_path = dirname( __DIR__, 2 ) . '/includes/tools/healthcare/class-wp-mcp-ai-healthcare-engine.php';
	if ( file_exists( $engine_path ) ) {
		require_once $engine_path;
	}
}

/**
 * Test case for WP_MCP_AI_Healthcare_Engine.
 */
class Test_Healthcare_Engine extends WP_UnitTestCase {

	/**
	 * Class is loadable.
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Healthcare_Engine' ) );
	}

	/**
	 * Kg <-> lb round trip.
	 */
	public function test_kg_lb_roundtrip() {
		$kg   = 70.0;
		$lb   = WP_MCP_AI_Healthcare_Engine::kg_to_lb( $kg );
		$back = WP_MCP_AI_Healthcare_Engine::lb_to_kg( $lb );
		$this->assertEqualsWithDelta( $kg, $back, 0.0001 );
		$this->assertEqualsWithDelta( 154.32, $lb, 0.05 );
	}

	/**
	 * Cm <-> in round trip.
	 */
	public function test_cm_in_roundtrip() {
		$cm   = 175.0;
		$in   = WP_MCP_AI_Healthcare_Engine::cm_to_in( $cm );
		$back = WP_MCP_AI_Healthcare_Engine::in_to_cm( $in );
		$this->assertEqualsWithDelta( $cm, $back, 0.0001 );
	}

	/**
	 * Celsius <-> Fahrenheit conversion.
	 */
	public function test_temperature_conversion() {
		$this->assertEqualsWithDelta( 98.6, WP_MCP_AI_Healthcare_Engine::c_to_f( 37.0 ), 0.05 );
		$this->assertEqualsWithDelta( 0.0, WP_MCP_AI_Healthcare_Engine::f_to_c( 32.0 ), 0.001 );
	}

	/**
	 * BMI calculation and category bands.
	 */
	public function test_bmi_and_category() {
		$bmi = WP_MCP_AI_Healthcare_Engine::bmi( 70.0, 175.0 );
		$this->assertEqualsWithDelta( 22.86, $bmi, 0.05 );
		$this->assertSame( 'normal', WP_MCP_AI_Healthcare_Engine::bmi_category( $bmi ) );

		$this->assertSame( 'underweight', WP_MCP_AI_Healthcare_Engine::bmi_category( 17.0 ) );
		$this->assertSame( 'overweight', WP_MCP_AI_Healthcare_Engine::bmi_category( 27.0 ) );
		$this->assertSame( 'obese_1', WP_MCP_AI_Healthcare_Engine::bmi_category( 32.0 ) );
		$this->assertSame( 'obese_2', WP_MCP_AI_Healthcare_Engine::bmi_category( 38.0 ) );
		$this->assertSame( 'obese_3', WP_MCP_AI_Healthcare_Engine::bmi_category( 41.0 ) );
		$this->assertSame( 'unknown', WP_MCP_AI_Healthcare_Engine::bmi_category( 0 ) );
	}

	/**
	 * Invalid BMI inputs return null.
	 */
	public function test_bmi_invalid() {
		$this->assertNull( WP_MCP_AI_Healthcare_Engine::bmi( 0, 175 ) );
		$this->assertNull( WP_MCP_AI_Healthcare_Engine::bmi( 70, 0 ) );
	}

	/**
	 * ACC/AHA blood-pressure staging.
	 */
	public function test_bp_stage() {
		$this->assertSame( 'normal', WP_MCP_AI_Healthcare_Engine::bp_stage( 110, 70 ) );
		$this->assertSame( 'elevated', WP_MCP_AI_Healthcare_Engine::bp_stage( 125, 75 ) );
		$this->assertSame( 'stage_1', WP_MCP_AI_Healthcare_Engine::bp_stage( 132, 82 ) );
		$this->assertSame( 'stage_2', WP_MCP_AI_Healthcare_Engine::bp_stage( 145, 95 ) );
		$this->assertSame( 'crisis', WP_MCP_AI_Healthcare_Engine::bp_stage( 200, 130 ) );
		$this->assertSame( 'unknown', WP_MCP_AI_Healthcare_Engine::bp_stage( 0, 0 ) );
	}

	/**
	 * Default reference ranges contain the common adult metrics.
	 */
	public function test_reference_ranges_human_default() {
		$ranges = WP_MCP_AI_Healthcare_Engine::reference_ranges();
		$this->assertArrayHasKey( 'heart_rate', $ranges );
		$this->assertArrayHasKey( 'systolic_bp', $ranges );
		$this->assertSame( 60, $ranges['heart_rate']['min'] );
		$this->assertSame( 100, $ranges['heart_rate']['max'] );
	}

	/**
	 * Veterinary species adjust the ranges.
	 */
	public function test_reference_ranges_canine_and_feline() {
		$canine = WP_MCP_AI_Healthcare_Engine::reference_ranges( array( 'species' => 'canine' ) );
		$this->assertSame( 60, $canine['heart_rate']['min'] );
		$this->assertSame( 140, $canine['heart_rate']['max'] );
		$this->assertEqualsWithDelta( 38.3, $canine['temperature_c']['min'], 0.001 );

		$feline = WP_MCP_AI_Healthcare_Engine::reference_ranges( array( 'species' => 'feline' ) );
		$this->assertSame( 140, $feline['heart_rate']['min'] );
		$this->assertSame( 220, $feline['heart_rate']['max'] );
	}

	/**
	 * Reference range filter is honoured.
	 */
	public function test_reference_ranges_filter() {
		add_filter(
			'wp_mcp_ai_healthcare_reference_ranges',
			static function ( $ranges ) {
				$ranges['heart_rate'] = array(
					'min'  => 50,
					'max'  => 90,
					'unit' => 'bpm',
				);
				return $ranges;
			}
		);
		$ranges = WP_MCP_AI_Healthcare_Engine::reference_ranges();
		$this->assertSame( 50, $ranges['heart_rate']['min'] );
		$this->assertSame( 90, $ranges['heart_rate']['max'] );
		remove_all_filters( 'wp_mcp_ai_healthcare_reference_ranges' );
	}

	/**
	 * `flag_value()` returns the right side of the range.
	 */
	public function test_flag_value() {
		$this->assertSame( 'in_range', WP_MCP_AI_Healthcare_Engine::flag_value( 'heart_rate', 75 ) );
		$this->assertSame( 'low', WP_MCP_AI_Healthcare_Engine::flag_value( 'heart_rate', 45 ) );
		$this->assertSame( 'high', WP_MCP_AI_Healthcare_Engine::flag_value( 'heart_rate', 130 ) );
		$this->assertSame( 'unknown', WP_MCP_AI_Healthcare_Engine::flag_value( 'no_such_metric', 1 ) );
	}

	/**
	 * Settings merge defaults with saved option values and respect the filter.
	 */
	public function test_settings_defaults_and_filter() {
		delete_option( WP_MCP_AI_Healthcare_Engine::SETTINGS_OPTION );
		$settings = WP_MCP_AI_Healthcare_Engine::get_toolkit_settings();
		$this->assertSame( 'metric', $settings['default_unit_system'] );
		$this->assertSame( 'icd10-cm-2025', $settings['default_code_pack'] );
		$this->assertSame( 365, $settings['audit_retention_days'] );
		$this->assertFalse( $settings['require_baa_acknowledged'] );

		update_option(
			WP_MCP_AI_Healthcare_Engine::SETTINGS_OPTION,
			array(
				'default_unit_system'  => 'imperial',
				'audit_retention_days' => 90,
			)
		);
		$settings = WP_MCP_AI_Healthcare_Engine::get_toolkit_settings();
		$this->assertSame( 'imperial', $settings['default_unit_system'] );
		$this->assertSame( 90, $settings['audit_retention_days'] );
		// Defaults still present for keys that weren't overridden.
		$this->assertSame( 'icd10-cm-2025', $settings['default_code_pack'] );

		add_filter(
			'wp_mcp_ai_healthcare_toolkit_settings',
			static function ( $resolved ) {
				$resolved['default_code_pack'] = 'icd11-2025';
				return $resolved;
			}
		);
		$settings = WP_MCP_AI_Healthcare_Engine::get_toolkit_settings();
		$this->assertSame( 'icd11-2025', $settings['default_code_pack'] );
		remove_all_filters( 'wp_mcp_ai_healthcare_toolkit_settings' );

		delete_option( WP_MCP_AI_Healthcare_Engine::SETTINGS_OPTION );
	}

	/**
	 * Sub-toolkit toggle resolution.
	 */
	public function test_subtoolkit_enabled() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_health_wellness_management' => true,
				'enable_healthcare_imaging'         => false,
			)
		);
		$this->assertTrue( WP_MCP_AI_Healthcare_Engine::is_subtoolkit_enabled( 'health_wellness' ) );
		$this->assertFalse( WP_MCP_AI_Healthcare_Engine::is_subtoolkit_enabled( 'imaging' ) );
		// Vitals defaults to health_wellness when its own toggle is absent.
		$this->assertTrue( WP_MCP_AI_Healthcare_Engine::is_subtoolkit_enabled( 'vitals' ) );

		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_health_wellness_management' => true,
				'enable_medical_vitals'             => false,
			)
		);
		$this->assertFalse( WP_MCP_AI_Healthcare_Engine::is_subtoolkit_enabled( 'vitals' ) );

		$this->assertFalse( WP_MCP_AI_Healthcare_Engine::is_subtoolkit_enabled( 'unknown_subtoolkit' ) );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Member resolution by post id.
	 */
	public function test_resolve_member_id_by_post_id() {
		$member_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_member',
				'post_status' => 'publish',
				'post_title'  => 'Test Member',
				'post_name'   => 'test-member-resolve',
			)
		);
		$this->assertSame( $member_id, WP_MCP_AI_Healthcare_Engine::resolve_member_id( $member_id ) );
		$this->assertSame( $member_id, WP_MCP_AI_Healthcare_Engine::resolve_member_id( (string) $member_id ) );
		$this->assertSame( $member_id, WP_MCP_AI_Healthcare_Engine::resolve_member_id( 'test-member-resolve' ) );
		$this->assertSame( 0, WP_MCP_AI_Healthcare_Engine::resolve_member_id( 0 ) );
		$this->assertSame( 0, WP_MCP_AI_Healthcare_Engine::resolve_member_id( 'no-such-slug' ) );
	}

	/**
	 * Member resolution via MRN meta.
	 */
	public function test_resolve_member_id_by_mrn() {
		$member_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_member',
				'post_status' => 'publish',
				'post_title'  => 'MRN Member',
			)
		);
		update_post_meta( $member_id, '_member_mrn', 'MRN-12345' );
		$this->assertSame( $member_id, WP_MCP_AI_Healthcare_Engine::resolve_member_id( 'MRN-12345' ) );
	}

	/**
	 * Single-site PHI gate is always true.
	 */
	public function test_phi_acknowledged_single_site() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site behaviour only.' );
		}
		$this->assertTrue( WP_MCP_AI_Healthcare_Engine::phi_acknowledged() );
	}
}
