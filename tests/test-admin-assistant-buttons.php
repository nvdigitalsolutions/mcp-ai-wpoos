<?php
/**
 * Test Admin Assistant Buttons
 *
 * Verifies that the Create Assistant and Create Team buttons
 * are properly rendered without showing phpcs comments.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for admin assistant buttons
 */
class Test_Admin_Assistant_Buttons extends WP_UnitTestCase {

	/**
	 * Test that Create Assistant button doesn't output phpcs comments
	 */
	public function test_create_assistant_button_no_phpcs_comments() {
		// Capture output of add_create_button method.
		ob_start();
		$views  = WP_MCP_AI_Admin_Create_Assistant_Button::add_create_button( array() );
		$output = ob_get_clean();

		// Verify that phpcs:ignore comments are NOT in the output.
		$this->assertStringNotContainsString(
			'// phpcs:ignore',
			$output,
			'Output should not contain phpcs:ignore comments as plain text'
		);

		// Verify that the style tag IS in the output.
		$this->assertStringContainsString(
			'<style>',
			$output,
			'Output should contain style tag'
		);

		// Verify that the script tag IS in the output.
		$this->assertStringContainsString(
			'<script type="text/javascript">',
			$output,
			'Output should contain script tag'
		);

		// Verify that the button class IS in the output.
		$this->assertStringContainsString(
			'wp-mcp-ai-create-assistant-btn',
			$output,
			'Output should contain button class'
		);
	}

	/**
	 * Test that Create Team button doesn't output phpcs comments
	 */
	public function test_create_team_button_no_phpcs_comments() {
		// Capture output of add_create_button method.
		ob_start();
		$views  = WP_MCP_AI_Admin_Create_Team_Button::add_create_button( array() );
		$output = ob_get_clean();

		// Verify that phpcs:ignore comments are NOT in the output.
		$this->assertStringNotContainsString(
			'// phpcs:ignore',
			$output,
			'Output should not contain phpcs:ignore comments as plain text'
		);

		// Verify that the style tag IS in the output.
		$this->assertStringContainsString(
			'<style>',
			$output,
			'Output should contain style tag'
		);

		// Verify that the script tag IS in the output.
		$this->assertStringContainsString(
			'<script type="text/javascript">',
			$output,
			'Output should contain script tag'
		);

		// Verify that the button class IS in the output.
		$this->assertStringContainsString(
			'wp-mcp-ai-create-team-btn',
			$output,
			'Output should contain button class'
		);
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
