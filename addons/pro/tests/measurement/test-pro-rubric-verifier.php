<?php
/**
 * Tests for the Pro Rubric Verifier.
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
 * Test Pro Rubric Verifier.
 */
class Test_WP_MCP_AI_Pro_Rubric_Verifier extends WP_UnitTestCase {

	/**
	 * Test construct requires criteria.
	 */
	public function test_construct_requires_criteria() {
		$this->expectException( 'InvalidArgumentException' );
		new WP_MCP_AI_Pro_Rubric_Verifier( 'r', array() );
	}

	/**
	 * Test callback criteria return weighted score.
	 */
	public function test_callback_criteria_return_weighted_score() {
		$v   = new WP_MCP_AI_Pro_Rubric_Verifier(
			'r',
			array(
				array(
					'slug'     => 'a',
					'weight'   => 3,
					'callback' => static function () {
						return 1.0; },
				),
				array(
					'slug'     => 'b',
					'weight'   => 1,
					'callback' => static function () {
						return 0.0; },
				),
			),
			'',
			0.7 // threshold.
		);
		$res = $v->verify( array( 'output' => 'x' ) );
		$this->assertIsArray( $res );
		// Weighted score = (1.0 * 3 + 0.0 * 1) / 4 = 0.75, above threshold.
		$this->assertEqualsWithDelta( 0.75, $res['score'], 0.0001 );
		$this->assertTrue( $res['passed'] );
		$this->assertArrayHasKey( 'a', $res['evidence']['criteria'] );
		$this->assertArrayHasKey( 'b', $res['evidence']['criteria'] );
	}

	/**
	 * Test callback bool return normalized.
	 */
	public function test_callback_bool_return_normalized() {
		$v   = new WP_MCP_AI_Pro_Rubric_Verifier(
			'r',
			array(
				array(
					'slug'     => 'a',
					'weight'   => 1,
					'callback' => static function () {
																	return true; },
				),
				array(
					'slug'     => 'b',
					'weight'   => 1,
					'callback' => static function () {
												return false; },
				),
			)
		);
		$res = $v->verify( array() );
		// (1 + 0) / 2 = 0.5, below default threshold 0.7.
		$this->assertEqualsWithDelta( 0.5, $res['score'], 0.0001 );
		$this->assertFalse( $res['passed'] );
	}

	/**
	 * Test bad callback shape yields error reason.
	 */
	public function test_bad_callback_shape_yields_error_reason() {
		$v   = new WP_MCP_AI_Pro_Rubric_Verifier(
			'r',
			array(
				array(
					'slug'     => 'a',
					'weight'   => 1,
					'callback' => static function () {
															return 'nope'; },
				),
				array(
					'slug'     => 'b',
					'weight'   => 1,
					'callback' => static function () {
												return 1.0; },
				),
			)
		);
		$res = $v->verify( array() );
		// Error in `a` prevents the rubric from passing even if `b` scores well.
		$this->assertFalse( $res['passed'] );
		// `b` still contributes to the score, so we can observe it.
		$this->assertArrayHasKey( 'a', $res['evidence']['criteria'] );
		$this->assertArrayHasKey( 'error', $res['evidence']['criteria']['a'] );
	}

	/**
	 * Test criterion with no evaluator is dropped.
	 */
	public function test_criterion_with_no_evaluator_is_dropped() {
		$v = new WP_MCP_AI_Pro_Rubric_Verifier(
			'r',
			array(
				array(
					'slug'     => 'a',
					'weight'   => 1,
					'callback' => static function () {
															return 1.0; },
				),
				array(
					'slug'   => 'b',
					'weight' => 1,
				), // No evaluator — dropped.
			)
		);
		$this->assertCount( 1, $v->get_criteria() );
	}

	/**
	 * Test all criteria dropped raises.
	 */
	public function test_all_criteria_dropped_raises() {
		$this->expectException( 'InvalidArgumentException' );
		new WP_MCP_AI_Pro_Rubric_Verifier(
			'r',
			array(
				array(
					'slug'   => 'a',
					'weight' => 1,
				),
				array(
					'slug'   => 'b',
					'weight' => 1,
				),
			)
		);
	}

