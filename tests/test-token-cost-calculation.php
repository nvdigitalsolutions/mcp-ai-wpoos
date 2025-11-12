<?php
/**
 * Tests for token cost calculation functionality.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_Token_Cost_Calculation_Test extends WP_UnitTestCase {

	/**
	 * Test basic cost calculation for GPT-4o.
	 */
	public function test_calculate_cost_gpt4o() {
		$model            = 'gpt-4o';
		$prompt_tokens    = 1000;
		$completion_tokens = 500;

		$cost = WP_MCP_AI_Token_Budget_Manager::calculate_cost( $model, $prompt_tokens, $completion_tokens );

		$this->assertIsArray( $cost );
		$this->assertArrayHasKey( 'input_cost', $cost );
		$this->assertArrayHasKey( 'output_cost', $cost );
		$this->assertArrayHasKey( 'cached_cost', $cost );
		$this->assertArrayHasKey( 'total_cost', $cost );
		$this->assertArrayHasKey( 'currency', $cost );

		// GPT-4o: $0.0025 per 1K input, $0.01 per 1K output.
		$expected_input  = 1000 / 1000 * 0.0025;  // 0.0025.
		$expected_output = 500 / 1000 * 0.01;     // 0.005.
		$expected_total  = $expected_input + $expected_output; // 0.0075.

		$this->assertEquals( round( $expected_input, 6 ), $cost['input_cost'] );
		$this->assertEquals( round( $expected_output, 6 ), $cost['output_cost'] );
		$this->assertEquals( round( $expected_total, 6 ), $cost['total_cost'] );
		$this->assertEquals( 'USD', $cost['currency'] );
	}

	/**
	 * Test cost calculation with cached tokens.
	 */
	public function test_calculate_cost_with_cached_tokens() {
		$model            = 'gpt-4o-mini';
		$prompt_tokens    = 2000;
		$completion_tokens = 1000;
		$cached_tokens    = 500;

		$cost = WP_MCP_AI_Token_Budget_Manager::calculate_cost( $model, $prompt_tokens, $completion_tokens, $cached_tokens );

		$this->assertIsArray( $cost );
		$this->assertGreaterThan( 0, $cost['cached_cost'] );
		$this->assertGreaterThan( 0, $cost['total_cost'] );

		// Cached tokens should cost approximately half of input tokens.
		$this->assertLessThan( $cost['input_cost'], $cost['cached_cost'] );
	}

	/**
	 * Test cost calculation for local models (should be free).
	 */
	public function test_calculate_cost_local_model() {
		$model            = 'llama3';
		$prompt_tokens    = 5000;
		$completion_tokens = 2000;

		$cost = WP_MCP_AI_Token_Budget_Manager::calculate_cost( $model, $prompt_tokens, $completion_tokens );

		$this->assertIsArray( $cost );
		$this->assertEquals( 0.0, $cost['input_cost'] );
		$this->assertEquals( 0.0, $cost['output_cost'] );
		$this->assertEquals( 0.0, $cost['total_cost'] );
	}

	/**
	 * Test getting cost per 1K for different token types.
	 */
	public function test_get_model_cost_per_1k() {
		$model = 'gpt-4o';

		$input_cost  = WP_MCP_AI_Token_Budget_Manager::get_model_cost_per_1k( $model, '', 'input' );
		$output_cost = WP_MCP_AI_Token_Budget_Manager::get_model_cost_per_1k( $model, '', 'output' );
		$cached_cost = WP_MCP_AI_Token_Budget_Manager::get_model_cost_per_1k( $model, '', 'cached' );

		$this->assertGreaterThan( 0, $input_cost );
		$this->assertGreaterThan( 0, $output_cost );
		$this->assertGreaterThan( 0, $cached_cost );

		// Output should cost more than input for most models.
		$this->assertGreaterThan( $input_cost, $output_cost );

		// Cached should cost less than input (typically 50%).
		$this->assertLessThan( $input_cost, $cached_cost );
	}

	/**
	 * Test cost calculation for Claude models.
	 */
	public function test_calculate_cost_claude() {
		$model            = 'claude-3.5-sonnet';
		$prompt_tokens    = 1000;
		$completion_tokens = 500;

		$cost = WP_MCP_AI_Token_Budget_Manager::calculate_cost( $model, $prompt_tokens, $completion_tokens );

		$this->assertIsArray( $cost );
		$this->assertGreaterThan( 0, $cost['input_cost'] );
		$this->assertGreaterThan( 0, $cost['output_cost'] );
		$this->assertGreaterThan( 0, $cost['total_cost'] );
	}

	/**
	 * Test cost calculation for Gemini models.
	 */
	public function test_calculate_cost_gemini() {
		$model            = 'gemini-1.5-flash';
		$prompt_tokens    = 10000;
		$completion_tokens = 5000;

		$cost = WP_MCP_AI_Token_Budget_Manager::calculate_cost( $model, $prompt_tokens, $completion_tokens );

		$this->assertIsArray( $cost );
		$this->assertGreaterThan( 0, $cost['input_cost'] );
		$this->assertGreaterThan( 0, $cost['output_cost'] );
		$this->assertGreaterThan( 0, $cost['total_cost'] );

		// Gemini Flash should be relatively inexpensive.
		$this->assertLessThan( 0.01, $cost['total_cost'] );
	}

	/**
	 * Test that zero tokens results in zero cost.
	 */
	public function test_calculate_cost_zero_tokens() {
		$model            = 'gpt-4o';
		$prompt_tokens    = 0;
		$completion_tokens = 0;

		$cost = WP_MCP_AI_Token_Budget_Manager::calculate_cost( $model, $prompt_tokens, $completion_tokens );

		$this->assertEquals( 0.0, $cost['input_cost'] );
		$this->assertEquals( 0.0, $cost['output_cost'] );
		$this->assertEquals( 0.0, $cost['total_cost'] );
	}

	/**
	 * Test cost calculation for unknown model falls back gracefully.
	 */
	public function test_calculate_cost_unknown_model() {
		$model            = 'unknown-model-xyz';
		$prompt_tokens    = 1000;
		$completion_tokens = 500;

		$cost = WP_MCP_AI_Token_Budget_Manager::calculate_cost( $model, $prompt_tokens, $completion_tokens );

		$this->assertIsArray( $cost );
		// Unknown models should default to 0 cost.
		$this->assertEquals( 0.0, $cost['total_cost'] );
	}

	/**
	 * Test usage tracker includes cost data.
	 */
	public function test_usage_tracker_records_cost() {
		$user_id      = $this->factory()->user->create();
		$assistant_id = $this->factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		$options = array(
			'model'    => 'gpt-4o-mini',
			'provider' => 'openai',
		);

		$response = array(
			'model'    => 'gpt-4o-mini',
			'provider' => 'openai',
			'usage'    => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
			),
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response );

		$usage = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$this->assertIsArray( $usage );
		$this->assertArrayHasKey( 'openai', $usage );
		$this->assertArrayHasKey( 'gpt-4o-mini', $usage['openai'] );

		$model_usage = $usage['openai']['gpt-4o-mini'];

		$this->assertArrayHasKey( 'total_cost', $model_usage );
		$this->assertArrayHasKey( 'input_cost', $model_usage );
		$this->assertArrayHasKey( 'output_cost', $model_usage );

		$this->assertGreaterThan( 0, $model_usage['total_cost'] );
		$this->assertGreaterThan( 0, $model_usage['input_cost'] );
		$this->assertGreaterThan( 0, $model_usage['output_cost'] );
	}

	/**
	 * Test cumulative cost tracking across multiple requests.
	 */
	public function test_cumulative_cost_tracking() {
		$user_id      = $this->factory()->user->create();
		$assistant_id = $this->factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		$options = array(
			'model'    => 'gpt-4o',
			'provider' => 'openai',
		);

		// First request.
		$response1 = array(
			'model'    => 'gpt-4o',
			'provider' => 'openai',
			'usage'    => array(
				'prompt_tokens'     => 1000,
				'completion_tokens' => 500,
				'total_tokens'      => 1500,
			),
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response1 );

		$usage_after_first = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );
		$first_cost        = $usage_after_first['openai']['gpt-4o']['total_cost'];

		// Second request.
		$response2 = array(
			'model'    => 'gpt-4o',
			'provider' => 'openai',
			'usage'    => array(
				'prompt_tokens'     => 2000,
				'completion_tokens' => 1000,
				'total_tokens'      => 3000,
			),
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response2 );

		$usage_after_second = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );
		$second_cost        = $usage_after_second['openai']['gpt-4o']['total_cost'];

		// Second cost should be greater than first (cumulative).
		$this->assertGreaterThan( $first_cost, $second_cost );

		// Verify total tokens also increased.
		$this->assertEquals( 4500, $usage_after_second['openai']['gpt-4o']['total_tokens'] );
	}
}
