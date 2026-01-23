<?php
/**
 * Test preset settings preview rendering
 *
 * @package WP_MCP_AI
 */

/**
 * Test preset settings preview in preset cards
 */
class Test_Preset_Settings_Preview extends WP_UnitTestCase {

	/**
	 * Test that preset cards include settings preview section
	 */
	public function test_preset_cards_include_settings_preview() {
		// Get presets.
		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
		
		// Render the preset selector.
		$html = WP_MCP_AI_Orchestration_Renderer::render_presets_selector( $presets );
		
		// Assert that the HTML includes the settings preview container.
		$this->assertStringContainsString( 'preset-settings-preview', $html, 'Preset cards should include settings preview container' );
		
		// Assert that the HTML includes setting items.
		$this->assertStringContainsString( 'preset-setting-item', $html, 'Settings preview should include setting items' );
		
		// Assert that the HTML includes setting labels.
		$this->assertStringContainsString( 'preset-setting-label', $html, 'Settings should include labels' );
		
		// Assert that the HTML includes setting values.
		$this->assertStringContainsString( 'preset-setting-value', $html, 'Settings should include values' );
	}

	/**
	 * Test that balanced preset shows expected token values
	 */
	public function test_balanced_preset_shows_correct_tokens() {
		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
		$html    = WP_MCP_AI_Orchestration_Renderer::render_presets_selector( $presets );
		
		// Balanced preset should show 32,000 tokens for high tier.
		$this->assertStringContainsString( '32,000', $html, 'Balanced preset should show 32,000 token limit' );
		
		// Should show per-call limit.
		$this->assertStringContainsString( '10,000', $html, 'Balanced preset should show 10,000 per-call limit' );
		
		// Should show memory threshold.
		$this->assertStringContainsString( '85%', $html, 'Balanced preset should show 85% memory threshold' );
	}

	/**
	 * Test that conservative preset shows lower token values
	 */
	public function test_conservative_preset_shows_correct_tokens() {
		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
		$html    = WP_MCP_AI_Orchestration_Renderer::render_presets_selector( $presets );
		
		// Conservative preset should show 16,000 tokens for high tier.
		$this->assertStringContainsString( '16,000', $html, 'Conservative preset should show 16,000 token limit' );
		
		// Should show per-call limit.
		$this->assertStringContainsString( '5,000', $html, 'Conservative preset should show 5,000 per-call limit' );
		
		// Should show memory threshold.
		$this->assertStringContainsString( '75%', $html, 'Conservative preset should show 75% memory threshold' );
	}

	/**
	 * Test that performance preset shows higher token values
	 */
	public function test_performance_preset_shows_correct_tokens() {
		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
		$html    = WP_MCP_AI_Orchestration_Renderer::render_presets_selector( $presets );
		
		// Performance preset should show 64,000 tokens for high tier.
		$this->assertStringContainsString( '64,000', $html, 'Performance preset should show 64,000 token limit' );
		
		// Should show per-call limit.
		$this->assertStringContainsString( '25,000', $html, 'Performance preset should show 25,000 per-call limit' );
		
		// Should show memory threshold.
		$this->assertStringContainsString( '90%', $html, 'Performance preset should show 90% memory threshold' );
	}

	/**
	 * Test that settings preview includes proper icons
	 */
	public function test_settings_preview_includes_icons() {
		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
		$html    = WP_MCP_AI_Orchestration_Renderer::render_presets_selector( $presets );
		
		// Should include dashicons for visual indicators.
		$this->assertStringContainsString( 'dashicons-chart-bar', $html, 'Should include chart-bar icon for context window' );
		$this->assertStringContainsString( 'dashicons-admin-tools', $html, 'Should include tools icon for per-call limit' );
		$this->assertStringContainsString( 'dashicons-warning', $html, 'Should include warning icon for memory threshold' );
	}

	/**
	 * Test that settings preview is properly escaped
	 */
	public function test_settings_preview_properly_escaped() {
		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
		$html    = WP_MCP_AI_Orchestration_Renderer::render_presets_selector( $presets );
		
		// Check that numbers are formatted and escaped properly.
		$this->assertStringNotContainsString( '<script>', $html, 'Output should not contain unescaped script tags' );
		$this->assertStringNotContainsString( 'javascript:', $html, 'Output should not contain javascript: protocol' );
		
		// Should use esc_html for numbers.
		$this->assertMatchesRegularExpression( '/[\d,]+\s+tokens/', $html, 'Token values should be properly formatted with commas' );
	}

	/**
	 * Test that custom preset doesn't show settings preview
	 */
	public function test_custom_preset_no_settings_preview() {
		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
		
		// Custom preset has empty settings array, so it shouldn't show the preview.
		$this->assertEmpty( $presets['custom']['settings'], 'Custom preset should have empty settings array' );
	}
}
