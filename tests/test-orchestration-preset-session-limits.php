<?php
/**
 * Tests for orchestration preset integration with per-session/tool limits
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for orchestration preset session/tool limit integration
 */
class Test_Orchestration_Preset_Session_Limits extends WP_UnitTestCase {

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Tear down test environment
	 */
	public function tearDown(): void {
		// Clean up any preset settings.
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that all presets include per-call limit settings
	 */
	public function test_all_presets_include_per_call_limits() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			$this->markTestSkipped( 'Orchestration Preset Service not available' );
		}

		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();

		// Check each preset (except 'custom' which has no settings).
		foreach ( $presets as $preset_id => $preset ) {
			if ( 'custom' === $preset_id ) {
				continue;
			}

			$this->assertIsArray( $preset, "Preset {$preset_id} should be an array" );
			$this->assertArrayHasKey( 'settings', $preset, "Preset {$preset_id} should have settings" );

			$settings = $preset['settings'];

			// Verify per-call limit settings exist.
			$this->assertArrayHasKey(
				'enable_per_call_limits',
				$settings,
				"Preset {$preset_id} should include enable_per_call_limits"
			);
			$this->assertArrayHasKey(
				'per_call_token_limit',
				$settings,
				"Preset {$preset_id} should include per_call_token_limit"
			);

			// Verify types.
			$this->assertIsBool(
				$settings['enable_per_call_limits'],
				"Preset {$preset_id} enable_per_call_limits should be boolean"
			);
			$this->assertIsInt(
				$settings['per_call_token_limit'],
				"Preset {$preset_id} per_call_token_limit should be integer"
			);

			// Verify limits are reasonable.
			$this->assertGreaterThan(
				0,
				$settings['per_call_token_limit'],
				"Preset {$preset_id} per_call_token_limit should be positive"
			);
		}
	}

	/**
	 * Test that all presets include per-session limit settings
	 */
	public function test_all_presets_include_per_session_limits() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			$this->markTestSkipped( 'Orchestration Preset Service not available' );
		}

		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();

		// Check each preset (except 'custom' which has no settings).
		foreach ( $presets as $preset_id => $preset ) {
			if ( 'custom' === $preset_id ) {
				continue;
			}

			$this->assertIsArray( $preset, "Preset {$preset_id} should be an array" );
			$this->assertArrayHasKey( 'settings', $preset, "Preset {$preset_id} should have settings" );

			$settings = $preset['settings'];

			// Verify per-session limit settings exist.
			$this->assertArrayHasKey(
				'enable_per_session_limits',
				$settings,
				"Preset {$preset_id} should include enable_per_session_limits"
			);
			$this->assertArrayHasKey(
				'per_session_token_limit',
				$settings,
				"Preset {$preset_id} should include per_session_token_limit"
			);

			// Verify types.
			$this->assertIsBool(
				$settings['enable_per_session_limits'],
				"Preset {$preset_id} enable_per_session_limits should be boolean"
			);
			$this->assertIsInt(
				$settings['per_session_token_limit'],
				"Preset {$preset_id} per_session_token_limit should be integer"
			);

			// Verify limits are reasonable.
			$this->assertGreaterThan(
				0,
				$settings['per_session_token_limit'],
				"Preset {$preset_id} per_session_token_limit should be positive"
			);
		}
	}

	/**
	 * Test that preset values are sensible relative to each other
	 */
	public function test_preset_limit_hierarchy() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			$this->markTestSkipped( 'Orchestration Preset Service not available' );
		}

		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();

		// Conservative should have the most restrictive limits.
		$conservative = $presets['conservative']['settings'];
		$balanced     = $presets['balanced']['settings'];
		$aggressive   = $presets['aggressive']['settings'];

		// Per-call limits hierarchy.
		$this->assertLessThan(
			$balanced['per_call_token_limit'],
			$conservative['per_call_token_limit'],
			'Conservative per-call limit should be less than balanced'
		);
		$this->assertLessThan(
			$aggressive['per_call_token_limit'],
			$balanced['per_call_token_limit'],
			'Balanced per-call limit should be less than aggressive'
		);

		// Per-session limits hierarchy.
		$this->assertLessThan(
			$balanced['per_session_token_limit'],
			$conservative['per_session_token_limit'],
			'Conservative per-session limit should be less than balanced'
		);
		$this->assertLessThan(
			$aggressive['per_session_token_limit'],
			$balanced['per_session_token_limit'],
			'Balanced per-session limit should be less than aggressive'
		);
	}

	/**
	 * Test that applying a preset sets per-call and per-session limits
	 */
	public function test_applying_preset_sets_session_limits() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			$this->markTestSkipped( 'Orchestration Preset Service not available' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
			$this->markTestSkipped( 'Settings Registry not available' );
		}

		// Apply the balanced preset.
		$result = WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'balanced' );
		$this->assertTrue( $result, 'Preset application should succeed' );

		// Verify per-call settings were applied.
		$enable_per_call = WP_MCP_AI_Settings_Registry::get_setting( 'enable_per_call_limits' );
		$per_call_limit  = WP_MCP_AI_Settings_Registry::get_setting( 'per_call_token_limit' );

		$this->assertTrue( $enable_per_call, 'Per-call limits should be enabled' );
		$this->assertEquals( 10000, $per_call_limit, 'Per-call limit should match balanced preset' );

		// Verify per-session settings were applied.
		$enable_per_session = WP_MCP_AI_Settings_Registry::get_setting( 'enable_per_session_limits' );
		$per_session_limit  = WP_MCP_AI_Settings_Registry::get_setting( 'per_session_token_limit' );

		$this->assertTrue( $enable_per_session, 'Per-session limits should be enabled' );
		$this->assertEquals( 50000, $per_session_limit, 'Per-session limit should match balanced preset' );
	}

	/**
	 * Test that development preset disables limits
	 */
	public function test_development_preset_disables_limits() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			$this->markTestSkipped( 'Orchestration Preset Service not available' );
		}

		$presets     = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
		$development = $presets['development']['settings'];

		// Development preset should disable limits for easier testing.
		$this->assertFalse(
			$development['enable_per_call_limits'],
			'Development preset should disable per-call limits'
		);
		$this->assertFalse(
			$development['enable_per_session_limits'],
			'Development preset should disable per-session limits'
		);

		// But still have reasonable values if manually enabled.
		$this->assertGreaterThan( 0, $development['per_call_token_limit'] );
		$this->assertGreaterThan( 0, $development['per_session_token_limit'] );
	}

	/**
	 * Test that cost-optimized preset has the strictest limits
	 */
	public function test_cost_optimized_preset_has_strict_limits() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			$this->markTestSkipped( 'Orchestration Preset Service not available' );
		}

		$presets        = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
		$cost_optimized = $presets['cost_optimized']['settings'];
		$balanced       = $presets['balanced']['settings'];

		// Cost-optimized should have stricter limits than balanced.
		$this->assertLessThan(
			$balanced['per_call_token_limit'],
			$cost_optimized['per_call_token_limit'],
			'Cost-optimized per-call limit should be stricter than balanced'
		);
		$this->assertLessThan(
			$balanced['per_session_token_limit'],
			$cost_optimized['per_session_token_limit'],
			'Cost-optimized per-session limit should be stricter than balanced'
		);

		// And limits should be enabled.
		$this->assertTrue(
			$cost_optimized['enable_per_call_limits'],
			'Cost-optimized should enable per-call limits'
		);
		$this->assertTrue(
			$cost_optimized['enable_per_session_limits'],
			'Cost-optimized should enable per-session limits'
		);
	}

	/**
	 * Test that failsafe preset has very strict limits
	 */
	public function test_failsafe_preset_has_very_strict_limits() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			$this->markTestSkipped( 'Orchestration Preset Service not available' );
		}

		$presets      = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
		$failsafe     = $presets['failsafe']['settings'];
		$conservative = $presets['conservative']['settings'];

		// Failsafe should be even stricter than conservative.
		$this->assertLessThanOrEqual(
			$conservative['per_call_token_limit'],
			$failsafe['per_call_token_limit'],
			'Failsafe per-call limit should be stricter than or equal to conservative'
		);
		$this->assertLessThanOrEqual(
			$conservative['per_session_token_limit'],
			$failsafe['per_session_token_limit'],
			'Failsafe per-session limit should be stricter than or equal to conservative'
		);

		// And limits should be enabled.
		$this->assertTrue(
			$failsafe['enable_per_call_limits'],
			'Failsafe should enable per-call limits'
		);
		$this->assertTrue(
			$failsafe['enable_per_session_limits'],
			'Failsafe should enable per-session limits'
		);
	}

	/**
	 * Test that per-session limit is always greater than per-call limit
	 */
	public function test_per_session_limit_greater_than_per_call() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			$this->markTestSkipped( 'Orchestration Preset Service not available' );
		}

		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();

		foreach ( $presets as $preset_id => $preset ) {
			if ( 'custom' === $preset_id ) {
				continue;
			}

			$settings = $preset['settings'];

			$this->assertGreaterThan(
				$settings['per_call_token_limit'],
				$settings['per_session_token_limit'],
				"Preset {$preset_id} per-session limit should be greater than per-call limit"
			);
		}
	}
}
