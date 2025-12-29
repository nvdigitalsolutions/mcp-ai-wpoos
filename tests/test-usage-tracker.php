<?php
/**
 * Tests for the usage tracker utilities.
 */
class WP_MCP_AI_Usage_Tracker_Test extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		parent::tearDown();
	}

	public function test_record_chat_usage_updates_totals() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 42;

		$options = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o-mini',
		);

		$response = array(
			'model'    => 'gpt-4o-mini',
			'usage'    => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 75,
				'total_tokens'      => 175,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response );

		$totals = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$this->assertArrayHasKey( 'openai', $totals );
		$this->assertArrayHasKey( 'gpt-4o-mini', $totals['openai'] );

		$model_totals = $totals['openai']['gpt-4o-mini'];

		$this->assertSame( 1, $model_totals['requests'] );
		$this->assertSame( 100, $model_totals['prompt_tokens'] );
		$this->assertSame( 75, $model_totals['completion_tokens'] );
		$this->assertSame( 175, $model_totals['total_tokens'] );
		$this->assertNotEmpty( $model_totals['last_used_gmt'] );
		$this->assertArrayHasKey( $assistant_id, $model_totals['assistants'] );

		$assistant_totals = $model_totals['assistants'][ $assistant_id ];
		$this->assertSame( 1, $assistant_totals['requests'] );
		$this->assertSame( 175, $assistant_totals['total_tokens'] );
	}

	public function test_record_chat_usage_accumulates_values() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 7;

		$options = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o-mini',
		);

		$response = array(
			'usage'    => array(
				'prompt_tokens'     => 10,
				'completion_tokens' => 5,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response );
		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response );

		$totals = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$model_totals = $totals['openai']['gpt-4o-mini'];
		$this->assertSame( 2, $model_totals['requests'] );
		$this->assertSame( 20, $model_totals['prompt_tokens'] );
		$this->assertSame( 10, $model_totals['completion_tokens'] );
		$this->assertSame( 30, $model_totals['total_tokens'] );
	}

	public function test_record_chat_usage_respects_filters() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 13;

		add_filter(
			'wp_mcp_ai_usage_snapshot',
			function ( $usage ) {
				$usage['prompt_tokens']     = 0;
				$usage['completion_tokens'] = 0;
				$usage['total_tokens']      = 0;

				return $usage;
			}
		);

		$response = array(
			'usage'    => array(
				'prompt_tokens'     => 10,
				'completion_tokens' => 5,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, array(), $response );

		remove_all_filters( 'wp_mcp_ai_usage_snapshot' );

		$this->assertSame( array(), WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id ) );
	}

	public function test_record_chat_usage_uses_provider_defaults() {
		$user_id = self::factory()->user->create();

		$options = array(
			'provider' => 'gemini',
		);

		$response = array(
			'usage'    => array(
				'prompt_tokens'     => 50,
				'completion_tokens' => 25,
			),
			'provider' => 'gemini',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, 0, $options, $response );

		$totals = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$this->assertArrayHasKey( 'gemini', $totals );
		$this->assertArrayHasKey( 'gemini-1.5-flash', $totals['gemini'] );
	}

	public function test_record_chat_usage_requires_user_id() {
		$response = array(
			'usage'    => array(
				'prompt_tokens'     => 5,
				'completion_tokens' => 5,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( 0, 0, array(), $response );

		$this->assertSame( array(), WP_MCP_AI_Usage_Tracker::get_usage_for_user( 0 ) );
	}

	public function test_record_chat_usage_tracks_cached_tokens() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 21;

		$response = array(
			'usage'    => array(
				'cached_tokens' => 25,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, array(), $response );

		$totals = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$this->assertArrayHasKey( 'openai', $totals );
		$this->assertNotEmpty( $totals['openai'] );

		$model_totals = reset( $totals['openai'] );

		$this->assertSame( 25, $model_totals['cached_tokens'] );
		$this->assertArrayHasKey( $assistant_id, $model_totals['assistants'] );
		$this->assertSame( 25, $model_totals['assistants'][ $assistant_id ]['cached_tokens'] );
	}

	public function test_gemini_usage_with_total_tokens_is_tracked() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 30;

		$options = array(
			'provider' => 'gemini',
			'model'    => 'gemini-1.5-flash',
		);

		// Simulating Gemini response with all three token fields.
		$response = array(
			'usage'    => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
			),
			'provider' => 'gemini',
			'model'    => 'gemini-1.5-flash',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response );

		$totals = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$this->assertArrayHasKey( 'gemini', $totals );
		$this->assertArrayHasKey( 'gemini-1.5-flash', $totals['gemini'] );

		$model_totals = $totals['gemini']['gemini-1.5-flash'];

		$this->assertSame( 1, $model_totals['requests'] );
		$this->assertSame( 100, $model_totals['prompt_tokens'] );
		$this->assertSame( 50, $model_totals['completion_tokens'] );
		$this->assertSame( 150, $model_totals['total_tokens'] );
	}

	public function test_ollama_usage_with_total_tokens_is_tracked() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 31;

		$options = array(
			'provider' => 'ollama',
			'model'    => 'llama3:latest',
		);

		// Simulating Ollama response with all three token fields.
		$response = array(
			'usage'    => array(
				'prompt_tokens'     => 80,
				'completion_tokens' => 40,
				'total_tokens'      => 120,
			),
			'provider' => 'ollama',
			'model'    => 'llama3:latest',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response );

		$totals = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$this->assertArrayHasKey( 'ollama', $totals );
		$this->assertArrayHasKey( 'llama3:latest', $totals['ollama'] );

		$model_totals = $totals['ollama']['llama3:latest'];

		$this->assertSame( 1, $model_totals['requests'] );
		$this->assertSame( 80, $model_totals['prompt_tokens'] );
		$this->assertSame( 40, $model_totals['completion_tokens'] );
		$this->assertSame( 120, $model_totals['total_tokens'] );
	}

	public function test_gemini_usage_auto_calculates_total_tokens_if_missing() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 32;

		$options = array(
			'provider' => 'gemini',
			'model'    => 'gemini-2.0-flash',
		);

		// Test fallback: if total_tokens is missing, it should be calculated.
		$response = array(
			'usage'    => array(
				'prompt_tokens'     => 60,
				'completion_tokens' => 30,
			),
			'provider' => 'gemini',
			'model'    => 'gemini-2.0-flash',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response );

		$totals = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$this->assertArrayHasKey( 'gemini', $totals );
		$this->assertArrayHasKey( 'gemini-2.0-flash', $totals['gemini'] );

		$model_totals = $totals['gemini']['gemini-2.0-flash'];

		// The usage tracker should auto-calculate total_tokens as sum of prompt + completion.
		$this->assertSame( 90, $model_totals['total_tokens'] );
	}

	public function test_calculate_cost_returns_cost_for_known_model() {
		// Test with GPT-4o-mini (known model with fallback pricing).
		$cost = WP_MCP_AI_Usage_Tracker::calculate_cost( 'openai', 'gpt-4o-mini', 10000, 5000 );

		// Expected: (10000/1000 * 0.00015) + (5000/1000 * 0.0006) = 0.0015 + 0.003 = 0.0045.
		$this->assertEqualsWithDelta( 0.0045, $cost, 0.00001 );
	}

	public function test_calculate_cost_returns_zero_for_unknown_model() {
		// Test with an unknown model that has no pricing.
		$cost = WP_MCP_AI_Usage_Tracker::calculate_cost( 'unknown', 'unknown-model', 10000, 5000 );

		// Should return 0 for unknown models.
		$this->assertSame( 0.0, $cost );
	}

	public function test_calculate_cost_handles_gemini_model() {
		// Test with Gemini 1.5 Flash (known model with fallback pricing).
		$cost = WP_MCP_AI_Usage_Tracker::calculate_cost( 'google', 'gemini-1.5-flash', 100000, 50000 );

		// Expected: (100000/1000 * 0.000075) + (50000/1000 * 0.0003) = 0.0075 + 0.015 = 0.0225.
		$this->assertEqualsWithDelta( 0.0225, $cost, 0.00001 );
	}

	public function test_calculate_cost_handles_gemini_25_pro_model() {
		// Test with Gemini 2.5 Pro (known model with fallback pricing).
		$cost = WP_MCP_AI_Usage_Tracker::calculate_cost( 'gemini', 'gemini-2.5-pro', 100000, 50000 );

		// Expected: (100000/1000 * 0.00125) + (50000/1000 * 0.01) = 0.125 + 0.5 = 0.625.
		$this->assertEqualsWithDelta( 0.625, $cost, 0.00001 );
	}

	public function test_calculate_cost_handles_claude_model() {
		// Test with Claude 3.5 Sonnet (known model with fallback pricing).
		$cost = WP_MCP_AI_Usage_Tracker::calculate_cost( 'anthropic', 'claude-3.5-sonnet', 50000, 25000 );

		// Expected: (50000/1000 * 0.003) + (25000/1000 * 0.015) = 0.15 + 0.375 = 0.525.
		$this->assertEqualsWithDelta( 0.525, $cost, 0.00001 );
	}

	public function test_calculate_cost_handles_zero_tokens() {
		// Test with zero tokens.
		$cost = WP_MCP_AI_Usage_Tracker::calculate_cost( 'openai', 'gpt-4o', 0, 0 );

		// Should return 0.0.
		$this->assertSame( 0.0, $cost );
	}

	public function test_calculate_cost_handles_prefix_match() {
		// Test with gpt-4o-2024-05-13 which should match gpt-4o prefix.
		$cost = WP_MCP_AI_Usage_Tracker::calculate_cost( 'openai', 'gpt-4o-2024-05-13', 10000, 5000 );

		// Expected: (10000/1000 * 0.0025) + (5000/1000 * 0.01) = 0.025 + 0.05 = 0.075.
		$this->assertEqualsWithDelta( 0.075, $cost, 0.00001 );
	}

	public function test_calculate_user_total_cost() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 42;

		// Record some usage for GPT-4o-mini.
		$options = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o-mini',
		);

		$response = array(
			'model'    => 'gpt-4o-mini',
			'usage'    => array(
				'prompt_tokens'     => 10000,
				'completion_tokens' => 5000,
				'total_tokens'      => 15000,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response );

		// Record some usage for Gemini Flash.
		$options2 = array(
			'provider' => 'google',
			'model'    => 'gemini-1.5-flash',
		);

		$response2 = array(
			'model'    => 'gemini-1.5-flash',
			'usage'    => array(
				'prompt_tokens'     => 100000,
				'completion_tokens' => 50000,
				'total_tokens'      => 150000,
			),
			'provider' => 'google',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, 43, $options2, $response2 );

		// Calculate total cost.
		$total_cost = WP_MCP_AI_Usage_Tracker::calculate_user_total_cost( $user_id );

		// Expected GPT-4o-mini cost: 0.0045.
		// Expected Gemini Flash cost: 0.0225.
		// Total: 0.027.
		$this->assertEqualsWithDelta( 0.027, $total_cost, 0.00001 );
	}

	public function test_calculate_user_total_cost_returns_zero_for_nonexistent_user() {
		$cost = WP_MCP_AI_Usage_Tracker::calculate_user_total_cost( 999999 );

		$this->assertSame( 0.0, $cost );
	}

	/**
	 * Test cost calculation for GPT-5 variant with version suffix.
	 */
	public function test_calculate_cost_gpt5_variant() {
		$cost = WP_MCP_AI_Usage_Tracker::calculate_cost( 'openai', 'gpt-5-2025-08-07', 1000000, 500000 );

		// gpt-5 pricing: input $0.01/1K, output $0.03/1K.
		// Expected: (1M / 1000) * 0.01 + (500K / 1000) * 0.03 = 10 + 15 = $25.00.
		$this->assertEquals( 25.00, $cost, 'gpt-5-2025-08-07 should match gpt-5 pricing' );
	}

	/**
	 * Test cost calculation for GPT-5-mini variant with version suffix.
	 */
	public function test_calculate_cost_gpt5_mini_variant() {
		$cost = WP_MCP_AI_Usage_Tracker::calculate_cost( 'openai', 'gpt-5-mini-2025-08-07', 1000000, 1000000 );

		// gpt-5-mini pricing: input $0.002/1K, output $0.006/1K.
		// Expected: (1M / 1000) * 0.002 + (1M / 1000) * 0.006 = 2 + 6 = $8.00.
		$this->assertEquals( 8.00, $cost, 'gpt-5-mini-2025-08-07 should match gpt-5-mini pricing' );
	}

	/**
	 * Test that GPT-5 and GPT-5-mini have different costs (longest prefix matching).
	 */
	public function test_gpt5_mini_distinct_from_gpt5_in_usage_tracker() {
		$cost_gpt5      = WP_MCP_AI_Usage_Tracker::calculate_cost( 'openai', 'gpt-5', 1000000, 1000000 );
		$cost_gpt5_mini = WP_MCP_AI_Usage_Tracker::calculate_cost( 'openai', 'gpt-5-mini', 1000000, 1000000 );

		// gpt-5: input $0.01/1K, output $0.03/1K = $40 total.
		$this->assertEquals( 40.00, $cost_gpt5, 'gpt-5 cost calculation incorrect' );

		// gpt-5-mini: input $0.002/1K, output $0.006/1K = $8 total.
		$this->assertEquals( 8.00, $cost_gpt5_mini, 'gpt-5-mini cost calculation incorrect' );

		// They should be different.
		$this->assertNotEquals( $cost_gpt5, $cost_gpt5_mini, 'gpt-5 and gpt-5-mini should have different costs' );
	}

	/**
	 * Test user total cost with GPT-5 variant usage.
	 */
	public function test_calculate_user_total_cost_with_gpt5_variant() {
		$user_id = self::factory()->user->create();

		// Simulate usage with gpt-5-2025-08-07.
		$totals = array(
			'openai' => array(
				'gpt-5-2025-08-07' => array(
					'requests'          => 248,
					'prompt_tokens'     => 5688474,
					'completion_tokens' => 249446,
					'total_tokens'      => 6196026,
					'cached_tokens'     => 0,
				),
			),
		);

		update_user_meta( $user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY, $totals );

		$total_cost = WP_MCP_AI_Usage_Tracker::calculate_user_total_cost( $user_id );

		// Expected cost for gpt-5:
		// Input: 5,688,474 tokens / 1000 * $0.01 = $56.88474.
		// Output: 249,446 tokens / 1000 * $0.03 = $7.48338.
		// Total: $64.36812.
		$this->assertEqualsWithDelta( 64.36812, $total_cost, 0.00001, 'gpt-5 variant cost calculation should work' );
	}
}
