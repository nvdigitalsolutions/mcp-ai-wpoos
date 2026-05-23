<?php
/**
 * Tests for the Pro Measurement Bootstrap.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap wiring tests.
 */
class Test_WP_MCP_AI_Pro_Measurement_Bootstrap extends WP_UnitTestCase {

	/**
	 * Setup.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Pro_Measurement_Bootstrap::reset();
		WP_MCP_AI_Budget_Registry::reset_instance();
		WP_MCP_AI_Reward_Function_Registry::reset_instance();
	}

	/**
	 * Teardown.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Pro_Measurement_Bootstrap::reset();
		WP_MCP_AI_Budget_Registry::reset_instance();
		WP_MCP_AI_Reward_Function_Registry::reset_instance();
		parent::tearDown();
	}

	/**
	 * Test boot attaches hooks idempotently.
	 */
	public function test_boot_attaches_hooks_idempotently() {
		WP_MCP_AI_Pro_Measurement_Bootstrap::boot();
		WP_MCP_AI_Pro_Measurement_Bootstrap::boot(); // idempotent.

		$this->assertSame(
			20,
			has_action( 'wp_mcp_ai_register_verifiers', array( 'WP_MCP_AI_Pro_Measurement_Bootstrap', 'register_verifiers' ) )
		);
		$this->assertSame(
			20,
			has_action( 'wp_mcp_ai_register_budgets', array( 'WP_MCP_AI_Pro_Measurement_Bootstrap', 'register_budgets' ) )
		);
		$this->assertSame(
			30,
			has_action( 'wp_mcp_ai_register_reward_functions', array( 'WP_MCP_AI_Pro_Measurement_Bootstrap', 'register_rewards' ) )
		);
	}

	/**
	 * Test register verifiers registers pro rubric.
	 */
	public function test_register_verifiers_registers_pro_rubric() {
		$registry = WP_MCP_AI_Verifier_Registry::get_instance();
		// Unregister if stale from a prior suite.
		$registry->unregister( 'pro_content_rubric' );

		WP_MCP_AI_Pro_Measurement_Bootstrap::register_verifiers( $registry );
		$v = $registry->get( 'pro_content_rubric' );
		$this->assertInstanceOf( 'WP_MCP_AI_Pro_Rubric_Verifier', $v );

		$registry->unregister( 'pro_content_rubric' );
	}

	/**
	 * Test register budgets registers pro request cost.
	 */
	public function test_register_budgets_registers_pro_request_cost() {
		$registry = WP_MCP_AI_Budget_Registry::get_instance();
		WP_MCP_AI_Pro_Measurement_Bootstrap::register_budgets( $registry );
		$env = $registry->get( 'pro_request_cost_usd' );
		$this->assertInstanceOf( 'WP_MCP_AI_Budget_Envelope', $env );
		$this->assertEqualsWithDelta( 0.25, $env->get_limit(), 0.0001 );
	}

	/**
	 * Test budget limit filter honored.
	 */
	public function test_budget_limit_filter_honored() {
		$filter = static function () {
			return 1.5;
		};
		add_filter( 'wp_mcp_ai_pro_request_cost_budget_limit', $filter );

		$registry = WP_MCP_AI_Budget_Registry::get_instance();
		WP_MCP_AI_Pro_Measurement_Bootstrap::register_budgets( $registry );
		$env = $registry->get( 'pro_request_cost_usd' );
		$this->assertEqualsWithDelta( 1.5, $env->get_limit(), 0.0001 );

		remove_filter( 'wp_mcp_ai_pro_request_cost_budget_limit', $filter );
	}

	/**
	 * Test budget limit filter zero skips registration.
	 */
	public function test_budget_limit_filter_zero_skips_registration() {
		$filter = static function () {
			return 0;
		};
		add_filter( 'wp_mcp_ai_pro_request_cost_budget_limit', $filter );

		$registry = WP_MCP_AI_Budget_Registry::get_instance();
		WP_MCP_AI_Pro_Measurement_Bootstrap::register_budgets( $registry );
		$this->assertNull( $registry->get( 'pro_request_cost_usd' ) );

		remove_filter( 'wp_mcp_ai_pro_request_cost_budget_limit', $filter );
	}

	/**
	 * Test rewards register guarded verified success after base.
	 */
	public function test_rewards_register_guarded_verified_success_after_base() {
		// Register base reference rewards first.
		WP_MCP_AI_Reference_Rewards::register( WP_MCP_AI_Reward_Function_Registry::get_instance() );

		// Budget must exist so the guarded reward can see its envelope.
		WP_MCP_AI_Pro_Measurement_Bootstrap::register_budgets( WP_MCP_AI_Budget_Registry::get_instance() );
		WP_MCP_AI_Pro_Measurement_Bootstrap::register_rewards( WP_MCP_AI_Reward_Function_Registry::get_instance() );

		$def = WP_MCP_AI_Reward_Function_Registry::get_instance()->get( 'verified_success_budget_guarded' );
		$this->assertIsArray( $def );
		$this->assertIsCallable( $def['callback'] );
	}
}
