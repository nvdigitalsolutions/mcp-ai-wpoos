<?php
/**
 * Tests for the Eval Runner.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Eval Runner.
 */
class Test_WP_MCP_AI_Eval_Runner extends WP_UnitTestCase {

	/**
	 * @var WP_MCP_AI_Verifier_Registry
	 */
	private $verifiers;

	/**
	 * @var WP_MCP_AI_Reward_Function_Registry
	 */
	private $rewards;

	/**
	 * @var WP_MCP_AI_Metric_Collector
	 */
	private $collector;

	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Verifier_Registry::reset_instance();
		WP_MCP_AI_Reward_Function_Registry::reset_instance();

		$this->verifiers = WP_MCP_AI_Verifier_Registry::get_instance();
		$this->rewards   = WP_MCP_AI_Reward_Function_Registry::get_instance();
		$this->collector = WP_MCP_AI_Metric_Collector::get_instance();
		$this->collector->clear_buffer();

		// A deterministic rule verifier: requires `value` to be non-empty.
		$this->verifiers->register(
			new WP_MCP_AI_Rule_Verifier(
				'runner_test_rule',
				array(
					array( 'type' => 'required', 'path' => 'value' ),
				)
			)
		);

		WP_MCP_AI_Reference_Rewards::register( $this->rewards );
	}

	public function tearDown(): void {
		WP_MCP_AI_Verifier_Registry::reset_instance();
		WP_MCP_AI_Reward_Function_Registry::reset_instance();
		parent::tearDown();
	}

	/**
	 * Build a suite with a single passing case.
	 */
	private function passing_suite() {
		return new WP_MCP_AI_Eval_Suite(
			array(
				'slug'  => 't',
				'cases' => array(
					array( 'slug' => 'c1', 'verifier_slug' => 'runner_test_rule' ),
				),
			)
		);
	}

	public function test_run_reports_passing_case() {
		$runner    = new WP_MCP_AI_Eval_Runner( $this->verifiers, $this->rewards, $this->collector );
		$generator = static function () {
			return array( 'output' => 'hello', 'stated_confidence' => 0.9, 'cost_usd' => 0.01, 'budget_usd' => 1.0 );
		};
		$report = $runner->run( $this->passing_suite(), $generator, array( 'rewards' => array( 'verified_success' ) ) );
		$this->assertSame( 1, $report['summary']['total'] );
		$this->assertSame( 1, $report['summary']['passed'] );
		$this->assertSame( 1.0, $report['summary']['pass_rate'] );
		$this->assertArrayHasKey( 'verified_success', $report['summary']['reward_means'] );
		$this->assertSame( 1.0, $report['summary']['reward_means']['verified_success'] );
		$this->assertTrue( $report['cases'][0]['passed'] );
	}

	public function test_run_reports_failing_case() {
		$runner    = new WP_MCP_AI_Eval_Runner( $this->verifiers, $this->rewards, $this->collector );
		$generator = static function () {
			return array( 'output' => '' );
		};
		$report = $runner->run( $this->passing_suite(), $generator );
		$this->assertSame( 0, $report['summary']['passed'] );
		$this->assertFalse( $report['cases'][0]['passed'] );
	}

	public function test_generator_wp_error_becomes_error_case() {
		$runner    = new WP_MCP_AI_Eval_Runner( $this->verifiers, $this->rewards, $this->collector );
		$generator = static function () {
			return new WP_Error( 'boom', 'no' );
		};
		$report = $runner->run( $this->passing_suite(), $generator );
		$this->assertSame( 0, $report['summary']['passed'] );
		$this->assertSame( 1, $report['summary']['errors'] );
		$this->assertSame( 'generator_error', $report['cases'][0]['error']['code'] );
	}

	public function test_invalid_generator_return_becomes_error_case() {
		$runner    = new WP_MCP_AI_Eval_Runner( $this->verifiers, $this->rewards, $this->collector );
		$generator = static function () {
			return 'not an array';
		};
		$report = $runner->run( $this->passing_suite(), $generator );
		$this->assertSame( 'generator_invalid_return', $report['cases'][0]['error']['code'] );
	}

	public function test_independence_violation_becomes_verifier_error() {
		// Register a verifier that disallows provider=openai.
		$this->verifiers->register(
			new WP_MCP_AI_Rule_Verifier(
				'strict_verifier',
				array( array( 'type' => 'required', 'path' => 'value' ) )
			)
		);
		// Inject disallowed provider via reflection by swapping the profile.
		$v   = $this->verifiers->get( 'strict_verifier' );
		$ref = new ReflectionObject( $v );
		$p   = $ref->getProperty( 'independence_profile' );
		$p->setAccessible( true );
		$p->setValue( $v, array( 'disallowed_providers' => array( 'openai' ), 'disallowed_models' => array(), 'disallowed_tools' => array() ) );

		$suite = new WP_MCP_AI_Eval_Suite(
			array(
				'slug'              => 't',
				'generator_context' => array( 'provider' => 'openai' ),
				'cases'             => array(
					array( 'slug' => 'c1', 'verifier_slug' => 'strict_verifier' ),
				),
			)
		);

		$runner    = new WP_MCP_AI_Eval_Runner( $this->verifiers, $this->rewards, $this->collector );
		$generator = static function () {
			return array( 'output' => 'hi' );
		};
		$report = $runner->run( $suite, $generator );
		$this->assertSame( 1, $report['summary']['errors'] );
		$this->assertSame( 'verifier_error', $report['cases'][0]['error']['code'] );
	}

	public function test_abstention_is_counted_separately_and_not_passed() {
		// An LLM judge with no callable abstains.
		$this->verifiers->register( new WP_MCP_AI_LLM_Judge_Verifier( 'abstainer' ) );
		$suite = new WP_MCP_AI_Eval_Suite(
			array(
				'slug'  => 't',
				'cases' => array( array( 'slug' => 'c1', 'verifier_slug' => 'abstainer' ) ),
			)
		);
		$runner    = new WP_MCP_AI_Eval_Runner( $this->verifiers, $this->rewards, $this->collector );
		$generator = static function () {
			return array( 'output' => 'hi' );
		};
		$report = $runner->run( $suite, $generator );
		$this->assertSame( 0, $report['summary']['passed'] );
		$this->assertSame( 1, $report['summary']['abstained'] );
		$this->assertSame( 1.0, $report['summary']['abstention_rate'] );
	}

	public function test_non_callable_generator_returns_error_report() {
		$runner = new WP_MCP_AI_Eval_Runner( $this->verifiers, $this->rewards, $this->collector );
		$report = $runner->run( $this->passing_suite(), 'not_a_function_that_exists_anywhere' );
		$this->assertSame( 'generator_not_callable', $report['error'] );
		$this->assertSame( 0, $report['summary']['total'] );
	}

	public function test_completion_action_fires() {
		$received = null;
		add_action(
			'wp_mcp_ai_eval_suite_completed',
			static function ( $report ) use ( &$received ) {
				$received = $report;
			}
		);
		$runner    = new WP_MCP_AI_Eval_Runner( $this->verifiers, $this->rewards, $this->collector );
		$generator = static function () {
			return array( 'output' => 'hi' );
		};
		$runner->run( $this->passing_suite(), $generator );
		$this->assertNotNull( $received );
		$this->assertSame( 1, $received['summary']['total'] );
	}

	public function test_median_and_mean_score() {
		// Three cases with different required paths to yield varied outcomes.
		$this->verifiers->register(
			new WP_MCP_AI_Rule_Verifier(
				'need_a_and_b',
				array(
					array( 'type' => 'required', 'path' => 'value.a' ),
					array( 'type' => 'required', 'path' => 'value.b' ),
				)
			)
		);
		$suite = new WP_MCP_AI_Eval_Suite(
			array(
				'slug'  => 't',
				'cases' => array(
					array( 'slug' => 'c1', 'verifier_slug' => 'need_a_and_b', 'input' => array( 'n' => 1 ) ),
					array( 'slug' => 'c2', 'verifier_slug' => 'need_a_and_b', 'input' => array( 'n' => 2 ) ),
					array( 'slug' => 'c3', 'verifier_slug' => 'need_a_and_b', 'input' => array( 'n' => 3 ) ),
				),
			)
		);
		// n=1 → a only (0.5), n=2 → a+b (1.0), n=3 → neither (0.0).
		$generator = static function ( $case ) {
			$n = $case->get_input()['n'];
			if ( 1 === $n ) {
				return array( 'output' => array( 'a' => 'yes' ) );
			}
			if ( 2 === $n ) {
				return array( 'output' => array( 'a' => 'yes', 'b' => 'yes' ) );
			}
			return array( 'output' => array() );
		};
		$runner = new WP_MCP_AI_Eval_Runner( $this->verifiers, $this->rewards, $this->collector );
		$report = $runner->run( $suite, $generator );
		$this->assertEqualsWithDelta( 0.5, $report['summary']['mean_score'], 0.0001 );
		$this->assertEqualsWithDelta( 0.5, $report['summary']['median_score'], 0.0001 );
		$this->assertSame( 1, $report['summary']['passed'] );
	}
}
