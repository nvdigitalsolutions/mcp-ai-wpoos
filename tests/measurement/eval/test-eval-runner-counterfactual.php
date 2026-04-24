<?php
/**
 * Tests for WP_MCP_AI_Eval_Runner::run_counterfactual().
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! class_exists( 'ER_CF_Length_Verifier' ) ) {
	/**
	 * Length verifier local to this test file (independent of the
	 * one in test-counterfactual-runner.php to avoid load-order
	 * coupling).
	 */
	class ER_CF_Length_Verifier extends WP_MCP_AI_Verifier_Base {
		public function __construct() {
			$this->slug                 = 'er_cf_length';
			$this->kind                 = 'rule';
			$this->label                = 'ER CF Length';
			$this->independence_profile = array(
				'disallowed_providers' => array(),
				'disallowed_models'    => array(),
				'disallowed_tools'     => array(),
				'allowed_domains'      => array(),
			);
		}
		public function verify( array $subject, array $context = array() ) {
			$value = array_key_exists( 'value', $subject ) ? $subject['value'] : '';
			$len   = is_string( $value ) ? strlen( $value ) : 0;
			$score = min( 1.0, $len / 40.0 );
			return $score >= 0.5
				? $this->result_pass( $score, 1.0, array() )
				: $this->result_fail( $score, 1.0, array() );
		}
	}
}

/**
 * Eval runner counterfactual mode.
 */
class Test_WP_MCP_AI_Eval_Runner_Counterfactual extends WP_UnitTestCase {

	/**
	 * @var WP_MCP_AI_Eval_Runner
	 */
	private $runner;

	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Verifier_Registry::reset_instance();
		$verifiers = WP_MCP_AI_Verifier_Registry::get_instance();
		$verifiers->register( new ER_CF_Length_Verifier() );
		$this->runner = new WP_MCP_AI_Eval_Runner( $verifiers );
	}

	public function tearDown(): void {
		WP_MCP_AI_Verifier_Registry::reset_instance();
		parent::tearDown();
	}

	public function test_run_counterfactual_produces_summary_and_case_reports() {
		$suite = new WP_MCP_AI_Eval_Suite(
			array(
				'slug'  => 'cf_suite',
				'cases' => array(
					array(
						'slug'          => 'long',
						'verifier_slug' => 'er_cf_length',
						'input'         => 'prompt',
					),
					array(
						'slug'          => 'short',
						'verifier_slug' => 'er_cf_length',
						'input'         => 'p',
					),
				),
			)
		);

		$generator = static function ( $case ) {
			return array( 'output' => 'short' === $case->get_slug() ? 'ab' : str_repeat( 'a', 40 ) );
		};

		$report = $this->runner->run_counterfactual( $suite, $generator, array( 'counterfactual_variants' => array( 'truncate_to_prefix' ) ) );

		$this->assertSame( 'counterfactual', $report['mode'] );
		$this->assertCount( 2, $report['cases'] );
		$this->assertArrayHasKey( 'counterfactual_rate', $report['summary'] );
		$this->assertArrayHasKey( 'counterfactual_flat_rate', $report['summary'] );

		// The long case wins strictly over its truncated variant; the
		// short case scores at the floor for both so it is neither
		// preferred nor strictly flat (variant == candidate == 0.05).
		$by_slug = array();
		foreach ( $report['cases'] as $r ) {
			$by_slug[ $r['case']['slug'] ] = $r;
		}
		$this->assertTrue( $by_slug['long']['preferred'] );
	}

	public function test_run_counterfactual_generator_invalid_return_records_error() {
		$suite = new WP_MCP_AI_Eval_Suite(
			array(
				'slug'  => 'cf_err',
				'cases' => array(
					array(
						'slug'          => 'c',
						'verifier_slug' => 'er_cf_length',
						'input'         => 'x',
					),
				),
			)
		);

		$report = $this->runner->run_counterfactual(
			$suite,
			static function () {
				return 'not an array';
			}
		);

		$this->assertSame( 1, $report['summary']['errors'] );
		$this->assertSame( 0, $report['summary']['preferred'] );
	}

	public function test_run_counterfactual_rejects_non_callable_generator() {
		$suite  = new WP_MCP_AI_Eval_Suite(
			array(
				'slug'  => 'cf_no_gen',
				'cases' => array(),
			)
		);
		$report = $this->runner->run_counterfactual( $suite, 'not_a_callable_xyz' );
		$this->assertSame( 'generator_not_callable', $report['error'] );
	}

	public function test_run_counterfactual_fires_completion_action() {
		$captured = array();
		add_action(
			'wp_mcp_ai_eval_counterfactual_completed',
			static function ( $report ) use ( &$captured ) {
				$captured[] = $report;
			}
		);

		$suite = new WP_MCP_AI_Eval_Suite(
			array(
				'slug'  => 'cf_action',
				'cases' => array(
					array(
						'slug'          => 'c',
						'verifier_slug' => 'er_cf_length',
						'input'         => 'prompt',
					),
				),
			)
		);
		$this->runner->run_counterfactual(
			$suite,
			static function () {
				return array( 'output' => str_repeat( 'a', 40 ) );
			}
		);

		remove_all_actions( 'wp_mcp_ai_eval_counterfactual_completed' );
		$this->assertCount( 1, $captured );
		$this->assertSame( 'counterfactual', $captured[0]['mode'] );
	}
}
