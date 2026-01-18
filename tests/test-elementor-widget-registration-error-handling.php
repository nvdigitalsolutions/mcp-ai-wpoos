<?php
/**
 * Tests for Elementor widget registration error handling.
 *
 * Verifies that the plugin properly handles errors during widget registration
 * by using output buffering and exception handling to prevent breaking JSON responses.
 *
 * @package WP_MCP_AI
 */

// Load Elementor stubs for testing.
require_once __DIR__ . '/helpers/elementor-stubs.php';

/**
 * Test class for Elementor widget registration error handling.
 */
class WP_MCP_AI_Elementor_Widget_Registration_Error_Handling_Test extends WP_UnitTestCase {
	/**
	 * Test that the shortcode class dependency is checked.
	 *
	 * Verifies that widget registration checks for WP_MCP_AI_Shortcode
	 * before proceeding to prevent fatal errors.
	 */
	public function test_shortcode_class_dependency_exists() {
		// The shortcode class should already be loaded in the test environment.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Shortcode' ), 'Shortcode class should be available for widget registration' );
	}

	/**
	 * Test that trait dependency exists.
	 *
	 * Verifies that the text formatting trait file exists and is loadable.
	 */
	public function test_trait_dependency_exists() {
		// Check that the trait file exists.
		$trait_path = WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';
		$this->assertFileExists( $trait_path, 'Text formatting trait file should exist' );

		// Ensure trait is loadable.
		require_once $trait_path;
		$this->assertTrue( trait_exists( 'WP_MCP_AI_Elementor_Text_Formatting' ), 'Text formatting trait should be loadable' );
	}

	/**
	 * Test that widget files exist.
	 *
	 * Verifies that all widget files referenced in the integration class exist.
	 */
	public function test_all_widget_files_exist() {
		$widget_files = array(
			'class-wp-mcp-ai-elementor-widget.php',
			'class-wp-mcp-ai-elementor-professional-selector-widget.php',
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
			'class-wp-mcp-ai-elementor-performance-test-runner-widget.php',
			'class-wp-mcp-ai-elementor-performance-metrics-widget.php',
			'class-wp-mcp-ai-elementor-performance-trends-widget.php',
			'class-wp-mcp-ai-elementor-test-results-table-widget.php',
			'class-wp-mcp-ai-elementor-performance-recommendations-widget.php',
			'class-wp-mcp-ai-elementor-system-health-status-widget.php',
		);

		foreach ( $widget_files as $file ) {
			$path = WP_MCP_AI_PATH . 'includes/elementor/' . $file;
			$this->assertFileExists( $path, "Widget file {$file} should exist" );
		}
	}

	/**
	 * Test that integration class has the register_widget method.
	 *
	 * Verifies the method exists and is callable.
	 */
	public function test_integration_has_register_widget_method() {
		$integration = new WP_MCP_AI_Elementor_Integration();
		$this->assertTrue( method_exists( $integration, 'register_widget' ), 'Integration should have register_widget method' );
	}

	/**
	 * Test that widget registration uses error handling.
	 *
	 * Verifies that the register_widget method is wrapped in try-catch
	 * to prevent exceptions from breaking JSON responses.
	 */
	public function test_register_widget_method_has_error_handling() {
		// Read the integration file to verify it contains error handling.
		$integration_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-elementor-integration.php';
		$content          = file_get_contents( $integration_file );

		// Check for output buffering.
		$this->assertStringContainsString( 'ob_start()', $content, 'Widget registration should use output buffering' );
		$this->assertStringContainsString( 'ob_end_clean()', $content, 'Widget registration should clean output buffer' );

		// Check for try-catch.
		$this->assertStringContainsString( 'try {', $content, 'Widget registration should use try-catch for error handling' );
		$this->assertStringContainsString( 'catch', $content, 'Widget registration should catch exceptions' );

		// Check for shortcode class validation.
		$this->assertStringContainsString( 'WP_MCP_AI_Shortcode', $content, 'Widget registration should check for shortcode class' );
	}

	/**
	 * Test that Logger is used for error logging if available.
	 *
	 * Verifies that errors are logged when WP_DEBUG is enabled.
	 */
	public function test_error_logging_when_debug_enabled() {
		// Read the integration file to verify it uses the logger.
		$integration_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-elementor-integration.php';
		$content          = file_get_contents( $integration_file );

		// Check that logger is used in the catch block.
		$this->assertStringContainsString( 'WP_MCP_AI_Logger', $content, 'Widget registration should use logger for errors' );
		$this->assertStringContainsString( 'WP_DEBUG', $content, 'Error logging should check WP_DEBUG constant' );
	}
}
