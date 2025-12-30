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

	/**
	 * Test GPT-5 variant matching with version suffix.
	 */
	public function test_gpt5_variant_matching() {
		// Test that gpt-5-2025-08-07 matches gpt-5 pricing (not gpt-5-mini or gpt-5-nano).
		$cost = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-5-2025-08-07', 1000000, 500000 );

		// Should use gpt-5 pricing: input $10/1M, output $30/1M.
		// Expected: (1M / 1M) * 10 + (500K / 1M) * 30 = 10 + 15 = $25.00.
		$this->assertEquals( 25.00, $cost, 'gpt-5-2025-08-07 should match gpt-5 pricing' );
	}

	/**
	 * Test GPT-5-mini is distinct from GPT-5.
	 */
	public function test_gpt5_mini_distinct_from_gpt5() {
		// Test that gpt-5-mini has its own pricing, not gpt-5.
		$cost_gpt5      = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-5', 1000000, 1000000 );
		$cost_gpt5_mini = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-5-mini', 1000000, 1000000 );

		// gpt-5: input $10/1M, output $30/1M = $40 total.
		$this->assertEquals( 40.00, $cost_gpt5, 'gpt-5 cost calculation incorrect' );

		// gpt-5-mini: input $2/1M, output $6/1M = $8 total.
		$this->assertEquals( 8.00, $cost_gpt5_mini, 'gpt-5-mini cost calculation incorrect' );

		// They should be different.
		$this->assertNotEquals( $cost_gpt5, $cost_gpt5_mini, 'gpt-5 and gpt-5-mini should have different costs' );
	}

	/**
	 * Test GPT-5-mini variant with version suffix.
	 */
	public function test_gpt5_mini_variant_matching() {
		// Test that gpt-5-mini-2025-08-07 matches gpt-5-mini pricing (not gpt-5).
		$cost = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-5-mini-2025-08-07', 1000000, 1000000 );

		// Should use gpt-5-mini pricing: input $2/1M, output $6/1M = $8 total.
		$this->assertEquals( 8.00, $cost, 'gpt-5-mini-2025-08-07 should match gpt-5-mini pricing' );
	}

	/**
	 * Test longest prefix matching ensures correct model selection.
	 */
	public function test_longest_prefix_matching() {
		// When we have gpt-5, gpt-5-mini, and gpt-5-nano in the system,.
		// gpt-5-2025-08-07 should match gpt-5 (not just any prefix).

		// Get pricing for different variants to ensure longest match wins.
		$pricing_gpt5           = WP_MCP_AI_Cost_Calculator::get_model_pricing( 'openai', 'gpt-5' );
		$pricing_gpt5_mini      = WP_MCP_AI_Cost_Calculator::get_model_pricing( 'openai', 'gpt-5-mini' );
		$pricing_gpt5_versioned = WP_MCP_AI_Cost_Calculator::get_model_pricing( 'openai', 'gpt-5-2025-08-07' );

		// Verify gpt-5 and gpt-5-mini have different pricing.
		$this->assertNotEquals( $pricing_gpt5['input'], $pricing_gpt5_mini['input'], 'gpt-5 and gpt-5-mini should have different input costs' );

		// Verify gpt-5-2025-08-07 matches gpt-5 pricing (longest prefix).
		$this->assertEquals( $pricing_gpt5['input'], $pricing_gpt5_versioned['input'], 'gpt-5-2025-08-07 should match gpt-5 input cost' );
		$this->assertEquals( $pricing_gpt5['output'], $pricing_gpt5_versioned['output'], 'gpt-5-2025-08-07 should match gpt-5 output cost' );
	}

	/**
	 * Test longest prefix matching works for all model families.
	 */
	public function test_longest_prefix_matching_all_models() {
		// Test GPT-4o variants.
		$cost_gpt4o_dated = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-4o-2024-11-20', 1000000, 1000000 );
		$cost_gpt4o       = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-4o', 1000000, 1000000 );
		$this->assertEquals( $cost_gpt4o, $cost_gpt4o_dated, 'gpt-4o-2024-11-20 should match gpt-4o' );

		// Test GPT-4o-mini variants (should NOT match gpt-4o).
		$cost_gpt4o_mini_dated = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-4o-mini-2024-11-20', 1000000, 1000000 );
		$cost_gpt4o_mini       = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-4o-mini', 1000000, 1000000 );
		$this->assertEquals( $cost_gpt4o_mini, $cost_gpt4o_mini_dated, 'gpt-4o-mini-2024-11-20 should match gpt-4o-mini' );
		$this->assertNotEquals( $cost_gpt4o, $cost_gpt4o_mini, 'gpt-4o-mini should NOT match gpt-4o pricing' );

		// Test Gemini variants.
		$cost_gemini_flash_dated = WP_MCP_AI_Cost_Calculator::calculate_cost( 'gemini', 'gemini-1.5-flash-2024-05', 1000000, 1000000 );
		$cost_gemini_flash       = WP_MCP_AI_Cost_Calculator::calculate_cost( 'gemini', 'gemini-1.5-flash', 1000000, 1000000 );
		$this->assertEquals( $cost_gemini_flash, $cost_gemini_flash_dated, 'gemini-1.5-flash-2024-05 should match gemini-1.5-flash' );

		// Test Gemini 2.5 flash variants.
		$cost_gemini_25_flash_dated = WP_MCP_AI_Cost_Calculator::calculate_cost( 'gemini', 'gemini-2.5-flash-2024-12', 1000000, 1000000 );
		$cost_gemini_25_flash       = WP_MCP_AI_Cost_Calculator::calculate_cost( 'gemini', 'gemini-2.5-flash', 1000000, 1000000 );
		$this->assertEquals( $cost_gemini_25_flash, $cost_gemini_25_flash_dated, 'gemini-2.5-flash-2024-12 should match gemini-2.5-flash' );

		// Test Claude variants.
		$cost_claude_dated = WP_MCP_AI_Cost_Calculator::calculate_cost( 'anthropic', 'claude-3.5-sonnet-20241022', 1000000, 1000000 );
		$cost_claude       = WP_MCP_AI_Cost_Calculator::calculate_cost( 'anthropic', 'claude-3.5-sonnet', 1000000, 1000000 );
		$this->assertEquals( $cost_claude, $cost_claude_dated, 'claude-3.5-sonnet-20241022 should match claude-3.5-sonnet' );

		// Test O1 variants.
		$cost_o1_preview_dated = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'o1-preview-2024-09-12', 1000000, 1000000 );
		$cost_o1_preview       = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'o1-preview', 1000000, 1000000 );
		$this->assertEquals( $cost_o1_preview, $cost_o1_preview_dated, 'o1-preview-2024-09-12 should match o1-preview' );

		// Test O1-mini variants (should NOT match o1-preview).
		$cost_o1_mini_dated = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'o1-mini-2024-09-12', 1000000, 1000000 );
		$cost_o1_mini       = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'o1-mini', 1000000, 1000000 );
		$this->assertEquals( $cost_o1_mini, $cost_o1_mini_dated, 'o1-mini-2024-09-12 should match o1-mini' );
		$this->assertNotEquals( $cost_o1_preview, $cost_o1_mini, 'o1-mini should NOT match o1-preview pricing' );

		// Test GPT-4.1 family variants.
		$cost_gpt41_dated = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-4.1-2025-04', 1000000, 1000000 );
		$cost_gpt41       = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-4.1', 1000000, 1000000 );
		$this->assertEquals( $cost_gpt41, $cost_gpt41_dated, 'gpt-4.1-2025-04 should match gpt-4.1' );

		// Test GPT-4.1-mini variants (should NOT match gpt-4.1).
		$cost_gpt41_mini_dated = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-4.1-mini-2025-04', 1000000, 1000000 );
		$cost_gpt41_mini       = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-4.1-mini', 1000000, 1000000 );
		$this->assertEquals( $cost_gpt41_mini, $cost_gpt41_mini_dated, 'gpt-4.1-mini-2025-04 should match gpt-4.1-mini' );
		$this->assertNotEquals( $cost_gpt41, $cost_gpt41_mini, 'gpt-4.1-mini should NOT match gpt-4.1 pricing' );

		// Test GPT-4.1-nano variants (should NOT match gpt-4.1 or gpt-4.1-mini).
		$cost_gpt41_nano_dated = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-4.1-nano-2025-04', 1000000, 1000000 );
		$cost_gpt41_nano       = WP_MCP_AI_Cost_Calculator::calculate_cost( 'openai', 'gpt-4.1-nano', 1000000, 1000000 );
		$this->assertEquals( $cost_gpt41_nano, $cost_gpt41_nano_dated, 'gpt-4.1-nano-2025-04 should match gpt-4.1-nano' );
		$this->assertNotEquals( $cost_gpt41, $cost_gpt41_nano, 'gpt-4.1-nano should NOT match gpt-4.1 pricing' );
		$this->assertNotEquals( $cost_gpt41_mini, $cost_gpt41_nano, 'gpt-4.1-nano should NOT match gpt-4.1-mini pricing' );
	}

	/**
	 * Test Gemini 2.5 Pro pricing matches model config.
	 */
	public function test_gemini_25_pro_pricing() {
		// Get pricing from cost calculator.
		$pricing = WP_MCP_AI_Cost_Calculator::get_model_pricing( 'gemini', 'gemini-2.5-pro' );

		$this->assertIsArray( $pricing, 'gemini-2.5-pro should have pricing' );
		$this->assertArrayHasKey( 'input', $pricing, 'Pricing should have input key' );
		$this->assertArrayHasKey( 'output', $pricing, 'Pricing should have output key' );

		// Verify pricing matches updated rates (November 2025): $1.20 input, $4.80 output per 1M tokens.
		$this->assertEquals( 1.20, $pricing['input'], 'Input pricing should be $1.20 per 1M tokens' );
		$this->assertEquals( 4.80, $pricing['output'], 'Output pricing should be $4.80 per 1M tokens' );

		// Verify average cost matches model config ($3.00 per 1M tokens).
		$avg_cost = ( $pricing['input'] + $pricing['output'] ) / 2;
		$this->assertEquals( 3.00, $avg_cost, 'Average cost should be $3.00 per 1M tokens' );
	}

	/**
	 * Test Gemini 2.5 Pro cost calculation.
	 */
	public function test_calculate_cost_gemini_25_pro() {
		// Test with 1M input tokens and 1M output tokens.
		$cost = WP_MCP_AI_Cost_Calculator::calculate_cost( 'gemini', 'gemini-2.5-pro', 1000000, 1000000 );

		// Expected: (1M / 1M) * $1.20 + (1M / 1M) * $4.80 = $6.00.
		$this->assertEquals( 6.00, $cost, 'Gemini 2.5 Pro cost calculation should be $6.00 for 1M input + 1M output tokens' );

		// Test with different token counts.
		$cost = WP_MCP_AI_Cost_Calculator::calculate_cost( 'gemini', 'gemini-2.5-pro', 500000, 250000 );

		// Expected: (500K / 1M) * $1.20 + (250K / 1M) * $4.80 = $0.60 + $1.20 = $1.80.
		$this->assertEquals( 1.80, $cost, 'Gemini 2.5 Pro cost calculation should be $1.80 for 500K input + 250K output tokens' );
	}

	/**
	 * Test Gemini 2.5 Pro pricing is consistent with model config.
	 */
	public function test_gemini_25_pro_updated_pricing() {
		$pricing = WP_MCP_AI_Cost_Calculator::get_model_pricing( 'gemini', 'gemini-2.5-pro' );

		// Old pricing was $1.25 input / $5.00 output - verify it's been updated.
		$this->assertNotEquals( 1.25, $pricing['input'], 'Pricing should not be old rate of $1.25' );
		$this->assertNotEquals( 5.00, $pricing['output'], 'Pricing should not be old rate of $5.00' );

		// Verify new pricing maintains 1:4 ratio (Google standard for Gemini models).
		$ratio = $pricing['output'] / $pricing['input'];
		$this->assertEquals( 4.0, $ratio, 'Output should be 4x input (1:4 ratio)' );

		// Verify pricing is consistent with model config ($3.00 per 1M average).
		$model_config = WP_MCP_AI_Model_Config::get_model_config( 'gemini-2.5-pro' );
		if ( $model_config && isset( $model_config['cost_per_1k'] ) ) {
			$expected_avg = $model_config['cost_per_1k'] * 1000; // Convert per 1k to per 1M.
			$actual_avg   = ( $pricing['input'] + $pricing['output'] ) / 2;
			$this->assertEquals( $expected_avg, $actual_avg, 'Cost calculator average should match model config' );
		}
	}
}
