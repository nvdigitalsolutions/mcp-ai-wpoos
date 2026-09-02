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
	 * Reset the asset queues before each test.
	 *
	 * The scripts/styles globals persist across tests in a single process,
	 * and WP 6.9 memoizes the all_queued_deps set, so a stale queue would
	 * make the enqueue assertions below unreliable.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_scripts;
		foreach ( (array) $wp_scripts->queue as $handle ) {
			wp_dequeue_script( $handle );
		}
		foreach ( (array) wp_styles()->queue as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	/**
	 * Test that the Create Assistant views filter renders no inline markup.
	 *
	 * Inline styles/scripts were converted to proper asset enqueuing; the
	 * filter must stay a pure pass-through and must not leak phpcs:ignore
	 * comments into the page output.
	 */
	public function test_create_assistant_button_no_phpcs_comments() {
		// Capture output of add_create_button method.
		ob_start();
		$views  = WP_MCP_AI_Admin_Create_Assistant_Button::add_create_button( array() );
		$output = ob_get_clean();

		$this->assertSame( array(), $views );

		// Verify that phpcs:ignore comments are NOT in the output.
		$this->assertStringNotContainsString(
			'// phpcs:ignore',
			$output,
			'Output should not contain phpcs:ignore comments as plain text'
		);

		// The button UI ships as enqueued assets, not inline markup.
		$this->assertStringNotContainsString( '<style>', $output, 'Output should not contain inline styles.' );
		$this->assertStringNotContainsString( '<script', $output, 'Output should not contain inline scripts.' );
	}

	/**
	 * Test that the Create Team views filter renders no inline markup.
	 *
	 * The team modal markup is rendered via admin_footer and its assets are
	 * enqueued; the views filter itself must stay a pure pass-through.
	 */
	public function test_create_team_button_no_phpcs_comments() {
		// Capture output of add_create_button method.
		ob_start();
		$views  = WP_MCP_AI_Admin_Create_Team_Button::add_create_button( array() );
		$output = ob_get_clean();

		$this->assertSame( array(), $views );

		// Verify that phpcs:ignore comments are NOT in the output.
		$this->assertStringNotContainsString(
			'// phpcs:ignore',
			$output,
			'Output should not contain phpcs:ignore comments as plain text'
		);

		// The team UI ships as enqueued assets, not inline markup.
		$this->assertStringNotContainsString( '<style>', $output, 'Output should not contain inline styles.' );
		$this->assertStringNotContainsString( '<script', $output, 'Output should not contain inline scripts.' );
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

	/**
	 * Test that assistant button assets enqueue on the assistant list page.
	 */
	public function test_create_assistant_button_enqueues_assets() {
		$_GET['post_type'] = 'mcp_ai_assistant';

		WP_MCP_AI_Admin_Create_Assistant_Button::enqueue_scripts( 'edit.php' );

		$this->assertTrue( wp_style_is( 'wp-mcp-ai-create-assistant-button', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-create-assistant-button', 'enqueued' ) );

		$script = wp_scripts()->registered['wp-mcp-ai-create-assistant-button'];
		$this->assertStringContainsString( 'wpMcpAiCreateAssistantButton', $script->extra['data'] );

		unset( $_GET['post_type'] );
	}

	/**
	 * Test that assistant button assets skip other admin pages.
	 */
	public function test_create_assistant_button_skips_other_pages() {
		$_GET['post_type'] = 'mcp_ai_assistant';

		WP_MCP_AI_Admin_Create_Assistant_Button::enqueue_scripts( 'post.php' );

		$this->assertFalse( wp_style_is( 'wp-mcp-ai-create-assistant-button', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'wp-mcp-ai-create-assistant-button', 'enqueued' ) );

		unset( $_GET['post_type'] );
	}

	/**
	 * Test that team button assets enqueue on the assistant list page.
	 */
	public function test_create_team_button_enqueues_assets() {
		$_GET['post_type'] = 'mcp_ai_assistant';

		WP_MCP_AI_Admin_Create_Team_Button::enqueue_scripts( 'edit.php' );

		$this->assertTrue( wp_style_is( 'wp-mcp-ai-create-team-button', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wp-mcp-ai-create-team-modal', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-create-team-button', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-create-team-modal', 'enqueued' ) );

		$script = wp_scripts()->registered['wp-mcp-ai-create-team-modal'];
		$this->assertStringContainsString( 'wpMcpAiCreateTeam', $script->extra['data'] );

		unset( $_GET['post_type'] );
	}
}
