<?php
/**
 * Tests for Elementor widget rendering with actual widget instead of placeholder.
 *
 * Verifies that the chat widget renders correctly in Elementor editor mode
 * and displays a preview notice to inform users.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Elementor widget rendering.
 */
class WP_MCP_AI_Elementor_Widget_Rendering_Test extends WP_UnitTestCase {
	/**
	 * Assistant post ID for testing.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Create a test assistant.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		// Delete test assistant.
		if ( $this->assistant_id ) {
			wp_delete_post( $this->assistant_id, true );
		}

		parent::tear_down();
	}

	/**
	 * Test that is_elementor_editor method exists in shortcode class.
	 */
	public function test_is_elementor_editor_method_exists() {
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Shortcode', 'is_elementor_editor' ),
			'is_elementor_editor method should exist in WP_MCP_AI_Shortcode class'
		);
	}

	/**
	 * Test that widget renders in Elementor editor instead of showing placeholder.
	 */
	public function test_widget_renders_in_elementor_editor() {
		// Create user with edit capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Simulate Elementor editor mode.
		$_GET['action'] = 'elementor';

		// Create a mock Elementor instance to satisfy is_elementor_editor check.
		if ( ! class_exists( 'Elementor\Plugin' ) ) {
			// Skip if Elementor is not available.
			$this->markTestSkipped( 'Elementor is not available' );
			return;
		}

		// Render the shortcode.
		$output = do_shortcode( '[mcp_ai_chat assistant="' . $this->assistant_id . '"]' );

		// Verify it's not the old placeholder style.
		$this->assertStringNotContainsString(
			'wp-mcp-ai-chat__editor-placeholder',
			$output,
			'Should not contain old placeholder class'
		);

		// Verify it contains the actual chat widget.
		$this->assertStringContainsString(
			'wp-mcp-ai-chat',
			$output,
			'Should contain actual chat widget'
		);

		// Verify it contains the editor notice.
		$this->assertStringContainsString(
			'wp-mcp-ai-chat__editor-notice',
			$output,
			'Should contain editor preview notice'
		);

		$this->assertStringContainsString(
			'Editor Preview:',
			$output,
			'Should contain "Editor Preview:" text'
		);

		// Clean up.
		unset( $_GET['action'] );
	}

	/**
	 * Test that widget does not show editor notice on frontend.
	 */
	public function test_widget_no_editor_notice_on_frontend() {
		// Create user with edit capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Make sure we're NOT in Elementor editor mode.
		unset( $_GET['action'] );

		// Render the shortcode.
		$output = do_shortcode( '[mcp_ai_chat assistant="' . $this->assistant_id . '"]' );

		// Verify it contains the actual chat widget.
		$this->assertStringContainsString(
			'wp-mcp-ai-chat',
			$output,
			'Should contain actual chat widget'
		);

		// Verify it does NOT contain the editor notice.
		$this->assertStringNotContainsString(
			'wp-mcp-ai-chat__editor-notice',
			$output,
			'Should not contain editor preview notice on frontend'
		);

		$this->assertStringNotContainsString(
			'Editor Preview:',
			$output,
			'Should not contain "Editor Preview:" text on frontend'
		);
	}

	/**
	 * Test that WP_DEBUG fix is still in place.
	 */
	public function test_wp_debug_fix_maintained() {
		// Verify the main plugin class still has the debug suppression methods.
		$this->assertTrue(
			method_exists( 'WP_MCP_AI', 'suppress_debug_in_elementor_ajax' ),
			'suppress_debug_in_elementor_ajax method should still exist'
		);

		$this->assertTrue(
			method_exists( 'WP_MCP_AI', 'disable_auth_check_in_elementor' ),
			'disable_auth_check_in_elementor method should still exist'
		);
	}
}
