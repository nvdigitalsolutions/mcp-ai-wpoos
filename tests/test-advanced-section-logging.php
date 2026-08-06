<?php
/**
 * Tests for Section Logging Table Display.
 *
 * Verifies that the enable_extended_logging field exists in the General
 * section (where it is defined), and that the logging table renders
 * (or is hidden) depending on the enable_logging setting.
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
	 * Set up admin user before tests that render sections.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create and log in as admin — render_wrapper() cascades into
		// WP_MCP_AI_Section_Performance::render() which requires manage_options.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Test that the General section (not Advanced) has the enable_extended_logging field.
	 *
	 * The field is defined in WP_MCP_AI_Section_General::get_fields().
	 */
	public function test_general_section_has_extended_logging_field() {
		$section = new WP_MCP_AI_Section_General();
		$fields  = $section->get_fields();

		// Check that extended logging field exists.
		$this->assertArrayHasKey( 'enable_extended_logging', $fields );

		// Verify the field configuration.
		$this->assertEquals( 'checkbox', $fields['enable_extended_logging']['type'] );
		$this->assertStringContainsString(
			'may impact site performance',
			$fields['enable_extended_logging']['description']
		);
	}

	/**
	 * Test that the logging table does not render when logging is disabled.
	 *
	 * The render_logging_table() method is called from render() when the
	 * 'logs' subtab is active in the General section.
	 */
	public function test_logging_table_not_rendered_when_logging_disabled() {
		// Set logging to disabled.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_logging' => false )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Set the active subtab to 'logs' via GET parameter.
		$_GET['subtab'] = 'logs';

		$section = new WP_MCP_AI_Section_General();

		// Capture the output.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// The logging table should NOT be present (HTML-encoded ampersand).
		$this->assertStringNotContainsString( 'Recent Error &amp; Activity Log', $output );
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

		// Set the active subtab to 'logs' via GET parameter.
		$_GET['subtab'] = 'logs';

		$section = new WP_MCP_AI_Section_General();

		// Capture the output.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// The logging table SHOULD be present (HTML-encoded ampersand in rendered output).
		$this->assertStringContainsString( 'Recent Error &amp; Activity Log', $output );
	}

	/**
	 * Test that General section has updated description for enable_logging.
	 */
	public function test_general_section_logging_description_updated() {
		$section = new WP_MCP_AI_Section_General();
		$fields  = $section->get_fields();

		// Check that enable_logging field exists.
		$this->assertArrayHasKey( 'enable_logging', $fields );

		// Verify the description mentions viewing logs.
		$this->assertStringContainsString(
			'View logs in the Advanced tab',
			$fields['enable_logging']['description']
		);

		// Verify the description mentions what gets logged.
		$this->assertStringContainsString(
			'errors, warnings, and key activity',
			$fields['enable_logging']['description']
		);
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
		$this->assertStringContainsString(
			'may impact site performance',
			$fields['enable_extended_logging']['description']
		);

		// Should explain what it logs.
		$this->assertStringContainsString(
			'request/response',
			$fields['enable_extended_logging']['description']
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Reset GET parameters.
		$_GET = array();

		// Clear the settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		wp_set_current_user( 0 );

		parent::tearDown();
	}
}
