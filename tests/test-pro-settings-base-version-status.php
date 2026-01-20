<?php
/**
 * Tests for Pro Settings Base Version Status
 *
 * Verifies that the pro settings page correctly detects whether
 * the system is running in base version mode by checking if the
 * Pro plugin is loaded, rather than the deprecated WP_MCP_AI_BASE_VERSION constant.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Pro Settings Base Version status check.
 */
class WP_MCP_AI_Pro_Settings_Base_Version_Status_Test extends WP_UnitTestCase {

	/**
	 * Test that base_version status reflects Pro plugin activation state.
	 *
	 * When WP_MCP_AI_PRO_VERSION is defined (Pro plugin is active),
	 * the base_version should be false (not in base mode).
	 */
	public function test_base_version_status_with_pro_active() {
		// Get the pro toolkit status.
		$status = WP_MCP_AI_Pro_Settings::get_pro_toolkit_status();

		// Verify base_version key exists.
		$this->assertArrayHasKey( 'base_version', $status );
		$this->assertIsBool( $status['base_version'] );

		// Check if Pro is actually loaded in this environment.
		$pro_is_loaded = defined( 'WP_MCP_AI_PRO_VERSION' );

		// When Pro is loaded, base_version should be false.
		// When Pro is not loaded, base_version should be true.
		$expected_base_version = ! $pro_is_loaded;

		$this->assertEquals(
			$expected_base_version,
			$status['base_version'],
			sprintf(
				'Base version status should be %s when Pro is %s',
				$expected_base_version ? 'true (base mode)' : 'false (full mode)',
				$pro_is_loaded ? 'loaded' : 'not loaded'
			)
		);
	}

	/**
	 * Test that base_version is determined by Pro plugin state, not old constant.
	 *
	 * This ensures we're not using the deprecated WP_MCP_AI_BASE_VERSION constant.
	 */
	public function test_base_version_uses_pro_version_constant() {
		$status = WP_MCP_AI_Pro_Settings::get_pro_toolkit_status();

		// The status should match Pro plugin activation state.
		$pro_loaded     = defined( 'WP_MCP_AI_PRO_VERSION' );
		$is_base_mode   = ! $pro_loaded;

		$this->assertEquals(
			$is_base_mode,
			$status['base_version'],
			'Base version status should be determined by WP_MCP_AI_PRO_VERSION, not WP_MCP_AI_BASE_VERSION'
		);
	}

	/**
	 * Test that all expected status fields exist.
	 */
	public function test_pro_toolkit_status_has_required_fields() {
		$status = WP_MCP_AI_Pro_Settings::get_pro_toolkit_status();

		// Required fields.
		$required_fields = array(
			'pro_dashboard_enabled',
			'base_version',
			'debug_mode',
			'php_version',
			'wp_version',
			'plugin_version',
			'integrations',
		);

		foreach ( $required_fields as $field ) {
			$this->assertArrayHasKey(
				$field,
				$status,
				"Status array should contain '{$field}' key"
			);
		}
	}

	/**
	 * Test that base_version is a boolean.
	 */
	public function test_base_version_is_boolean() {
		$status = WP_MCP_AI_Pro_Settings::get_pro_toolkit_status();

		$this->assertIsBool(
			$status['base_version'],
			'Base version status should be a boolean value'
		);
	}
}
