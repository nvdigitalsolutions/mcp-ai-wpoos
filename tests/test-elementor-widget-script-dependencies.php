<?php
/**
 * Tests for Elementor chat widget script and style dependencies.
 *
 * Verifies that the chat widget properly declares script and style dependencies
 * for Elementor editor rendering.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Elementor widget script dependencies.
 */
class WP_MCP_AI_Elementor_Widget_Script_Dependencies_Test extends WP_UnitTestCase {
	/**
	 * Test that chat widget has get_script_depends method.
	 */
	public function test_chat_widget_has_get_script_depends_method() {
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Elementor_Widget', 'get_script_depends' ),
			'Chat widget should have get_script_depends method'
		);
	}

	/**
	 * Test that chat widget has get_style_depends method.
	 */
	public function test_chat_widget_has_get_style_depends_method() {
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Elementor_Widget', 'get_style_depends' ),
			'Chat widget should have get_style_depends method'
		);
	}

	/**
	 * Test that get_script_depends returns the chat script handle.
	 */
	public function test_get_script_depends_returns_chat_handle() {
		// Skip if Elementor is not available.
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			$this->markTestSkipped( 'Elementor is not available' );
			return;
		}

		// Create widget instance using reflection to bypass protected constructor.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Elementor_Widget' );
		$widget     = $reflection->newInstanceWithoutConstructor();

		$dependencies = $widget->get_script_depends();

		$this->assertIsArray( $dependencies, 'Script dependencies should be an array' );
		$this->assertContains(
			WP_MCP_AI_Shortcode::SCRIPT_HANDLE,
			$dependencies,
			'Script dependencies should include the chat script handle'
		);
	}

	/**
	 * Test that get_style_depends returns the chat style handle.
	 */
	public function test_get_style_depends_returns_chat_handle() {
		// Skip if Elementor is not available.
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			$this->markTestSkipped( 'Elementor is not available' );
			return;
		}

		// Create widget instance using reflection to bypass protected constructor.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Elementor_Widget' );
		$widget     = $reflection->newInstanceWithoutConstructor();

		$dependencies = $widget->get_style_depends();

		$this->assertIsArray( $dependencies, 'Style dependencies should be an array' );
		$this->assertContains(
			WP_MCP_AI_Shortcode::STYLE_HANDLE,
			$dependencies,
			'Style dependencies should include the chat style handle'
		);
	}

	/**
	 * Test that shortcode registers assets on Elementor hook.
	 */
	public function test_shortcode_registers_assets_on_elementor_hook() {
		// Get the priority of the registered action.
		$priority = has_action( 'elementor/frontend/after_register_scripts', array( WP_MCP_AI_Shortcode::class, 'register_assets' ) );

		$this->assertNotFalse(
			$priority,
			'Shortcode should register assets on elementor/frontend/after_register_scripts hook'
		);
	}

	/**
	 * Test that assets are registered when hook is called.
	 */
	public function test_assets_registered_after_elementor_hook() {
		// Call the hook to ensure assets are registered.
		do_action( 'elementor/frontend/after_register_scripts' );

		// Check that the script is registered.
		$this->assertTrue(
			wp_script_is( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'registered' ),
			'Chat script should be registered after Elementor hook'
		);

		// Check that the style is registered.
		$this->assertTrue(
			wp_style_is( WP_MCP_AI_Shortcode::STYLE_HANDLE, 'registered' ),
			'Chat style should be registered after Elementor hook'
		);
	}

	/**
	 * Test that chat script handle constant is defined correctly.
	 */
	public function test_chat_script_handle_constant_exists() {
		$this->assertTrue(
			defined( 'WP_MCP_AI_Shortcode::SCRIPT_HANDLE' ),
			'Chat script handle constant should be defined'
		);

		$this->assertEquals(
			'wp-mcp-ai-chat',
			WP_MCP_AI_Shortcode::SCRIPT_HANDLE,
			'Chat script handle should be wp-mcp-ai-chat'
		);
	}

	/**
	 * Test that chat style handle constant is defined correctly.
	 */
	public function test_chat_style_handle_constant_exists() {
		$this->assertTrue(
			defined( 'WP_MCP_AI_Shortcode::STYLE_HANDLE' ),
			'Chat style handle constant should be defined'
		);

		$this->assertEquals(
			'wp-mcp-ai-chat',
			WP_MCP_AI_Shortcode::STYLE_HANDLE,
			'Chat style handle should be wp-mcp-ai-chat'
		);
	}

	/**
	 * Test that the pattern matches other widgets like User Chats.
	 */
	public function test_pattern_matches_user_chats_widget() {
		// Skip if Elementor is not available.
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			$this->markTestSkipped( 'Elementor is not available' );
			return;
		}

		// Verify User Chats widget has similar methods.
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Elementor_Dashboard_User_Chats_Widget', 'get_script_depends' ),
			'User Chats widget should have get_script_depends method'
		);

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Elementor_Dashboard_User_Chats_Widget', 'get_style_depends' ),
			'User Chats widget should have get_style_depends method'
		);

		// Verify Chat widget follows the same pattern.
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Elementor_Widget', 'get_script_depends' ),
			'Chat widget should have get_script_depends method like User Chats widget'
		);

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Elementor_Widget', 'get_style_depends' ),
			'Chat widget should have get_style_depends method like User Chats widget'
		);
	}

	/**
	 * Test that the pattern matches chart widgets.
	 */
	public function test_pattern_matches_chart_widgets() {
		// Skip if Elementor is not available.
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			$this->markTestSkipped( 'Elementor is not available' );
			return;
		}

		// Verify Performance Trends widget has similar method.
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Elementor_Performance_Trends_Widget', 'get_script_depends' ),
			'Performance Trends widget should have get_script_depends method'
		);

		// Verify Chat widget follows the same pattern.
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Elementor_Widget', 'get_script_depends' ),
			'Chat widget should have get_script_depends method like chart widgets'
		);
	}
}
