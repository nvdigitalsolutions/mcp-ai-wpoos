<?php
/**
 * Test WordPress Settings API registration.
 *
 * Verifies that the configuration page properly registers settings sections
 * with the WordPress Settings API.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Settings API registration.
 */
class WP_MCP_AI_Settings_API_Registration_Test extends WP_UnitTestCase {

	/**
	 * Test that settings sections are registered with WordPress Settings API.
	 */
	public function test_settings_sections_are_registered() {
		global $wp_settings_sections;

		// Create dashboard instance.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Call register_settings to trigger section registration.
		$dashboard->register_settings();

		// Verify that sections are registered with WordPress.
		$page_slug = WP_MCP_AI_Settings_Dashboard::PAGE_SLUG;
		$this->assertArrayHasKey(
			$page_slug,
			$wp_settings_sections,
			'Settings page should be registered in wp_settings_sections'
		);

		// Get all registered sections for this page.
		$registered_sections = isset( $wp_settings_sections[ $page_slug ] ) ? $wp_settings_sections[ $page_slug ] : array();

		// Verify some key sections are registered.
		$this->assertNotEmpty(
			$registered_sections,
			'Settings sections should not be empty'
		);

		// Check that we have multiple sections registered.
		$this->assertGreaterThan(
			0,
			count( $registered_sections ),
			'Should have at least one section registered'
		);
	}

	/**
	 * Test that the main settings option is registered.
	 */
	public function test_settings_option_is_registered() {
		global $wp_registered_settings;

		// Create dashboard instance.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Call register_settings.
		$dashboard->register_settings();

		// Verify the main settings option is registered.
		$option_name = WP_MCP_AI_Admin_Settings::OPTION_NAME;
		$this->assertArrayHasKey(
			$option_name,
			$wp_registered_settings,
			'Settings option should be registered with WordPress'
		);

		// Verify the registered setting has correct properties.
		$registered = $wp_registered_settings[ $option_name ];
		$this->assertSame(
			'wp_mcp_ai_settings_group',
			$registered['group'],
			'Settings should be in correct group'
		);

		$this->assertSame(
			'array',
			$registered['type'],
			'Settings should be of type array'
		);
	}

	/**
	 * Test that sections have proper IDs and titles.
	 */
	public function test_registered_sections_have_valid_metadata() {
		global $wp_settings_sections;

		// Create dashboard instance.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Call register_settings.
		$dashboard->register_settings();

		// Get registered sections.
		$page_slug           = WP_MCP_AI_Settings_Dashboard::PAGE_SLUG;
		$registered_sections = isset( $wp_settings_sections[ $page_slug ] ) ? $wp_settings_sections[ $page_slug ] : array();

		// Verify each section has an ID and title.
		foreach ( $registered_sections as $section_id => $section_data ) {
			$this->assertNotEmpty(
				$section_id,
				'Section should have a non-empty ID'
			);

			$this->assertArrayHasKey(
				'id',
				$section_data,
				'Section should have an id key'
			);

			$this->assertArrayHasKey(
				'title',
				$section_data,
				'Section should have a title key'
			);

			$this->assertArrayHasKey(
				'callback',
				$section_data,
				'Section should have a callback key'
			);
		}
	}
}
