<?php
/**
 * Tests for the Pro Budget-Guarded Reward factory.
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
 * Test the budget-guarded reward wrapper.
 */
class Test_WP_MCP_AI_Pro_Budget_Guarded_Reward extends WP_UnitTestCase {

	/**
	 * @var WP_MCP_AI_Reward_Function_Registry
	 */
	private $rewards;

	/**
	 * @var WP_MCP_AI_Budget_Registry
	 */
	private $budgets;

	public function setUp(): void {
		parent::setUp();

		$this->rewards = WP_MCP_AI_Reward_Function_Registry::get_instance();
		WP_MCP_AI_Budget_Registry::reset_instance();
		$this->budgets = WP_MCP_AI_Budget_Registry::get_instance();

		// Inner reward: always returns 1.0.
		$this->rewards->register(
			array(
				'slug'           => 'always_one',
				'label'          => 'Always One',
				'description'    => 'test',
				'callback'       => static function () { return 1.0; },
				'inputs'         => array(),
				'output_min'     => 0.0,
				'output_max'     => 1.0,
				'anti_gaming'    => 'test reward, not for production',
				'counter_metric' => '',
			)
		);

		$this->budgets->register(
			array(
				'slug'       => 'test_cost',
				'metric_ids' => array( 'model.cost_usd' ),
				'limit'      => 1.0,
				'warn_ratio' => 0.5,
				'scope'      => WP_MCP_AI_Budget_Envelope::SCOPE_REQUEST,
			)
		);
	}

	public function tearDown(): void {
		WP_MCP_AI_Budget_Registry::reset_instance();
		parent::tearDown();
	}

	public function test_make_callback_rejects_missing_args() {
		$this->assertInstanceOf( 'WP_Error', WP_MCP_AI_Pro_Budget_Guarded_Reward::make_callback( array() ) );
		$this->assertInstanceOf( 'WP_Error', WP_MCP_AI_Pro_Budget_Guarded_Reward::make_callback( array( 'inner' => 'x' ) ) );
	}

	public function test_passthrough_when_budget_ok() {
		$cb = WP_MCP_AI_Pro_Budget_Guarded_Reward::make_callback(
			array( 'inner' => 'always_one', 'budget' => 'test_cost' )
		);
		$this->assertIsCallable( $cb );
		$this->assertSame( 1.0, $cb( array() ) );
	}

	public function test_warn_multiplier_applies_in_warn_state() {
		$this->budgets->consume( 'test_cost', 0.6 ); // warn (limit=1.0, warn_ratio=0.5).
		$cb = WP_MCP_AI_Pro_Budget_Guarded_Reward::make_callback(
			array(
				'inner'           => 'always_one',
				'budget'          => 'test_cost',
				'warn_multiplier' => 0.5,
			)
		);
		$this->assertEqualsWithDelta( 0.5, $cb( array() ), 0.0001 );
	}

	public function test_exceeded_state_zeros_the_reward() {
		$this->budgets->consume( 'test_cost', 1.5 );
		$cb = WP_MCP_AI_Pro_Budget_Guarded_Reward::make_callback(
			array( 'inner' => 'always_one', 'budget' => 'test_cost' )
		);
		$this->assertSame( 0.0, $cb( array() ) );
	}

	public function test_missing_inner_reward_returns_zero() {
		$cb = WP_MCP_AI_Pro_Budget_Guarded_Reward::make_callback(
			array( 'inner' => 'not_registered', 'budget' => 'test_cost' )
		);
		$this->assertSame( 0.0, $cb( array() ) );
	}

	public function test_missing_budget_degrades_to_passthrough() {
		$cb = WP_MCP_AI_Pro_Budget_Guarded_Reward::make_callback(
			array( 'inner' => 'always_one', 'budget' => 'no_such_budget' )
		);
		// Absent budget → treated as "ok" → passthrough.
		$this->assertSame( 1.0, $cb( array() ) );
	}

	public function test_register_wrapper_produces_working_definition() {
		$def = WP_MCP_AI_Pro_Budget_Guarded_Reward::register_wrapper(
			$this->rewards,
			array(
				'inner'               => 'always_one',
				'budget'              => 'test_cost',
				'slug'                => 'always_one_guarded',
				'exceeded_multiplier' => 0.0,
			)
		);
		$this->assertIsArray( $def );
		$this->assertSame( 'always_one_guarded', $def['slug'] );

		$found = $this->rewards->get( 'always_one_guarded' );
		$this->assertIsArray( $found );
		$this->assertIsCallable( $found['callback'] );

		// Before exceed: 1.0.
		$this->assertSame( 1.0, call_user_func( $found['callback'], array() ) );
		// After exceed: 0.0.
		$this->budgets->consume( 'test_cost', 2.0 );
		$this->assertSame( 0.0, call_user_func( $found['callback'], array() ) );
	}

	public function test_non_registry_returns_wp_error() {
		$res = WP_MCP_AI_Pro_Budget_Guarded_Reward::register_wrapper(
			'not a registry',
			array( 'inner' => 'always_one', 'budget' => 'test_cost' )
		);
		$this->assertInstanceOf( 'WP_Error', $res );
	}

	public function test_multiplier_clamped_to_unit_interval() {
		$cb = WP_MCP_AI_Pro_Budget_Guarded_Reward::make_callback(
			array(
				'inner'               => 'always_one',
				'budget'              => 'test_cost',
				'exceeded_multiplier' => 5.0,
			)
		);
		$this->budgets->consume( 'test_cost', 2.0 );
		// Clamped to 1.0 → passes inner value through unchanged.
		$this->assertSame( 1.0, $cb( array() ) );
	}
}
