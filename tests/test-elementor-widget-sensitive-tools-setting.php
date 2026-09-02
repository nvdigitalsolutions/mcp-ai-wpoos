<?php
/**
 * Tests for Elementor widget allow_sensitive_tools setting.
 *
 * Verifies that the allow_sensitive_tools setting is properly registered
 * and passed through to the shortcode when rendering.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for Elementor widget allow_sensitive_tools setting.
 */
class WP_MCP_AI_Elementor_Widget_Sensitive_Tools_Setting_Test extends WP_UnitTestCase {
	/**
	 * Assistant post ID for testing.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Widget instance for testing.
	 *
	 * @var WP_MCP_AI_Elementor_Widget|null
	 */
	private $widget;

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

		// Create user with edit capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Initialize widget if Elementor is available.
		if ( class_exists( 'WP_MCP_AI_Elementor_Widget' ) ) {
			$this->widget = new WP_MCP_AI_Elementor_Widget();
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		// Delete test assistant.
		if ( $this->assistant_id ) {
			wp_delete_post( $this->assistant_id, true );
		}

		$this->widget = null;

		parent::tear_down();
	}

	/**
	 * Test that allow_sensitive_tools control is registered in the widget.
	 */
	public function test_allow_sensitive_tools_control_exists() {
		if ( ! $this->widget ) {
			$this->markTestSkipped( 'Elementor widget class not available' );
			return;
		}

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->widget );
		$method     = $reflection->getMethod( 'register_controls' );
		$method->setAccessible( true );

		// Register controls.
		$method->invoke( $this->widget );

		// Get the controls.
		$controls = $this->widget->get_controls();

		// Verify allow_sensitive_tools control exists.
		$this->assertArrayHasKey(
			'allow_sensitive_tools',
			$controls,
			'allow_sensitive_tools control should be registered'
		);

		// Verify control properties.
		$control = $controls['allow_sensitive_tools'];
		$this->assertEquals( 'switcher', $control['type'], 'Control should be a switcher' );
		$this->assertEquals( 'yes', $control['return_value'], 'Return value should be "yes"' );
		$this->assertEquals( '', $control['default'], 'Default value should be empty' );
	}

	/**
	 * Test that allow_sensitive_tools is passed to shortcode when disabled (default).
	 */
	public function test_allow_sensitive_tools_disabled_by_default() {
		// Render the shortcode with default settings (allow_sensitive_tools = false).
		$output = do_shortcode( '[mcp_ai_chat assistant="' . $this->assistant_id . '"]' );

		// The shortcode should NOT contain allow_sensitive_tools="true" when it's false/default.
		// By default, the shortcode doesn't include the attribute when it's false.
		$this->assertStringNotContainsString(
			'allow_sensitive_tools="true"',
			$output,
			'Shortcode should not contain allow_sensitive_tools="true" when disabled'
		);
	}

	/**
	 * Test that allow_sensitive_tools is passed to shortcode when enabled.
	 */
	public function test_allow_sensitive_tools_enabled_passed_to_shortcode() {
		// Render the shortcode with allow_sensitive_tools enabled.
		$output = do_shortcode( '[mcp_ai_chat assistant="' . $this->assistant_id . '" allow_sensitive_tools="true"]' );

		// Verify the shortcode was rendered (not an error message).
		$this->assertStringContainsString(
			'wp-mcp-ai-chat',
			$output,
			'Shortcode should render successfully'
		);

		// Verify it's not a permission error.
		$this->assertStringNotContainsString(
			'You do not have permission',
			$output,
			'Should not show permission error'
		);
	}

	/**
	 * Test that shortcode properly handles allow_sensitive_tools attribute.
	 */
	public function test_shortcode_accepts_allow_sensitive_tools_attribute() {
		// Test with allow_sensitive_tools="true".
		$output_enabled = do_shortcode( '[mcp_ai_chat assistant="' . $this->assistant_id . '" allow_sensitive_tools="true"]' );
		$this->assertStringContainsString(
			'wp-mcp-ai-chat',
			$output_enabled,
			'Shortcode should accept allow_sensitive_tools="true"'
		);

		// Test with allow_sensitive_tools="false".
		$output_disabled = do_shortcode( '[mcp_ai_chat assistant="' . $this->assistant_id . '" allow_sensitive_tools="false"]' );
		$this->assertStringContainsString(
			'wp-mcp-ai-chat',
			$output_disabled,
			'Shortcode should accept allow_sensitive_tools="false"'
		);

		// Both should render successfully.
		$this->assertNotEmpty( $output_enabled, 'Enabled output should not be empty' );
		$this->assertNotEmpty( $output_disabled, 'Disabled output should not be empty' );
	}

	/**
	 * Test that the widget render method includes allow_sensitive_tools in attributes.
	 */
	public function test_widget_render_includes_allow_sensitive_tools() {
		if ( ! $this->widget ) {
			$this->markTestSkipped( 'Elementor widget class not available' );
			return;
		}

		// Widget settings with allow_sensitive_tools enabled. Elementor 4.x
		// removed the Controls_Stack::$settings property, so drive the
		// settings through a partial mock of get_settings_for_display().
		$mock_settings = array(
			'assistant'             => (string) $this->assistant_id,
			'allow_guests'          => 'false',
			'save_transcript'       => 'true',
			'enable_streaming'      => 'false',
			'allow_sensitive_tools' => 'yes',
		);

		$widget = $this->getMockBuilder( WP_MCP_AI_Elementor_Widget::class )
			->onlyMethods( array( 'get_settings_for_display' ) )
			->getMock();
		$widget->method( 'get_settings_for_display' )->willReturn( $mock_settings );

		// render() is protected; invoke via reflection.
		$reflection = new ReflectionClass( $widget );
		$method     = $reflection->getMethod( 'render' );
		$method->setAccessible( true );

		// Start output buffering to capture render output.
		ob_start();
		$method->invoke( $widget );
		$output = ob_get_clean();

		// The widget must render a chat surface.
		$this->assertStringContainsString( 'wp-mcp-ai-chat', $output );

		// The per-instance config is attached via wp_add_inline_script() on
		// the chat script handle — assert the enabled flag made it into the
		// inline config data.
		global $wp_scripts;
		$handle = WP_MCP_AI_Shortcode::SCRIPT_HANDLE;
		$this->assertTrue( wp_script_is( $handle, 'registered' ), 'Chat script should be registered' );

		$inline = isset( $wp_scripts->registered[ $handle ]->extra['before'] )
			? implode( "\n", (array) $wp_scripts->registered[ $handle ]->extra['before'] )
			: '';
		$this->assertStringContainsString(
			'allowSensitiveTools',
			$inline,
			'Inline config should include allowSensitiveTools when enabled'
		);
	}
}
