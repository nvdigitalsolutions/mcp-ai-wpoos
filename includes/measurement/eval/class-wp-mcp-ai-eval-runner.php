<?php
/**
 * Eval Runner
 *
 * Executes an {@see WP_MCP_AI_Eval_Suite} against a generator callable and
 * produces a structured report. For each case:
 *
 * 1. The generator callable is invoked with the case input.
 * 2. The resulting output is handed to the verifier declared by the case,
 *    using the verifier registry's `run()` which enforces independence.
 * 3. The verifier result is recorded as metrics via the collector
 *    (`eval.case.passed`, `eval.case.score`, `eval.case.confidence`,
 *    `eval.case.latency_ms`).
 * 4. Optional rewards declared on the runner are evaluated with the
 *    canonical input tuple (verifier_passed, verifier_confidence,
 *    stated_confidence, cost_usd, budget_usd) and recorded as rewards.
 *
 * Anti-Goodhart notes baked in:
 *  - Abstentions (verifier `score ≈ 0.5`, `confidence = 0`) are counted
 *    separately and DO NOT pass. The summary surfaces `abstention_rate`
 *    as a first-class metric.
 *  - A single WP_Error from either the generator or the verifier becomes
 *    a counted "error" rather than killing the whole run — the summary
 *    shows how many cases failed to even produce a verdict.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eval Runner.
 */
class WP_MCP_AI_Eval_Runner {

	/**
	 * Verifier registry.
	 *
	 * @var WP_MCP_AI_Verifier_Registry
	 */
	private $verifiers;

	/**
	 * Reward registry.
	 *
	 * @var WP_MCP_AI_Reward_Function_Registry
	 */
	private $rewards;

	/**
	 * Metric collector.
	 *
	 * @var WP_MCP_AI_Metric_Collector
	 */
	private $collector;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Verifier_Registry|null        $verifiers Verifier registry (optional).
	 * @param WP_MCP_AI_Reward_Function_Registry|null $rewards   Reward registry (optional).
	 * @param WP_MCP_AI_Metric_Collector|null         $collector Collector (optional).
	 */
	public function __construct(
		$verifiers = null,
		$rewards = null,
		$collector = null
	) {
		$this->verifiers = $verifiers instanceof WP_MCP_AI_Verifier_Registry
			? $verifiers
			: WP_MCP_AI_Verifier_Registry::get_instance();
		$this->rewards   = $rewards instanceof WP_MCP_AI_Reward_Function_Registry
			? $rewards
			: WP_MCP_AI_Reward_Function_Registry::get_instance();
		$this->collector = $collector instanceof WP_MCP_AI_Metric_Collector
			? $collector
			: WP_MCP_AI_Metric_Collector::get_instance();
	}

	/**
	 * Run a suite.
	 *
	 * The generator callable signature is:
	 *   function ( WP_MCP_AI_Eval_Case $case, array $suite_context ): array|WP_Error
	 *
	 * The array must contain at least an `output` key (the subject fed to
	 * the verifier). Optional keys are passed through:
	 *   - stated_confidence (float 0..1)
	 *   - cost_usd (float)
	 *   - budget_usd (float)
	 *   - provider_context (array, used for verifier independence)
	 *
	 * @param WP_MCP_AI_Eval_Suite $suite            Suite.
	 * @param callable             $generator        Generator callable.
	 * @param array                $options          Runner options.
	 * @return array                                 Report.
	 */
	public function run( WP_MCP_AI_Eval_Suite $suite, $generator, array $options = array() ) {
		if ( ! is_callable( $generator ) ) {
			return array(
				'suite'   => $suite->to_array(),
				'error'   => 'generator_not_callable',
				'summary' => $this->empty_summary(),
				'cases'   => array(),
			);
		}

		$reward_slugs = isset( $options['rewards'] ) && is_array( $options['rewards'] ) ? $options['rewards'] : array();
		$started_at   = microtime( true );
		$case_reports = array();

		foreach ( $suite->get_cases() as $case ) {
			$case_reports[] = $this->run_case( $case, $suite, $generator, $reward_slugs );
		}

		$summary = $this->summarize( $case_reports );

		$report = array(
			'suite'       => $suite->to_array(),
			'summary'     => $summary,
			'cases'       => $case_reports,
			'duration_ms' => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			'started_at'  => (int) $started_at,
		);

		/**
		 * Fires when an eval suite has finished running.
		 *
		 * @since 1.3.0
		 *
		 * @param array                $report Report.
		 * @param WP_MCP_AI_Eval_Suite $suite  Suite.
		 */
		do_action( 'wp_mcp_ai_eval_suite_completed', $report, $suite );

		return $report;
	}

