<?php
/**
 * Test Workflow Presets Section Rendering
 *
 * Verifies that orchestration workflow presets (including swarm pattern)
 * are rendered on the presets view page grouped by category.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for workflow presets section rendering.
 */
class Test_Workflow_Presets_Section_Rendering extends WP_UnitTestCase {

	/**
	 * Test that renderer has the workflow presets method.
	 */
	public function test_renderer_has_workflow_presets_method() {
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Orchestration_Renderer', 'render_workflow_presets_section' ),
			'Renderer should have render_workflow_presets_section method'
		);
	}

	/**
	 * Test that workflow presets section renders successfully.
	 */
	public function test_workflow_presets_section_renders() {
		$html = WP_MCP_AI_Orchestration_Renderer::render_workflow_presets_section();

		$this->assertNotEmpty( $html, 'Workflow presets section should produce output' );
		$this->assertStringContainsString( 'wp-mcp-ai-workflow-presets-section', $html, 'Should contain workflow presets section wrapper' );
		$this->assertStringContainsString( 'Workflow Presets', $html, 'Should contain section title' );
	}

	/**
	 * Test that swarm pattern preset is surfaced in the output.
	 */
	public function test_swarm_pattern_is_surfaced() {
		$html = WP_MCP_AI_Orchestration_Renderer::render_workflow_presets_section();

		$this->assertStringContainsString( 'Swarm Pattern', $html, 'Should display the Swarm Pattern preset' );
		$this->assertStringContainsString( 'data-preset="swarm_pattern"', $html, 'Should have swarm_pattern data attribute' );
	}

	/**
	 * Test that presets are grouped by category with section headers.
	 */
	public function test_presets_grouped_by_category() {
		$html = WP_MCP_AI_Orchestration_Renderer::render_workflow_presets_section();

		$this->assertStringContainsString( 'wp-mcp-ai-workflow-category', $html, 'Should contain category sections' );
		$this->assertStringContainsString( 'data-category="orchestration"', $html, 'Should have orchestration category section' );
		$this->assertStringContainsString( 'Orchestration Patterns', $html, 'Should display Orchestration Patterns category label' );
	}

	/**
	 * Test that all orchestration pattern presets appear in the orchestration category.
	 */
	public function test_orchestration_category_contains_all_patterns() {
		$html = WP_MCP_AI_Orchestration_Renderer::render_workflow_presets_section();

		// Orchestration category should contain all four pattern presets.
		$this->assertStringContainsString( 'Autonomous Workflow', $html, 'Should contain Autonomous Workflow preset' );
		$this->assertStringContainsString( 'Supervisor Pattern', $html, 'Should contain Supervisor Pattern preset' );
		$this->assertStringContainsString( 'Pipeline Pattern', $html, 'Should contain Pipeline Pattern preset' );
		$this->assertStringContainsString( 'Swarm Pattern', $html, 'Should contain Swarm Pattern preset' );
	}

	/**
	 * Test that workflow preset cards have correct data-preset-type attribute.
	 */
	public function test_preset_cards_have_workflow_type() {
		$html = WP_MCP_AI_Orchestration_Renderer::render_workflow_presets_section();

		$this->assertStringContainsString( 'data-preset-type="workflow"', $html, 'Preset cards should have workflow type attribute' );
	}

	/**
	 * Test that non-orchestration categories are also rendered.
	 */
	public function test_other_categories_rendered() {
		$html = WP_MCP_AI_Orchestration_Renderer::render_workflow_presets_section();

		$this->assertStringContainsString( 'data-category="research"', $html, 'Should have research category' );
		$this->assertStringContainsString( 'data-category="development"', $html, 'Should have development category' );
		$this->assertStringContainsString( 'data-category="performance"', $html, 'Should have performance category' );
	}

	/**
	 * Test that preset settings preview shows relevant workflow settings.
	 */
	public function test_workflow_settings_preview() {
		$html = WP_MCP_AI_Orchestration_Renderer::render_workflow_presets_section();

		// Swarm pattern has parallel_execution and max_team_size.
		$this->assertStringContainsString( 'Parallel Execution:', $html, 'Should show parallel execution setting' );
		$this->assertStringContainsString( 'Max Team Size:', $html, 'Should show max team size setting' );
	}

	/**
	 * Test that output is properly escaped (no script tags).
	 */
	public function test_output_is_properly_escaped() {
		$html = WP_MCP_AI_Orchestration_Renderer::render_workflow_presets_section();

		$this->assertStringNotContainsString( '<script>', $html, 'Output should not contain script tags' );
		$this->assertStringNotContainsString( 'javascript:', $html, 'Output should not contain javascript: protocol' );
	}

	/**
	 * Test that the orchestration presets service has the swarm_pattern preset.
	 */
	public function test_orchestration_presets_service_has_swarm() {
		$service = new WP_MCP_AI_Orchestration_Presets();
		$presets = $service->get_presets();

		$this->assertArrayHasKey( 'swarm_pattern', $presets, 'Presets should include swarm_pattern' );
		$this->assertSame( 'orchestration', $presets['swarm_pattern']['category'], 'Swarm pattern should be in orchestration category' );
	}
}