	/**
	 * Test sub verifier chaining.
	 */
	public function test_sub_verifier_chaining() {
		// Register a fake sub-verifier on the base registry and have the.
		// rubric call it.
		$registry = WP_MCP_AI_Verifier_Registry::get_instance();
		$fake     = new class() extends WP_MCP_AI_Verifier_Base {
			/**
			 *   Construct.
			 */
			public function __construct() {
				$this->slug                 = 'fake_sub';
				$this->label                = 'fake';
				$this->kind                 = 'rule';
				$this->independence_profile = array(
					'disallowed_providers' => array(),
					'disallowed_models'    => array(),
				);
			}
			/**
			 * Verify.
			 *
			 * @param array $subject Subject data.
			 * @param array $context  Context data.
			 * @return array
			 */
			public function verify( array $subject, array $context = array() ) {
				return $this->result_pass( 0.9, 1.0, array( 'ok' ) );
			}
		};
		$registry->register( $fake );

		$v   = new WP_MCP_AI_Pro_Rubric_Verifier(
			'r',
			array(
				array(
					'slug'     => 'sub',
					'weight'   => 1,
					'verifier' => 'fake_sub',
				),
			),
			'',
			0.5
		);
		$res = $v->verify( array( 'any' => 'thing' ) );
		$this->assertTrue( $res['passed'] );
		$this->assertEqualsWithDelta( 0.9, $res['score'], 0.0001 );

		$registry->unregister( 'fake_sub' );
	}

	/**
	 * Test unknown sub verifier produces error reason.
	 */
	public function test_unknown_sub_verifier_produces_error_reason() {
		$v   = new WP_MCP_AI_Pro_Rubric_Verifier(
			'r',
			array(
				array(
					'slug'     => 'sub',
					'weight'   => 1,
					'verifier' => 'definitely_not_registered',
				),
			)
		);
		$res = $v->verify( array() );
		$this->assertFalse( $res['passed'] );
		$this->assertArrayHasKey( 'error', $res['evidence']['criteria']['sub'] );
	}

	/**
	 * Test zero weight criterion is skipped.
	 */
	public function test_zero_weight_criterion_is_skipped() {
		$v   = new WP_MCP_AI_Pro_Rubric_Verifier(
			'r',
			array(
				array(
					'slug'     => 'a',
					'weight'   => 1,
					'callback' => static function () {
															return 1.0; },
				),
				array(
					'slug'     => 'b',
					'weight'   => 0,
					'callback' => static function () {
												return 0.0; },
				),
			)
		);
		$res = $v->verify( array() );
		// The zero-weight criterion is skipped entirely; score = 1.0.
		$this->assertEqualsWithDelta( 1.0, $res['score'], 0.0001 );
	}

	/**
	 * Test self reference is rejected.
	 */
	public function test_self_reference_is_rejected() {
		// A rubric that lists itself as a sub-verifier should produce an.
		// explicit self-reference error (via the verifier-registry lookup.
		// path), not silently loop.
		$rubric = new WP_MCP_AI_Pro_Rubric_Verifier(
			'self_ref_rubric',
			array(
				array(
					'slug'     => 'anchor',
					'weight'   => 1,
					'callback' => static function () {
															return 1.0; },
				),
				array(
					'slug'     => 'self',
					'weight'   => 1,
					'verifier' => 'self_ref_rubric',
				),
			)
		);
		WP_MCP_AI_Verifier_Registry::get_instance()->register( $rubric );

		$res = $rubric->verify( array() );
		$this->assertIsArray( $res );
		// Criterion 'self' must show an error; the anchor still scores.
		$this->assertArrayHasKey( 'error', $res['evidence']['criteria']['self'] );

		WP_MCP_AI_Verifier_Registry::get_instance()->unregister( 'self_ref_rubric' );
	}
}
