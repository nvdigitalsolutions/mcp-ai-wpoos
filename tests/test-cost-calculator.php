<?php
/**
 * Test WP_MCP_AI_Cost_Calculator class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Cost Calculator.
 */
class Test_Cost_Calculator extends WP_UnitTestCase {

	/**
	 * Test basic cost calculation for OpenAI.
	 */
	public function test_calculate_cost_openai() {
		// gpt-4o: input $2.50/1M, output $10.00/1M.
		$cost = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-4o', 1000000, 500000 );

		// Expected: (1M / 1M) * 2.50 + (500K / 1M) * 10.00 = 2.50 + 5.00 = $7.50.
		$this->assertEquals( 7.50, $cost, 'OpenAI gpt-4o cost calculation incorrect' );
	}

	/**
	 * Test cost calculation for Gemini.
	 */
	public function test_calculate_cost_gemini() {
		// gemini-1.5-flash: input $0.075/1M, output $0.30/1M.
		$cost = WP_MCP_AI_Cost_Calculator::calculate_cost( 'gemini', 'gemini-1.5-flash', 2000000, 1000000 );

		// Expected: (2M / 1M) * 0.075 + (1M / 1M) * 0.30 = 0.15 + 0.30 = $0.45.
		$this->assertEquals( 0.45, $cost, 'Gemini cost calculation incorrect' );
	}

	/**
	 * Test cost calculation for Anthropic.
	 */
	public function test_calculate_cost_anthropic() {
		// claude-3-haiku: input $0.25/1M, output $1.25/1M.
		$cost = WP_MCP_AI_Cost_Calculator::calculate_cost( 'anthropic', 'claude-3-haiku', 500000, 500000 );

		// Expected: (500K / 1M) * 0.25 + (500K / 1M) * 1.25 = 0.125 + 0.625 = $0.75.
		$this->assertEquals( 0.75, $cost, 'Anthropic cost calculation incorrect' );
	}

	/**
	 * Test cost calculation for Ollama (free).
	 */
	public function test_calculate_cost_ollama() {
		$cost = WP_MCP_AI_Cost_Calculator::calculate_cost( 'ollama', 'llama2', 1000000, 1000000 );

		// Ollama is free.
		$this->assertEquals( 0.0, $cost, 'Ollama should be free' );
	}

	/**
	 * Test cost calculation for LM Studio (free).
	 */
	public function test_calculate_cost_lm_studio() {
		$cost = WP_MCP_AI_Cost_Calculator::calculate_cost( 'lm_studio', 'any-model', 1000000, 1000000 );

		// LM Studio is free.
		$this->assertEquals( 0.0, $cost, 'LM Studio should be free' );
	}

	/**
	 * Test cost calculation for unknown provider.
	 */
	public function test_calculate_cost_unknown_provider() {
		$cost = WP_MCP_AI_Cost_Calculator::calculate_cost( 'unknown', 'model', 1000000, 1000000 );

		// Should return 0 for unknown provider.
		$this->assertEquals( 0.0, $cost, 'Unknown provider should return 0 cost' );
	}

	/**
	 * Test cost calculation for unknown model.
	 */
	public function test_calculate_cost_unknown_model() {
		$cost = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'unknown-model', 1000000, 1000000 );

