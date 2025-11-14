<?php
/**
 * Tests for Voice Conversation Widget asset loading and JavaScript initialization.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Voice Conversation Widget.
 */
class WP_MCP_AI_Voice_Conversation_Widget_Test extends WP_UnitTestCase {
	/**
	 * Test that the widget class file exists.
	 */
	public function test_widget_file_exists() {
		$widget_path = WP_MCP_AI_PATH . 'includes/elementor/class-wp-mcp-ai-elementor-voice-conversation-button-widget.php';
		$this->assertFileExists( $widget_path, 'Voice conversation widget file should exist' );
	}

	/**
	 * Test that the JavaScript file exists.
	 */
	public function test_javascript_file_exists() {
		$js_path = WP_MCP_AI_PATH . 'assets/js/voice-conversation.js';
		$this->assertFileExists( $js_path, 'Voice conversation JavaScript file should exist' );
	}

	/**
	 * Test that the CSS file exists.
	 */
	public function test_css_file_exists() {
		$css_path = WP_MCP_AI_PATH . 'assets/css/voice-conversation.css';
		$this->assertFileExists( $css_path, 'Voice conversation CSS file should exist' );
	}

	/**
	 * Test that the asset manager class exists.
	 */
	public function test_asset_manager_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Voice_Conversation_Assets' ), 'Voice conversation asset manager class should exist' );
	}

	/**
	 * Test that assets are registered with correct handles.
	 */
	public function test_assets_are_registered() {
		// Trigger asset registration.
		do_action( 'init' );

		// Check if script is registered.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-voice-conversation', 'registered' ), 'Voice conversation script should be registered' );

		// Check if style is registered.
		$this->assertTrue( wp_style_is( 'wp-mcp-ai-voice-conversation', 'registered' ), 'Voice conversation style should be registered' );
	}

	/**
	 * Test that script localization data is available.
	 */
	public function test_script_localization() {
		global $wp_scripts;

		// Trigger asset registration.
		do_action( 'init' );

		// Check if script has localized data.
		$this->assertArrayHasKey( 'wp-mcp-ai-voice-conversation', $wp_scripts->registered, 'Script should be registered' );

		$script = $wp_scripts->registered['wp-mcp-ai-voice-conversation'];
		$this->assertNotEmpty( $script->extra, 'Script should have extra data' );
		$this->assertArrayHasKey( 'data', $script->extra, 'Script should have localized data' );

		// Check that wpMcpAiVoice object is localized.
		$localized_data = $script->extra['data'];
		$this->assertStringContainsString( 'wpMcpAiVoice', $localized_data, 'Script should have wpMcpAiVoice localized object' );
		$this->assertStringContainsString( 'apiUrl', $localized_data, 'Localized data should include apiUrl' );
		$this->assertStringContainsString( 'nonce', $localized_data, 'Localized data should include nonce' );
	}

	/**
	 * Test that JavaScript has Elementor editor detection.
	 */
	public function test_javascript_has_editor_detection() {
		$js_path = WP_MCP_AI_PATH . 'assets/js/voice-conversation.js';
		$js_content = file_get_contents( $js_path );

		// Check for Elementor editor detection function.
		$this->assertStringContainsString( 'isElementorEditor', $js_content, 'JavaScript should have Elementor editor detection function' );
		// Check for primary method - elementorFrontend.isEditMode() used in preview iframe
		$this->assertStringContainsString( 'elementorFrontend.isEditMode', $js_content, 'JavaScript should check elementorFrontend.isEditMode()' );
		// Check for fallback - elementor.isEditMode for other editor contexts
		$this->assertStringContainsString( 'elementor.isEditMode', $js_content, 'JavaScript should check elementor.isEditMode as fallback' );
	}

	/**
	 * Test that JavaScript doesn't initialize in Elementor editor.
	 */
	public function test_javascript_skips_editor_initialization() {
		$js_path = WP_MCP_AI_PATH . 'assets/js/voice-conversation.js';
		$js_content = file_get_contents( $js_path );

		// Check that document.ready callback checks for editor mode.
		$this->assertStringContainsString( '! isElementorEditor()', $js_content, 'JavaScript should skip initialization in editor mode' );
	}

	/**
	 * Test that JavaScript uses widget-specific Elementor hook.
	 */
	public function test_javascript_uses_specific_elementor_hook() {
		$js_path = WP_MCP_AI_PATH . 'assets/js/voice-conversation.js';
		$js_content = file_get_contents( $js_path );

		// Check for widget-specific hook.
		$this->assertStringContainsString( 'frontend/element_ready/wp_mcp_ai_voice_conversation_button.default', $js_content, 'JavaScript should use widget-specific Elementor hook' );
	}

	/**
	 * Test that JavaScript has API configuration safety check.
	 */
	public function test_javascript_has_api_safety_check() {
		$js_path = WP_MCP_AI_PATH . 'assets/js/voice-conversation.js';
		$js_content = file_get_contents( $js_path );

		// Check for wpMcpAiVoice existence check.
		$this->assertStringContainsString( 'typeof wpMcpAiVoice === \'undefined\'', $js_content, 'JavaScript should check if wpMcpAiVoice exists' );
		$this->assertStringContainsString( 'wpMcpAiVoice.apiUrl', $js_content, 'JavaScript should check for apiUrl' );
		$this->assertStringContainsString( 'API configuration is not available', $js_content, 'JavaScript should have meaningful error message' );
	}

	/**
	 * Test that widget declares script dependencies correctly.
	 */
	public function test_widget_declares_script_dependencies() {
		// Load Elementor widget base if available (in test environment it may not be).
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			$this->markTestSkipped( 'Elementor not available in test environment' );
			return;
		}

		require_once WP_MCP_AI_PATH . 'includes/elementor/class-wp-mcp-ai-elementor-voice-conversation-button-widget.php';

		$widget = new WP_MCP_AI_Elementor_Voice_Conversation_Button_Widget();
		$script_deps = $widget->get_script_depends();
		$style_deps = $widget->get_style_depends();

		$this->assertContains( 'wp-mcp-ai-voice-conversation', $script_deps, 'Widget should declare script dependency' );
		$this->assertContains( 'wp-mcp-ai-voice-conversation', $style_deps, 'Widget should declare style dependency' );
	}

	/**
	 * Test that widget has proper get_name() for Elementor hook.
	 */
	public function test_widget_has_proper_name() {
		// Load Elementor widget base if available.
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			$this->markTestSkipped( 'Elementor not available in test environment' );
			return;
		}

		require_once WP_MCP_AI_PATH . 'includes/elementor/class-wp-mcp-ai-elementor-voice-conversation-button-widget.php';

		$widget = new WP_MCP_AI_Elementor_Voice_Conversation_Button_Widget();
		$widget_name = $widget->get_name();

		$this->assertEquals( 'wp_mcp_ai_voice_conversation_button', $widget_name, 'Widget name should match the Elementor hook used in JavaScript' );
	}
}
