<?php
/**
 * Tests for WP_MCP_AI_Token_Usage_Service.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Token_Usage_Service.
 */
class Test_Service_Token_Usage extends WP_UnitTestCase {

	/**
	 * Test that calculate_usage_totals returns zeroed array for empty input.
	 */
	public function test_calculate_usage_totals_returns_zeros_for_empty_array() {
		$result = WP_MCP_AI_Token_Usage_Service::calculate_usage_totals( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'requests', $result );
		$this->assertArrayHasKey( 'prompt_tokens', $result );
		$this->assertArrayHasKey( 'completion_tokens', $result );
		$this->assertArrayHasKey( 'total_tokens', $result );
		$this->assertArrayHasKey( 'total_cost', $result );
		$this->assertSame( 0, $result['requests'] );
		$this->assertSame( 0, $result['prompt_tokens'] );
		$this->assertSame( 0, $result['completion_tokens'] );
		$this->assertSame( 0, $result['total_tokens'] );
	}

	/**
	 * Test that calculate_usage_totals returns zeroed array for non-array input.
	 */
	public function test_calculate_usage_totals_returns_zeros_for_non_array() {
		$result = WP_MCP_AI_Token_Usage_Service::calculate_usage_totals( 'invalid' );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['requests'] );
		$this->assertSame( 0, $result['total_tokens'] );
	}

	/**
	 * Test that calculate_usage_totals correctly sums token fields.
	 */
	public function test_calculate_usage_totals_sums_tokens_correctly() {
		$usage = array(
			'openai' => array(
				'gpt-4o' => array(
					'requests'          => 5,
					'prompt_tokens'     => 1000,
					'completion_tokens' => 500,
					'total_tokens'      => 1500,
					'cached_tokens'     => 100,
				),
			),
		);

		$result = WP_MCP_AI_Token_Usage_Service::calculate_usage_totals( $usage );

		$this->assertSame( 5, $result['requests'] );
		$this->assertSame( 1000, $result['prompt_tokens'] );
		$this->assertSame( 500, $result['completion_tokens'] );
		$this->assertSame( 1500, $result['total_tokens'] );
		$this->assertSame( 100, $result['cached_tokens'] );
	}

	/**
	 * Test that calculate_usage_totals aggregates multiple providers.
	 */
	public function test_calculate_usage_totals_aggregates_multiple_providers() {
		$usage = array(
			'openai' => array(
				'gpt-4o' => array(
					'requests'          => 3,
					'prompt_tokens'     => 300,
					'completion_tokens' => 150,
					'total_tokens'      => 450,
					'cached_tokens'     => 0,
				),
			),
			'anthropic' => array(
				'claude-3-haiku' => array(
					'requests'          => 2,
					'prompt_tokens'     => 200,
					'completion_tokens' => 100,
					'total_tokens'      => 300,
					'cached_tokens'     => 0,
				),
			),
		);

		$result = WP_MCP_AI_Token_Usage_Service::calculate_usage_totals( $usage );

		$this->assertSame( 5, $result['requests'] );
		$this->assertSame( 500, $result['prompt_tokens'] );
		$this->assertSame( 750, $result['total_tokens'] );
	}

	/**
	 * Test that get_provider_display_name returns known provider name.
	 */
	public function test_get_provider_display_name_returns_known_provider() {
		$name = WP_MCP_AI_Token_Usage_Service::get_provider_display_name( 'openai' );
		$this->assertNotEmpty( $name );
		$this->assertIsString( $name );
	}

	/**
	 * Test that get_provider_display_name returns formatted string for unknown provider.
	 */
	public function test_get_provider_display_name_returns_formatted_fallback_for_unknown() {
		$name = WP_MCP_AI_Token_Usage_Service::get_provider_display_name( 'custom_provider' );
		$this->assertIsString( $name );
		$this->assertNotEmpty( $name );
	}

	/**
	 * Test that get_provider_display_name sanitizes input.
	 */
	public function test_get_provider_display_name_sanitizes_input() {
		// Passing a value with HTML — sanitize_key should strip it.
		$name = WP_MCP_AI_Token_Usage_Service::get_provider_display_name( '<script>alert(1)</script>openai' );
		// sanitize_key removes non-alphanumeric chars, so it won't stay as 'openai'.
		$this->assertIsString( $name );
		$this->assertStringNotContainsString( '<script>', $name );
	}

	/**
	 * Test that get_tool_multiplier returns a float (default 1.0 when class absent).
	 */
	public function test_get_tool_multiplier_returns_float_for_unknown_tool() {
		$multiplier = WP_MCP_AI_Token_Usage_Service::get_tool_multiplier( 'nonexistent_tool_slug' );
		$this->assertIsFloat( $multiplier );
		$this->assertGreaterThan( 0.0, $multiplier );
	}

	/**
	 * Test that calculate_usage_totals handles non-array model data gracefully.
	 */
	public function test_calculate_usage_totals_skips_non_array_model_data() {
		$usage = array(
			'openai' => 'not_an_array',
		);

		$result = WP_MCP_AI_Token_Usage_Service::calculate_usage_totals( $usage );
		$this->assertSame( 0, $result['total_tokens'] );
	}
}
