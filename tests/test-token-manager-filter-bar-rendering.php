<?php
/**
 * Tests for Token Manager filter bar rendering functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test token manager's filter bar rendering to ensure JavaScript is properly included.
 */
class Test_Token_Manager_Filter_Bar_Rendering extends WP_UnitTestCase {

	/**
	 * Test that render_per_tool_view includes the filter bar with script tags.
	 */
	public function test_render_per_tool_view_includes_filter_bar_script() {
		// Create a token manager section instance.
		$token_manager = new WP_MCP_AI_Section_Token_Manager();

		// Use reflection to access the private method.
		$reflection = new ReflectionClass( $token_manager );
		$method     = $reflection->getMethod( 'render_per_tool_view' );
		$method->setAccessible( true );

		// Capture the output.
		ob_start();
		$method->invoke( $token_manager );
		$output = ob_get_clean();

		// Verify the output contains the filter bar elements.
		$this->assertStringContainsString( 'wp-mcp-ai-filter-tools', $output, 'Output should contain filter button' );
		$this->assertStringContainsString( 'tool_search', $output, 'Output should contain search input' );
		$this->assertStringContainsString( 'tool_group', $output, 'Output should contain group select' );

		// Verify the output contains script tags (not stripped by wp_kses_post).
		$this->assertStringContainsString( '<script>', $output, 'Output should contain opening script tag' );
		$this->assertStringContainsString( '</script>', $output, 'Output should contain closing script tag' );

		// Verify the JavaScript functionality is present (check for key identifiers).
		$this->assertStringContainsString( 'wp-mcp-ai-filter-tools', $output, 'Output should reference filter button in JavaScript' );
		$this->assertStringContainsString( 'window.location.href', $output, 'Output should contain navigation code' );
	}

	/**
	 * Test that the filter bar JavaScript is not leaked as plain text.
	 */
	public function test_filter_bar_javascript_not_leaked_as_text() {
		// Create a token manager section instance.
		$token_manager = new WP_MCP_AI_Section_Token_Manager();

		// Use reflection to access the private method.
		$reflection = new ReflectionClass( $token_manager );
		$method     = $reflection->getMethod( 'render_per_tool_view' );
		$method->setAccessible( true );

		// Capture the output.
		ob_start();
		$method->invoke( $token_manager );
		$output = ob_get_clean();

		// Count occurrences of the function keyword to detect code leakage.
		// If script tags are properly formed, "function($)" should appear within script tags.
		// If code is leaked, it might appear outside script tags as well.
		preg_match_all( '/<script>.*?function\s*\(/s', $output, $script_matches );
		preg_match_all( '/function\s*\(/', $output, $all_matches );

		// The function should only appear within script tags, not as leaked text.
		$this->assertGreaterThan( 0, count( $script_matches[0] ), 'Function should appear within script tags' );

		// Strip all script tags and check if function still appears (indicating leak).
		$output_without_scripts = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $output );
		$this->assertStringNotContainsString( 'function($)', $output_without_scripts, 'JavaScript code should not leak outside script tags' );
	}

	/**
	 * Test that filter bar renderer is loaded properly.
	 */
	public function test_filter_bar_renderer_is_loaded() {
		// Create a token manager section instance.
		$token_manager = new WP_MCP_AI_Section_Token_Manager();

		// Use reflection to access the private method.
		$reflection = new ReflectionClass( $token_manager );
		$method     = $reflection->getMethod( 'render_per_tool_view' );
		$method->setAccessible( true );

		// Capture the output (this should trigger the class loading).
		ob_start();
		$method->invoke( $token_manager );
		ob_get_clean();

		// Verify the filter bar renderer class is now loaded.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tools_Filter_Bar_Renderer' ), 'Filter bar renderer class should be loaded' );
	}
}
