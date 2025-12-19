<?php
/**
 * Tests for the Model Pricing Checker.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Model_Pricing_Checker_Test extends WP_UnitTestCase {

	/**
	 * Test that the cron hook is registered.
	 */
	public function test_cron_hook_registered() {
		$this->assertNotFalse( wp_next_scheduled( WP_MCP_AI_Model_Pricing_Checker::CRON_HOOK ) );
	}

	/**
	 * Test pricing check runs without errors.
	 */
	public function test_pricing_check_runs() {
		// Clear previous data.
		delete_option( WP_MCP_AI_Model_Pricing_Checker::OPTION_LAST_CHECK );
		delete_option( WP_MCP_AI_Model_Pricing_Checker::OPTION_PRICE_CHANGES );

		// Run the pricing check.
		WP_MCP_AI_Model_Pricing_Checker::trigger_check();

		// Verify pricing data was stored.
		$last_check = get_option( WP_MCP_AI_Model_Pricing_Checker::OPTION_LAST_CHECK );
		$this->assertIsArray( $last_check );
		$this->assertNotEmpty( $last_check );
	}

	/**
	 * Test that pricing data is stored correctly.
	 */
	public function test_pricing_data_storage() {
		// Clear previous data.
		delete_option( WP_MCP_AI_Model_Pricing_Checker::OPTION_LAST_CHECK );

		// Run the pricing check.
		WP_MCP_AI_Model_Pricing_Checker::trigger_check();

		// Get stored pricing.
		$pricing = get_option( WP_MCP_AI_Model_Pricing_Checker::OPTION_LAST_CHECK );

		// Check for known models.
		$this->assertArrayHasKey( 'gpt-4o', $pricing );
		$this->assertArrayHasKey( 'gemini-2.5-flash', $pricing );
		$this->assertArrayHasKey( 'o3-mini', $pricing );

		// Verify structure.
		$this->assertArrayHasKey( 'input', $pricing['gpt-4o'] );
		$this->assertArrayHasKey( 'output', $pricing['gpt-4o'] );
	}

	/**
	 * Test price change detection.
	 */
	public function test_price_change_detection() {
		// Set initial pricing.
		$initial_pricing = array(
			'gpt-4o' => array(
				'input'  => 0.001,
				'output' => 0.002,
			),
		);
		update_option( WP_MCP_AI_Model_Pricing_Checker::OPTION_LAST_CHECK, $initial_pricing );
		delete_option( WP_MCP_AI_Model_Pricing_Checker::OPTION_PRICE_CHANGES );

		// Run pricing check (which will detect the price change).
		WP_MCP_AI_Model_Pricing_Checker::trigger_check();

		// Get price changes.
		$changes = WP_MCP_AI_Model_Pricing_Checker::get_price_changes();

		// Should detect that gpt-4o price changed from 0.001/0.002 to current values.
		$gpt4o_change = null;
		foreach ( $changes as $change ) {
			if ( $change['model'] === 'gpt-4o' ) {
				$gpt4o_change = $change;
				break;
			}
		}

		$this->assertNotNull( $gpt4o_change );
		$this->assertEquals( 0.001, $gpt4o_change['old_input'] );
		$this->assertEquals( 0.002, $gpt4o_change['old_output'] );
		$this->assertEquals( 0.005, $gpt4o_change['new_input'] ); // Current price.
		$this->assertEquals( 0.015, $gpt4o_change['new_output'] ); // Current price.
	}

	/**
	 * Test clearing price changes.
	 */
	public function test_clear_price_changes() {
		// Add some price changes.
		$changes = array(
			array(
				'model'     => 'test-model',
				'old_input' => 0.001,
				'new_input' => 0.002,
			),
		);
		update_option( WP_MCP_AI_Model_Pricing_Checker::OPTION_PRICE_CHANGES, $changes );

		// Clear changes.
		WP_MCP_AI_Model_Pricing_Checker::clear_price_changes();

		// Verify cleared.
		$this->assertEmpty( WP_MCP_AI_Model_Pricing_Checker::get_price_changes() );
	}

	/**
	 * Test that newly added models are tracked.
	 */
	public function test_new_models_tracked() {
		// Run pricing check.
		WP_MCP_AI_Model_Pricing_Checker::trigger_check();

		// Get pricing data.
		$pricing = get_option( WP_MCP_AI_Model_Pricing_Checker::OPTION_LAST_CHECK );

		// Check for new models added in this PR.
		$this->assertArrayHasKey( 'gemini-2.5-flash', $pricing, 'gemini-2.5-flash should be tracked' );
		$this->assertArrayHasKey( 'o1-2024-12-17', $pricing, 'o1-2024-12-17 should be tracked' );
		$this->assertArrayHasKey( 'o3-mini', $pricing, 'o3-mini should be tracked' );

		// Verify correct pricing for o3-mini.
		$this->assertEquals( 0.00110, $pricing['o3-mini']['input'], 'o3-mini input price should be $0.00110' );
		$this->assertEquals( 0.00440, $pricing['o3-mini']['output'], 'o3-mini output price should be $0.00440' );
	}
}
