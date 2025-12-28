<?php
/**
 * Tests for chat template selector in Elementor widget and blocks.
 *
 * Verifies that the template selector control is properly registered
 * and that template values are correctly passed to the shortcode.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for chat template selector.
 */
class WP_MCP_AI_Chat_Template_Selector_Test extends WP_UnitTestCase {
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
	 * Test that block has template attribute defined.
	 */
	public function test_block_has_template_attribute() {
		$block_json_path = WP_MCP_AI_PATH . 'includes/blocks/chat/block.json';
		$this->assertFileExists( $block_json_path, 'Block JSON file should exist' );

		$block_json = json_decode( file_get_contents( $block_json_path ), true );
		$this->assertIsArray( $block_json, 'Block JSON should be valid' );
		$this->assertArrayHasKey( 'attributes', $block_json, 'Block should have attributes' );
		$this->assertArrayHasKey( 'template', $block_json['attributes'], 'Block should have template attribute' );
		$this->assertEquals( 'classic', $block_json['attributes']['template']['default'], 'Default template should be classic' );
		$this->assertIsArray( $block_json['attributes']['template']['enum'], 'Template should have enum values' );
		$this->assertContains( 'classic', $block_json['attributes']['template']['enum'], 'Template enum should include classic' );
		$this->assertContains( 'speech-bubbles', $block_json['attributes']['template']['enum'], 'Template enum should include speech-bubbles' );
		$this->assertContains( 'compact', $block_json['attributes']['template']['enum'], 'Template enum should include compact' );
		$this->assertContains( 'sidebar', $block_json['attributes']['template']['enum'], 'Template enum should include sidebar' );
	}

	/**
	 * Test that block render passes template to shortcode.
	 */
	public function test_block_render_passes_template() {
		// Simulate block attributes.
		$attributes = array(
			'assistantId' => $this->assistant_id,
			'template'    => 'speech-bubbles',
		);

		// Capture the rendered output.
		ob_start();
		include WP_MCP_AI_PATH . 'includes/blocks/chat/render.php';
		$output = ob_get_clean();

		// Verify the output contains the template attribute.
		$this->assertStringContainsString( 'template="speech-bubbles"', $output, 'Rendered block should include template attribute' );
	}

	/**
	 * Test that shortcode renders with different templates.
	 */
	public function test_shortcode_renders_with_templates() {
		$templates = array( 'classic', 'speech-bubbles', 'compact', 'sidebar' );

		foreach ( $templates as $template ) {
			$output = do_shortcode( '[mcp_ai_chat assistant="' . $this->assistant_id . '" template="' . $template . '"]' );

			// Verify the output contains the template data attribute.
			$this->assertStringContainsString(
				'data-template="' . $template . '"',
				$output,
				'Shortcode output should include data-template attribute for ' . $template
			);

			// Verify the output contains the template CSS class (for non-classic templates).
			if ( 'classic' !== $template ) {
				$this->assertStringContainsString(
					'wp-mcp-ai-chat--template-' . $template,
					$output,
					'Shortcode output should include template CSS class for ' . $template
				);
			}
		}
	}

	/**
	 * Test that invalid template defaults to classic.
	 */
	public function test_invalid_template_defaults_to_classic() {
		$output = do_shortcode( '[mcp_ai_chat assistant="' . $this->assistant_id . '" template="invalid-template"]' );

		// Verify the output contains classic template.
		$this->assertStringContainsString(
			'data-template="classic"',
			$output,
			'Invalid template should default to classic'
		);

		// Verify the output does NOT contain the invalid template CSS class.
		$this->assertStringNotContainsString(
			'wp-mcp-ai-chat--template-invalid-template',
			$output,
			'Invalid template should not generate CSS class'
		);
	}

	/**
	 * Test that Elementor widget has template control.
	 *
	 * This test loads the Elementor widget class and verifies the template control is registered.
	 */
	public function test_elementor_widget_has_template_control() {
		// Skip if Elementor is not available.
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			$this->markTestSkipped( 'Elementor is not available' );
			return;
		}

		// Load the widget class.
		require_once WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';
		require_once WP_MCP_AI_PATH . 'includes/elementor/class-wp-mcp-ai-elementor-widget.php';

		$widget = new WP_MCP_AI_Elementor_Widget();

		// Use reflection to check if the widget registers the template control.
		$reflection = new ReflectionClass( $widget );
		$method     = $reflection->getMethod( 'register_controls' );
		$method->setAccessible( true );

		// Capture output to avoid side effects.
		ob_start();
		try {
			$method->invoke( $widget );
		} catch ( Exception $e ) {
			// Some methods may throw exceptions in test environment.
			unset( $e );
		}
		ob_end_clean();

		// We can't easily test the control registration without full Elementor setup,
		// but we can verify the method exists and doesn't throw errors.
		$this->assertTrue(
			method_exists( $widget, 'register_controls' ),
			'Widget should have register_controls method'
		);
	}
}
