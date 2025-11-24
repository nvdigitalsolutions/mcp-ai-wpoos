<?php
/**
 * Test orchestration preset service circular dependency fix
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for orchestration preset service
 */
class Test_Orchestration_Preset_Circular_Dependency extends WP_UnitTestCase {

	/**
	 * Test that get_presets() doesn't cause infinite recursion
	 */
	public function test_get_presets_no_infinite_recursion() {
		// This would timeout or exhaust memory if there's a circular dependency.
		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();

		// Verify we got presets back.
		$this->assertIsArray( $presets );
		$this->assertNotEmpty( $presets );

		// Verify all expected presets are present.
		$expected_presets = array(
			'custom',
			'auto',
			'balanced',
			'conservative',
			'aggressive',
			'development',
			'high_traffic',
			'burst_workload',
			'cost_optimized',
			'enterprise',
			'failsafe',
			'predictive_first',
			'design_professional',
		);

		foreach ( $expected_presets as $preset_id ) {
			$this->assertArrayHasKey( $preset_id, $presets, "Missing preset: {$preset_id}" );
		}
	}

	/**
	 * Test that auto preset works correctly
	 */
	public function test_auto_preset_detection() {
		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
		$auto    = $presets['auto'];

		// Verify structure.
		$this->assertArrayHasKey( 'name', $auto );
		$this->assertArrayHasKey( 'description', $auto );
		$this->assertArrayHasKey( 'settings', $auto );

		// Settings should be an array (inherited from detected preset).
		$this->assertIsArray( $auto['settings'] );
		$this->assertNotEmpty( $auto['settings'] );
	}

	/**
	 * Test that apply_preset doesn't cause circular dependency
	 */
	public function test_apply_preset_auto() {
		// Set up current user with proper capabilities.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Apply auto preset - this would hang if there's circular dependency.
		$result = WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'auto' );

		// Should succeed.
		$this->assertTrue( $result );

		// Verify preset was applied.
		$active_preset = WP_MCP_AI_Orchestration_Preset_Service::get_active_preset();
		$this->assertEquals( 'auto', $active_preset );
	}

	/**
	 * Test that balanced preset can be applied
	 */
	public function test_apply_preset_balanced() {
		// Set up current user with proper capabilities.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Apply balanced preset.
		$result = WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'balanced' );

		// Should succeed.
		$this->assertTrue( $result );

		// Verify settings were applied (updated to modern cloud-native standard).
		$memory_warning = WP_MCP_AI_Settings_Registry::get_setting( 'memory_warning_threshold' );
		$this->assertEquals( 70, $memory_warning );
	}
}
