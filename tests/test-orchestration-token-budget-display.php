<?php
/**
 * Test Token Budget Display Improvements
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for token budget display improvements.
 */
class Test_Orchestration_Token_Budget_Display extends WP_UnitTestCase {

	/**
	 * Test that token budget explanation renderer returns valid HTML.
	 */
	public function test_token_budget_explanation_renders_successfully() {
		// Arrange.
		$max_tokens = 16000;

		// Act.
		$output = WP_MCP_AI_Orchestration_Renderer::render_token_budget_explanation( $max_tokens );

		// Assert.
		$this->assertIsString( $output );
		$this->assertStringContainsString( 'wp-mcp-ai-token-budget-explanation', $output );
		$this->assertStringContainsString( 'Understanding Your Token Budget', $output );
		$this->assertStringContainsString( number_format( $max_tokens ), $output );
	}

	/**
	 * Test that token budget explanation includes all required components.
	 */
	public function test_token_budget_explanation_includes_all_components() {
		// Arrange.
		$max_tokens = 8000;

		// Act.
		$output = WP_MCP_AI_Orchestration_Renderer::render_token_budget_explanation( $max_tokens );

		// Assert - check for all component mentions.
		$this->assertStringContainsString( 'System Prompt', $output );
		$this->assertStringContainsString( 'Conversation History', $output );
		$this->assertStringContainsString( 'User Input', $output );
		$this->assertStringContainsString( 'Tool/API Data', $output );
		$this->assertStringContainsString( 'AI Output', $output );
	}

	/**
	 * Test that token budget explanation handles edge cases.
	 */
	public function test_token_budget_explanation_handles_edge_cases() {
		// Test with low token count.
		$output_low = WP_MCP_AI_Orchestration_Renderer::render_token_budget_explanation( 1000 );
		$this->assertIsString( $output_low );
		$this->assertStringContainsString( '1,000', $output_low );

		// Test with high token count.
		$output_high = WP_MCP_AI_Orchestration_Renderer::render_token_budget_explanation( 128000 );
		$this->assertIsString( $output_high );
		$this->assertStringContainsString( '128,000', $output_high );
	}

	/**
	 * Test that invalid token values return fallback message.
	 */
	public function test_token_budget_explanation_handles_invalid_input() {
		// Test with zero tokens.
		$output_zero = WP_MCP_AI_Orchestration_Renderer::render_token_budget_explanation( 0 );
		$this->assertIsString( $output_zero );
		$this->assertStringContainsString( 'temporarily unavailable', $output_zero );

		// Test with negative tokens.
		$output_negative = WP_MCP_AI_Orchestration_Renderer::render_token_budget_explanation( -100 );
		$this->assertIsString( $output_negative );
		$this->assertStringContainsString( 'temporarily unavailable', $output_negative );
	}

	/**
	 * Test that the stats card label is updated to show Context Window.
	 */
	public function test_stats_card_shows_context_window_label() {
		// This is an integration test - we need to check the orchestration section output.
		$section = new WP_MCP_AI_Section_Orchestration();

		// Get the stats content using reflection since it's a private method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_stats_content' );
		$method->setAccessible( true );

		// Act.
		$output = $method->invoke( $section );

		// Assert.
		$this->assertIsString( $output );
		$this->assertStringContainsString( 'Context Window (Max Tokens)', $output );
		$this->assertStringContainsString( 'Total Budget Per Request', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-stats-card--context-window', $output );
	}

	/**
	 * Test that CSS is included for proper styling.
	 */
	public function test_token_budget_explanation_includes_css() {
		// Arrange.
		$max_tokens = 16000;

		// The renderer attaches its CSS via wp_add_inline_style() on this
		// handle; production enqueues the style, so register it here so the
		// inline style data is observable.
		wp_register_style( 'wp-mcp-ai-orchestration-renderer', false, array(), WP_MCP_AI_VERSION );

		// Act.
		$output = WP_MCP_AI_Orchestration_Renderer::render_token_budget_explanation( $max_tokens );

		// Assert - the panel markup is present, and the styling is registered
		// as an inline style on the orchestration renderer handle rather than
		// embedded as a <style> tag in the returned HTML.
		$this->assertStringContainsString( 'wp-mcp-ai-token-budget-explanation', $output );

		$styles = wp_styles();
		$inline = isset( $styles->registered['wp-mcp-ai-orchestration-renderer']->extra['after'] )
			? implode( "\n", (array) $styles->registered['wp-mcp-ai-orchestration-renderer']->extra['after'] )
			: '';
		$this->assertStringContainsString( '.wp-mcp-ai-token-budget-explanation', $inline );
		$this->assertStringContainsString( '.wp-mcp-ai-budget-components', $inline );
	}

	/**
	 * Test that field labels were updated to reflect context window terminology.
	 */
	public function test_field_labels_use_context_window_terminology() {
		// Arrange.
		$section = new WP_MCP_AI_Section_Orchestration();
		$fields  = $section->get_fields();

		// Assert - check section header.
		$this->assertArrayHasKey( 'slider_section_tokens', $fields );
		$this->assertStringContainsString( 'Context Window Limits', $fields['slider_section_tokens']['content'] );
		$this->assertStringContainsString( 'total token budget per request', $fields['slider_section_tokens']['content'] );

		// Assert - check individual field labels.
		$this->assertEquals( 'Low Tier Context Window', $fields['low_tier_max_tokens']['label'] );
		$this->assertEquals( 'Medium Tier Context Window', $fields['medium_tier_max_tokens']['label'] );
		$this->assertEquals( 'High Tier Context Window', $fields['high_tier_max_tokens']['label'] );

		// Assert - check field descriptions mention "Total context window".
		$this->assertStringContainsString( 'Total context window', $fields['low_tier_max_tokens']['description'] );
		$this->assertStringContainsString( 'all input and output tokens', $fields['low_tier_max_tokens']['description'] );
	}

	/**
	 * Test that link to Token Manager is included.
	 */
	public function test_token_budget_explanation_includes_token_manager_link() {
		// Arrange.
		$max_tokens = 16000;

		// Act.
		$output = WP_MCP_AI_Orchestration_Renderer::render_token_budget_explanation( $max_tokens );

		// Assert - esc_url() encodes the ampersand in the query string, so
		// assert the ampersand-free parts of the link instead of the raw URL.
		$this->assertStringContainsString( 'Token Manager', $output );
		$this->assertStringContainsString( 'admin.php?page=wp-mcp-ai-dashboard', $output );
		$this->assertStringContainsString( 'tab=token_manager', $output );
	}

	/**
	 * Test that important notice is displayed.
	 */
	public function test_token_budget_explanation_shows_important_notice() {
		// Arrange.
		$max_tokens = 16000;

		// Act.
		$output = WP_MCP_AI_Orchestration_Renderer::render_token_budget_explanation( $max_tokens );

		// Assert.
		$this->assertStringContainsString( 'Important:', $output );
		$this->assertStringContainsString( 'orchestration layer manages this budget automatically', $output );
		$this->assertStringContainsString( 'notice notice-info', $output );
	}
}
