<?php
/**
 * Tests for Schedule Manager Section Logging Table Display.
 *
 * Verifies that the Recent Error & Activity Log and Recent Activity Log
 * sections render (or are hidden) on the Pro Schedule Manager page depending
 * on the enable_logging setting.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// Guard: only run if Pro addon is present.
if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-schedule-manager.php';

/**
 * Test Schedule Manager Section Logging functionality.
 */
class Test_Schedule_Manager_Section_Logging extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure an admin user is set so capability checks pass.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Test that the logging table does NOT render when logging is disabled.
	 */
	public function test_logging_table_not_rendered_when_logging_disabled() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_logging' => false )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$section = new WP_MCP_AI_Section_Schedule_Manager();

		ob_start();
		$section->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'wp-mcp-ai-error-log-section', $output );
		$this->assertStringNotContainsString( 'wp-mcp-ai-activity-log-section', $output );
	}

	/**
	 * Test that the logging table renders when logging is enabled.
	 */
	public function test_logging_table_renders_when_logging_enabled() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_logging' => true )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$section = new WP_MCP_AI_Section_Schedule_Manager();

		ob_start();
		$section->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wp-mcp-ai-error-log-section', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-activity-log-section', $output );
	}

	/**
	 * Test that the logging table shows the empty state message when no errors exist.
	 */
	public function test_logging_table_shows_empty_state() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_logging' => true )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Ensure no error entries exist.
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );

		$section = new WP_MCP_AI_Section_Schedule_Manager();

		ob_start();
		$section->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No schedule-related error or warning messages have been recorded yet.', $output );
		$this->assertStringContainsString( 'No schedule activity has been recorded yet.', $output );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
	}
}
