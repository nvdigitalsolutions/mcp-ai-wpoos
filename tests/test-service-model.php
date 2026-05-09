<?php
/**
 * Tests for WP_MCP_AI_Model_Service.
 *
 * Covers model listing per provider, capability filtering, filter hook
 * application, and invalid-provider handling.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Model_Service.
 */
class Test_Service_Model extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var WP_MCP_AI_Model_Service
	 */
	private $service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Class is loaded on-demand in production; require it explicitly for tests.
		if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-service.php';
		}

		$this->service = new WP_MCP_AI_Model_Service();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		$this->service = null;
		parent::tearDown();
	}

	/**
	 * Test that get_models_for_provider returns an array for Anthropic (no API key needed).
	 *
	 * Anthropic models are listed statically regardless of API key.
	 */
	public function test_get_models_for_provider_anthropic_returns_array() {
		$models = $this->service->get_models_for_provider( 'anthropic' );

		$this->assertIsArray( $models );
		$this->assertNotEmpty( $models );
	}

	/**
	 * Test that Anthropic models are keyed by model ID strings.
	 */
	public function test_get_models_for_provider_anthropic_keys_are_strings() {
		$models = $this->service->get_models_for_provider( 'anthropic' );

		foreach ( array_keys( $models ) as $key ) {
			$this->assertIsString( $key );
			$this->assertStringStartsWith( 'claude', $key );
		}
	}

	/**
	 * Test that get_models_for_provider returns empty array for an unknown provider.
	 */
	public function test_get_models_for_provider_unknown_returns_empty_array() {
		$models = $this->service->get_models_for_provider( 'nonexistent_provider_xyz' );

		$this->assertIsArray( $models );
		$this->assertEmpty( $models );
	}

	/**
	 * Test that get_models_for_provider sanitizes the provider key.
	 *
	 * A provider with HTML injection characters should still return an array
	 * (possibly empty) — not throw or expose unsanitized values.
	 */
	public function test_get_models_for_provider_sanitizes_provider_input() {
		$models = $this->service->get_models_for_provider( '<script>alert(1)</script>' );
		$this->assertIsArray( $models );
	}

	/**
	 * Test that the wp_mcp_ai_models_for_provider filter is applied.
	 */
	public function test_get_models_for_provider_applies_filter() {
		$filter_invoked = false;

		add_filter(
			'wp_mcp_ai_models_for_provider',
			function ( $models, $provider ) use ( &$filter_invoked ) {
				$filter_invoked = true;
				return $models;
			},
			10,
			2
		);

		$this->service->get_models_for_provider( 'anthropic' );

		remove_all_filters( 'wp_mcp_ai_models_for_provider' );

		$this->assertTrue( $filter_invoked, 'wp_mcp_ai_models_for_provider filter was not called.' );
	}

	/**
	 * Test that get_models_for_provider returns empty array for OpenAI without API key.
	 */
	public function test_get_models_for_provider_openai_returns_empty_without_api_key() {
		// Ensure no API key is set.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$orig_key  = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';

		$settings['openai_api_key'] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		$models = $this->service->get_models_for_provider( 'openai' );

		// Restore.
		$settings['openai_api_key'] = $orig_key;
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->assertIsArray( $models );
		$this->assertEmpty( $models );
	}

	/**
	 * Test that a filter can inject additional models for a provider.
	 */
	public function test_filter_can_inject_additional_models() {
		add_filter(
			'wp_mcp_ai_models_for_provider',
			function ( $models, $provider ) {
				if ( 'anthropic' === $provider ) {
					$models['test-injected-model'] = 'Test Injected Model';
				}
				return $models;
			},
			10,
			2
		);

		$models = $this->service->get_models_for_provider( 'anthropic' );

		remove_all_filters( 'wp_mcp_ai_models_for_provider' );

		$this->assertArrayHasKey( 'test-injected-model', $models );
	}
}
