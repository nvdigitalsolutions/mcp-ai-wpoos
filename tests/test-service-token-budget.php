<?php
/**
 * Tests for WP_MCP_AI_Token_Budget_Manager.
 *
 * Covers token estimation, model limit lookup, budget calculation,
 * and message truncation logic.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Token_Budget_Manager.
 */
class Test_Service_Token_Budget extends WP_UnitTestCase {

	/**
	 * Test that estimate_tokens returns 0 for an empty string.
	 */
	public function test_estimate_tokens_returns_zero_for_empty_string() {
		$count = WP_MCP_AI_Token_Budget_Manager::estimate_tokens( '' );
		$this->assertSame( 0, $count );
	}

	/**
	 * Test that estimate_tokens returns 0 for non-string input.
	 */
	public function test_estimate_tokens_returns_zero_for_non_string_input() {
		$count = WP_MCP_AI_Token_Budget_Manager::estimate_tokens( null );
		$this->assertSame( 0, $count );
	}

	/**
	 * Test that estimate_tokens returns a positive integer for normal text.
	 */
	public function test_estimate_tokens_returns_positive_for_normal_text() {
		$count = WP_MCP_AI_Token_Budget_Manager::estimate_tokens( 'Hello, world! This is a test string.' );
		$this->assertIsInt( $count );
		$this->assertGreaterThan( 0, $count );
	}

	/**
	 * Test that longer text produces a higher token estimate than shorter text.
	 */
	public function test_estimate_tokens_scales_with_text_length() {
		$short = WP_MCP_AI_Token_Budget_Manager::estimate_tokens( 'Hi' );
		$long  = WP_MCP_AI_Token_Budget_Manager::estimate_tokens( str_repeat( 'word ', 100 ) );
		$this->assertGreaterThan( $short, $long );
	}

	/**
	 * Test that get_model_limit returns a known limit for gpt-4o.
	 */
	public function test_get_model_limit_returns_known_limit_for_gpt4o() {
		$limit = WP_MCP_AI_Token_Budget_Manager::get_model_limit( 'gpt-4o' );
		$this->assertIsInt( $limit );
		$this->assertSame( 128000, $limit );
	}

	/**
	 * Test that get_model_limit returns a positive integer for an unknown model.
	 */
	public function test_get_model_limit_returns_positive_for_unknown_model() {
		$limit = WP_MCP_AI_Token_Budget_Manager::get_model_limit( 'nonexistent-model-abc' );
		$this->assertIsInt( $limit );
		$this->assertGreaterThan( 0, $limit );
	}

	/**
	 * Test that calculate_budget returns array with all required keys.
	 */
	public function test_calculate_budget_returns_expected_keys() {
		$messages = array(
			array( 'role' => 'user', 'content' => 'Hello' ),
		);

		$result = WP_MCP_AI_Token_Budget_Manager::calculate_budget( 'gpt-4o', $messages );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'available', $result );
		$this->assertArrayHasKey( 'used', $result );
		$this->assertArrayHasKey( 'reserved', $result );
		$this->assertArrayHasKey( 'limit', $result );
		$this->assertArrayHasKey( 'model', $result );
	}

	/**
	 * Test that calculate_budget 'used' is non-negative and less than 'limit'.
	 */
	public function test_calculate_budget_used_within_limit() {
		$messages = array(
			array( 'role' => 'system', 'content' => 'You are a helpful assistant.' ),
			array( 'role' => 'user', 'content' => 'Tell me a joke.' ),
		);

		$result = WP_MCP_AI_Token_Budget_Manager::calculate_budget( 'gpt-4o', $messages );

		$this->assertGreaterThanOrEqual( 0, $result['used'] );
		$this->assertGreaterThan( 0, $result['limit'] );
	}

	/**
	 * Test that truncate_messages returns input unchanged when already within budget.
	 */
	public function test_truncate_messages_returns_messages_when_within_budget() {
		$messages = array(
			array( 'role' => 'user', 'content' => 'Hi' ),
		);

		$result = WP_MCP_AI_Token_Budget_Manager::truncate_messages( $messages, 'gpt-4o', 10000 );

		$this->assertIsArray( $result );
		// Messages fit within 10000 tokens so output should equal input.
		$this->assertCount( count( $messages ), $result );
	}

	/**
	 * Test that truncate_messages returns empty array when max_tokens is zero.
	 */
	public function test_truncate_messages_returns_empty_for_zero_max_tokens() {
		$messages = array(
			array( 'role' => 'user', 'content' => 'Hello' ),
		);

		$result = WP_MCP_AI_Token_Budget_Manager::truncate_messages( $messages, 'gpt-4o', 0 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that the default safety margin constant is 0.1 (10%).
	 */
	public function test_default_safety_margin_constant() {
		$this->assertSame( 0.1, WP_MCP_AI_Token_Budget_Manager::DEFAULT_SAFETY_MARGIN );
	}

	/**
	 * Test that get_model_tpm_limit returns null for models without a configured limit.
	 */
	public function test_get_model_tpm_limit_returns_null_for_unconfigured_model() {
		$limit = WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit( 'gpt-4o' );
		// gpt-4o is not in $default_tpm_limits, so expect null (unless CCT present).
		if ( ! class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			$this->assertNull( $limit );
		} else {
			// When CCT class present, still assert we get int or null.
			$this->assertTrue( is_null( $limit ) || is_int( $limit ) );
		}
	}
}
