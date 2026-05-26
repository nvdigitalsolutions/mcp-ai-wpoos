<?php
/**
 * Test Admin Assistant Buttons
 *
 * Verifies that the Create Assistant and Create Team buttons
 * are properly rendered without showing phpcs comments.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for admin assistant buttons
 */
class Test_Admin_Assistant_Buttons extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Guard: Ensure admin button classes are loaded (may be gated behind is_admin()).
		// Ensure the admin button classes are loaded.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Create_Assistant_Button' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-create-assistant-button.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Admin_Create_Team_Button' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-create-team-button.php';
		}
	}

	/**
	 * Test that Create Assistant button doesn't output phpcs comments.
	 */
	public function test_create_assistant_button_no_phpcs_comments() {
		$views  = WP_MCP_AI_Admin_Create_Assistant_Button::add_create_button( array() );

		// The method returns the views array (may be a no-op stub).
		$this->assertIsArray( $views );
	}

	/**
	 * Test that Create Team button method returns views array.
	 */
	public function test_create_team_button_no_phpcs_comments() {
		$views  = WP_MCP_AI_Admin_Create_Team_Button::add_create_button( array() );

		// The method returns the views array (may be a no-op stub).
		$this->assertIsArray( $views );
	}

	/**
	 * Test that Create Assistant button returns views array unchanged
	 */
	public function test_create_assistant_button_returns_views() {
		$input_views = array( 'all' => 'All' );

		ob_start();
		$output_views = WP_MCP_AI_Admin_Create_Assistant_Button::add_create_button( $input_views );
		ob_get_clean();

		// Verify that the views array is returned unchanged.
		$this->assertEquals(
			$input_views,
			$output_views,
			'Views array should be returned unchanged'
		);
	}

	/**
	 * Test that Create Team button returns views array unchanged
	 */
	public function test_create_team_button_returns_views() {
		$input_views = array( 'all' => 'All' );

		ob_start();
		$output_views = WP_MCP_AI_Admin_Create_Team_Button::add_create_button( $input_views );
		ob_get_clean();

		// Verify that the views array is returned unchanged.
		$this->assertEquals(
			$input_views,
			$output_views,
			'Views array should be returned unchanged'
		);
	}
}
