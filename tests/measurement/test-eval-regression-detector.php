<?php
/**
 * Tests for `WP_MCP_AI_Eval_Regression_Detector` (PR 11).
 *
 * The detector is a pure helper — no WP, no DB. We exercise the
 * three threshold rules independently so a future change to one
 * rule cannot silently relax the others.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Regression detector tests.
 */
class Test_WP_MCP_AI_Eval_Regression_Detector extends WP_UnitTestCase {

	/**
	 * Build a synthetic summary row with sane defaults.
	 *
	 * @param float $pass_rate       Pass rate.
	 * @param float $error_rate      Error rate.
	 * @param float $abstention_rate Abstention rate.
	 * @return array<string,mixed>
	 */
	private function summary( $pass_rate, $error_rate = 0.0, $abstention_rate = 0.0 ) {
		return array(
			'pass_rate'       => $pass_rate,
			'error_rate'      => $error_rate,
			'abstention_rate' => $abstention_rate,
		);
	}

	/**
	 * Test empty baseline is never regression.
	 */
	public function test_empty_baseline_is_never_regression() {
		$out = WP_MCP_AI_Eval_Regression_Detector::detect( $this->summary( 0.0 ), array() );
		$this->assertFalse( $out['is_regression'] );
		$this->assertSame( 0, $out['baseline_size'] );
		$this->assertSame( array(), $out['reasons'] );
	}

	/**
	 * Test pass rate drop triggers.
	 */
	public function test_pass_rate_drop_triggers() {
		$baseline = array(
			$this->summary( 0.90 ),
			$this->summary( 0.92 ),
			$this->summary( 0.88 ),
		);
		// Mean baseline pass_rate = 0.90; drop to 0.80 = -0.10 ≥ default 0.05.
		$out = WP_MCP_AI_Eval_Regression_Detector::detect( $this->summary( 0.80 ), $baseline );
		$this->assertTrue( $out['is_regression'] );
		$this->assertCount( 1, $out['reasons'] );
		$this->assertSame( 'pass_rate', $out['reasons'][0]['metric'] );
		$this->assertEqualsWithDelta( 0.10, $out['reasons'][0]['delta'], 1e-9 );
	}

	/**
	 * Test pass rate drop below threshold does not trigger.
	 */
	public function test_pass_rate_drop_below_threshold_does_not_trigger() {
		$baseline = array( $this->summary( 0.90 ) );
		// Drop of 0.04, below default 0.05.
		$out = WP_MCP_AI_Eval_Regression_Detector::detect( $this->summary( 0.86 ), $baseline );
		$this->assertFalse( $out['is_regression'] );
	}

	/**
	 * Test error rate rise triggers independently.
	 */
	public function test_error_rate_rise_triggers_independently() {
		$baseline = array( $this->summary( 0.95, 0.01 ) );
		$out      = WP_MCP_AI_Eval_Regression_Detector::detect( $this->summary( 0.95, 0.10 ), $baseline );
		$this->assertTrue( $out['is_regression'] );
		$this->assertSame( 'error_rate', $out['reasons'][0]['metric'] );
	}

	/**
	 * Test abstention rate rise triggers independently.
	 */
	public function test_abstention_rate_rise_triggers_independently() {
		$baseline = array( $this->summary( 0.90, 0.0, 0.05 ) );
		$out      = WP_MCP_AI_Eval_Regression_Detector::detect( $this->summary( 0.90, 0.0, 0.18 ), $baseline );
		$this->assertTrue( $out['is_regression'] );
		$this->assertSame( 'abstention_rate', $out['reasons'][0]['metric'] );
	}

	/**
	 * Test all three can trigger at once.
	 */
	public function test_all_three_can_trigger_at_once() {
		$baseline = array( $this->summary( 0.95, 0.0, 0.0 ) );
		$out      = WP_MCP_AI_Eval_Regression_Detector::detect( $this->summary( 0.50, 0.20, 0.30 ), $baseline );
		$this->assertTrue( $out['is_regression'] );
		$this->assertCount( 3, $out['reasons'] );
		$metrics = array_column( $out['reasons'], 'metric' );
		$this->assertSame( array( 'pass_rate', 'error_rate', 'abstention_rate' ), $metrics );
	}

	/**
	 * Test custom thresholds override defaults.
	 */
	public function test_custom_thresholds_override_defaults() {
		$baseline = array( $this->summary( 0.90 ) );
		// 0.04 drop is fine by default but should trip a 0.02 threshold.
		$out = WP_MCP_AI_Eval_Regression_Detector::detect(
			$this->summary( 0.86 ),
			$baseline,
			array( 'pass_rate_drop' => 0.02 )
		);
		$this->assertTrue( $out['is_regression'] );
		$this->assertSame( 0.02, $out['thresholds']['pass_rate_drop'] );
	}

	/**
	 * Test non numeric threshold overrides are ignored.
	 */
	public function test_non_numeric_threshold_overrides_are_ignored() {
		$baseline = array( $this->summary( 0.90 ) );
		$out      = WP_MCP_AI_Eval_Regression_Detector::detect(
			$this->summary( 0.86 ),
			$baseline,
			array( 'pass_rate_drop' => 'not-a-number' )
		);
		// Default 0.05 still in effect; 0.04 drop does not trigger.
		$this->assertFalse( $out['is_regression'] );
		$this->assertSame( 0.05, $out['thresholds']['pass_rate_drop'] );
	}

	/**
	 * Test missing summary field treats current as zero.
	 */
	public function test_missing_summary_field_treats_current_as_zero() {
		// Baseline pass_rate = 0.9, current pass_rate missing → treated as 0.0
		// → drop of 0.9 ≥ 0.05 so this MUST trigger. This guards against a
		// caller silently dropping a field and dodging the alarm.
		$baseline = array( $this->summary( 0.9 ) );
		$out      = WP_MCP_AI_Eval_Regression_Detector::detect( array(), $baseline );
		$this->assertTrue( $out['is_regression'] );
	}
}
