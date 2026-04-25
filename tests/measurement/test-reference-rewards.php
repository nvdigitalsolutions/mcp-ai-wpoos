<?php
/**
 * Tests for the reference reward functions.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Reference Rewards.
 */
class Test_WP_MCP_AI_Reference_Rewards extends WP_UnitTestCase {

	/**
	 * Registry.
	 *
	 * @var WP_MCP_AI_Reward_Function_Registry
	 */
	private $registry;

	/**
	 * Set up — register the reference rewards into a fresh registry.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Reward_Function_Registry::reset_instance();
		$this->registry = WP_MCP_AI_Reward_Function_Registry::get_instance();
		WP_MCP_AI_Reference_Rewards::register( $this->registry );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Reward_Function_Registry::reset_instance();
		parent::tearDown();
	}

	/**
	 * All three reference functions register successfully with anti-gaming.
	 */
	public function test_all_registered() {
		foreach ( array( 'verified_success', 'cost_adjusted_success', 'calibration_brier' ) as $slug ) {
			$def = $this->registry->get( $slug );
			$this->assertNotNull( $def, "$slug should be registered" );
			$this->assertNotSame( '', $def['anti_gaming'], "$slug needs anti-gaming text" );
		}
	}

	/**
	 * verified_success returns 1.0 only when passed AND confidence >= 0.5.
	 */
	public function test_verified_success_thresholds() {
		$this->assertSame( 1.0, $this->registry->evaluate( 'verified_success', array( 'verifier_passed' => true, 'verifier_confidence' => 0.9 ) ) );
		$this->assertSame( 0.0, $this->registry->evaluate( 'verified_success', array( 'verifier_passed' => true, 'verifier_confidence' => 0.4 ) ) );
		$this->assertSame( 0.0, $this->registry->evaluate( 'verified_success', array( 'verifier_passed' => false, 'verifier_confidence' => 0.9 ) ) );
	}

	/**
	 * cost_adjusted_success penalizes high cost even on success.
	 */
	public function test_cost_adjusted_success_penalizes_cost() {
		$cheap = $this->registry->evaluate(
			'cost_adjusted_success',
			array( 'verifier_passed' => true, 'verifier_confidence' => 0.9, 'cost_usd' => 0.0, 'budget_usd' => 1.0 )
		);
		$expensive = $this->registry->evaluate(
			'cost_adjusted_success',
			array( 'verifier_passed' => true, 'verifier_confidence' => 0.9, 'cost_usd' => 1.0, 'budget_usd' => 1.0 )
		);
		$this->assertSame( 1.0, $cheap );
		$this->assertEqualsWithDelta( 0.5, $expensive, 0.0001 );
	}

	/**
	 * cost_adjusted_success stays in [0,1] under adversarial cost values.
	 */
	public function test_cost_adjusted_success_bounded() {
		for ( $i = 0; $i < 20; $i++ ) {
			$val = $this->registry->evaluate(
				'cost_adjusted_success',
				array(
					'verifier_passed'     => (bool) ( $i % 2 ),
					'verifier_confidence' => wp_rand( 0, 100 ) / 100.0,
					'cost_usd'            => wp_rand( 0, 100000 ) / 100.0,
					'budget_usd'          => wp_rand( 1, 100 ) / 100.0,
				)
			);
			$this->assertGreaterThanOrEqual( 0.0, $val );
			$this->assertLessThanOrEqual( 1.0, $val );
		}
	}

	/**
	 * calibration_brier: perfect calibration gets 1.0, worst case gets 0.0.
	 */
	public function test_calibration_brier_extremes() {
		// Agent says 1.0 confident AND is right: perfect calibration.
		$this->assertSame( 1.0, $this->registry->evaluate( 'calibration_brier', array( 'stated_confidence' => 1.0, 'verifier_passed' => true ) ) );
		// Agent says 0.0 confident AND is wrong: still perfect calibration (honestly predicted failure).
		$this->assertSame( 1.0, $this->registry->evaluate( 'calibration_brier', array( 'stated_confidence' => 0.0, 'verifier_passed' => false ) ) );
		// Agent says 1.0 confident but is wrong: worst case.
		$this->assertSame( 0.0, $this->registry->evaluate( 'calibration_brier', array( 'stated_confidence' => 1.0, 'verifier_passed' => false ) ) );
	}

	/**
	 * calibration_brier stays in [0,1] for adversarial stated_confidence.
	 */
	public function test_calibration_brier_bounded_under_adversarial() {
		foreach ( array( -10.0, -1.0, 0.0, 0.5, 1.0, 2.0, 99.9 ) as $conf ) {
			foreach ( array( true, false ) as $passed ) {
				$val = $this->registry->evaluate(
					'calibration_brier',
					array( 'stated_confidence' => $conf, 'verifier_passed' => $passed )
				);
				$this->assertGreaterThanOrEqual( 0.0, $val );
				$this->assertLessThanOrEqual( 1.0, $val );
			}
		}
	}

	/**
	 * Property-based reward hacking check: no input combination lets an
	 * agent score better than telling the truth on verified_success.
	 */
	public function test_no_reward_hack_for_verified_success() {
		$honest_high = $this->registry->evaluate( 'verified_success', array( 'verifier_passed' => true, 'verifier_confidence' => 1.0 ) );
		for ( $i = 0; $i < 50; $i++ ) {
			$val = $this->registry->evaluate(
				'verified_success',
				array(
					'verifier_passed'     => (bool) wp_rand( 0, 1 ),
					'verifier_confidence' => wp_rand( 0, 100 ) / 100.0,
				)
			);
			$this->assertLessThanOrEqual( $honest_high, $val, 'verified_success must cap at 1.0' );
		}
	}
}
