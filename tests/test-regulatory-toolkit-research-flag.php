<?php
/**
 * Tests for Regulatory Registration Toolkit research flag.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that the Regulatory Registration Toolkit has research disabled.
 * Research & Add functionality has been moved to its own dedicated page.
 */
class Test_Regulatory_Toolkit_Research_Flag extends WP_UnitTestCase {

	/**
	 * Test that the Regulatory Registration Toolkit settings page has research disabled.
	 * Research & Add functionality has been moved to its own dedicated page.
	 */
	public function test_regulatory_toolkit_has_research_disabled() {
		// Load the settings page class.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro version not available' );
			return;
		}

		$settings_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-regulatory-registration-toolkit-settings-page.php';
		if ( ! file_exists( $settings_file ) ) {
			$this->markTestSkipped( 'Regulatory toolkit settings file not found' );
			return;
		}

		// Load the base class first.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

		// Load the regulatory registration settings page.
		require_once $settings_file;

		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Regulatory_Registration_Toolkit_Settings_Page' ),
			'Regulatory Registration Toolkit settings page class should exist'
		);

		// Create an instance using reflection to access protected properties.
		$settings_page = new WP_MCP_AI_Regulatory_Registration_Toolkit_Settings_Page();
		$reflection    = new ReflectionClass( $settings_page );

		// Get the has_research property.
		$has_research_property = $reflection->getProperty( 'has_research' );
		$has_research_property->setAccessible( true );

		// Check that has_research is false (Research & Add tab removed from settings).
		$this->assertFalse(
			$has_research_property->getValue( $settings_page ),
			'Regulatory Registration Toolkit should have research disabled in settings (has_research should be false) since Research & Add has its own dedicated page'
		);
	}

	/**
	 * Test that the research assistant field is NOT registered when has_research is false.
	 * Research & Add functionality has been moved to its own dedicated page.
	 */
	public function test_research_assistant_field_is_not_registered() {
		// Load the settings page class.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro version not available' );
			return;
		}

		$settings_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-regulatory-registration-toolkit-settings-page.php';
		if ( ! file_exists( $settings_file ) ) {
			$this->markTestSkipped( 'Regulatory toolkit settings file not found' );
			return;
		}

		// Load the base class first.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

		// Load the regulatory registration settings page.
		require_once $settings_file;

		// Create an instance to trigger registration.
		$settings_page = new WP_MCP_AI_Regulatory_Registration_Toolkit_Settings_Page();
		$reflection    = new ReflectionClass( $settings_page );

		// Get the option_name property.
		$option_name_property = $reflection->getProperty( 'option_name' );
		$option_name_property->setAccessible( true );
		$option_name = $option_name_property->getValue( $settings_page );

		// Trigger the admin_init action to register settings.
		do_action( 'admin_init' );

		// Get registered settings fields.
		global $wp_settings_fields;

		// Check that the research_assistant_id field is NOT registered.
		$section = $option_name . '_config_section';

		// The section should still exist for other config fields.
		if ( isset( $wp_settings_fields[ $option_name ][ $section ] ) ) {
			// But research_assistant_id field should NOT be registered when has_research is false.
			$this->assertArrayNotHasKey(
				'research_assistant_id',
				$wp_settings_fields[ $option_name ][ $section ],
				'research_assistant_id field should NOT be registered when has_research is false'
			);
		}
	}
}
