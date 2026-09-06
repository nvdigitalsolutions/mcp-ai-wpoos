<?php
/**
 * Artifact Verification Gate — post-mutation verification for evolved artifacts.
 *
 * Runs the incumbent and candidate artifacts (as generator callables) over
 * the same eval suite and decides whether the candidate may proceed. This is
 * Imbue's post-mutation verification step applied to the Continual Harness:
 * a candidate that does not improve on the failure cases it was meant to fix
 * is dismissed before any further evaluation or storage.
 *
 * Modes:
 *   - `improve` (default, Imbue): accept iff the candidate passes at least
 *     one case the incumbent failed.
 *   - `no_regression`: accept iff the candidate fails no case the incumbent
 *     passed and its pass rate is within `tolerance` of the incumbent's.
 *
 * The final verdict is filterable via `wp_mcp_ai_artifact_verification_decision`
 * so site owners can apply their own acceptance policy.
 *
 * @package WP_MCP_AI
 * @subpackage WP_MCP_AI/includes/harness
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @reference Imbue (2026). "LLM-based Evolution as a Universal Optimizer."
 *   https://imbue.com/blog/2026-02-27-darwinian-evolver
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artifact Verification Gate class.
 *
 * @since 1.9.0
 */
class ArtifactVerificationGate {

	/**
	 * Accept when the candidate improves on at least one failure case.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const MODE_IMPROVE = 'improve';

	/**
	 * Accept when the candidate causes no regressions (within tolerance).
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const MODE_NO_REGRESSION = 'no_regression';

	/**
	 * Default pass-rate tolerance for no_regression mode.
	 *
	 * @since 1.9.0
	 * @var   float
	 */
	const DEFAULT_TOLERANCE = 0.05;