		// Should return 0 for unknown model.
		$this->assertEquals( 0.0, $cost, 'Unknown model should return 0 cost' );
	}

	/**
	 * Test model name normalization.
	 */
	public function test_model_name_normalization() {
		// Test with versioned model name.
		$cost = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-4o-2024-11-20', 1000000, 500000 );

		// Should match gpt-4o pricing after normalization.
		$this->assertEquals( 7.50, $cost, 'Model normalization failed' );
	}

	/**
	 * Test getting model pricing.
	 */
	public function test_get_model_pricing() {
		$pricing = WP_MCP_AI_Cost_Calculator::get_model_pricing( 'openai', 'gpt-4o' );

		$this->assertIsArray( $pricing, 'Pricing should be an array' );
		$this->assertArrayHasKey( 'input', $pricing, 'Pricing should have input key' );
		$this->assertArrayHasKey( 'output', $pricing, 'Pricing should have output key' );
		$this->assertEquals( 2.50, $pricing['input'], 'Input pricing incorrect' );
		$this->assertEquals( 10.00, $pricing['output'], 'Output pricing incorrect' );
	}

	/**
	 * Test getting pricing for non-existent model.
	 */
	public function test_get_model_pricing_nonexistent() {
		$pricing = WP_MCP_AI_Cost_Calculator::get_model_pricing( 'openai', 'nonexistent' );

		$this->assertNull( $pricing, 'Nonexistent model should return null' );
	}

	/**
	 * Test getting all providers.
	 */
	public function test_get_all_providers() {
		$providers = WP_MCP_AI_Cost_Calculator::get_all_providers();

		$this->assertIsArray( $providers, 'Providers should be an array' );
		$this->assertArrayHasKey( 'openai', $providers, 'Should include OpenAI' );
		$this->assertArrayHasKey( 'gemini', $providers, 'Should include Gemini' );
		$this->assertArrayHasKey( 'anthropic', $providers, 'Should include Anthropic' );
		$this->assertArrayHasKey( 'ollama', $providers, 'Should include Ollama' );
		$this->assertArrayHasKey( 'lm_studio', $providers, 'Should include LM Studio' );
	}

	/**
	 * Test getting provider models.
	 */
	public function test_get_provider_models() {
		$models = WP_MCP_AI_Cost_Calculator::get_provider_models( 'openai' );

		$this->assertIsArray( $models, 'Models should be an array' );
		$this->assertContains( 'gpt-4o', $models, 'Should include gpt-4o' );
		$this->assertContains( 'gpt-4o-mini', $models, 'Should include gpt-4o-mini' );
		$this->assertContains( 'gpt-3.5-turbo', $models, 'Should include gpt-3.5-turbo' );
	}

	/**
	 * Test getting models for unknown provider.
	 */
	public function test_get_provider_models_unknown() {
		$models = WP_MCP_AI_Cost_Calculator::get_provider_models( 'unknown' );

		$this->assertIsArray( $models, 'Should return empty array' );
		$this->assertEmpty( $models, 'Unknown provider should have no models' );
	}

	/**
	 * Test cost breakdown using service layer.
	 */
	public function test_service_get_user_cost_breakdown() {
		$user_id = $this->factory->user->create();

		// Simulate some token usage.
		update_user_meta(
			$user_id,
			'_wp_mcp_ai_tool_token_usage',
			array(
				'search_content' => array(
					'total_tokens' => 100000,
					'requests'     => 10,
					'first_used'   => '2024-11-01 10:00:00',
					'last_used'    => '2024-11-13 10:00:00',
					'daily'        => array(
						'2024-11-13' => 50000,
						'2024-11-12' => 30000,
						'2024-11-11' => 20000,
					),
				),
			)
		);

		$breakdown = WP_MCP_AI_Cost_Tracking_Service::get_user_cost_breakdown(
			$user_id,
			'2024-11-11',
			'2024-11-13'
		);

		$this->assertIsArray( $breakdown, 'Breakdown should be an array' );
		$this->assertArrayHasKey( 'total_cost', $breakdown, 'Should have total_cost' );
		$this->assertArrayHasKey( 'by_provider', $breakdown, 'Should have by_provider' );
		$this->assertArrayHasKey( 'by_model', $breakdown, 'Should have by_model' );
		$this->assertArrayHasKey( 'by_tool', $breakdown, 'Should have by_tool' );
		$this->assertArrayHasKey( 'by_date', $breakdown, 'Should have by_date' );
		$this->assertGreaterThan( 0, $breakdown['total_cost'], 'Should have positive cost' );
	}

	/**
	 * Test pure calculation function (separation of concerns).
	 */
	public function test_calculate_cost_breakdown_pure() {
		$usage_data = array(
			'search_content' => array(
				'total_tokens' => 100000,
				'requests'     => 10,
				'first_used'   => '2024-11-01 10:00:00',
				'last_used'    => '2024-11-13 10:00:00',
				'daily'        => array(
					'2024-11-13' => 50000,
					'2024-11-12' => 30000,
					'2024-11-11' => 20000,
				),
			),
		);

		$breakdown = WP_MCP_AI_Cost_Calculator::calculate_cost_breakdown(
			$usage_data,
			'2024-11-11',
			'2024-11-13'
		);

		$this->assertIsArray( $breakdown, 'Breakdown should be an array' );
		$this->assertGreaterThan( 0, $breakdown['total_cost'], 'Should have positive cost' );
		$this->assertArrayHasKey( 'by_date', $breakdown, 'Should have by_date' );
		$this->assertCount( 3, $breakdown['by_date'], 'Should have 3 days of data' );
	}

	/**
	 * Test ROI calculation using service layer.
	 */
	public function test_service_calculate_roi() {
		$user_id = $this->factory->user->create();

		// Simulate some token usage.
		update_user_meta(
			$user_id,
			'_wp_mcp_ai_tool_token_usage',
			array(
				'search_content' => array(
					'total_tokens' => 1000000,
					'requests'     => 100,
					'first_used'   => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
					'last_used'    => gmdate( 'Y-m-d H:i:s' ),
					'daily'        => array(
						gmdate( 'Y-m-d', strtotime( '-1 day' ) )  => 50000,
						gmdate( 'Y-m-d', strtotime( '-2 days' ) ) => 30000,
						gmdate( 'Y-m-d', strtotime( '-3 days' ) ) => 20000,
					),
				),
			)
		);

		$metrics = array(
			'time_saved_hours' => 10,
			'tasks_automated'  => 50,
			'hourly_rate'      => 100,
		);

		$roi = WP_MCP_AI_Cost_Tracking_Service::get_user_roi( $user_id, $metrics, 30 );

		$this->assertIsArray( $roi, 'ROI should be an array' );
		$this->assertArrayHasKey( 'total_cost', $roi, 'Should have total_cost' );
		$this->assertArrayHasKey( 'time_saved', $roi, 'Should have time_saved' );
		$this->assertArrayHasKey( 'tasks_automated', $roi, 'Should have tasks_automated' );
		$this->assertArrayHasKey( 'cost_per_task', $roi, 'Should have cost_per_task' );
		$this->assertArrayHasKey( 'hourly_rate', $roi, 'Should have hourly_rate' );
		$this->assertArrayHasKey( 'value_generated', $roi, 'Should have value_generated' );
		$this->assertArrayHasKey( 'roi_percentage', $roi, 'Should have roi_percentage' );

		// Check calculations.
		$this->assertEquals( 10, $roi['time_saved'], 'Time saved incorrect' );
		$this->assertEquals( 50, $roi['tasks_automated'], 'Tasks automated incorrect' );
		$this->assertEquals( 100, $roi['hourly_rate'], 'Hourly rate incorrect' );
		$this->assertEquals( 1000, $roi['value_generated'], 'Value generated should be 10 * 100 = 1000' );

		// ROI should be positive (value > cost).
		$this->assertGreaterThan( 0, $roi['roi_percentage'], 'ROI should be positive' );
	}

	/**
	 * Test pure ROI calculation function (separation of concerns).
	 */
	public function test_calculate_roi_pure() {
		$total_cost = 5.0;

		$metrics = array(
			'time_saved_hours' => 10,
			'tasks_automated'  => 50,
			'hourly_rate'      => 100,
		);

		$roi = WP_MCP_AI_Cost_Calculator::calculate_roi( $total_cost, $metrics );

		$this->assertIsArray( $roi, 'ROI should be an array' );
		$this->assertEquals( 5.0, $roi['total_cost'], 'Total cost incorrect' );
		$this->assertEquals( 10, $roi['time_saved'], 'Time saved incorrect' );
		$this->assertEquals( 50, $roi['tasks_automated'], 'Tasks automated incorrect' );
		$this->assertEquals( 0.1, $roi['cost_per_task'], 'Cost per task should be 5/50 = 0.1' );
		$this->assertEquals( 1000, $roi['value_generated'], 'Value should be 10 * 100 = 1000' );
		$this->assertEquals( 19900, $roi['roi_percentage'], 'ROI should be ((1000-5)/5)*100 = 19900%' );
	}

	/**
	 * Test cost formatting.
	 */
	public function test_format_cost() {
		$formatted = WP_MCP_AI_Cost_Calculator::format_cost( 1.2345 );
		$this->assertEquals( '$1.2345', $formatted, 'Cost formatting incorrect' );

		$formatted = WP_MCP_AI_Cost_Calculator::format_cost( 0.0001 );
		$this->assertEquals( '$0.0001', $formatted, 'Small cost formatting incorrect' );

		$formatted = WP_MCP_AI_Cost_Calculator::format_cost( 100.5678 );
		$this->assertEquals( '$100.5678', $formatted, 'Large cost formatting incorrect' );
	}

	/**
	 * Test cost calculation with zero tokens.
	 */
	public function test_calculate_cost_zero_tokens() {
		$cost = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-4o', 0, 0 );
		$this->assertEquals( 0.0, $cost, 'Zero tokens should have zero cost' );
	}

	/**
	 * Test cost breakdown with empty date range.
	 */
	public function test_calculate_cost_breakdown_empty_range() {
		$usage_data = array(
			'search_content' => array(
				'daily' => array(
					'2024-11-13' => 50000,
				),
			),
		);

		$breakdown = WP_MCP_AI_Cost_Calculator::calculate_cost_breakdown( $usage_data, '2024-01-01', '2024-01-02' );

		$this->assertEquals( 0.0, $breakdown['total_cost'], 'Empty range should have zero cost' );
		$this->assertEmpty( $breakdown['by_date'], 'Should have no date entries' );
	}

	/**
	 * Test ROI with zero tasks.
	 */
	public function test_calculate_roi_zero_tasks() {
		$total_cost = 10.0;

		$metrics = array(
			'time_saved_hours' => 0,
			'tasks_automated'  => 0,
			'hourly_rate'      => 50,
		);

		$roi = WP_MCP_AI_Cost_Calculator::calculate_roi( $total_cost, $metrics );

		$this->assertEquals( 0.0, $roi['cost_per_task'], 'Cost per task should be 0 with no tasks' );
		$this->assertEquals( 0.0, $roi['value_generated'], 'Value should be 0 with no time saved' );
	}

	/**
	 * Test partial model name matching.
	 */
	public function test_partial_model_matching() {
		// Test that gpt-4-turbo-2024-04-09 matches gpt-4-turbo pricing.
		$cost = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-4-turbo-2024-04-09', 1000000, 1000000 );

		// Should use gpt-4-turbo pricing: input $10/1M, output $30/1M = $40 total.
		$this->assertGreaterThan( 0, $cost, 'Should find pricing for versioned model' );
	}
}