	/**
	 * Run a single case.
	 *
	 * @param WP_MCP_AI_Eval_Case  $case         Case.
	 * @param WP_MCP_AI_Eval_Suite $suite        Suite.
	 * @param callable             $generator    Generator.
	 * @param array                $reward_slugs Reward slugs to evaluate.
	 * @return array
	 */
	private function run_case( WP_MCP_AI_Eval_Case $case, WP_MCP_AI_Eval_Suite $suite, $generator, array $reward_slugs ) {
		$case_started = microtime( true );

		$generation = call_user_func(
			$generator,
			$case,
			array(
				'suite_slug'        => $suite->get_slug(),
				'generator_context' => $suite->get_generator_context(),
			)
		);

		$case_latency_ms = (int) round( ( microtime( true ) - $case_started ) * 1000 );

		if ( is_wp_error( $generation ) ) {
			return $this->error_case_report( $case, 'generator_error', $generation->get_error_message(), $case_latency_ms );
		}
		if ( ! is_array( $generation ) || ! array_key_exists( 'output', $generation ) ) {
			return $this->error_case_report( $case, 'generator_invalid_return', 'Generator must return an array with an "output" key.', $case_latency_ms );
		}

		$output            = $generation['output'];
		$stated_confidence = isset( $generation['stated_confidence'] ) ? (float) $generation['stated_confidence'] : null;
		$cost_usd          = isset( $generation['cost_usd'] ) ? (float) $generation['cost_usd'] : 0.0;
		$budget_usd        = isset( $generation['budget_usd'] ) ? (float) $generation['budget_usd'] : ( isset( $generation['cost_usd'] ) ? max( 0.000001, (float) $generation['cost_usd'] ) : 1.0 );
		$provider_context  = isset( $generation['provider_context'] ) && is_array( $generation['provider_context'] )
			? $generation['provider_context']
			: $suite->get_generator_context();

		$subject          = array(
			'value'    => $output,
			'input'    => $case->get_input(),
			'expected' => $case->get_expected(),
		);
		$verifier_context = array_merge( $case->get_verifier_args(), array( 'eval_case' => $case->get_slug() ) );

		$verifier_result = $this->verifiers->run(
			$case->get_verifier_slug(),
			$subject,
			$verifier_context,
			$provider_context
		);

		if ( is_wp_error( $verifier_result ) ) {
			return $this->error_case_report( $case, 'verifier_error', $verifier_result->get_error_message(), $case_latency_ms, $output );
		}

		$passed     = ! empty( $verifier_result['passed'] );
		$score      = isset( $verifier_result['score'] ) ? (float) $verifier_result['score'] : 0.0;
		$confidence = isset( $verifier_result['confidence'] ) ? (float) $verifier_result['confidence'] : 0.0;
		$abstained  = ! empty( $verifier_result['evidence']['abstained'] );

		// Emit metrics (best-effort — if the metric id is not registered the
		// collector simply drops the event, which keeps the runner usable
		// before site authors have registered their full metric catalogue).
		$this->collector->record(
			'eval.case.passed',
			$passed ? 1 : 0,
			array(
				'suite' => $suite->get_slug(),
				'case'  => $case->get_slug(),
			)
		);
		$this->collector->record(
			'eval.case.score',
			$score,
			array(
				'suite' => $suite->get_slug(),
				'case'  => $case->get_slug(),
			)
		);
		$this->collector->record(
			'eval.case.confidence',
			$confidence,
			array(
				'suite' => $suite->get_slug(),
				'case'  => $case->get_slug(),
			)
		);
		$this->collector->record(
			'eval.case.latency_ms',
			$case_latency_ms,
			array(
				'suite' => $suite->get_slug(),
				'case'  => $case->get_slug(),
			)
		);
		if ( $abstained ) {
			$this->collector->record(
				'eval.case.abstained',
				1,
				array(
					'suite' => $suite->get_slug(),
					'case'  => $case->get_slug(),
				)
			);
		}

		$rewards = array();
		foreach ( $reward_slugs as $reward_slug ) {
			$value = $this->rewards->evaluate(
				$reward_slug,
				array(
					'verifier_passed'     => $passed,
					'verifier_confidence' => $confidence,
					'stated_confidence'   => null !== $stated_confidence ? $stated_confidence : $confidence,
					'cost_usd'            => $cost_usd,
					'budget_usd'          => $budget_usd,
				),
				array(
					'suite' => $suite->get_slug(),
					'case'  => $case->get_slug(),
				)
			);
			if ( is_wp_error( $value ) ) {
				$rewards[ $reward_slug ] = array( 'error' => $value->get_error_message() );
			} else {
				$rewards[ $reward_slug ] = (float) $value;
				$this->collector->record(
					'eval.reward.' . $reward_slug,
					(float) $value,
					array(
						'suite' => $suite->get_slug(),
						'case'  => $case->get_slug(),
					)
				);
			}
		}

		return array(
			'case'              => $case->to_array(),
			'passed'            => $passed,
			'abstained'         => $abstained,
			'score'             => $score,
			'confidence'        => $confidence,
			'stated_confidence' => $stated_confidence,
			'latency_ms'        => $case_latency_ms,
			'cost_usd'          => $cost_usd,
			'reasons'           => isset( $verifier_result['reasons'] ) ? $verifier_result['reasons'] : array(),
			'rewards'           => $rewards,
		);
	}