	/**
	 * Evaluate an incumbent and a candidate artifact over a suite.
	 *
	 * @since 1.9.0
	 *
	 * @param callable             $incumbent_generator Generator for the current artifact.
	 * @param callable             $candidate_generator Generator for the proposed artifact.
	 * @param WP_MCP_AI_Eval_Suite $suite               Suite to score both against.
	 * @param array                $options             Options: `mode` (improve|no_regression),
	 *                                                   `tolerance` (float), `runner_options` (array).
	 * @return array|WP_Error Decision payload, or WP_Error on setup failure.
	 */
	public static function evaluate( $incumbent_generator, $candidate_generator, $suite, array $options = array() ) {
		if ( ! is_callable( $incumbent_generator ) || ! is_callable( $candidate_generator ) ) {
			return new \WP_Error(
				'wp_mcp_ai_verification_generator_not_callable',
				__( 'Both generators must be callable.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Eval_Runner' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_verification_no_runner',
				__( 'The eval runner subsystem is not available.', 'nvoos-content-graph-ai-platform' )
			);
		}

		/**
		 * Filters the verification mode.
		 *
		 * @since 1.9.0
		 *
		 * @param string               $mode  Verification mode. Default MODE_IMPROVE.
		 * @param WP_MCP_AI_Eval_Suite $suite Suite being verified against.
		 */
		$mode = isset( $options['mode'] ) && '' !== (string) $options['mode']
			? (string) $options['mode']
			: (string) apply_filters( 'wp_mcp_ai_artifact_verification_mode', self::MODE_IMPROVE, $suite );
		if ( self::MODE_NO_REGRESSION !== $mode ) {
			$mode = self::MODE_IMPROVE;
		}
		$tolerance      = isset( $options['tolerance'] ) ? max( 0.0, min( 1.0, (float) $options['tolerance'] ) ) : self::DEFAULT_TOLERANCE;
		$runner_options = isset( $options['runner_options'] ) && is_array( $options['runner_options'] ) ? $options['runner_options'] : array();

		if ( 0 === $suite->count_cases() ) {
			return array(
				'decision' => 'skip',
				'mode'     => $mode,
				'reason'   => __( 'The verification suite contains no cases.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		$runner    = new \WP_MCP_AI_Eval_Runner();
		$incumbent = $runner->run( $suite, $incumbent_generator, $runner_options );
		$candidate = $runner->run( $suite, $candidate_generator, $runner_options );

		$incumbent_by_case = self::index_case_reports( $incumbent );
		$candidate_by_case = self::index_case_reports( $candidate );

		$improved  = 0;
		$regressed = 0;
		$errors    = 0;

		foreach ( $incumbent_by_case as $slug => $incumbent_report ) {
			if ( ! isset( $candidate_by_case[ $slug ] ) ) {
				++$errors;
				continue;
			}
			$candidate_report = $candidate_by_case[ $slug ];

			if ( ! empty( $incumbent_report['error'] ) || ! empty( $candidate_report['error'] ) ) {
				++$errors;
				continue;
			}

			$incumbent_passed = ! empty( $incumbent_report['passed'] );
			$candidate_passed = ! empty( $candidate_report['passed'] );

			if ( $candidate_passed && ! $incumbent_passed ) {
				++$improved;
			} elseif ( ! $candidate_passed && $incumbent_passed ) {
				++$regressed;
			}
		}

		$incumbent_pass_rate = isset( $incumbent['summary']['pass_rate'] ) ? (float) $incumbent['summary']['pass_rate'] : 0.0;
		$candidate_pass_rate = isset( $candidate['summary']['pass_rate'] ) ? (float) $candidate['summary']['pass_rate'] : 0.0;

		if ( self::MODE_NO_REGRESSION === $mode ) {
			$accepted = ( 0 === $regressed ) && ( $candidate_pass_rate >= ( $incumbent_pass_rate - $tolerance ) );
			$reason   = $accepted
				? __( 'Candidate caused no regressions and held the pass rate within tolerance.', 'nvoos-content-graph-ai-platform' )
				: __( 'Candidate regressed on cases the incumbent passed, or dropped the pass rate beyond tolerance.', 'nvoos-content-graph-ai-platform' );
		} else {
			$accepted = $improved > 0;
			$reason   = $accepted
				? sprintf(
					/* translators: %d: number of improved cases */
					_n( 'Candidate improved on %d failure case.', 'Candidate improved on %d failure cases.', $improved, 'nvoos-content-graph-ai-platform' ),
					$improved
				)
				: __( 'Candidate did not improve on any failure case.', 'nvoos-content-graph-ai-platform' );
		}

		$decision = array(
			'decision'            => $accepted ? 'accept' : 'reject',
			'mode'                => $mode,
			'tolerance'           => $tolerance,
			'improved_cases'      => $improved,
			'regressed_cases'     => $regressed,
			'error_cases'         => $errors,
			'incumbent_pass_rate' => $incumbent_pass_rate,
			'candidate_pass_rate' => $candidate_pass_rate,
			'incumbent_summary'   => isset( $incumbent['summary'] ) ? $incumbent['summary'] : array(),
			'candidate_summary'   => isset( $candidate['summary'] ) ? $candidate['summary'] : array(),
			'reason'              => $reason,
		);

		/**
		 * Filters the final verification decision.
		 *
		 * @since 1.9.0
		 *
		 * @param array                $decision Decision payload.
		 * @param WP_MCP_AI_Eval_Suite $suite    Suite verified against.
		 */
		return (array) apply_filters( 'wp_mcp_ai_artifact_verification_decision', $decision, $suite );
	}

	/**
	 * Index a runner report's case reports by case slug.
	 *
	 * @since 1.9.0
	 *
	 * @param array $report Eval runner report.
	 * @return array<string,array> Case reports keyed by slug.
	 */
	private static function index_case_reports( $report ) {
		$indexed = array();

		if ( ! is_array( $report ) || empty( $report['cases'] ) || ! is_array( $report['cases'] ) ) {
			return $indexed;
		}

		foreach ( $report['cases'] as $case_report ) {
			if ( is_array( $case_report ) && isset( $case_report['case']['slug'] ) ) {
				$indexed[ (string) $case_report['case']['slug'] ] = $case_report;
			}
		}

		return $indexed;
	}
}
