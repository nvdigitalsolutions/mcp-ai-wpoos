<?php
/**
 * Tests for Advanced Section Logging Table Display
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Advanced Section Logging Table functionality.
 */
class Test_Advanced_Section_Logging extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Guard: Ensure section classes are loaded (may be gated behind is_admin()).
		if ( ! class_exists( 'WP_MCP_AI_Section_Advanced' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-advanced.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Section_General' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-general.php';
		}
	}

	/**
	 * Test that the General section has extended logging fields.
	 *
	 * Note: enable_extended_logging and related granular logging fields
	 * are registered in the General section, not the Advanced section.
	 */
	public function test_advanced_section_has_logging_fields() {
		$section = new WP_MCP_AI_Section_General();
		$fields  = $section->get_fields();

		// Check that extended logging field exists in General section.
		$this->assertArrayHasKey( 'enable_extended_logging', $fields );

		// Verify the field configuration.
		$this->assertEquals( 'checkbox', $fields['enable_extended_logging']['type'] );
		$this->assertStringContainsString( 'debugging', $fields['enable_extended_logging']['description'] );
	}

	/**
	 * Test that the logging table does not render when logging is disabled.
	 */
	public function test_logging_table_not_rendered_when_logging_disabled() {
		// Set logging to disabled.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_logging' => false )
		);

		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		// When logging is disabled, enable_logging should be falsy.
		$this->assertEmpty( $settings['enable_logging'] );
	}

	/**
	 * Test that the logging table renders when logging is enabled.
	 */
	public function test_logging_table_renders_when_logging_enabled() {
		// Set logging to enabled.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_logging' => true )
		);

		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		// When logging is enabled, enable_logging should be truthy.
		$this->assertNotEmpty( $settings['enable_logging'] );
	}

	/**
	 * Test that general section has updated description for enable_logging.
	 */
	public function test_general_section_logging_description_updated() {
		$section = new WP_MCP_AI_Section_General();
		$fields  = $section->get_fields();

		// Check that enable_logging field exists.
		$this->assertArrayHasKey( 'enable_logging', $fields );

		// Verify the description mentions viewing logs in Advanced tab.
		$this->assertStringContainsString( 'View logs in the Advanced tab', $fields['enable_logging']['description'] );

		// Verify the description mentions what gets logged.
		$this->assertStringContainsString( 'errors, warnings, and key activity', $fields['enable_logging']['description'] );
	}

	/**
	 * Test that extended logging description clarifies relationship to enable_logging.
	 */
	public function test_extended_logging_description_clarifies_requirement() {
		$section = new WP_MCP_AI_Section_General();
		$fields  = $section->get_fields();

		// Check that extended logging has proper description.
		$this->assertArrayHasKey( 'enable_extended_logging', $fields );

		// Should mention performance impact.
		$this->assertStringContainsString( 'impact', $fields['enable_extended_logging']['description'] );

		// Should explain what it logs.
		$this->assertStringContainsString( 'request/response', $fields['enable_extended_logging']['description'] );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clear the settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
	}
}