	/**
	 * Build an error case report.
	 *
	 * @param WP_MCP_AI_Eval_Case $case       Case.
	 * @param string              $code       Error code.
	 * @param string              $message    Error message.
	 * @param int                 $latency_ms Latency so far.
	 * @param mixed               $output     Optional generator output.
	 * @return array
	 */
	private function error_case_report( WP_MCP_AI_Eval_Case $case, $code, $message, $latency_ms, $output = null ) {
		return array(
			'case'       => $case->to_array(),
			'passed'     => false,
			'abstained'  => false,
			'score'      => 0.0,
			'confidence' => 0.0,
			'latency_ms' => (int) $latency_ms,
			'error'      => array(
				'code'    => (string) $code,
				'message' => (string) $message,
			),
			'output'     => null !== $output ? true : false,
		);
	}

	/**
	 * Aggregate case reports into a summary.
	 *
	 * @param array $case_reports Case reports.
	 * @return array
	 */
	private function summarize( array $case_reports ) {
		$total = count( $case_reports );
		if ( 0 === $total ) {
			return $this->empty_summary();
		}

		$passed        = 0;
		$abstained     = 0;
		$errors        = 0;
		$scores        = array();
		$confidences   = array();
		$reward_totals = array();

		foreach ( $case_reports as $r ) {
			if ( ! empty( $r['error'] ) ) {
				++$errors;
				continue;
			}
			if ( ! empty( $r['abstained'] ) ) {
				++$abstained;
			}
			if ( ! empty( $r['passed'] ) ) {
				++$passed;
			}
			$scores[]      = isset( $r['score'] ) ? (float) $r['score'] : 0.0;
			$confidences[] = isset( $r['confidence'] ) ? (float) $r['confidence'] : 0.0;
			if ( ! empty( $r['rewards'] ) && is_array( $r['rewards'] ) ) {
				foreach ( $r['rewards'] as $slug => $value ) {
					if ( is_numeric( $value ) ) {
						if ( ! isset( $reward_totals[ $slug ] ) ) {
							$reward_totals[ $slug ] = array(
								'sum'   => 0.0,
								'count' => 0,
							);
						}
						$reward_totals[ $slug ]['sum'] += (float) $value;
						++$reward_totals[ $slug ]['count'];
					}
				}
			}
		}

		$reward_means = array();
		foreach ( $reward_totals as $slug => $t ) {
			$reward_means[ $slug ] = $t['count'] > 0 ? (float) ( $t['sum'] / $t['count'] ) : 0.0;
		}

		return array(
			'total'           => $total,
			'passed'          => $passed,
			'abstained'       => $abstained,
			'errors'          => $errors,
			'pass_rate'       => $total > 0 ? (float) ( $passed / $total ) : 0.0,
			'abstention_rate' => $total > 0 ? (float) ( $abstained / $total ) : 0.0,
			'error_rate'      => $total > 0 ? (float) ( $errors / $total ) : 0.0,
			'mean_score'      => $this->mean( $scores ),
			'median_score'    => $this->median( $scores ),
			'mean_confidence' => $this->mean( $confidences ),
			'reward_means'    => $reward_means,
		);
	}

	/**
	 * Empty summary for zero-case / error states.
	 *
	 * @return array
	 */
	private function empty_summary() {
		return array(
			'total'           => 0,
			'passed'          => 0,
			'abstained'       => 0,
			'errors'          => 0,
			'pass_rate'       => 0.0,
			'abstention_rate' => 0.0,
			'error_rate'      => 0.0,
			'mean_score'      => 0.0,
			'median_score'    => 0.0,
			'mean_confidence' => 0.0,
			'reward_means'    => array(),
		);
	}

