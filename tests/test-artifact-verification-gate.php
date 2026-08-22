<?php
/**
 * Tests for the Artifact Verification Gate (Phase B.3).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test post-mutation verification decisions.
 */
class Test_Artifact_Verification_Gate extends WP_UnitTestCase {

	/**
	 * Verifier slug used by gate-test cases.
	 *
	 * @var string
	 */
	private $verifier_slug = 'gate_test_required';

	/**
	 * Set up a deterministic required-output verifier.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Artifact_Verification_Gate' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Verification_Gate class not available.' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Rule_Verifier' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Rule_Verifier class not available.' );
		}

		if ( class_exists( 'WP_MCP_AI_Verifier_Registry' ) ) {
			WP_MCP_AI_Verifier_Registry::reset_instance();
		}
		WP_MCP_AI_Verifier_Registry::get_instance()->register(
			new WP_MCP_AI_Rule_Verifier(
				$this->verifier_slug,
				array(
					array(
						'type' => 'required',
						'path' => 'value',
					),
				)
			)
		);
	}

	/**
	 * Clean up registry and filter state.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_artifact_verification_mode' );
		remove_all_filters( 'wp_mcp_ai_artifact_verification_decision' );
		if ( class_exists( 'WP_MCP_AI_Verifier_Registry' ) ) {
			WP_MCP_AI_Verifier_Registry::reset_instance();
		}

		parent::tearDown();
	}

	/**
	 * Build a two-case suite using the deterministic verifier.
	 *
	 * @return WP_MCP_AI_Eval_Suite
	 */
	private function build_suite() {
		return new WP_MCP_AI_Eval_Suite(
			array(
				'slug'  => 'gate-suite',
				'cases' => array(
					array(
						'slug'          => 'case-a',
						'verifier_slug' => $this->verifier_slug,
					),
					array(
						'slug'          => 'case-b',
						'verifier_slug' => $this->verifier_slug,
					),
				),
			)
		);
	}

	/**
	 * A generator that always fails (empty output).
	 *
	 * @return callable
	 */
	private function failing_generator() {
		return static function () {
			return array( 'output' => '' );
		};
	}

	/**
	 * A generator that always passes.
	 *
	 * @return callable
	 */
	private function passing_generator() {
		return static function () {
			return array( 'output' => 'A useful answer.' );
		};
	}

	/**
	 * Improve mode: candidate fixing failures is accepted.
	 */
	public function test_improve_mode_accepts_when_candidate_improves() {
		$result = WP_MCP_AI_Artifact_Verification_Gate::evaluate(
			$this->failing_generator(),
			$this->passing_generator(),
			$this->build_suite()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'accept', $result['decision'] );
		$this->assertSame( 2, $result['improved_cases'] );
		$this->assertSame( 0, $result['regressed_cases'] );
	}

	/**
	 * Improve mode: a non-improving candidate is rejected.
	 */
	public function test_improve_mode_rejects_when_candidate_does_not_improve() {
		$result = WP_MCP_AI_Artifact_Verification_Gate::evaluate(
			$this->failing_generator(),
			$this->failing_generator(),
			$this->build_suite()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 0, $result['improved_cases'] );
	}

	/**
	 * No_regression mode: regressions are rejected.
	 */
	public function test_no_regression_mode_rejects_regressions() {
		$result = WP_MCP_AI_Artifact_Verification_Gate::evaluate(
			$this->passing_generator(),
			$this->failing_generator(),
			$this->build_suite(),
			array( 'mode' => WP_MCP_AI_Artifact_Verification_Gate::MODE_NO_REGRESSION )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 2, $result['regressed_cases'] );
	}

	/**
	 * No_regression mode: equal performance is accepted.
	 */
	public function test_no_regression_mode_accepts_equal_performance() {
		$result = WP_MCP_AI_Artifact_Verification_Gate::evaluate(
			$this->passing_generator(),
			$this->passing_generator(),
			$this->build_suite(),
			array( 'mode' => WP_MCP_AI_Artifact_Verification_Gate::MODE_NO_REGRESSION )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'accept', $result['decision'] );
	}

	/**
	 * Non-callable generators return WP_Error.
	 */
	public function test_non_callable_generator_returns_error() {
		$result = WP_MCP_AI_Artifact_Verification_Gate::evaluate(
			'not-callable',
			$this->passing_generator(),
			$this->build_suite()
		);

		$this->assertWPError( $result );
	}

	/**
	 * An empty suite skips (no signal).
	 */
	public function test_empty_suite_skips() {
		$empty = new WP_MCP_AI_Eval_Suite( array( 'slug' => 'gate-empty' ) );

		$result = WP_MCP_AI_Artifact_Verification_Gate::evaluate(
			$this->passing_generator(),
			$this->passing_generator(),
			$empty
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'skip', $result['decision'] );
	}

	/**
	 * The decision filter can override the verdict.
	 */
	public function test_decision_filter_can_override() {
		add_filter(
			'wp_mcp_ai_artifact_verification_decision',
			static function ( $decision ) {
				$decision['decision'] = 'reject';

				return $decision;
			}
		);

		$result = WP_MCP_AI_Artifact_Verification_Gate::evaluate(
			$this->failing_generator(),
			$this->passing_generator(),
			$this->build_suite()
		);

		$this->assertSame( 'reject', $result['decision'] );
	}
}
