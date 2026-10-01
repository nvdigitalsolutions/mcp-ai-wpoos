<?php
/**
 * Tests for WP_MCP_AI_Verification_Cascade.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the verify-then-escalate cascade service.
 */
class Test_Verification_Cascade extends WP_UnitTestCase {

	/**
	 * Canned decision answers served by the stub client, keyed by qid.
	 *
	 * Public so the stub client's anonymous class can read it.
	 *
	 * @var array
	 */
	public $canned_answers = array();

	/**
	 * Number of decide() calls the stub client received.
	 *
	 * Public so the stub client's anonymous class can increment it.
	 *
	 * @var int
	 */
	public $stub_call_count = 0;

	/**
	 * WP_Error to return from the stub client when set.
	 *
	 * Public so the stub client's anonymous class can read it.
	 *
	 * @var WP_Error|null
	 */
	public $stub_error = null;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-decision-client.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-verification-cascade.php';

		$this->canned_answers  = array();
		$this->stub_call_count = 0;
		$this->stub_error      = null;

		wp_cache_flush();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_cascade_escalate_threshold' );
		remove_all_filters( 'wp_mcp_ai_cascade_metrics' );
		$this->canned_answers = array();
		$this->stub_error     = null;
		wp_cache_flush();
		parent::tearDown();
	}

	/**
	 * Build a stub decision client serving $this->canned_answers.
	 *
	 * @return Interface_WP_MCP_AI_Decision_Client
	 */
	private function stub_client() {
		$test = $this;

		return new class( $test ) implements Interface_WP_MCP_AI_Decision_Client {
			/**
			 * Owning test case.
			 *
			 * @var Test_Verification_Cascade
			 */
			private $test;

			/**
			 * Constructor.
			 *
			 * @param Test_Verification_Cascade $test Owning test.
			 */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * Serve canned noul answers for the requested question ids.
			 *
			 * @param mixed $state     State.
			 * @param array $questions Question map.
			 * @param array $options   Options.
			 * @return array|WP_Error
			 */
			public function decide( $state, $questions, $options = array() ) {
				++$this->test->stub_call_count;

				if ( null !== $this->test->stub_error ) {
					return $this->test->stub_error;
				}

				$answers = array();
				foreach ( $questions as $qid => $question ) {
					$probability = isset( $this->test->canned_answers[ $qid ] ) ? $this->test->canned_answers[ $qid ] : 0.1;

					$answers[ $qid ] = array(
						'type' => 'noul',
						'noul' => $probability,
					);
				}

				return array(
					'model'   => 'jev-1.13.0',
					'answers' => $answers,
					'usage'   => array(
						'input_tokens'  => 100,
						'output_tokens' => 10,
					),
				);
			}

			/**
			 * Provider slug.
			 *
			 * @return string
			 */
			public function get_provider_slug() {
				return 'stub';
			}
		};
	}

	/**
	 * Build a cascade with the stub client injected.
	 *
	 * @return WP_MCP_AI_Verification_Cascade
	 */
	private function cascade() {
		return new WP_MCP_AI_Verification_Cascade( $this->stub_client() );
	}

	// -------------------------------------------------------------------------
	// Battery construction.
	// -------------------------------------------------------------------------

	/**
	 * Test build_battery() emits one noul question per field-metric pair.
	 */
	public function test_build_battery_emits_one_question_per_field_metric() {
		$battery = $this->cascade()->build_battery(
			array(
				'claim'       => 'The sky is blue.',
				'description' => 'Rayleigh scattering.',
			),
			array(
				'claim'       => array(
					'type'        => 'string',
					'description' => 'Claim text',
					'required'    => true,
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Description text',
				),
			),
			'The sky is blue due to Rayleigh scattering.'
		);

		$this->assertCount( 10, $battery['questions'] );

		foreach ( WP_MCP_AI_Verification_Cascade::DEFAULT_METRICS as $metric ) {
			$this->assertArrayHasKey( 'claim__' . $metric, $battery['questions'] );
			$this->assertArrayHasKey( 'description__' . $metric, $battery['questions'] );
		}
	}

	/**
	 * Test battery questions are framed bad = true with explicit criteria.
	 */
	public function test_build_battery_questions_are_bad_true_framed() {
		$battery = $this->cascade()->build_battery(
			array( 'claim' => 'The sky is blue.' ),
			array(
				'claim' => array(
					'type'        => 'string',
					'description' => 'Claim text',
				),
			),
			'The sky is blue.'
		);

		$question = $battery['questions']['claim__hallucinated'];

		$this->assertSame( 'noul', $question['type'] );
		$this->assertArrayHasKey( 'field_spec', $question['instructions'] );
		$this->assertArrayHasKey( 'extracted_field', $question['instructions'] );
		$this->assertArrayHasKey( 'main_question', $question['instructions'] );
		$this->assertArrayHasKey( 'true', $question['criteria'] );
		$this->assertArrayHasKey( 'false', $question['criteria'] );

		// The "true" case must describe the escalate condition (bad).
		$this->assertStringContainsString( 'hallucination', $question['criteria']['true'] );
	}

	/**
	 * Test empty fields get only the absence_wrong head.
	 */
	public function test_build_battery_empty_field_gets_absence_head_only() {
		$battery = $this->cascade()->build_battery(
			array(
				'claim'       => 'The sky is blue.',
				'description' => '',
			),
			array(
				'claim'       => array(
					'type'        => 'string',
					'description' => 'Claim text',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Description text',
				),
			),
			'The sky is blue.'
		);

		$this->assertArrayHasKey( 'description__absence_wrong', $battery['questions'] );
		$this->assertArrayNotHasKey( 'description__hallucinated', $battery['questions'] );
		$this->assertArrayHasKey( 'claim__hallucinated', $battery['questions'] );
	}

	/**
	 * Test a restricted metric subset is respected.
	 */
	public function test_build_battery_respects_metric_subset() {
		$battery = $this->cascade()->build_battery(
			array( 'claim' => 'The sky is blue.' ),
			array(
				'claim' => array(
					'type'        => 'string',
					'description' => 'Claim text',
				),
			),
			'The sky is blue.',
			array( 'hallucinated', 'off_target' )
		);

		$this->assertCount( 2, $battery['questions'] );
		$this->assertArrayHasKey( 'claim__hallucinated', $battery['questions'] );
		$this->assertArrayHasKey( 'claim__off_target', $battery['questions'] );
	}

	/**
	 * Test extracted values are sanitised at entry (two-gate rule, gate one).
	 */
	public function test_build_battery_sanitises_extracted_values() {
		$battery = $this->cascade()->build_battery(
			array( 'claim' => '<script>alert(1)</script>Plain text.' ),
			array(
				'claim' => array(
					'type'        => 'string',
					'description' => 'Claim text',
				),
			),
			'The sky is blue.'
		);

		$instructions = $battery['questions']['claim__hallucinated']['instructions'];

		$this->assertStringNotContainsString( '<script>', $instructions['extracted_field'] );
		$this->assertStringContainsString( 'Plain text.', $instructions['extracted_field'] );
	}

	// -------------------------------------------------------------------------
	// Evaluation.
	// -------------------------------------------------------------------------

	/**
	 * Test evaluate() uses max aggregation — one confident flag fires.
	 */
	public function test_evaluate_max_aggregation_single_flag_fires() {
		$cascade = $this->cascade();
		$battery = $cascade->build_battery(
			array( 'claim' => 'The sky is blue.' ),
			array(
				'claim' => array(
					'type'        => 'string',
					'description' => 'Claim text',
				),
			),
			'The sky is blue.'
		);

		$this->canned_answers = array(
			'claim__hallucinated'     => 0.95,
			'claim__off_target'       => 0.60,
			'claim__incomplete'       => 0.20,
			'claim__format_violation' => 0.10,
			'claim__unreasonable'     => 0.05,
		);

		$evaluation = $cascade->evaluate( $battery );

		$this->assertSame( array( 'claim__hallucinated' => 0.95 ), $evaluation['fired'] );
		$this->assertNull( $evaluation['error'] );
		$this->assertTrue( $evaluation['used_jev'] );
	}

	/**
	 * Test the threshold filter is respected.
	 */
	public function test_evaluate_respects_threshold_filter() {
		add_filter(
			'wp_mcp_ai_cascade_escalate_threshold',
			function () {
				return 0.9;
			}
		);

		$cascade = $this->cascade();
		$battery = $cascade->build_battery(
			array( 'claim' => 'The sky is blue.' ),
			array(
				'claim' => array(
					'type'        => 'string',
					'description' => 'Claim text',
				),
			),
			'The sky is blue.'
		);

		$this->canned_answers = array( 'claim__hallucinated' => 0.85 );

		$evaluation = $cascade->evaluate( $battery );

		$this->assertEmpty( $evaluation['fired'] );
		$this->assertEquals( 0.9, $evaluation['threshold'] );
	}

	/**
	 * Test evaluate() fails open on a verifier error.
	 */
	public function test_evaluate_fails_open_on_verifier_error() {
		$cascade = $this->cascade();
		$battery = $cascade->build_battery(
			array( 'claim' => 'The sky is blue.' ),
			array(
				'claim' => array(
					'type'        => 'string',
					'description' => 'Claim text',
				),
			),
			'The sky is blue.'
		);

		$this->stub_error = new WP_Error( 'stub_down', 'Verifier unavailable.' );

		$evaluation = $cascade->evaluate( $battery );

		$this->assertEmpty( $evaluation['fired'] );
		$this->assertEquals( 'Verifier unavailable.', $evaluation['error'] );
		$this->assertFalse( $evaluation['used_jev'] );
	}

	/**
	 * Test evaluate() chunks batteries larger than the per-call cap.
	 */
	public function test_evaluate_chunks_large_batteries() {
		$cascade = $this->cascade();

		$record      = array();
		$field_specs = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$record[ 'field_' . $i ]      = 'Value ' . $i;
			$field_specs[ 'field_' . $i ] = array(
				'type'        => 'string',
				'description' => 'Field ' . $i,
			);
		}

		$battery    = $cascade->build_battery( $record, $field_specs, 'Source text.' );
		$evaluation = $cascade->evaluate( $battery );

		// 12 fields × 5 metrics = 60 questions → two calls of ≤ 50.
		$this->assertEquals( 2, $this->stub_call_count );
		$this->assertCount( 60, $evaluation['answers'] );
	}

	// -------------------------------------------------------------------------
	// run() — the full cascade.
	// -------------------------------------------------------------------------

	/**
	 * Test run() escalates exactly once when a flag fires.
	 */
	public function test_run_escalates_once_when_flag_fires() {
		$cascade = $this->cascade();

		$record = array( 'claim' => 'The sky is blue.' );
		$specs  = array(
			'claim' => array(
				'type'        => 'string',
				'description' => 'Claim text',
			),
		);

		$this->canned_answers = array( 'claim__hallucinated' => 0.95 );

		$calls = 0;

		$result = $cascade->run(
			$record,
			$specs,
			'The sky is blue.',
			function ( $record, $fired ) use ( &$calls ) {
				unset( $record, $fired );
				++$calls;

				return array( 'claim' => 'Revised claim.' );
			}
		);

		$this->assertTrue( $result['needs_escalation'] );
		$this->assertEquals( 1, $calls );
		$this->assertEquals( array( 'claim' => 'Revised claim.' ), $result['escalated'] );
		$this->assertSame( array( 'claim__hallucinated' => 0.95 ), $result['fired'] );
	}

	/**
	 * Test run() never escalates on a clean evaluation.
	 */
	public function test_run_does_not_escalate_when_clean() {
		$cascade = $this->cascade();

		$record = array( 'claim' => 'The sky is blue.' );
		$specs  = array(
			'claim' => array(
				'type'        => 'string',
				'description' => 'Claim text',
			),
		);

		$this->canned_answers = array( 'claim__hallucinated' => 0.1 );

		$calls = 0;

		$result = $cascade->run(
			$record,
			$specs,
			'The sky is blue.',
			function ( $record, $fired ) use ( &$calls ) {
				unset( $record, $fired );
				++$calls;
			}
		);

		$this->assertFalse( $result['needs_escalation'] );
		$this->assertEquals( 0, $calls );
		$this->assertNull( $result['escalated'] );
	}

	/**
	 * Test run() fails open when the verifier errors.
	 */
	public function test_run_fails_open_on_verifier_error() {
		$cascade = $this->cascade();

		$this->stub_error = new WP_Error( 'stub_down', 'Verifier unavailable.' );

		$calls = 0;

		$result = $cascade->run(
			array( 'claim' => 'The sky is blue.' ),
			array(
				'claim' => array(
					'type'        => 'string',
					'description' => 'Claim text',
				),
			),
			'The sky is blue.',
			function ( $record, $fired ) use ( &$calls ) {
				unset( $record, $fired );
				++$calls;
			}
		);

		$this->assertFalse( $result['needs_escalation'] );
		$this->assertEquals( 0, $calls );
		$this->assertEquals( 'Verifier unavailable.', $result['error'] );
	}

	/**
	 * Test run() catches a crashing escalation callback without looping.
	 */
	public function test_run_catches_crashing_escalation_callback() {
		$cascade = $this->cascade();

		$this->canned_answers = array( 'claim__hallucinated' => 0.95 );

		$result = $cascade->run(
			array( 'claim' => 'The sky is blue.' ),
			array(
				'claim' => array(
					'type'        => 'string',
					'description' => 'Claim text',
				),
			),
			'The sky is blue.',
			function ( $record, $fired ) {
				unset( $record, $fired );
				throw new RuntimeException( 'Escalation exploded.' );
			}
		);

		$this->assertTrue( $result['needs_escalation'] );
		$this->assertNull( $result['escalated'] );
		$this->assertEquals( 'Escalation exploded.', $result['escalation_error'] );
	}

	/**
	 * Test get_client() returns an error when nothing is configured.
	 */
	public function test_get_client_errors_without_configuration() {
		delete_option( 'wp_mcp_ai_settings' );

		$client = ( new WP_MCP_AI_Verification_Cascade() )->get_client();

		$this->assertWPError( $client );
		$this->assertEquals( 'wp_mcp_ai_cascade_unavailable', $client->get_error_code() );
	}
}
