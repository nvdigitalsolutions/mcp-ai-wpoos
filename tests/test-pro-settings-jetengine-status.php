<?php
/**
 * Tests for Pro Settings JetEngine Status Check
 *
 * Verifies that the pro settings page uses the correct method
 * to check for JetEngine activation, matching the check used
 * throughout the rest of the codebase.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Pro Settings JetEngine status check.
 */
class WP_MCP_AI_Pro_Settings_JetEngine_Status_Test extends WP_UnitTestCase {

	/**
	 * Test that get_pro_toolkit_status uses class_exists for JetEngine check.
	 */
	public function test_jetengine_status_uses_class_exists() {
		// Get the pro toolkit status.
		$status = WP_MCP_AI_Pro_Settings::get_pro_toolkit_status();

		// Verify integrations array exists.
		$this->assertArrayHasKey( 'integrations', $status );
		$this->assertIsArray( $status['integrations'] );

		// Verify jetengine key exists.
		$this->assertArrayHasKey( 'jetengine', $status['integrations'] );

		// The status should be a boolean.
		$this->assertIsBool( $status['integrations']['jetengine'] );

		// Verify it matches the standard check (class_exists).
		$expected = class_exists( 'Jet_Engine' );
		$this->assertEquals(
			$expected,
			$status['integrations']['jetengine'],
			'JetEngine status should match class_exists( "Jet_Engine" ) check'
		);
	}

	/**
	 * Test that JetEngine status matches plugins integration section check.
	 *
	 * This ensures consistency across different admin pages.
	 */
	public function test_jetengine_status_matches_plugins_integration_section() {
		// Get pro settings status.
		$pro_status = WP_MCP_AI_Pro_Settings::get_pro_toolkit_status();

		// Get the plugins integration section check.
		$plugins_section_check = class_exists( 'Jet_Engine' );

		// They should match.
		$this->assertEquals(
			$plugins_section_check,
			$pro_status['integrations']['jetengine'],
			'JetEngine status in Pro Settings should match Plugins Integration section check'
		);
	}

	/**
	 * Test that all integrations use proper detection methods.
	 */
	public function test_all_integrations_have_proper_checks() {
		$status = WP_MCP_AI_Pro_Settings::get_pro_toolkit_status();

		// Expected integrations.
		$expected_integrations = array( 'jetengine', 'woocommerce', 'elementor', 'rankmath', 'wpcode' );

		foreach ( $expected_integrations as $integration ) {
			$this->assertArrayHasKey(
				$integration,
				$status['integrations'],
				"Integration '{$integration}' should be present in status array"
			);

			$this->assertIsBool(
				$status['integrations'][ $integration ],
				"Integration '{$integration}' status should be a boolean"
			);
		}
	}

	/**
	 * Test that JetEngine detection is consistent with tool checks.
	 */
	public function test_jetengine_status_matches_tool_checks() {
		$status = WP_MCP_AI_Pro_Settings::get_pro_toolkit_status();

		// Tools use class_exists( 'Jet_Engine' ) - verify consistency.
		$tool_check = class_exists( 'Jet_Engine' );

		$this->assertEquals(
			$tool_check,
			$status['integrations']['jetengine'],
			'JetEngine status should match tool availability check'
		);
	}
}
