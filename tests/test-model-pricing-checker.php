<?php
/**
 * Tests for the Model Pricing Checker.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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

		// Check for known models still present in the bundled catalog
		// (o3-mini and o1-2024-12-17 were removed upstream; these remain).
		$this->assertArrayHasKey( 'gpt-4o', $pricing );
		$this->assertArrayHasKey( 'gemini-2.5-flash', $pricing );
		$this->assertArrayHasKey( 'gpt-4.1-mini', $pricing );

		// Verify structure.
		$this->assertArrayHasKey( 'input', $pricing['gpt-4o'] );
		$this->assertArrayHasKey( 'output', $pricing['gpt-4o'] );
	}

	/**
	 * Test price change detection.
	 */
	public function test_price_change_detection() {
		// Anchor the "new" price to the bundled catalog instead of a memorized
		// value, so upstream price updates don't break this test.
		$catalog_models = WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data();
		$current        = null;
		foreach ( $catalog_models as $model ) {
			if ( 'gpt-4o' === ( isset( $model['model_name'] ) ? $model['model_name'] : '' ) ) {
				$current = $model;
				break;
			}
		}
		$this->assertNotNull( $current, 'gpt-4o should be in the bundled catalog' );

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
		$this->assertEquals( (float) $current['cost_per_1k_input_tokens'], $gpt4o_change['new_input'] );
		$this->assertEquals( (float) $current['cost_per_1k_output_tokens'], $gpt4o_change['new_output'] );
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

		// Models present in the bundled catalog must be tracked. o3-mini and
		// o1-2024-12-17 were removed upstream; gpt-4.1-mini and gpt-5 remain.
		$this->assertArrayHasKey( 'gemini-2.5-flash', $pricing, 'gemini-2.5-flash should be tracked' );
		$this->assertArrayHasKey( 'gpt-4.1-mini', $pricing, 'gpt-4.1-mini should be tracked' );
		$this->assertArrayHasKey( 'gpt-5', $pricing, 'gpt-5 should be tracked' );

		// Verify correct pricing anchored to the bundled catalog for gpt-4.1-mini.
		$catalog_models = WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data();
		$expected       = null;
		foreach ( $catalog_models as $model ) {
			if ( 'gpt-4.1-mini' === ( isset( $model['model_name'] ) ? $model['model_name'] : '' ) ) {
				$expected = $model;
				break;
			}
		}
		$this->assertNotNull( $expected, 'gpt-4.1-mini should be in the bundled catalog' );
		$this->assertEquals( (float) $expected['cost_per_1k_input_tokens'], $pricing['gpt-4.1-mini']['input'] );
		$this->assertEquals( (float) $expected['cost_per_1k_output_tokens'], $pricing['gpt-4.1-mini']['output'] );
	}

	/**
	 * Test update_model_costs requires authentication.
	 */
	public function test_update_model_costs_requires_auth() {
		// Ensure user is not logged in.
		wp_set_current_user( 0 );

		// Simulate AJAX request without authentication.
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_update_model_costs' );

		// Capture output.
		ob_start();
		try {
			WP_MCP_AI_Model_Pricing_Checker::update_model_costs();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$output = ob_get_clean();

		// Verify error response.
		$this->assertStringContainsString( 'You must be logged in', $output );
	}

	/**
	 * Test update_model_costs requires manage_options capability.
	 */
	public function test_update_model_costs_requires_capability() {
		// Create a user without manage_options capability.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Simulate AJAX request.
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_update_model_costs' );

		// Capture output.
		ob_start();
		try {
			WP_MCP_AI_Model_Pricing_Checker::update_model_costs();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$output = ob_get_clean();

		// Verify error response.
		$this->assertStringContainsString( 'You do not have permission', $output );
	}

	/**
	 * Test update_model_costs with no price changes.
	 */
	public function test_update_model_costs_no_changes() {
		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Clear price changes.
		delete_option( WP_MCP_AI_Model_Pricing_Checker::OPTION_PRICE_CHANGES );

		// Simulate AJAX request.
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_update_model_costs' );

		// Capture output.
		ob_start();
		try {
			WP_MCP_AI_Model_Pricing_Checker::update_model_costs();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$output = ob_get_clean();

		// Verify error response.
		$this->assertStringContainsString( 'No pricing changes', $output );
	}

	/**
	 * Test update_model_costs validates pricing values.
	 */
	public function test_update_model_costs_validates_pricing() {
		// The validation branch runs after the JetEngine CCT item-handler gate
		// inside update_model_costs(); without JetEngine the handler responds
		// with an unavailable-CCT error before reaching validation.
		if ( class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' )
			&& ! WP_MCP_AI_Model_Rate_Limits_CCT::get_item_handler() ) {
			$this->markTestSkipped( 'JetEngine CCT item handler is not available.' );
		}

		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Set invalid price changes (negative and too high values).
		$invalid_changes = array(
			array(
				'model'      => 'test-model-negative',
				'provider'   => 'test',
				'old_input'  => 0.001,
				'new_input'  => -0.5, // Invalid: negative.
				'old_output' => 0.002,
				'new_output' => 0.003,
			),
			array(
				'model'      => 'test-model-too-high',
				'provider'   => 'test',
				'old_input'  => 0.001,
				'new_input'  => 15.0, // Invalid: too high.
				'old_output' => 0.002,
				'new_output' => 0.003,
			),
		);
		update_option( WP_MCP_AI_Model_Pricing_Checker::OPTION_PRICE_CHANGES, $invalid_changes );

		// Simulate AJAX request.
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_update_model_costs' );

		// Capture output.
		ob_start();
		try {
			WP_MCP_AI_Model_Pricing_Checker::update_model_costs();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$output = ob_get_clean();

		// Verify error response mentions invalid pricing.
		$this->assertStringContainsString( 'Invalid pricing values', $output );
	}
}