	/**
	 * Arithmetic mean.
	 *
	 * @param array $values Values.
	 * @return float
	 */
	private function mean( array $values ) {
		$n = count( $values );
		if ( 0 === $n ) {
			return 0.0;
		}
		return array_sum( $values ) / $n;
	}

	/**
	 * Median (numeric).
	 *
	 * @param array $values Values.
	 * @return float
	 */
	private function median( array $values ) {
		$n = count( $values );
		if ( 0 === $n ) {
			return 0.0;
		}
		$sorted = $values;
		sort( $sorted, SORT_NUMERIC );
		$mid = (int) floor( $n / 2 );
		if ( 1 === $n % 2 ) {
			return (float) $sorted[ $mid ];
		}
		return ( (float) $sorted[ $mid - 1 ] + (float) $sorted[ $mid ] ) / 2.0;
	}

	/**
	 * Run a suite under counterfactual conditions.
	 *
	 * For each case, the generator is invoked once as normal, then the
	 * {@see WP_MCP_AI_Counterfactual_Runner} evaluates the same verifier
	 * against the candidate and a list of variant subjects produced by
	 * degraders declared at the suite, case, or runner level.
	 *
	 * Generator return shape is the same as `run()`. Additionally, a
	 * case or suite author may expose a `counterfactual_variants`
	 * entry (array of degrader slugs or inline callables) on either
	 * the case's `verifier_args` or the runner `$options`. Runner-level
	 * variants apply to every case; case-level variants override.
	 *
	 * The runner produces an `eval.counterfactual.preferred` metric
	 * per case (1 when the candidate wins strictly, 0 otherwise) and
	 * surfaces `counterfactual_rate` + `counterfactual_flat_rate` in
	 * the summary so a dashboard or alert rule can flag suites whose
	 * verifier lost discriminative power.
	 *
	 * @param WP_MCP_AI_Eval_Suite $suite     Suite.
	 * @param callable             $generator Generator callable.
	 * @param array                $options   Runner options.
	 *                                        Accepts `rewards` (see run())
	 *                                        and `counterfactual_variants`
	 *                                        (array of slugs/callables).
	 * @return array                           Report.
	 */
	public function run_counterfactual( WP_MCP_AI_Eval_Suite $suite, $generator, array $options = array() ) {
		if ( ! is_callable( $generator ) ) {
			return array(
				'suite'   => $suite->to_array(),
				'error'   => 'generator_not_callable',
				'summary' => $this->empty_counterfactual_summary(),
				'cases'   => array(),
			);
		}

		$default_variants = isset( $options['counterfactual_variants'] ) && is_array( $options['counterfactual_variants'] )
			? $options['counterfactual_variants']
			: array( 'shuffle_tokens', 'truncate_to_prefix' );
		$flat_epsilon     = isset( $options['flat_epsilon'] ) ? (float) $options['flat_epsilon'] : WP_MCP_AI_Counterfactual_Runner::DEFAULT_FLAT_EPSILON;

		$counterfactual = new WP_MCP_AI_Counterfactual_Runner( $this->verifiers );
		$started_at     = microtime( true );
		$case_reports   = array();

		foreach ( $suite->get_cases() as $case ) {
			$case_reports[] = $this->run_counterfactual_case( $case, $suite, $generator, $counterfactual, $default_variants, $flat_epsilon );
		}

		$summary = $this->summarize_counterfactual( $case_reports );

		$report = array(
			'suite'       => $suite->to_array(),
			'summary'     => $summary,
			'cases'       => $case_reports,
			'duration_ms' => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			'started_at'  => (int) $started_at,
			'mode'        => 'counterfactual',
		);

		/**
		 * Fires when a counterfactual eval run completes.
		 *
		 * @since 1.3.0
		 *
		 * @param array                $report Report.
		 * @param WP_MCP_AI_Eval_Suite $suite  Suite.
		 */
		do_action( 'wp_mcp_ai_eval_counterfactual_completed', $report, $suite );

		return $report;
	}

