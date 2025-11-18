<?php
/**
 * Tests for orchestration view-aware sanitization.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that view-aware sanitization prevents settings from being wiped
 * when saving one view while other views have existing settings.
 */
class WP_MCP_AI_Orchestration_View_Sanitization_Test extends WP_UnitTestCase {

	/**
	 * Test that saving settings view doesn't wipe thresholds settings.
	 */
	public function test_saving_settings_view_preserves_thresholds() {
		// First, save some thresholds settings.
		$initial_thresholds = array(
			'memory_warning_threshold'  => 70,
			'memory_critical_threshold' => 85,
			'low_tier_max_tokens'       => 2000,
			'per_call_token_limit'      => 10000,
			'per_session_token_limit'   => 50000,
		);

		foreach ( $initial_thresholds as $key => $value ) {
			WP_MCP_AI_Settings_Registry::update_setting( $key, $value );
		}

		// Verify they were saved.
		foreach ( $initial_thresholds as $key => $expected ) {
			$actual = WP_MCP_AI_Settings_Registry::get_setting( $key );
			$this->assertEquals( $expected, $actual, "Initial threshold setting $key should be saved" );
		}

		// Now simulate saving the settings view (not thresholds).
		$section = WP_MCP_AI_Settings_Registry::get_section( 'orchestration' );
		$this->assertNotNull( $section, 'Orchestration section should be registered' );

		// Simulate submitting the settings view form.
		$_POST['view'] = 'settings';
		$input         = array(
			'enable_budget_management'       => '1',
			'enable_predictive_optimization' => '1',
			'enable_capability_gating'       => '1',
		);

		$sanitized = $section->sanitize( $input );

		// The sanitized output should only contain settings view fields.
		$this->assertArrayHasKey( 'enable_budget_management', $sanitized );
		$this->assertArrayNotHasKey( 'memory_warning_threshold', $sanitized, 'Threshold fields should not be in sanitized output' );

		// Save the sanitized settings.
		foreach ( $sanitized as $key => $value ) {
			WP_MCP_AI_Settings_Registry::update_setting( $key, $value );
		}

		// Verify that threshold settings are still intact.
		foreach ( $initial_thresholds as $key => $expected ) {
			$actual = WP_MCP_AI_Settings_Registry::get_setting( $key );
			$this->assertEquals( $expected, $actual, "Threshold setting $key should still be intact after saving settings view" );
		}

		// Clean up.
		unset( $_POST['view'] );
	}

	/**
	 * Test that saving thresholds view doesn't wipe settings view settings.
	 */
	public function test_saving_thresholds_view_preserves_settings() {
		// First, save some settings view settings.
		$initial_settings = array(
			'enable_budget_management'       => true,
			'enable_predictive_optimization' => true,
			'enable_capability_gating'       => false,
		);

		foreach ( $initial_settings as $key => $value ) {
			WP_MCP_AI_Settings_Registry::update_setting( $key, $value );
		}

		// Verify they were saved.
		foreach ( $initial_settings as $key => $expected ) {
			$actual = WP_MCP_AI_Settings_Registry::get_setting( $key );
			$this->assertEquals( $expected, $actual, "Initial setting $key should be saved" );
		}

		// Now simulate saving the thresholds view.
		$section = WP_MCP_AI_Settings_Registry::get_section( 'orchestration' );
		$this->assertNotNull( $section, 'Orchestration section should be registered' );

		// Simulate submitting the thresholds view form.
		$_POST['view'] = 'thresholds';
		$input         = array(
			'memory_warning_threshold'  => '75',
			'memory_critical_threshold' => '90',
			'low_tier_max_tokens'       => '1500',
			'per_call_token_limit'      => '15000',
			'per_session_token_limit'   => '60000',
			'enable_per_call_limits'    => '1',
			'enable_per_session_limits' => '1',
		);

		$sanitized = $section->sanitize( $input );

		// The sanitized output should only contain thresholds view fields.
		$this->assertArrayHasKey( 'memory_warning_threshold', $sanitized );
		$this->assertArrayHasKey( 'per_call_token_limit', $sanitized );
		$this->assertArrayNotHasKey( 'enable_budget_management', $sanitized, 'Settings fields should not be in sanitized output' );

		// Save the sanitized settings.
		foreach ( $sanitized as $key => $value ) {
			WP_MCP_AI_Settings_Registry::update_setting( $key, $value );
		}

		// Verify that settings view settings are still intact.
		foreach ( $initial_settings as $key => $expected ) {
			$actual = WP_MCP_AI_Settings_Registry::get_setting( $key );
			$this->assertEquals( $expected, $actual, "Setting $key should still be intact after saving thresholds view" );
		}

		// Clean up.
		unset( $_POST['view'] );
	}

