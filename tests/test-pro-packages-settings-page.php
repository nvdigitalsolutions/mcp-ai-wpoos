<?php
/**
 * Test Pro Packages Settings Page
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Pro Packages Settings Page
 */
class Test_Pro_Packages_Settings_Page extends WP_UnitTestCase {

	/**
	 * Test that the Pro Packages Settings Page class can be instantiated
	 */
	public function test_class_instantiation() {
		// Check if Pro addon path constant is defined.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$settings_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-packages-settings-page.php';

		if ( ! file_exists( $settings_file ) ) {
			$this->markTestSkipped( 'Pro Packages Settings Page file not found' );
		}

		// Load the base class.
		$base_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';
		if ( file_exists( $base_file ) ) {
			require_once $base_file;
		}

		// Load the settings page class.
		require_once $settings_file;

		// Verify class exists.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Pro_Packages_Settings_Page' ),
			'WP_MCP_AI_Pro_Packages_Settings_Page class should exist'
		);

		// Verify the class implements all required abstract methods.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Packages_Settings_Page' );
		$this->assertFalse(
			$reflection->isAbstract(),
			'WP_MCP_AI_Pro_Packages_Settings_Page should not be abstract'
		);
	}

	/**
	 * Test that get_tools_list method exists and returns an array
	 */
	public function test_get_tools_list_method() {
		// Check if Pro addon path constant is defined.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$settings_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-packages-settings-page.php';

		if ( ! file_exists( $settings_file ) ) {
			$this->markTestSkipped( 'Pro Packages Settings Page file not found' );
		}

		// Load the base class.
		$base_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';
		if ( file_exists( $base_file ) ) {
			require_once $base_file;
		}

		// Load the settings page class.
		require_once $settings_file;

		// Create reflection to test protected method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Packages_Settings_Page' );
		$this->assertTrue(
			$reflection->hasMethod( 'get_tools_list' ),
			'get_tools_list method should exist'
		);

		$method = $reflection->getMethod( 'get_tools_list' );
		$this->assertTrue(
			$method->isProtected(),
			'get_tools_list method should be protected'
		);
	}
}
