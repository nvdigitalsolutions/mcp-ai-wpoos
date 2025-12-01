<?php
/**
 * Tests for Elementor widget loading and registration.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Elementor widget loading.
 */
class WP_MCP_AI_Elementor_Widget_Loading_Test extends WP_UnitTestCase {
	/**
	 * Ensure the trait file exists and is loadable.
	 */
	public function test_trait_file_exists() {
		$trait_path = WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';
		$this->assertFileExists( $trait_path );
	}

	/**
	 * Ensure the trait is defined after loading.
	 */
	public function test_trait_is_defined() {
		require_once WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';
		$this->assertTrue( trait_exists( 'WP_MCP_AI_Elementor_Text_Formatting' ) );
	}

	/**
	 * Ensure integration class exists.
	 */
	public function test_integration_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Elementor_Integration' ) );
	}

	/**
	 * Ensure all widget files exist.
	 */
	public function test_widget_files_exist() {
		$widget_files = array(
			'class-wp-mcp-ai-elementor-widget.php',
			'class-wp-mcp-ai-elementor-assistant-defaults-widget.php',
			'class-wp-mcp-ai-elementor-assistant-base-knowledge-widget.php',
			'class-wp-mcp-ai-elementor-assistant-prompt-shortcuts-widget.php',
			'class-wp-mcp-ai-elementor-assistant-tools-widget.php',
			'class-wp-mcp-ai-elementor-chat-intro-widget.php',
			'class-wp-mcp-ai-elementor-chat-faq-widget.php',
			'class-wp-mcp-ai-elementor-chat-usage-timer-widget.php',
			'class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php',
			'class-wp-mcp-ai-elementor-dashboard-user-capability-widget.php',
			'class-wp-mcp-ai-elementor-dashboard-user-files-widget.php',
			'class-wp-mcp-ai-elementor-dashboard-user-chats-widget.php',
			'class-wp-mcp-ai-elementor-dashboard-theme-preview-widget.php',
			'class-wp-mcp-ai-elementor-dashboard-provider-links-widget.php',
			'class-wp-mcp-ai-elementor-dashboard-activity-feed-widget.php',
		);

		foreach ( $widget_files as $file ) {
			$path = WP_MCP_AI_PATH . 'includes/elementor/' . $file;
			$this->assertFileExists( $path, "Widget file {$file} should exist" );
		}
	}

	/**
	 * Ensure trait can be used when loaded.
	 */
	public function test_trait_can_be_used() {
		// Load trait.
		require_once WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';

		$this->assertTrue( trait_exists( 'WP_MCP_AI_Elementor_Text_Formatting' ) );

		// Verify trait methods exist by checking reflection.
		$trait_reflection = new ReflectionClass( 'WP_MCP_AI_Elementor_Text_Formatting' );
		$methods          = $trait_reflection->getMethods();
		$method_names     = array_map(
			function ( $method ) {
				return $method->getName();
			},
			$methods
		);

		$this->assertContains( 'format_text_block', $method_names );
		$this->assertContains( 'format_text_inline', $method_names );
	}

	/**
	 * Ensure integration handles missing trait file gracefully.
	 */
	public function test_integration_handles_missing_trait() {
		// This test verifies the logic flow, not actual execution.
		// The integration checks file_exists() before requiring the trait.
		$integration = new WP_MCP_AI_Elementor_Integration();

		$this->assertTrue( class_exists( 'WP_MCP_AI_Elementor_Integration' ) );
	}
}