	/**
	 * Test that per-call and per-session limit fields are properly sanitized.
	 */
	public function test_per_call_and_session_limits_sanitization() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'orchestration' );

		// Simulate submitting the thresholds view with per-call/session limits.
		$_POST['view'] = 'thresholds';
		$input         = array(
			'per_call_token_limit'      => '12000',
			'per_session_token_limit'   => '55000',
			'enable_per_call_limits'    => '1',
			'enable_per_session_limits' => '1',
		);

		$sanitized = $section->sanitize( $input );

		// Verify the limits are properly sanitized.
		$this->assertArrayHasKey( 'per_call_token_limit', $sanitized );
		$this->assertSame( 12000, $sanitized['per_call_token_limit'] );

		$this->assertArrayHasKey( 'per_session_token_limit', $sanitized );
		$this->assertSame( 55000, $sanitized['per_session_token_limit'] );

		$this->assertArrayHasKey( 'enable_per_call_limits', $sanitized );
		$this->assertTrue( $sanitized['enable_per_call_limits'] );

		$this->assertArrayHasKey( 'enable_per_session_limits', $sanitized );
		$this->assertTrue( $sanitized['enable_per_session_limits'] );

		// Clean up.
		unset( $_POST['view'] );
	}

	/**
	 * Test that invalid view doesn't process any fields.
	 */
	public function test_invalid_view_returns_empty() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'orchestration' );

		// Simulate submitting with an invalid view.
		$_POST['view'] = 'invalid_view_name';
		$input         = array(
			'memory_warning_threshold' => '80',
			'enable_budget_management' => '1',
		);

		$sanitized = $section->sanitize( $input );

		// Should return empty array to preserve all existing settings.
		$this->assertEmpty( $sanitized, 'Invalid view should return empty array' );

		// Clean up.
		unset( $_POST['view'] );
	}

	/**
	 * Test that checkbox fields default to false when unchecked.
	 */
	public function test_unchecked_checkboxes_are_false() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'orchestration' );

		// First set some checkboxes to true.
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_per_call_limits', true );
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_per_session_limits', true );

		// Verify they're set.
		$this->assertTrue( WP_MCP_AI_Settings_Registry::get_setting( 'enable_per_call_limits' ) );
		$this->assertTrue( WP_MCP_AI_Settings_Registry::get_setting( 'enable_per_session_limits' ) );

		// Now submit the thresholds form WITHOUT the checkbox values (unchecked).
		$_POST['view'] = 'thresholds';
		$input         = array(
			'per_call_token_limit'    => '10000',
			'per_session_token_limit' => '50000',
			// Checkboxes are not included (unchecked).
		);

		$sanitized = $section->sanitize( $input );

		// Save the sanitized values.
		foreach ( $sanitized as $key => $value ) {
			WP_MCP_AI_Settings_Registry::update_setting( $key, $value );
		}

		// Checkboxes should now be false since they weren't in the input.
		$this->assertFalse( WP_MCP_AI_Settings_Registry::get_setting( 'enable_per_call_limits' ), 'Unchecked checkbox should be false' );
		$this->assertFalse( WP_MCP_AI_Settings_Registry::get_setting( 'enable_per_session_limits' ), 'Unchecked checkbox should be false' );

		// Clean up.
		unset( $_POST['view'] );
	}
}
