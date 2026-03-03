<?php
/**
 * Tests for Anthropic Model Service
 *
 * Verifies that the Model Service returns correct Anthropic Claude models.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Anthropic model service functionality.
 */
class Test_Anthropic_Model_Service extends WP_UnitTestCase {

	/**
	 * Model service instance.
	 *
	 * @var WP_MCP_AI_Model_Service
	 */
	protected $model_service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load model service.
		if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-service.php';
		}

		$this->model_service = new WP_MCP_AI_Model_Service();

		// Set up Anthropic API key in settings.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'anthropic_api_key' => 'sk-test-key',
			)
		);
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that Anthropic models include Claude 4.6 series.
	 */
	public function test_anthropic_models_include_claude_46() {
		$models = $this->model_service->get_models_for_provider( 'anthropic' );

		$this->assertIsArray( $models );
		$this->assertNotEmpty( $models );

		// Check for Claude 4.6 models (alias IDs - no dated snapshots yet for 4.6).
		$this->assertArrayHasKey( 'claude-opus-4-6', $models );
		$this->assertArrayHasKey( 'claude-sonnet-4-6', $models );

		// Verify model names.
		$this->assertEquals( 'Claude Opus 4.6 (Feb 2026) - Flagship', $models['claude-opus-4-6'] );
		$this->assertEquals( 'Claude Sonnet 4.6 (Feb 2026) - Recommended', $models['claude-sonnet-4-6'] );
	}

	/**
	 * Test that Anthropic models include Claude 4.5 series.
	 */
	public function test_anthropic_models_include_claude_45() {
		$models = $this->model_service->get_models_for_provider( 'anthropic' );

		// Check for Claude 4.5 models.
		$this->assertArrayHasKey( 'claude-sonnet-4-5-20250929', $models );
		$this->assertArrayHasKey( 'claude-haiku-4-5-20251001', $models );
		$this->assertArrayHasKey( 'claude-opus-4-5-20251101', $models );
	}

	/**
	 * Test that Claude 4.6 models appear before 4.5 models in the list.
	 */
	public function test_claude_46_models_ordered_first() {
		$models     = $this->model_service->get_models_for_provider( 'anthropic' );
		$model_keys = array_keys( $models );

		// Find positions of 4.6 and 4.5 models.
		$opus_46_pos   = array_search( 'claude-opus-4-6', $model_keys, true );
		$sonnet_46_pos = array_search( 'claude-sonnet-4-6', $model_keys, true );
		$sonnet_45_pos = array_search( 'claude-sonnet-4-5-20250929', $model_keys, true );

		// 4.6 models should appear before 4.5 models.
		$this->assertNotFalse( $opus_46_pos, 'Claude Opus 4.6 should be in the list' );
		$this->assertNotFalse( $sonnet_46_pos, 'Claude Sonnet 4.6 should be in the list' );
		$this->assertNotFalse( $sonnet_45_pos, 'Claude Sonnet 4.5 should be in the list' );
		$this->assertLessThan( $sonnet_45_pos, $opus_46_pos, 'Claude 4.6 models should appear before 4.5 models' );
		$this->assertLessThan( $sonnet_45_pos, $sonnet_46_pos, 'Claude 4.6 models should appear before 4.5 models' );
	}

	/**
	 * Test that model service returns models even when API key is not set.
	 *
	 * Models are static and don't require API access to list.
	 */
	public function test_anthropic_models_available_without_api_key() {
		// Remove API key.
		delete_option( 'wp_mcp_ai_settings' );

		$models = $this->model_service->get_models_for_provider( 'anthropic' );

		// Anthropic models should still be available for browsing.
		$this->assertIsArray( $models );
		$this->assertNotEmpty( $models, 'Anthropic models should be available even without API key for browsing' );

		// Check that Claude 4.6 models are still present.
		$this->assertArrayHasKey( 'claude-opus-4-6', $models );
		$this->assertArrayHasKey( 'claude-sonnet-4-6', $models );
	}
}