	/**
	 * Run a single counterfactual case.
	 *
	 * @param WP_MCP_AI_Eval_Case             $case             Case.
	 * @param WP_MCP_AI_Eval_Suite            $suite            Suite.
	 * @param callable                        $generator        Generator.
	 * @param WP_MCP_AI_Counterfactual_Runner $counterfactual  Helper.
	 * @param array                           $default_variants Runner-level variants.
	 * @param float                           $flat_epsilon     Flat-signal epsilon.
	 * @return array
	 */
	private function run_counterfactual_case(
		WP_MCP_AI_Eval_Case $case,
		WP_MCP_AI_Eval_Suite $suite,
		$generator,
		WP_MCP_AI_Counterfactual_Runner $counterfactual,
		array $default_variants,
		$flat_epsilon
	) {
		$case_started    = microtime( true );
		$generation      = call_user_func(
			$generator,
			$case,
			array(
				'suite_slug'        => $suite->get_slug(),
				'generator_context' => $suite->get_generator_context(),
				'mode'              => 'counterfactual',
			)
		);
		$case_latency_ms = (int) round( ( microtime( true ) - $case_started ) * 1000 );

		if ( is_wp_error( $generation ) ) {
			return array(
				'case'       => $case->to_array(),
				'preferred'  => false,
				'flat'       => false,
				'latency_ms' => $case_latency_ms,
				'error'      => array(
					'code'    => 'generator_error',
					'message' => $generation->get_error_message(),
				),
			);
		}
		if ( ! is_array( $generation ) || ! array_key_exists( 'output', $generation ) ) {
			return array(
				'case'       => $case->to_array(),
				'preferred'  => false,
				'flat'       => false,
				'latency_ms' => $case_latency_ms,
				'error'      => array(
					'code'    => 'generator_invalid_return',
					'message' => 'Generator must return an array with an "output" key.',
				),
			);
		}

		$subject = array(
			'value'    => $generation['output'],
			'input'    => $case->get_input(),
			'expected' => $case->get_expected(),
		);

		$verifier_args = $case->get_verifier_args();
		$variants      = isset( $verifier_args['counterfactual_variants'] ) && is_array( $verifier_args['counterfactual_variants'] )
			? $verifier_args['counterfactual_variants']
			: $default_variants;

		$result = $counterfactual->run(
			$case->get_verifier_slug(),
			$subject,
			$variants,
			array(
				'verifier_context' => array_merge( $verifier_args, array( 'eval_case' => $case->get_slug() ) ),
				'provider_context' => isset( $generation['provider_context'] ) && is_array( $generation['provider_context'] )
					? $generation['provider_context']
					: $suite->get_generator_context(),
				'flat_epsilon'     => $flat_epsilon,
			)
		);

		$preferred = ! empty( $result['preferred'] );
		$flat      = ! empty( $result['flat'] );

		$this->collector->record(
			'eval.counterfactual.preferred',
			$preferred ? 1 : 0,
			array(
				'suite' => $suite->get_slug(),
				'case'  => $case->get_slug(),
			)
		);
		if ( $flat ) {
			$this->collector->record(
				'eval.counterfactual.flat',
				1,
				array(
					'suite' => $suite->get_slug(),
					'case'  => $case->get_slug(),
				)
			);
		}

		return array(
			'case'            => $case->to_array(),
			'preferred'       => $preferred,
			'flat'            => $flat,
			'candidate_score' => isset( $result['candidate_score'] ) ? (float) $result['candidate_score'] : 0.0,
			'variant_scores'  => isset( $result['variant_scores'] ) ? $result['variant_scores'] : array(),
			'reasons'         => isset( $result['reasons'] ) ? $result['reasons'] : array(),
			'latency_ms'      => $case_latency_ms,
		);
	}

	/**
	 * Summarize counterfactual case reports.
	 *
	 * @param array $case_reports Case reports.
	 * @return array
	 */
	private function summarize_counterfactual( array $case_reports ) {
		$total = count( $case_reports );
		if ( 0 === $total ) {
			return $this->empty_counterfactual_summary();
		}

		$preferred = 0;
		$flat      = 0;
		$errors    = 0;
		foreach ( $case_reports as $r ) {
			if ( ! empty( $r['error'] ) ) {
				++$errors;
				continue;
			}
			if ( ! empty( $r['preferred'] ) ) {
				++$preferred;
			}
			if ( ! empty( $r['flat'] ) ) {
				++$flat;
			}
		}

		return array(
			'total'                    => $total,
			'preferred'                => $preferred,
			'flat'                     => $flat,
			'errors'                   => $errors,
			'counterfactual_rate'      => $total > 0 ? (float) ( $preferred / $total ) : 0.0,
			'counterfactual_flat_rate' => $total > 0 ? (float) ( $flat / $total ) : 0.0,
			'error_rate'               => $total > 0 ? (float) ( $errors / $total ) : 0.0,
		);
	}

	/**
	 * Empty counterfactual summary.
	 *
	 * @return array
	 */
	private function empty_counterfactual_summary() {
		return array(
			'total'                    => 0,
			'preferred'                => 0,
			'flat'                     => 0,
			'errors'                   => 0,
			'counterfactual_rate'      => 0.0,
			'counterfactual_flat_rate' => 0.0,
			'error_rate'               => 0.0,
		);
	}
}
